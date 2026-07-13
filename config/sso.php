<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SSO Server URL
    |--------------------------------------------------------------------------
    | The base URL of the Bansud SSO auth server.
    */
    'server_url' => env('SSO_SERVER_URL', 'https://auth.bansud.gov.ph'),

    /*
    |--------------------------------------------------------------------------
    | OAuth Client Credentials
    |--------------------------------------------------------------------------
    | Issued by the auth server admin panel. Never commit these to git.
    */
    'client_id'     => env('SSO_CLIENT_ID'),
    'client_secret' => env('SSO_CLIENT_SECRET'),
    'redirect_uri'  => env('SSO_REDIRECT_URI'),

    /*
    |--------------------------------------------------------------------------
    | Public Key
    |--------------------------------------------------------------------------
    | Path to the RS256 public key used to verify JWT access tokens.
    | Download it once with: php artisan sso:install
    */
    'public_key' => env('SSO_PUBLIC_KEY_PATH', storage_path('sso-public.key')),

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    | Scopes to request during the OAuth flow.
    */
    'scopes' => env('SSO_SCOPES', 'openid profile email roles'),

    /*
    |--------------------------------------------------------------------------
    | Token Revocation (Redis)
    |--------------------------------------------------------------------------
    | When enabled, the middleware checks a Redis revocation list on every
    | request. Set to false if Redis is not available in your environment.
    */
    'check_revocation' => env('SSO_CHECK_REVOCATION', true),
    'redis_connection' => env('SSO_REDIS_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Local User Sync
    |--------------------------------------------------------------------------
    | Fields synced from the SSO token payload into your local users table.
    */
    'user_model'      => env('SSO_USER_MODEL', \App\Models\User::class),
    'sync_fields'     => ['name', 'email', 'office'],

    /*
    |--------------------------------------------------------------------------
    | Role Sync
    |--------------------------------------------------------------------------
    | When true, roles from the SSO token's `roles` claim are synced onto the
    | local user via spatie/laravel-permission. Set to FALSE for apps that
    | manage their own RBAC (their roles differ from the auth server's), so the
    | SSO token never overwrites local role assignments. An empty/absent roles
    | claim never wipes local roles regardless of this setting.
    */
    'sync_roles'      => env('SSO_SYNC_ROLES', true),
];
