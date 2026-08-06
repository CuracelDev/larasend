<?php

use App\Models\User;
use App\Notifications\ControlEmailVerification;
use App\Notifications\ControlPasswordReset;
use App\Support\ControlMail;

it('requires an explicitly configured independent control mailer', function () {
    expect(app(ControlMail::class)->isConfigured())->toBeFalse();

    config(['larasend.control_mailer' => 'log']);
    expect(app(ControlMail::class)->isConfigured())->toBeFalse();

    config(['larasend.control_mailer' => 'larasend']);
    expect(app(ControlMail::class)->isConfigured())->toBeFalse();

    config(['larasend.control_mailer' => 'smtp']);
    expect(app(ControlMail::class)->isConfigured())->toBeTrue();
});

it('rejects unsafe mailers anywhere in a configured failover graph', function () {
    config([
        'larasend.control_mailer' => 'control',
        'mail.mailers.control' => ['transport' => 'failover', 'mailers' => ['smtp', 'unsafe-fallback']],
        'mail.mailers.unsafe-fallback' => ['transport' => 'roundrobin', 'mailers' => ['log']],
    ]);

    expect(app(ControlMail::class)->isConfigured())->toBeFalse();
});

it('rejects missing mailers and cycles in a configured mailer graph', function () {
    config([
        'larasend.control_mailer' => 'control',
        'mail.mailers.control' => ['transport' => 'failover', 'mailers' => ['missing']],
    ]);

    expect(app(ControlMail::class)->isConfigured())->toBeFalse();

    config([
        'mail.mailers.control' => ['transport' => 'roundrobin', 'mailers' => ['secondary']],
        'mail.mailers.secondary' => ['transport' => 'failover', 'mailers' => ['control']],
    ]);

    expect(app(ControlMail::class)->isConfigured())->toBeFalse();
});

it('grandfathers existing users without changing historical verification timestamps', function () {
    config(['larasend.control_mailer' => 'smtp']);
    $user = User::factory()->create([
        'email_verified_at' => null,
        'email_verification_required_at' => null,
    ]);

    expect($user->hasVerifiedEmail())->toBeTrue();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect('/onboarding');
});

it('only enforces a pending verification while control mail is configured', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect('/onboarding');

    config(['larasend.control_mailer' => 'smtp']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});

it('routes authentication notifications through the dedicated mailer', function () {
    config(['larasend.control_mailer' => 'smtp']);
    $user = User::factory()->unverified()->create();

    expect((new ControlEmailVerification)->toMail($user)->mailer)->toBe('smtp')
        ->and((new ControlPasswordReset('reset-token'))->toMail($user)->mailer)->toBe('smtp');
});

it('administratively verifies an exact user only with force', function () {
    config(['larasend.control_mailer' => 'smtp']);
    $user = User::factory()->unverified()->create(['email' => 'owner@example.com']);

    $this->artisan('larasend:verify-user', ['email' => $user->email])
        ->expectsOutputToContain('--force option is required')
        ->assertExitCode(2);

    $this->artisan('larasend:verify-user', [
        'email' => $user->email,
        '--force' => true,
    ])->assertSuccessful();

    expect($user->fresh()->email_verified_at)->not->toBeNull()
        ->and($user->fresh()->email_verification_required_at)->toBeNull();

    $this->artisan('larasend:verify-user', [
        'email' => 'missing@example.com',
        '--force' => true,
    ])->assertFailed();
});

it('administratively finds an email address case insensitively', function () {
    $user = User::factory()->unverified()->create(['email' => 'Owner@Example.com']);

    $this->artisan('larasend:verify-user', [
        'email' => 'OWNER@example.COM',
        '--force' => true,
    ])->assertSuccessful();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});
