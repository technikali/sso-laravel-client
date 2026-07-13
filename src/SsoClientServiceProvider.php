<?php

namespace Technikali\SsoClient;

use Illuminate\Support\ServiceProvider;
use Technikali\SsoClient\Middleware\VerifySSOToken;
use Technikali\SsoClient\Commands\SsoInstallCommand;

class SsoClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/sso.php', 'sso');
    }

    public function boot(): void
    {
        // ── Publishables ──────────────────────────────────────────────────────

        $this->publishes([
            __DIR__ . '/../config/sso.php' => config_path('sso.php'),
        ], 'sso-client-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/add_sso_fields_to_users_table.php.stub' =>
                database_path('migrations/' . date('Y_m_d_His') . '_add_sso_fields_to_users_table.php'),
        ], 'sso-client-migrations');

        $this->publishes([
            __DIR__ . '/../stubs/react' => base_path('stubs/sso-react'),
        ], 'sso-client-react');

        // ── Routes ────────────────────────────────────────────────────────────
        // Registers /auth/redirect, /auth/callback, /auth/logout automatically.
        // Disable by setting SSO_REGISTER_ROUTES=false in your .env.

        if (config('sso.register_routes', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        }

        // ── Middleware alias ──────────────────────────────────────────────────

        $router = $this->app['router'];
        $router->aliasMiddleware('sso.verify', VerifySSOToken::class);

        // ── Artisan command ───────────────────────────────────────────────────

        if ($this->app->runningInConsole()) {
            $this->commands([SsoInstallCommand::class]);
        }
    }
}
