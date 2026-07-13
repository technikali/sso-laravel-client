<?php

namespace Technikali\SsoClient\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;

class VerifySSOToken
{
    public function handle(Request $request, Closure $next): mixed
    {
        $rawToken = $request->bearerToken();

        if (! $rawToken) {
            return response()->json(['error' => 'No token provided'], 401);
        }

        $publicKeyPath = config('sso.public_key');

        if (! file_exists($publicKeyPath)) {
            return response()->json([
                'error' => 'SSO public key not found. Run: php artisan sso:install',
            ], 500);
        }

        try {
            $publicKey = file_get_contents($publicKeyPath);
            $payload   = JWT::decode($rawToken, new Key($publicKey, 'RS256'));

            // Verify audience matches this app's client_id
            $clientId = config('sso.client_id');
            $aud      = is_array($payload->aud) ? $payload->aud : [$payload->aud];

            \Illuminate\Support\Facades\Log::info('SSO verify: aud check', [
                'client_id' => $clientId,
                'aud'       => $aud,
                'sub'       => $payload->sub ?? null,
            ]);

            if ($clientId && ! in_array($clientId, $aud)) {
                \Illuminate\Support\Facades\Log::warning('SSO verify: audience mismatch', ['expected' => $clientId, 'got' => $aud]);
                return response()->json(['error' => 'Invalid token audience'], 401);
            }

            // Check revocation list in Redis (optional)
            if (config('sso.check_revocation') && isset($payload->jti)) {
                try {
                    $connection = config('sso.redis_connection', 'default');
                    if (Redis::connection($connection)->exists('revoked:jti:' . $payload->jti)) {
                        return response()->json(['error' => 'Token has been revoked'], 401);
                    }
                } catch (\Throwable) {
                    // Redis unavailable — skip revocation check rather than blocking all requests
                }
            }

            // Hydrate or update the local user record
            $userModel = config('sso.user_model', \App\Models\User::class);
            $syncData  = [];

            foreach (config('sso.sync_fields', ['name', 'email', 'office']) as $field) {
                if (isset($payload->{$field})) {
                    $syncData[$field] = $payload->{$field};
                }
            }

            // Match on sso_id first; fall back to email so an existing local
            // account (pre-SSO, or one created before its sso_id was set) is
            // adopted rather than colliding with the unique email index. A plain
            // updateOrCreate(['sso_id' => ...]) would try to INSERT a duplicate
            // email and throw a 23505 unique violation. Lookups include
            // soft-deleted rows: the unique email index covers them too, so an
            // invisible trashed row would otherwise still break the save below.
            $softDeletes = in_array(
                \Illuminate\Database\Eloquent\SoftDeletes::class,
                class_uses_recursive($userModel),
                true
            );
            $lookup = fn () => $softDeletes ? $userModel::withTrashed() : $userModel::query();

            $user = $lookup()->where('sso_id', (string) $payload->sub)->first();

            // The token's email can already belong to a DIFFERENT local row —
            // e.g. the account was provisioned by email before it was linked to
            // an sso_id, or the auth-server email changed to one another row
            // still holds. Saving would then hit the unique email index, so
            // resolve the collision here instead of failing every login.
            if (! empty($syncData['email'])) {
                $byEmail = $lookup()->where('email', $syncData['email'])->first();

                if ($byEmail && ! $byEmail->is($user)) {
                    $byEmailTrashed = method_exists($byEmail, 'trashed') && $byEmail->trashed();

                    if ($user && $byEmailTrashed) {
                        // A deactivated row still owns the token's email. Keep
                        // the row matched by sso_id and leave its stored email
                        // untouched rather than colliding with the deleted row.
                        unset($syncData['email']);
                    } elseif ($user) {
                        // The row holding the token's email is the canonical
                        // account; the sso_id-matched row is stale. Move the
                        // sso_id over (it is unique, so clear it first).
                        $user->sso_id = null;
                        $user->save();
                        $user = $byEmail;
                    } else {
                        $user = $byEmail;
                    }
                }
            }

            // A soft-deleted account was deactivated on purpose — refuse the
            // login instead of silently resurrecting it (or crashing on the
            // unique index while trying to insert a duplicate).
            if ($user && method_exists($user, 'trashed') && $user->trashed()) {
                return response()->json(['error' => 'Account is deactivated'], 403);
            }

            $user ??= new $userModel();

            $user->sso_id = (string) $payload->sub;
            $user->fill($syncData);
            $user->save();

            // Sync roles from the SSO token — only when explicitly enabled, the
            // token actually carries roles, and spatie/laravel-permission is in
            // use. The non-empty guard is critical: syncRoles([]) would WIPE the
            // user's local roles. Apps that manage their own RBAC (distinct from
            // the auth server's roles) should set SSO_SYNC_ROLES=false so the SSO
            // token never touches their local role assignments.
            if (
                config('sso.sync_roles', true)
                && ! empty($payload->roles)
                && method_exists($user, 'syncRoles')
            ) {
                $user->syncRoles((array) $payload->roles);
            }

            // Bind user for this request without starting a session (API is stateless).
            // setUser() avoids writing a session cookie. shouldUse('web') ensures
            // that $request->user() / Auth::user() resolve from the 'web' guard so
            // controllers calling $request->user() get the hydrated model back
            // regardless of what the app's default guard is configured to be.
            Auth::guard('web')->setUser($user);
            Auth::shouldUse('web');

        } catch (\Firebase\JWT\ExpiredException $e) {
            \Illuminate\Support\Facades\Log::warning('SSO verify: token expired');
            return response()->json(['error' => 'Token expired'], 401);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('SSO verify: invalid token', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid token'], 401);
        }

        return $next($request);
    }
}
