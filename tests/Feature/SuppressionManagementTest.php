<?php

use App\Models\ApiKey;
use App\Models\Project;
use App\Models\Source;
use App\Models\Suppression;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

/**
 * @return array{User, Workspace, Project, Source}
 */
function suppressionManagementFixture(string $slug, string $provider = 'ses'): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::create([
        'owner_id' => $owner->id,
        'name' => str($slug)->headline()->toString(),
        'slug' => $slug,
    ]);
    $workspace->users()->attach($owner, ['role' => 'owner']);
    $project = Project::create([
        'workspace_id' => $workspace->id,
        'name' => str($slug)->headline()->toString(),
        'slug' => $slug,
    ]);
    $source = Source::create([
        'project_id' => $project->id,
        'name' => 'Production',
        'environment' => 'prod',
        'provider' => $provider,
        'ses_region' => 'us-east-1',
        'aws_access_key_id' => 'test-access-key',
        'aws_secret_access_key' => 'test-secret-key',
        'cloudflare_api_token' => $provider === 'cloudflare' ? 'test-cloudflare-token' : null,
        'cloudflare_account_id' => $provider === 'cloudflare' ? "account-{$slug}" : null,
        'webhook_token' => 'token-'.str()->random(8),
    ]);

    return [$owner, $workspace, $project, $source];
}

function managedSuppression(Project $project, ?Source $source, string $email = 'blocked@example.com'): Suppression
{
    return Suppression::create([
        'workspace_id' => $project->workspace_id,
        'project_id' => $project->id,
        'source_id' => $source?->id,
        'email' => $email,
        'reason' => 'hard_bounce',
        'event_type' => 'bounce',
    ]);
}

it('allows an owner to remove an SES suppression from the provider and project', function () {
    $this->travelTo(Carbon::parse('2030-01-02 03:04:05 UTC'));

    Http::preventStrayRequests();
    Http::fake([
        'https://email.us-east-1.amazonaws.com/v2/email/suppression/addresses/*' => Http::response(status: 204),
    ]);

    [$owner, , $project, $source] = suppressionManagementFixture('owner-removal');
    $suppression = managedSuppression($project, $source, 'Owner.Blocked@example.com');

    $this->actingAs($owner)
        ->get("/projects/{$project->slug}/suppressions")
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('workspace.can_manage_suppressions', true)
        );

    $this->actingAs($owner)
        ->delete("/projects/{$project->slug}/suppressions/{$suppression->id}")
        ->assertRedirect("/projects/{$project->slug}/suppressions");

    $this->assertModelMissing($suppression);

    Http::assertSent(function (Request $request): bool {
        $expectedAuthorization = 'AWS4-HMAC-SHA256 Credential=test-access-key/20300102/us-east-1/ses/aws4_request, '
            .'SignedHeaders=content-type;host;x-amz-date, '
            .'Signature=0649cdec45840e86b0bc4dd45e22f29959c0c612c2480022955966414a0ea0d7';

        return $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/v2/email/suppression/addresses/Owner.Blocked%40example.com')
            && $request->header('X-Amz-Date')[0] === '20300102T030405Z'
            && $request->header('Authorization')[0] === $expectedAuthorization;
    });

    $this->travelBack();
});

it('allows a member to remove an SES suppression when the provider already removed it', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://email.us-east-1.amazonaws.com/v2/email/suppression/addresses/*' => Http::response([
            'message' => 'Email address was not found.',
        ], 404),
    ]);

    [, $workspace, $project, $source] = suppressionManagementFixture('member-removal');
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'member']);
    $suppression = managedSuppression($project, $source);

    $this->actingAs($member)
        ->delete("/suppressions/{$suppression->id}")
        ->assertRedirect('/suppressions');

    $this->assertModelMissing($suppression);
});

it('forbids a sender from removing suppressions', function () {
    [, $workspace, $project, $source] = suppressionManagementFixture('sender-forbidden');
    $sender = User::factory()->create();
    $workspace->users()->attach($sender, ['role' => 'sender']);
    $suppression = managedSuppression($project, $source);

    $this->actingAs($sender)
        ->get("/projects/{$project->slug}/suppressions")
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('workspace.can_manage_suppressions', false)
        );

    $this->actingAs($sender)
        ->delete("/projects/{$project->slug}/suppressions/{$suppression->id}")
        ->assertForbidden();

    $this->assertModelExists($suppression);
});

it('returns not found for a suppression outside the selected web project', function () {
    [$owner, $workspace, $project] = suppressionManagementFixture('web-project-one');
    $otherProject = Project::create([
        'workspace_id' => $workspace->id,
        'name' => 'Project Two',
        'slug' => 'web-project-two',
    ]);
    $suppression = managedSuppression($otherProject, null);

    $this->actingAs($owner)
        ->delete("/projects/{$project->slug}/suppressions/{$suppression->id}")
        ->assertNotFound();

    $this->assertModelExists($suppression);
});

