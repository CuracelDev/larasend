<?php

use App\Jobs\SyncCloudflareSourceSuppressions;
use App\Models\Email;
use App\Models\Project;
use App\Models\Source;
use App\Models\Suppression;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CloudflareApiClient;
use App\Services\SesEventNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function suppressionEmailNormalizationMigrationFile(): string
{
    $migrationFiles = glob(database_path('migrations/*_normalize_suppression_emails_and_enforce_unique_index.php'));

    expect($migrationFiles)->toHaveCount(1);

    return $migrationFiles[0];
}

function suppressionEmailNormalizationMigration(): Migration
{
    return require suppressionEmailNormalizationMigrationFile();
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

it('keeps an active local blocker over a Cloudflare complaint and protects it from later pruning', function () {
    $migration = suppressionEmailNormalizationMigration();
    $migration->down();

    $now = Carbon::parse('2026-07-24 12:00:00');
    $this->travelTo($now);

    $user = User::factory()->create();
    $workspace = Workspace::create([
        'owner_id' => $user->id,
        'name' => 'Inverse Suppression Collision',
        'slug' => 'inverse-suppression-collision',
    ]);
    $project = Project::create([
        'workspace_id' => $workspace->id,
        'name' => 'Inverse Suppression Collision',
        'slug' => 'inverse-suppression-collision',
    ]);
    $sesSource = Source::create([
        'project_id' => $project->id,
        'name' => 'SES',
        'environment' => 'prod',
        'provider' => 'ses',
        'webhook_token' => 'ses-inverse-collision',
    ]);
    $cloudflareSource = Source::create([
        'project_id' => $project->id,
        'name' => 'Cloudflare',
        'environment' => 'staging',
        'provider' => 'cloudflare',
        'cloudflare_api_token' => 'token',
        'cloudflare_account_id' => 'inverse-collision-account',
        'webhook_token' => 'cloudflare-inverse-collision',
    ]);

    $localId = DB::table('suppressions')->insertGetId([
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $sesSource->id,
        'email_id' => null,
        'email' => ' Independent@Example.com ',
        'reason' => 'hard_bounce',
        'event_type' => 'bounce',
        'expires_at' => $now->copy()->addWeek(),
        'created_at' => $now->copy()->subDays(2),
        'updated_at' => $now->copy()->subDay(),
    ]);
    DB::table('suppressions')->insert([
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $cloudflareSource->id,
        'email_id' => null,
        'email' => 'independent@example.com',
        'reason' => 'complaint',
        'event_type' => 'provider_sync',
        'expires_at' => null,
        'created_at' => $now->copy()->subDay(),
        'updated_at' => $now,
    ]);

    $migration->up();

    $survivor = Suppression::query()
        ->where('project_id', $project->id)
        ->where('email', 'independent@example.com')
        ->firstOrFail();

    expect($survivor)
        ->id->toBe($localId)
        ->source_id->toBe($sesSource->id)
        ->reason->toBe('hard_bounce')
        ->event_type->toBe('bounce')
        ->expires_at->toBeNull();

    Http::preventStrayRequests();
    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/inverse-collision-account/email/sending/suppression*' => Http::sequence()
            ->push(['success' => true, 'result' => []])
            ->push(['success' => true, 'result' => []]),
    ]);

    (new SyncCloudflareSourceSuppressions($cloudflareSource->id))
        ->handle(app(CloudflareApiClient::class));

    expect($survivor->fresh())
        ->id->toBe($localId)
        ->source_id->toBe($sesSource->id)
        ->event_type->toBe('bounce');
});

it('uses the same ASCII normalization contract in the migration and model', function () {
    $migration = suppressionEmailNormalizationMigration();
    $migration->down();

    $user = User::factory()->create();
    $workspace = Workspace::create([
        'owner_id' => $user->id,
        'name' => 'Suppression Canonicalization',
        'slug' => 'suppression-canonicalization',
    ]);
    $project = Project::create([
        'workspace_id' => $workspace->id,
        'name' => 'Suppression Canonicalization',
        'slug' => 'suppression-canonicalization',
    ]);
    $source = Source::create([
        'project_id' => $project->id,
        'name' => 'SES',
        'environment' => 'prod',
        'provider' => 'ses',
        'webhook_token' => 'ses-suppression-canonicalization',
    ]);

    DB::table('suppressions')->insert([
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'email_id' => null,
        'email' => "\tTabs@Example.com\t",
        'reason' => 'hard_bounce',
        'event_type' => 'bounce',
        'expires_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('suppressions')->insert([
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'email_id' => null,
        'email' => "\tÄBC@Unicode.Example\t",
        'reason' => 'complaint',
        'event_type' => 'complaint',
        'expires_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    expect(DB::table('suppressions')->where('reason', 'hard_bounce')->value('email'))
        ->toBe("\ttabs@example.com\t")
        ->and(DB::table('suppressions')->where('reason', 'complaint')->value('email'))
        ->toBe("\tÄbc@unicode.example\t");

    $model = new Suppression;
    $model->email = "\tTABS@Example.com\t";
    $unicodeModel = new Suppression;
    $unicodeModel->email = "\tÄBC@Unicode.Example\t";

    expect($model->getAttributes()['email'])->toBe("\ttabs@example.com\t")
        ->and($unicodeModel->getAttributes()['email'])->toBe("\tÄbc@unicode.example\t")
        ->and(fn () => Suppression::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'source_id' => $source->id,
            'email' => "\tTABS@Example.com\t",
            'reason' => 'hard_bounce',
            'event_type' => 'bounce',
        ]))->toThrow(QueryException::class);

    DB::table('suppressions')->insert([
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'email_id' => null,
        'email' => 'tabs@example.com',
        'reason' => 'hard_bounce',
        'event_type' => 'bounce',
        'expires_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(Suppression::query()->where('project_id', $project->id)->count())->toBe(3);
});

it('locks affected duplicate rows before deleting a loser', function () {
    $migrationSource = file_get_contents(suppressionEmailNormalizationMigrationFile());

    expect($migrationSource)
        ->toContain('->lockForUpdate()')
        ->and($migrationSource)->toMatch('/lockForUpdate\\(\\).*whereIn\\([^;]+delete\\(\\)/s');
});

it('uses binary indexed values and replaces the conflicting legacy unique index on supported drivers', function () {
    $migrationSource = file_get_contents(suppressionEmailNormalizationMigrationFile());

    expect($migrationSource)
        ->toContain("private const ORIGINAL_UNIQUE_INDEX = 'suppressions_project_id_email_unique'")
        ->toContain('VARBINARY(1020)')
        ->toContain('CAST(')
        ->toContain(' AS BINARY)')
        ->toContain('Latin1_General_100_BIN2')
        ->toMatch('/DROP INDEX.*ORIGINAL_UNIQUE_INDEX/s')
        ->toMatch('/ADD UNIQUE INDEX.*ORIGINAL_UNIQUE_INDEX/s')
        ->toContain('dropUnique(self::ORIGINAL_UNIQUE_INDEX)')
        ->toContain("unique(['project_id', 'email'], self::ORIGINAL_UNIQUE_INDEX)");
});
