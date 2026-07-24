<?php

use App\Models\Email;
use App\Models\Project;
use App\Models\Source;
use App\Models\Suppression;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SesEventNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function suppressionEmailNormalizationMigration(): Migration
{
    $migrationFiles = glob(database_path('migrations/*_normalize_suppression_emails_and_enforce_unique_index.php'));

    expect($migrationFiles)->toHaveCount(1);

    return require $migrationFiles[0];
}

it('safely consolidates historical case variants and enforces normalized uniqueness', function () {
    $migration = suppressionEmailNormalizationMigration();
    $migration->down();

    $now = Carbon::parse('2026-07-24 12:00:00');
    $this->travelTo($now);

    $user = User::factory()->create();
    $workspace = Workspace::create([
        'owner_id' => $user->id,
        'name' => 'Suppression Migration',
        'slug' => 'suppression-migration',
    ]);
    $project = Project::create([
        'workspace_id' => $workspace->id,
        'name' => 'Suppression Migration',
        'slug' => 'suppression-migration',
    ]);
    $sesSource = Source::create([
        'project_id' => $project->id,
        'name' => 'SES',
        'environment' => 'prod',
        'provider' => 'ses',
        'webhook_token' => 'ses-suppression-migration',
    ]);
    $cloudflareSource = Source::create([
        'project_id' => $project->id,
        'name' => 'Cloudflare',
        'environment' => 'staging',
        'provider' => 'cloudflare',
        'cloudflare_api_token' => 'token',
        'cloudflare_account_id' => 'account',
        'webhook_token' => 'cloudflare-suppression-migration',
    ]);
    $historicalEmail = Email::create([
        'public_id' => 'historical_suppression_email',
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $sesSource->id,
        'status' => 'complained',
        'ses_message_id' => 'ses-historical',
        'from_email' => 'receipts@example.com',
        'subject' => 'Historical complaint',
    ]);
    $incomingEmail = Email::create([
        'public_id' => 'incoming_suppression_email',
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $sesSource->id,
        'status' => 'sent',
        'ses_message_id' => 'ses-incoming',
        'from_email' => 'receipts@example.com',
        'subject' => 'Incoming complaint',
    ]);

    $complaintId = DB::table('suppressions')->insertGetId([
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $sesSource->id,
        'email_id' => $historicalEmail->id,
        'email' => ' Abuse@Example.com ',
        'reason' => 'complaint',
        'event_type' => 'complaint',
        'expires_at' => $now->copy()->addWeek(),
        'created_at' => $now->copy()->subDays(3),
        'updated_at' => $now->copy()->subDays(2),
    ]);
    DB::table('suppressions')->insert([
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $cloudflareSource->id,
        'email_id' => null,
        'email' => 'abuse@example.com',
        'reason' => 'hard_bounce',
        'event_type' => 'provider_sync',
        'expires_at' => null,
        'created_at' => $now->copy()->subDays(2),
        'updated_at' => $now->copy()->subDay(),
    ]);
    DB::table('suppressions')->insert([
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $sesSource->id,
        'email_id' => $historicalEmail->id,
        'email' => 'ABUSE@example.com',
        'reason' => 'hard_bounce',
        'event_type' => 'bounce',
        'expires_at' => $now->copy()->subDay(),
        'created_at' => $now->copy()->subDays(4),
        'updated_at' => $now,
    ]);
    DB::table('suppressions')->insert([
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $sesSource->id,
        'email_id' => null,
        'email' => ' Singleton@Example.com ',
        'reason' => 'hard_bounce',
        'event_type' => 'bounce',
        'expires_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $migration->up();

    $suppression = Suppression::query()
        ->where('project_id', $project->id)
        ->where('email', 'abuse@example.com')
        ->firstOrFail();

    expect($suppression)
        ->id->toBe($complaintId)
        ->source_id->toBe($sesSource->id)
        ->email_id->toBe($historicalEmail->id)
        ->reason->toBe('complaint')
        ->event_type->toBe('complaint')
        ->expires_at->toBeNull()
        ->and(Suppression::query()->where('project_id', $project->id)->where('email', 'abuse@example.com')->count())->toBe(1)
        ->and(Suppression::query()->where('project_id', $project->id)->where('email', 'singleton@example.com')->exists())->toBeTrue();

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(SesEventNormalizer::class)->record($sesSource, [
        'eventType' => 'Complaint',
        'mail' => [
            'messageId' => 'ses-incoming',
            'timestamp' => $now->toIso8601String(),
            'destination' => ['AbUsE@Example.com'],
        ],
        'complaint' => [
            'timestamp' => $now->toIso8601String(),
            'complainedRecipients' => [
                ['emailAddress' => 'AbUsE@Example.com'],
            ],
        ],
    ]);

    $suppressionQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => str_contains(strtolower($query), 'suppressions'))
        ->values();
    DB::disableQueryLog();

    expect($suppression->fresh())
        ->id->toBe($complaintId)
        ->source_id->toBe($sesSource->id)
        ->email_id->toBe($incomingEmail->id)
        ->email->toBe('abuse@example.com')
        ->reason->toBe('complaint')
        ->event_type->toBe('complaint')
        ->expires_at->toBeNull()
        ->and(Suppression::query()->where('project_id', $project->id)->where('email', 'abuse@example.com')->count())->toBe(1)
        ->and($suppressionQueries)->toHaveCount(1)
        ->and($suppressionQueries->sole())->toMatch('/on conflict|on duplicate key update/i');

    $normalizedByModel = Suppression::create([
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $sesSource->id,
        'email' => ' Model.Write@Example.com ',
        'reason' => 'complaint',
        'event_type' => 'complaint',
    ]);

    expect($normalizedByModel->email)->toBe('model.write@example.com');

    expect(fn () => DB::table('suppressions')->insert([
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $cloudflareSource->id,
        'email_id' => null,
        'email' => 'ABUSE@EXAMPLE.COM',
        'reason' => 'hard_bounce',
        'event_type' => 'provider_sync',
        'expires_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]))->toThrow(QueryException::class);
});
