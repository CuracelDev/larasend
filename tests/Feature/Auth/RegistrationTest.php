<?php

use App\Models\User;
use App\Notifications\ControlEmailVerification;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();

    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->email_verification_required_at)->toBeNull();
});

test('registration screen redirects to login once a user exists', function () {
    User::factory()->create();

    $this->get(route('register'))->assertRedirect(route('login'));
});

test('registration is rejected once a user exists', function () {
    User::factory()->create();

    $response = $this->post(route('register.store'), [
        'name' => 'Second User',
        'email' => 'second@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
    expect(User::query()->count())->toBe(1);
});

test('registration stays open when LARASEND_OPEN_REGISTRATION is enabled', function () {
    config(['larasend.open_registration' => true]);
    User::factory()->create();

    $this->get(route('register'))->assertOk();

    $response = $this->post(route('register.store'), [
        'name' => 'Second User',
        'email' => 'second@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    expect(User::query()->where('email', 'second@example.com')->firstOrFail()->hasVerifiedEmail())->toBeTrue();
});

test('later registrations require verification when control mail is configured', function () {
    Notification::fake();
    config([
        'larasend.open_registration' => true,
        'larasend.control_mailer' => 'smtp',
    ]);
    User::factory()->create();

    $this->post(route('register.store'), [
        'name' => 'Second User',
        'email' => 'second@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'second@example.com')->firstOrFail();

    expect($user->email_verified_at)->toBeNull()
        ->and($user->email_verification_required_at)->not->toBeNull();
    Notification::assertSentTo($user, ControlEmailVerification::class);
});

test('the initial installation owner stays verified with control mail enabled', function () {
    Notification::fake();
    config(['larasend.control_mailer' => 'smtp']);

    $this->post(route('register.store'), [
        'name' => 'Initial Owner',
        'email' => 'owner@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'owner@example.com')->firstOrFail();

    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->email_verification_required_at)->toBeNull();
    Notification::assertNothingSent();
});