it('preserves a suppression when SES refuses provider removal', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://email.us-east-1.amazonaws.com/v2/email/suppression/addresses/*' => Http::response([
            'message' => 'Access denied.',
        ], 403),
    ]);

    [$owner, , $project, $source] = suppressionManagementFixture('ses-failure');
    $suppression = managedSuppression($project, $source);

    $response = $this->actingAs($owner)
        ->from("/projects/{$project->slug}/suppressions")
        ->delete("/projects/{$project->slug}/suppressions/{$suppression->id}");

    $response
        ->assertRedirect("/projects/{$project->slug}/suppressions")
        ->assertSessionHasErrors('suppression');

    expect(session('inertia.flash_data')['toast']['type'])->toBe('error');
    $this->assertModelExists($suppression);
});

it('refuses local Cloudflare removal while the address remains upstream', function () {
    Http::preventStrayRequests();
    [$owner, , $project, $source] = suppressionManagementFixture('cloudflare-present', 'cloudflare');
    $suppression = managedSuppression($project, $source);

    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/account-cloudflare-present/email/sending/suppression*' => Http::sequence()
            ->push([
                'success' => true,
                'result' => [
                    [
                        'id' => 'suppression-1',
                        'email' => 'BLOCKED@example.com',
                        'reason' => 'hard_bounce',
                        'created_at' => now()->toIso8601String(),
                        'expires_at' => null,
                    ],
                ],
            ])
            ->push(['success' => true, 'result' => []])
            ->push([
                'success' => true,
                'result' => [
                    [
                        'id' => 'suppression-1',
                        'email' => 'BLOCKED@example.com',
                        'reason' => 'hard_bounce',
                        'created_at' => now()->toIso8601String(),
                        'expires_at' => null,
                    ],
                ],
            ])
            ->push(['success' => true, 'result' => []]),
    ]);

    $this->actingAs($owner)
        ->from("/projects/{$project->slug}/suppressions")
        ->delete("/projects/{$project->slug}/suppressions/{$suppression->id}")
        ->assertRedirect("/projects/{$project->slug}/suppressions")
        ->assertSessionHasErrors('suppression');

    expect(session('errors')->get('suppression')[0])
        ->toContain('Cloudflare')
        ->toContain('upstream');
    $this->assertModelExists($suppression);
});

it('removes the local Cloudflare blocker after a complete list confirms it is absent', function () {
    Http::preventStrayRequests();
    [$owner, , $project, $source] = suppressionManagementFixture('cloudflare-absent', 'cloudflare');
    $suppression = managedSuppression($project, $source);

    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/account-cloudflare-absent/email/sending/suppression*' => Http::sequence()
            ->push([
                'success' => true,
                'result' => [
                    [
                        'id' => 'suppression-2',
                        'email' => 'someone-else@example.com',
                        'reason' => 'hard_bounce',
                        'created_at' => now()->toIso8601String(),
                        'expires_at' => null,
                    ],
                ],
            ])
            ->push(['success' => true, 'result' => []])
            ->push([
                'success' => true,
                'result' => [
                    [
                        'id' => 'suppression-2',
                        'email' => 'someone-else@example.com',
                        'reason' => 'hard_bounce',
                        'created_at' => now()->toIso8601String(),
                        'expires_at' => null,
                    ],
                ],
            ])
            ->push(['success' => true, 'result' => []]),
    ]);

    $this->actingAs($owner)
        ->delete("/projects/{$project->slug}/suppressions/{$suppression->id}")
        ->assertRedirect("/projects/{$project->slug}/suppressions");

    $this->assertModelMissing($suppression);
});

it('requires the manage suppressions scope for the API delete route', function () {
    [, , $project, $source] = suppressionManagementFixture('api-scope');
    $suppression = managedSuppression($project, null);
    $readKey = ApiKey::issue($project, 'Read key', $source, ['read:activity']);
    $manageKey = ApiKey::issue($project, 'Manage key', $source, ['manage:suppressions']);

    $this->withToken($readKey['plain_text'])
        ->deleteJson("/api/suppressions/{$suppression->id}")
        ->assertForbidden()
        ->assertJsonPath('message', 'This Larasend API key is missing the manage:suppressions scope.');

    $this->assertModelExists($suppression);

    $this->withToken($manageKey['plain_text'])
        ->deleteJson("/api/suppressions/{$suppression->id}")
        ->assertNoContent();

    $this->assertModelMissing($suppression);
});

