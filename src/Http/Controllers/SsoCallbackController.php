<?php

namespace Technikali\SsoClient\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class SsoCallbackController extends Controller
{
    /**
     * Redirect the user to the SSO server's authorization endpoint.
     */
    public function redirect(Request $request)
    {
        // Sign the state with the app key so we can verify it without a session.
        // Format: {random_hex}.{hmac} — no cookie or session needed.
        $random  = bin2hex(random_bytes(16));
        $state   = $random . '.' . hash_hmac('sha256', $random, config('app.key'));

        // Encode the intended URL into the state so it survives the round-trip.
        $intended = $request->query('intended', '/login?sso_callback=1');
        $state   .= '.' . base64_encode($intended);

        $query = http_build_query([
            'client_id'     => config('sso.client_id'),
            'redirect_uri'  => config('sso.redirect_uri'),
            'response_type' => 'code',
            'scope'         => config('sso.scopes', 'openid profile email roles'),
            'state'         => $state,
        ]);

        return redirect(rtrim(config('sso.server_url'), '/') . '/oauth/authorize?' . $query);
    }

    /**
     * Handle the callback from the SSO server, exchange code for tokens,
     * and store them in the session.
     */
    public function callback(Request $request)
    {
        if ($request->has('error')) {
            return redirect('/login')->withErrors(['sso' => $request->error_description ?? $request->error]);
        }

        // Verify signed state — no session required.
        $parts = explode('.', $request->state ?? '', 3);
        if (count($parts) !== 3) {
            abort(403, 'Invalid state parameter.');
        }
        [$random, $hmac, $encodedIntended] = $parts;
        $expected = hash_hmac('sha256', $random, config('app.key'));
        if (! hash_equals($expected, $hmac)) {
            abort(403, 'State mismatch — possible CSRF attack.');
        }
        $intended = base64_decode($encodedIntended) ?: '/login?sso_callback=1';

        // Exchange authorization code for tokens
        $response = Http::withoutVerifying()->asForm()->post(
            rtrim(config('sso.server_url'), '/') . '/oauth/token',
            [
                'grant_type'    => 'authorization_code',
                'client_id'     => config('sso.client_id'),
                'client_secret' => config('sso.client_secret'),
                'redirect_uri'  => config('sso.redirect_uri'),
                'code'          => $request->code,
            ]
        );

        if ($response->failed()) {
            abort(500, 'Failed to exchange authorization code: ' . $response->body());
        }

        $tokens = $response->json();

        // Store the access token in the cache under a one-time code.
        // The SPA exchanges the code at GET /sso/token?code=... within 60 s.
        // This avoids any session/cookie cross-site issues entirely.
        $code = bin2hex(random_bytes(16));
        \Illuminate\Support\Facades\Log::info('SSO callback: storing code', ['code' => substr($code, 0, 8).'...', 'intended' => $intended]);
        // Use the file store explicitly — no database table required.
        Cache::put('sso_handoff:' . $code, [
            'access_token'  => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? null,
            'expires_at'    => now()->addSeconds($tokens['expires_in'] ?? 7200)->toIso8601String(),
        ], now()->addSeconds(60));

        // Append the code to the intended URL so the SPA can exchange it.
        $separator = str_contains($intended, '?') ? '&' : '?';
        return redirect($intended . $separator . 'sso_code=' . $code);
    }

    /**
     * Log the user out — revoke the token on the SSO server and clear the local session.
     */
    public function logout(Request $request)
    {
        // Token is held by the SPA and sent as a Bearer token, not in the session.
        $accessToken = $request->bearerToken();

        // Add the JTI to the Redis revocation list so the auth server also rejects it
        if ($accessToken) {
            try {
                $publicKeyPath = config('sso.public_key');
                if (file_exists($publicKeyPath)) {
                    $publicKey = file_get_contents($publicKeyPath);
                    $payload   = JWT::decode($accessToken, new Key($publicKey, 'RS256'));
                    $ttl       = ($payload->exp ?? 0) - now()->timestamp;

                    if ($ttl > 0 && isset($payload->jti)) {
                        $connection = config('sso.redis_connection', 'default');
                        Redis::connection($connection)->setex('revoked:jti:' . $payload->jti, (int) $ttl, '1');
                    }
                }
            } catch (\Throwable) {
                // Best-effort revocation
            }
        }

        // Build the auth-server logout URL. The auth server destroys the SSO
        // session and redirects the browser back to this app's login page.
        $backTo    = rtrim(config('app.url'), '/') . '/login';
        $logoutUrl = rtrim(config('sso.server_url'), '/') . '/logout?redirect=' . urlencode($backTo);

        // XHR callers (Axios with Accept: application/json) need the URL back
        // so they can navigate programmatically. Browser redirects (non-XHR)
        // follow the 302 directly.
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['logout_url' => $logoutUrl]);
        }

        return redirect($logoutUrl);
    }
}
