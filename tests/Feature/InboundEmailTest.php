<?php

use App\Jobs\DeliverInboundWebhook;
use App\Models\CloudflareInboundDomain;
use App\Models\InboundEmail;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use App\Services\InboundEmailIngestor;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function inboundProjectFixture(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Inbound Co', 'slug' => 'inbound-co']);
    $workspace->users()->attach($user, ['role' => 'owner']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'postbox', 'slug' => 'postbox']);
    $source = Source::create([
        'project_id' => $project->id,
        'name' => 'Production',
        'environment' => 'prod',
        'provider' => 'cloudflare',
        'cloudflare_api_token' => 'cf-test-token',
        'cloudflare_account_id' => 'acc-inbound',
        'default_from_email' => 'notifications@mail.example.com',
        'webhook_token' => 'inbound-token-'.str()->random(8),
    ]);
    $project->domains()->create([
        'domain' => 'example.com',
        'status' => 'verified',
        'dns_records' => [],
        'verified_at' => now(),
        'inbound_enabled_at' => now(),
        'inbound_domain' => 'example.com',
    ]);

    return [$user, $workspace, $project, $source];
}

function sampleInboundMime(): string
{
    return implode("\r\n", [
        'From: Maya Lin <maya@customer.test>',
        'To: support@example.com',
        'Subject: Need help with my invoice',
        'Message-ID: <origin-123@customer.test>',
        'In-Reply-To: <thread-99@example.com>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="BOUND"',
        '',
        '--BOUND',
        'Content-Type: text/plain; charset=utf-8',
        '',
        'Hi, my invoice looks wrong.',
        '--BOUND',
        'Content-Type: text/html; charset=utf-8',
        '',
        '<p>Hi, my <strong>invoice</strong> looks wrong.</p>',
        '--BOUND--',
        '',
    ]);
}

it('ingests inbound email posted by the cloudflare worker', function () {
    [, $workspace, $project, $source] = inboundProjectFixture();

    Queue::fake();

    $this->postJson("/api/webhooks/inbound/cloudflare/{$source->webhook_token}", [
        'from' => 'maya@customer.test',
        'to' => 'support@example.com',
        'raw' => base64_encode(sampleInboundMime()),
    ])->assertStatus(202);

    $inbound = InboundEmail::query()->firstOrFail();

    expect($inbound->project_id)->toBe($project->id)
        ->and($inbound->from_email)->toBe('maya@customer.test')
        ->and($inbound->from_name)->toBe('Maya Lin')
        ->and($inbound->to_email)->toBe('support@example.com')
        ->and($inbound->subject)->toBe('Need help with my invoice')
        ->and(trim((string) $inbound->text))->toBe('Hi, my invoice looks wrong.')
        ->and($inbound->html)->toContain('<strong>invoice</strong>')
        ->and($inbound->message_id)->toBe('origin-123@customer.test')
        ->and($inbound->in_reply_to)->toBe('thread-99@example.com')
        ->and($inbound->mime_size)->toBeGreaterThan(0);

    Queue::assertPushed(DeliverInboundWebhook::class);
});

it('streams raw inbound mime from the cloudflare worker without base64 buffering', function () {
    [, , $project, $source] = inboundProjectFixture();
    Queue::fake();

    $this->call(
        'POST',
        "/api/webhooks/inbound/cloudflare/{$source->webhook_token}",
        server: [
            'CONTENT_TYPE' => 'message/rfc822',
            'HTTP_LARASEND_ENVELOPE_FROM' => 'maya@customer.test',
            'HTTP_LARASEND_ENVELOPE_TO' => 'support@example.com',
        ],
        content: sampleInboundMime(),
    )->assertAccepted();

    $inbound = InboundEmail::query()->firstOrFail();

    expect($inbound->project_id)->toBe($project->id)
        ->and($inbound->subject)->toBe('Need help with my invoice')
        ->and($inbound->mime_size)->toBe(strlen(sampleInboundMime()));
});

it('adopts an existing cloudflare zone when upgrading a legacy inbound domain', function () {
    [, , , $source] = inboundProjectFixture();
    $domain = $source->project->domains()->firstOrFail();
    $domain->forceFill([
        'domain' => 'mail.example.com',
        'inbound_domain' => null,
    ])->save();
    Queue::fake();
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones?*' => Http::response([
            'success' => true,
            'result' => [['id' => 'zone-legacy', 'name' => 'example.com', 'account' => ['id' => 'acc-inbound']]],
        ]),
    ]);

    $this->postJson("/api/webhooks/inbound/cloudflare/{$source->webhook_token}", [
        'from' => 'maya@customer.test',
        'to' => 'support@example.com',
        'raw' => base64_encode(sampleInboundMime()),
    ])->assertAccepted();

    expect($domain->fresh()->inbound_domain)->toBe('example.com')
        ->and(CloudflareInboundDomain::query()->where('zone', 'example.com')->value('project_id'))
        ->toBe($source->project_id);
});

