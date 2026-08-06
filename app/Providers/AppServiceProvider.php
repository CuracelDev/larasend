<?php

namespace App\Providers;

use App\Support\SystemHealth;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Stamped on every worker poll loop so the dashboard can tell users
        // when no queue worker is running instead of leaving emails "queued".
        Queue::looping(fn () => app(SystemHealth::class)->recordWorkerHeartbeat());

        RateLimiter::for('larasend-api-ip', function (Request $request): Limit {
            $socketAddress = $request->server->get('REMOTE_ADDR');

            return Limit::perMinute(180)->by(
                is_string($socketAddress) && $socketAddress !== '' ? $socketAddress : 'unknown',
            );
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
