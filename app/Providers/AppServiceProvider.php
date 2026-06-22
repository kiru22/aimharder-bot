<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
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
        // Detrás del proxy de Dokploy/Traefik (que termina TLS y reenvía HTTP al
        // contenedor, donde FrankenPHP/Caddy puede reescribir X-Forwarded-Proto),
        // forzar https en producción para que los assets de Filament no se
        // bloqueen por mixed-content. En local (APP_ENV=local, APP_URL http) no aplica.
        if ($this->app->environment('production') || str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
