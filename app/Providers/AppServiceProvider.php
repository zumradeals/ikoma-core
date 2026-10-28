<?php

namespace App\Providers;

use App\Services\Audit;
use App\Services\LoginAttempts;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

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
        RateLimiter::for('sync', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id.'|'.$r->attributes->get('poste')?->id));
        RateLimiter::for('recherche', fn (Request $r) => Limit::perMinute(240)->by($r->user()?->id));
        RateLimiter::for('exports', fn (Request $r) => Limit::perMinute(3)->by($r->user()?->id));
        Event::listen(Login::class, function ($event): void {
            app(LoginAttempts::class)->clear(request());
            app(Audit::class)->append('connexion.reussie', $event->user->getAuthIdentifier());
        });
        Event::listen(Failed::class, function ($event): void {
            $attempts = app(LoginAttempts::class);
            $attempts->failed(request());
            app(Audit::class)->append('connexion.echec', $event->user?->getAuthIdentifier(), $attempts->context(request()));
        });
        Event::listen(TwoFactorAuthenticationFailed::class, function ($event): void {
            $attempts = app(LoginAttempts::class);
            $attempts->failed(request());
            app(Audit::class)->append('connexion.echec', $event->user->getAuthIdentifier(), ['etape' => 'double_authentification', ...$attempts->context(request())]);
        });
        Event::listen(Logout::class, fn ($event) => app(Audit::class)->append('deconnexion', $event->user?->getAuthIdentifier()));
    }
}
