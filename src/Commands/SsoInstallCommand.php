<?php

namespace Technikali\SsoClient\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SsoInstallCommand extends Command
{
    protected $signature   = 'sso:install
                                {--force : Overwrite existing published files}';

    protected $description = 'Publish SSO client config, migration, and download the public key.';

    public function handle(): int
    {
        $this->info('');
        $this->info(' Technikali SSO Client — Installation');
        $this->info(' ─────────────────────────────────────');

        // 1. Publish config
        $this->call('vendor:publish', [
            '--tag'   => 'sso-client-config',
            '--force' => $this->option('force'),
        ]);

        // 2. Publish migration
        $this->call('vendor:publish', [
            '--tag'   => 'sso-client-migrations',
            '--force' => $this->option('force'),
        ]);

        // 3. Download public key from the SSO server
        $serverUrl = config('sso.server_url');

        if (! $serverUrl) {
            $this->warn('  SSO_SERVER_URL is not set. Skipping public key download.');
            $this->warn('  Set it in .env then run: curl -o ' . storage_path('sso-public.key') . ' {SSO_SERVER_URL}/api/oauth/public-key');
        } else {
            $this->line("  Downloading public key from {$serverUrl}/api/oauth/public-key ...");

            try {
                $response = Http::withoutVerifying()->get("{$serverUrl}/api/oauth/public-key");

                if ($response->successful()) {
                    $keyPath = config('sso.public_key', storage_path('sso-public.key'));
                    file_put_contents($keyPath, $response->body());
                    $this->info("  ✓ Public key saved to: {$keyPath}");
                } else {
                    $this->error("  ✗ Failed to download public key (HTTP {$response->status()}).");
                    $this->line('  Download manually: curl -o ' . storage_path('sso-public.key') . " {$serverUrl}/api/oauth/public-key");
                }
            } catch (\Throwable $e) {
                $this->error('  ✗ Could not reach SSO server: ' . $e->getMessage());
                $this->line('  Download manually: curl -o ' . storage_path('sso-public.key') . " {$serverUrl}/api/oauth/public-key");
            }
        }

        // 4. Print next steps
        $this->info('');
        $this->info(' ✅ Installation complete. Next steps:');
        $this->line('');
        $this->line('  1. Set these in your .env:');
        $this->line('       SSO_SERVER_URL=https://auth.bansud.gov.ph');
        $this->line('       SSO_CLIENT_ID=<your-client-id>');
        $this->line('       SSO_CLIENT_SECRET=<your-client-secret>');
        $this->line('       SSO_REDIRECT_URI=https://yourapp.bansud.gov.ph/auth/callback');
        $this->line('');
        $this->line('  2. Run the migration:');
        $this->line('       php artisan migrate');
        $this->line('');
        $this->line('  3. Protect your API routes:');
        $this->line('       Route::middleware([\'sso.verify\'])->group(function () {');
        $this->line('           // your protected routes');
        $this->line('       });');
        $this->line('');
        $this->line('  4. Protect your web routes (session-based OAuth flow):');
        $this->line('       Route::middleware([\'auth\'])->group(function () {');
        $this->line('           // routes that need a logged-in user');
        $this->line('       });');
        $this->line('');
        $this->line('  See the generated integration guide in the SSO admin panel for');
        $this->line('  the exact client_id and redirect_uri for this app.');
        $this->line('');

        return self::SUCCESS;
    }
}