it('stores inbound mime on the configured shared disk', function () {
    [, , , $source] = inboundProjectFixture();
    config(['larasend.mime_disk' => 'shared-mail']);
    Storage::fake('shared-mail');
    Queue::fake();

    $this->postJson("/api/webhooks/inbound/cloudflare/{$source->webhook_token}", [
        'from' => 'maya@customer.test',
        'to' => 'support@example.com',
        'raw' => base64_encode(sampleInboundMime()),
    ])->assertAccepted();

    $inbound = InboundEmail::query()->firstOrFail();

    expect($inbound->mime_disk)->toBe('shared-mail');
    Storage::disk('shared-mail')->assertExists($inbound->mime_path);
});

it('keeps committed inbound mime when queue dispatch is temporarily unavailable', function () {
    [, , , $source] = inboundProjectFixture();
    config(['larasend.mime_disk' => 'shared-mail']);
    Storage::fake('shared-mail');
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('getCommandHandler')->andReturnNull();
    $dispatcher->shouldReceive('dispatch')->andThrow(new RuntimeException('Queue unavailable.'));
    $this->app->instance(Dispatcher::class, $dispatcher);
    $mime = implode("\r\n", [
        'From: Maya <maya@customer.test>',
        'To: support@example.com',
        'Subject: Durable inbound handoff',
        'Message-ID: <durable-inbound@customer.test>',
        '',
        'Please keep this message.',
    ]);

    expect(fn () => app(InboundEmailIngestor::class)->ingest(
        $source,
        'maya@customer.test',
        'support@example.com',
        $mime,
    ))->toThrow(RuntimeException::class, 'Queue unavailable.');

    $inbound = InboundEmail::query()->where('subject', 'Durable inbound handoff')->firstOrFail();

    Storage::disk('shared-mail')->assertExists($inbound->mime_path);
});

it('deduplicates repeated inbound deliveries', function () {
    [, , , $source] = inboundProjectFixture();
    Queue::fake();
    $payload = [
        'from' => 'maya@customer.test',
        'to' => 'support@example.com',
        'raw' => base64_encode(sampleInboundMime()),
    ];

    $first = $this->postJson("/api/webhooks/inbound/cloudflare/{$source->webhook_token}", $payload)->assertAccepted();
    $second = $this->postJson("/api/webhooks/inbound/cloudflare/{$source->webhook_token}", $payload)->assertAccepted();

    expect(InboundEmail::query()->count())->toBe(1)
        ->and($second->json('id'))->toBe($first->json('id'));
    Queue::assertPushed(DeliverInboundWebhook::class, 1);
});

it('rejects inbound posts with an unknown token or wrong provider', function () {
    [, , , $source] = inboundProjectFixture();
    $source->forceFill(['provider' => 'ses'])->save();

    $this->postJson("/api/webhooks/inbound/cloudflare/{$source->webhook_token}", [
        'from' => 'a@b.test',
        'to' => 'c@d.test',
        'raw' => base64_encode('hello'),
    ])->assertNotFound();

    $this->postJson('/api/webhooks/inbound/cloudflare/not-a-token', [
        'from' => 'a@b.test',
        'to' => 'c@d.test',
        'raw' => base64_encode('hello'),
    ])->assertNotFound();

    expect(InboundEmail::query()->count())->toBe(0);
});

it('rejects inbound mail addressed to a domain that is not enabled for the source', function () {
    [, , , $source] = inboundProjectFixture();

    $this->postJson("/api/webhooks/inbound/cloudflare/{$source->webhook_token}", [
        'from' => 'maya@customer.test',
        'to' => 'support@other-project.example',
        'raw' => base64_encode(sampleInboundMime()),
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'Recipient domain is not enabled for this source.');

    expect(InboundEmail::query()->count())->toBe(0);
});

it('rejects invalid base64 payloads', function () {
    [, , , $source] = inboundProjectFixture();

    $this->postJson("/api/webhooks/inbound/cloudflare/{$source->webhook_token}", [
        'from' => 'a@b.test',
        'to' => 'c@d.test',
        'raw' => 'not!!valid@@base64',
    ])->assertStatus(422);
});

it('fans inbound emails out to subscribed webhook endpoints with signatures', function () {
    [, , $project, $source] = inboundProjectFixture();

    $issued = WebhookEndpoint::issue($project, 'https://customer.test/hooks', ['inbound.received']);
    WebhookEndpoint::issue($project, 'https://customer.test/other', ['delivery']);

    Http::fake(['https://customer.test/*' => Http::response(['ok' => true])]);

    $this->postJson("/api/webhooks/inbound/cloudflare/{$source->webhook_token}", [
        'from' => 'maya@customer.test',
        'to' => 'support@example.com',
        'raw' => base64_encode(sampleInboundMime()),
    ])->assertStatus(202);

    Http::assertSentCount(1);
    Http::assertSent(function ($request) {
        return $request->url() === 'https://customer.test/hooks'
            && $request->hasHeader('Larasend-Event-Type', 'inbound.received')
            && str_contains($request->header('Larasend-Signature')[0] ?? '', 'v1=')
            && ($request->data()['data']['inbound_email']['subject'] ?? null) === 'Need help with my invoice'
            && str_starts_with((string) ($request->data()['data']['inbound_email']['thread_id'] ?? ''), 'thread_');
    });

    expect($issued['endpoint']->fresh()->last_delivered_at)->not->toBeNull();
});
