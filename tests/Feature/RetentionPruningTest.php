<?php

use App\Jobs\PruneExpiredEmailData;
use App\Models\Email;
use App\Models\InboundEmail;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\WebhookLog;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;

it('prunes expired message data and backing mime while preserving recent records', function () {
    Storage::fake('retention-test');
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Retention', 'slug' => 'retention']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'Mail', 'slug' => 'mail']);
    $source = Source::create([
        'project_id' => $project->id,
        'name' => 'Production',
        'environment' => 'prod',
        'webhook_token' => 'retention-token',
        'retention_days' => 30,
    ]);

    $expiredEmail = retentionEmail($workspace, $project, $source, 'expired', now()->subDays(31));
    $recentEmail = retentionEmail($workspace, $project, $source, 'recent', now()->subDays(29));
    $expiredInbound = retentionInbound($workspace, $project, $source, 'expired', now()->subDays(31));
    $recentInbound = retentionInbound($workspace, $project, $source, 'recent', now()->subDays(29));
    $expiredEmail->events()->create([
        'source_id' => $source->id,
        'event_type' => 'delivery',
        'payload' => [],
        'occurred_at' => now()->subDays(31),
    ]);
    $oldLog = WebhookLog::create([
        'source_id' => $source->id,
        'provider' => 'ses',
        'status' => 'processed',
        'payload' => [],
    ]);
    $oldLog->forceFill(['created_at' => now()->subDays(31), 'updated_at' => now()->subDays(31)])->saveQuietly();
    $endpoint = WebhookEndpoint::issue($project, 'https://example.com/hooks', ['delivery'])['endpoint'];
    $oldDelivery = $endpoint->deliveries()->create([
        'public_id' => 'whd_expired',
        'event_type' => 'delivery',
        'status' => 'ok',
        'payload' => [],
        'delivered_at' => now()->subDays(31),
    ]);

    (new PruneExpiredEmailData)->handle();

    expect($expiredEmail->fresh())->toBeNull()
        ->and($expiredInbound->fresh())->toBeNull()
        ->and($recentEmail->fresh())->not->toBeNull()
        ->and($recentInbound->fresh())->not->toBeNull()
        ->and($oldLog->fresh())->toBeNull()
        ->and($oldDelivery->fresh())->toBeNull();
    Storage::disk('retention-test')->assertMissing($expiredEmail->mime_path);
    Storage::disk('retention-test')->assertMissing($expiredInbound->mime_path);
    Storage::disk('retention-test')->assertExists($recentEmail->mime_path);
    Storage::disk('retention-test')->assertExists($recentInbound->mime_path);
});

it('uses the longest source retention when pruning project webhook history', function () {
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Retention', 'slug' => 'retention']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'Mail', 'slug' => 'mail']);
    Source::create([
        'project_id' => $project->id,
        'name' => 'Short retention',
        'environment' => 'prod',
        'webhook_token' => 'short-retention',
        'retention_days' => 30,
    ]);
    Source::create([
        'project_id' => $project->id,
        'name' => 'Long retention',
        'environment' => 'staging',
        'webhook_token' => 'long-retention',
        'retention_days' => 90,
    ]);
    $endpoint = WebhookEndpoint::issue($project, 'https://example.com/hooks', ['delivery'])['endpoint'];
    $delivery = $endpoint->deliveries()->create([
        'public_id' => 'whd_retained',
        'event_type' => 'delivery',
        'status' => 'ok',
        'payload' => [],
        'delivered_at' => now()->subDays(31),
    ]);

    (new PruneExpiredEmailData)->handle();

    expect($delivery->fresh())->not->toBeNull();
});

function retentionEmail(Workspace $workspace, Project $project, Source $source, string $suffix, DateTimeInterface $createdAt): Email
{
    $path = "emails/{$project->id}/{$suffix}.eml";
    Storage::disk('retention-test')->put($path, 'mime');
    $email = Email::create([
        'public_id' => 'email_'.$suffix,
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'status' => 'delivered',
        'from_email' => 'sender@example.com',
        'subject' => ucfirst($suffix),
        'mime_disk' => 'retention-test',
        'mime_path' => $path,
    ]);
    $email->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

    return $email;
}

function retentionInbound(Workspace $workspace, Project $project, Source $source, string $suffix, DateTimeInterface $receivedAt): InboundEmail
{
    $path = "inbound/{$project->id}/{$suffix}.eml";
    Storage::disk('retention-test')->put($path, 'mime');

    return InboundEmail::create([
        'public_id' => 'inbound_'.$suffix,
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'from_email' => 'customer@example.com',
        'to_email' => 'support@example.com',
        'subject' => ucfirst($suffix),
        'headers' => [],
        'attachments' => [],
        'mime_disk' => 'retention-test',
        'mime_path' => $path,
        'received_at' => $receivedAt,
    ]);
}
