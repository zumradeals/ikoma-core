<?php

namespace App\Providers;

use App\Services\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

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
        Event::listen(Login::class, fn ($event) => app(Audit::class)->append('connexion.reussie', $event->user->getAuthIdentifier()));
        Event::listen(Failed::class, fn ($event) => app(Audit::class)->append('connexion.echec', $event->user?->getAuthIdentifier()));
        Event::listen(Logout::class, fn ($event) => app(Audit::class)->append('deconnexion', $event->user?->getAuthIdentifier()));
    }
}