it('returns conflict when Cloudflare still owns the suppression upstream', function () {
    Http::preventStrayRequests();
    [, , $project, $source] = suppressionManagementFixture('api-cloudflare-conflict', 'cloudflare');
    $suppression = managedSuppression($project, $source);
    $issued = ApiKey::issue($project, 'Manage key', $source, ['manage:suppressions']);
    $upstream = [
        'success' => true,
        'result' => [[
            'id' => 'suppression-conflict',
            'email' => 'blocked@example.com',
            'reason' => 'hard_bounce',
            'created_at' => now()->toIso8601String(),
            'expires_at' => null,
        ]],
    ];

    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/account-api-cloudflare-conflict/email/sending/suppression*' => Http::sequence()
            ->push($upstream)
            ->push(['success' => true, 'result' => []])
            ->push($upstream)
            ->push(['success' => true, 'result' => []]),
    ]);

    $this->withToken($issued['plain_text'])
        ->deleteJson("/api/suppressions/{$suppression->id}")
        ->assertConflict()
        ->assertJsonPath(
            'message',
            'Cloudflare still lists this address upstream and does not provide a suppression-removal API. Remove it in Cloudflare first, then retry.',
        );

    $this->assertModelExists($suppression);
});

it('returns service unavailable without exposing provider errors', function () {
    Http::preventStrayRequests();
    [, , $project, $source] = suppressionManagementFixture('api-provider-failure');
    $suppression = managedSuppression($project, $source);
    $issued = ApiKey::issue($project, 'Manage key', $source, ['manage:suppressions']);

    Exceptions::fake();
    Http::fake([
        'https://email.us-east-1.amazonaws.com/v2/email/suppression/addresses/*' => Http::response([
            'message' => 'super-secret-upstream-detail',
        ], 403),
    ]);

    $this->withToken($issued['plain_text'])
        ->deleteJson("/api/suppressions/{$suppression->id}")
        ->assertServiceUnavailable()
        ->assertJsonPath(
            'message',
            'Amazon SES could not remove this address right now. The local blocker was preserved. Try again later.',
        )
        ->assertJsonMissing(['message' => 'super-secret-upstream-detail']);

    Exceptions::assertReported(RequestException::class);
    $this->assertModelExists($suppression);
});

it('preserves a Cloudflare suppression when consecutive complete snapshots never stabilize', function () {
    Http::preventStrayRequests();
    [, , $project, $source] = suppressionManagementFixture('api-cloudflare-unstable', 'cloudflare');
    $suppression = managedSuppression($project, $source);
    $issued = ApiKey::issue($project, 'Manage key', $source, ['manage:suppressions']);
    $snapshot = fn (string $id): array => [
        'success' => true,
        'result' => [[
            'id' => $id,
            'email' => "{$id}@example.com",
            'reason' => 'hard_bounce',
            'created_at' => now()->toIso8601String(),
            'expires_at' => null,
        ]],
    ];

    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/account-api-cloudflare-unstable/email/sending/suppression*' => Http::sequence()
            ->push($snapshot('first'))
            ->push(['success' => true, 'result' => []])
            ->push($snapshot('second'))
            ->push(['success' => true, 'result' => []])
            ->push($snapshot('third'))
            ->push(['success' => true, 'result' => []]),
    ]);

    $this->withToken($issued['plain_text'])
        ->deleteJson("/api/suppressions/{$suppression->id}")
        ->assertServiceUnavailable();

    $this->assertModelExists($suppression);
});

it('keeps legacy null-scoped API keys authorized while preserving project isolation', function () {
    [, $workspace, $project, $source] = suppressionManagementFixture('api-legacy-scopes');
    $ownedSuppression = managedSuppression($project, null, 'owned@example.com');
    $otherProject = Project::create([
        'workspace_id' => $workspace->id,
        'name' => 'Legacy Other Project',
        'slug' => 'api-legacy-other',
    ]);
    $otherSuppression = managedSuppression($otherProject, null, 'other@example.com');
    $issued = ApiKey::issue($project, 'Legacy key', $source);

    $issued['api_key']->forceFill(['scopes' => null])->save();

    $this->withToken($issued['plain_text'])
        ->deleteJson("/api/suppressions/{$otherSuppression->id}")
        ->assertNotFound();

    $this->assertModelExists($otherSuppression);

    $this->withToken($issued['plain_text'])
        ->deleteJson("/api/suppressions/{$ownedSuppression->id}")
        ->assertNoContent();

    $this->assertModelMissing($ownedSuppression);
});

it('returns not found when an API key targets another project suppression', function () {
    [, $workspace, $project, $source] = suppressionManagementFixture('api-project-one');
    $otherProject = Project::create([
        'workspace_id' => $workspace->id,
        'name' => 'API Project Two',
        'slug' => 'api-project-two',
    ]);
    $suppression = managedSuppression($otherProject, null);
    $issued = ApiKey::issue($project, 'Manage key', $source, ['manage:suppressions']);

    $this->withToken($issued['plain_text'])
        ->deleteJson("/api/suppressions/{$suppression->id}")
        ->assertNotFound();

    $this->assertModelExists($suppression);
});
