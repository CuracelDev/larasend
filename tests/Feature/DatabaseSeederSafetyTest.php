<?php

use App\Models\Email;
use App\Models\Project;
use App\Models\User;
use App\Support\ProjectContext;
use Database\Seeders\DatabaseSeeder;

it('never alters an unrelated existing workspace or project', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $project = app(ProjectContext::class)->projectFor($owner);
    $project->forceFill(['name' => 'Customer Production', 'slug' => 'customer-production'])->save();
    $source = $project->sources()->firstOrFail();
    $email = Email::query()->create([
        'public_id' => 'email_customer_production',
        'workspace_id' => $project->workspace_id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'status' => 'delivered',
        'from_email' => 'receipts@customer.example',
        'subject' => 'Do not delete',
    ]);

    $this->seed(DatabaseSeeder::class);

    expect($project->fresh())
        ->name->toBe('Customer Production')
        ->slug->toBe('customer-production')
        ->and($email->fresh())->not->toBeNull();
});

it('is repeatable without duplicating demo records or using predictable api keys', function () {
    $this->seed(DatabaseSeeder::class);

    $demoWorkspace = User::query()
        ->where('email', 'demo@larasend.test')
        ->firstOrFail()
        ->workspaces()
        ->where('slug', 'larasend-demo')
        ->firstOrFail();

    $firstCounts = [
        'projects' => $demoWorkspace->projects()->count(),
        'emails' => $demoWorkspace->projects()->withCount('emails')->get()->sum('emails_count'),
        'events' => $demoWorkspace->projects()->with('emails')->get()->flatMap->emails->flatMap->events->count(),
        'api_keys' => $demoWorkspace->projects()->withCount('apiKeys')->get()->sum('api_keys_count'),
        'inbound_emails' => $demoWorkspace->projects()->withCount('inboundEmails')->get()->sum('inbound_emails_count'),
        'threads' => $demoWorkspace->projects()->withCount('threads')->get()->sum('threads_count'),
    ];

    $this->seed(DatabaseSeeder::class);

    $demoWorkspace->refresh();
    $secondCounts = [
        'projects' => $demoWorkspace->projects()->count(),
        'emails' => $demoWorkspace->projects()->withCount('emails')->get()->sum('emails_count'),
        'events' => $demoWorkspace->projects()->with('emails')->get()->flatMap->emails->flatMap->events->count(),
        'api_keys' => $demoWorkspace->projects()->withCount('apiKeys')->get()->sum('api_keys_count'),
        'inbound_emails' => $demoWorkspace->projects()->withCount('inboundEmails')->get()->sum('inbound_emails_count'),
        'threads' => $demoWorkspace->projects()->withCount('threads')->get()->sum('threads_count'),
    ];

    expect($secondCounts)->toBe($firstCounts)
        ->and($secondCounts['projects'])->toBe(2)
        ->and($secondCounts['emails'])->toBe(362)
        ->and($secondCounts['api_keys'])->toBe(12)
        ->and($secondCounts['inbound_emails'])->toBe(8)
        ->and($secondCounts['threads'])->toBe(6);

    $demoWorkspace->projects->each(function (Project $project): void {
        expect($project->apiKeys()->pluck('key_hash'))
            ->not->toContain(hash('sha256', 'lsk_live_8f2a_demo_secret_'.$project->id));
    });
});

it('never changes an existing account that matches the demo email', function () {
    $user = User::factory()->unverified()->create([
        'name' => 'Existing Demo Owner',
        'email' => 'demo@larasend.test',
        'password' => 'existing-password-hash',
    ]);
    $originalPassword = $user->password;

    $this->seed(DatabaseSeeder::class);

    expect($user->fresh())
        ->name->toBe('Existing Demo Owner')
        ->email_verified_at->toBeNull()
        ->password->toBe($originalPassword);
});

it('refuses to run in production', function () {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => app(DatabaseSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'only available in local and testing environments');
});
