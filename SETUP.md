# SSO Client — Backend Integration Guide

`technikali/sso-client` lets any Laravel + React (SPA) app authenticate against
the Bansud LGU SSO server (`auth.bansud.gov.ph`) with a few lines of wiring.

This guide takes a fresh Laravel backend from zero to a working single sign-on
login. Follow it top to bottom — every section is required unless marked
**Optional**.

> **How auth works in one paragraph.** The browser is bounced to the SSO server
> to log in. The SSO server redirects back to your backend, which exchanges the
> authorization code for a **signed RS256 JWT** and hands it to your SPA via a
> short-lived one-time code. From then on, the SPA sends that JWT as a
> `Bearer` token on every API call. Your backend verifies the token's signature
> **locally** with the SSO server's public key — no network call per request —
> and hydrates a local `users` row from the identity claims baked into the
> token (`name`, `email`, `office`, `roles`).

---

## Prerequisites

Before you start, get these from the SSO admin panel (or the IT Division):

| Item | Example |
|---|---|
| `client_id` | `019eff71-4143-7219-8bf4-5d2d59a881e2` |
| `client_secret` | `Gm3n2w3IHvlvBIfrZDzLwsNz8nG5NQE7NYnB13re` |
| Your app's `redirect_uri` | `https://yourapp.bansud.gov.ph/auth/callback` |

Your app's domain **and** redirect URI must be registered and **verified** on the
SSO server first, or the OAuth `authorize` step is rejected. Ask an SSO admin to
add it (Admin panel → Domains, then Apps).

Your Laravel app also needs:

- Laravel 11, 12, or 13
- A cache store the backend can write to (`file`, `redis`, `database`…) — used
  for the one-time login handoff
- A `users` table you control

---

## 1. Install the package

The package is distributed as a local path repository. In your backend's
`composer.json`:

```jsonc
{
  "repositories": [
    {
      "type": "path",
      "url": "../../packages/sso-client"
    }
  ],
  "require": {
    "technikali/sso-client": "1.0.0"
  }
}
```

Then:

```bash
composer require technikali/sso-client
```

The service provider auto-registers via Laravel package discovery. It wires:

- The `sso.verify` middleware alias
- The OAuth routes `/auth/redirect`, `/auth/callback`, `/auth/logout`
- The `php artisan sso:install` command
- Publishable config, migration, and React stubs

---

## 2. Run the installer

```bash
php artisan sso:install
```

This publishes `config/sso.php`, publishes the `add_sso_fields_to_users_table`
migration, and downloads the SSO server's public key to
`storage/sso-public.key`. If the download fails (server unreachable, self-signed
cert), grab it manually:

```bash
curl -k -o storage/sso-public.key https://auth.bansud.gov.ph/api/oauth/public-key
```

> The public key is what you use to **verify** tokens. The private key never
> leaves the auth server. Re-download the key if the auth server ever rotates it.

---

## 3. Configure `.env`

```env
SSO_SERVER_URL=https://auth.bansud.gov.ph
SSO_CLIENT_ID=<your-client-id>
SSO_CLIENT_SECRET=<your-client-secret>
SSO_REDIRECT_URI=https://yourapp.bansud.gov.ph/auth/callback

# Path to the public key downloaded in step 2 (default shown)
SSO_PUBLIC_KEY_PATH="${APP_URL}/../storage/sso-public.key"

# Token revocation list (Redis). Set false if you have no Redis.
SSO_CHECK_REVOCATION=true
SSO_REDIS_CONNECTION=default
```

> ⚠️ **Do not put an inline `#comment` on the same line as `SSO_CLIENT_SECRET`.**
> Laravel's dotenv parser treats ` #...` as a comment and strips it, which is
> usually fine — but it's an easy way to silently corrupt the secret. Keep
> secrets on their own clean line. A wrong secret makes the token exchange fail
> with **HTTP 401** at the auth server.

Full config reference: [`config/sso.php`](./config/sso.php).

| Key | Env | Purpose |
|---|---|---|
| `server_url` | `SSO_SERVER_URL` | Base URL of the auth server |
| `client_id` | `SSO_CLIENT_ID` | OAuth client id (also the token `aud`) |
| `client_secret` | `SSO_CLIENT_SECRET` | OAuth client secret |
| `redirect_uri` | `SSO_REDIRECT_URI` | Must exactly match the registered URI |
| `public_key` | `SSO_PUBLIC_KEY_PATH` | RS256 public key path |
| `scopes` | `SSO_SCOPES` | Default `openid profile email roles` |
| `check_revocation` | `SSO_CHECK_REVOCATION` | Check Redis revocation list per request |
| `user_model` | `SSO_USER_MODEL` | Your local user model |
| `sync_fields` | — | Token claims copied into the local user row |
| `sync_roles` | `SSO_SYNC_ROLES` | Sync roles from the token. Set `false` if your app owns its RBAC (§4.4) |
| `register_routes` | `SSO_REGISTER_ROUTES` | Set `false` to define the routes yourself |

---

## 4. Migrate the users table

```bash
php artisan migrate
```

The published migration adds `sso_id` (unique), `office`, and `employee_id` to
`users` — guarded by `hasColumn` checks so it's safe on existing tables.

### 4.1 Make the synced fields mass-assignable ⚠️

The middleware writes the user with `updateOrCreate([...], $syncData)`, which
respects `$fillable`. **Any sync field not in `$fillable` is silently dropped.**
Add them to your `User` model:

```php
// app/Models/User.php
protected $fillable = [
    'name', 'email', 'office', 'employee_id', 'sso_id',
    // ...your existing fields
];
```

### 4.2 `email` must be satisfiable

`email` is typically `NOT NULL UNIQUE`. The SSO token supplies it, so first
login works — **but only because the auth server embeds identity claims in the
token** (see [auth.bansud CLAUDE.md §1.5.1]). If you ever see a *successful*
login that still ends in 401, decode the JWT: a token missing `email`/`name`
means the auth server isn't injecting claims, and the local insert fails its
NOT NULL constraint. That is a server-side fix, not a client one.

### 4.3 Existing local accounts are adopted by email

If your `users` table already has rows (an admin seeded before SSO, a migrated
user, etc.), the middleware reconciles them: it matches on `sso_id` first, then
falls back to **`email`**, and only creates a new row if neither matches. The
matched row gets its `sso_id` stamped on first SSO login. This avoids a
`23505 duplicate key … users_email_unique` violation that a naive
`updateOrCreate(['sso_id' => …])` would cause when the email already exists under
a different (or null) `sso_id`. No action needed on your part — just be aware the
first SSO login **claims** the matching local account rather than duplicating it.

### 4.4 Roles: who owns them? ⚠️

Decide up front whether the **auth server** or **your app** owns roles:

- **Auth server owns roles** (default, `SSO_SYNC_ROLES=true`) — the token's
  `roles` claim is synced onto the local user via `spatie/laravel-permission` on
  every request. Use this only if your app's role names match the auth server's.
- **Your app owns roles** (`SSO_SYNC_ROLES=false`) — the SSO token never touches
  local role assignments; you manage roles in your own admin UI/seeders. Use this
  whenever your app has its own RBAC whose role names differ from the auth
  server's (e.g. HRIS uses `Super Administrator`, `HR Administrator`, … which the
  auth server knows nothing about).

> 💥 **The footgun this prevents.** The middleware guards against
> `syncRoles([])` — an **empty or absent** `roles` claim will *never* wipe local
> roles. Before that guard, a user whose SSO token carried no roles would have
> **all their local roles stripped on login** and lose access to everything. If
> you see a privileged user suddenly land on an empty dashboard right after SSO
> login, this is the first thing to check — and `SSO_SYNC_ROLES=false` is the fix
> for any app that manages its own roles.

---

## 5. Add the token-handoff route

The package's `/auth/callback` does the server-side code→token exchange, stashes
the token in your cache under a **one-time code**, and redirects the browser to
your SPA with `?sso_code=...`. Your SPA then exchanges that code for the actual
JWT. **This pickup route is not part of the package — add it to your app:**

```php
// routes/web.php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

// One-time token pickup. The SPA calls this right after the SSO callback
// redirects back. The code is consumed on first use.
Route::get('/sso/token', function (Request $request) {
    $code = $request->query('code');
    if (! $code) {
        return response()->json(['error' => 'Missing code'], 400);
    }

    $data = Cache::pull('sso_handoff:' . $code);   // pull = read + delete

    if (! $data) {
        return response()->json(['error' => 'Invalid or expired code'], 401);
    }

    return response()->json(['token' => $data['access_token']]);
});
```

> **Why the handoff dance?** It keeps the access token out of the browser
> history / referer headers and sidesteps every cross-site cookie problem — no
> session or cookie is involved in the SPA flow at all. The cache entry lives
> for 60 seconds and is single-use.

---

## 6. Protect your API routes

Wrap any route that needs an authenticated user in the `sso.verify` middleware.
It validates the Bearer JWT, checks the `aud` claim and the revocation list, and
binds the hydrated user onto `auth()->user()`.

```php
// routes/api.php
Route::middleware(['sso.verify'])->group(function () {
    Route::get('/user/me', fn (Request $r) => $r->user()->load('roles'));

    // ...your protected endpoints
});
```

Inside these routes `auth()->user()` / `$request->user()` returns your local
`User` model, freshly synced from the token. If `spatie/laravel-permission` is
installed, roles from the token are synced via `syncRoles()` automatically.

**Provide a `/user/me` endpoint** (above) — the React `AuthContext` stub calls it
on mount to load the current user.

---

## 7. Wire the React SPA

Publish the React starter components:

```bash
php artisan vendor:publish --tag=sso-client-react
# → stubs/sso-react/{api.ts, AuthContext.tsx, SsoCallback.tsx}
```

Drop them into your frontend and adjust import paths:

| Stub | Suggested location | Role |
|---|---|---|
| `api.ts` | `src/lib/api.ts` | Axios instance; in-memory Bearer token; auto-redirect to `/login` on 401 |
| `AuthContext.tsx` | `src/contexts/AuthContext.tsx` | `useAuth()` hook; loads `/api/user/me` |
| `SsoCallback.tsx` | `src/routes/auth/callback` | Reads the redirect params and finishes login |

Set the API base URL in the frontend `.env`:

```env
VITE_API_BASE_URL=https://yourapp.bansud.gov.ph
```

### 7.1 The login flow, end to end

1. **Start login** — send the browser to your backend's
   `GET /auth/redirect` (optionally `?intended=/path`). The package redirects to
   the SSO server's `/oauth/authorize`.
2. **User authenticates** on the SSO server — **only if they have no SSO session
   yet** (see "Single sign-on" below). If a session already exists this step is
   invisible: `/oauth/authorize` auto-approves and the browser comes straight
   back with a code.
3. **Callback** — SSO redirects to `GET /auth/callback`, which exchanges the
   code for a JWT, caches it, and redirects the browser to your SPA at the
   `intended` URL with `?sso_code=<code>` appended.
4. **Pickup** — your SPA reads `sso_code` from the URL and calls
   `GET /sso/token?code=<code>` (step 5) to receive the JWT.
5. **Store + use** — the SPA calls `setAccessToken(jwt)` and then loads
   `/api/user/me`. Every subsequent API call carries the Bearer token.

> ⚠️ **Adapt the `SsoCallback.tsx` stub to your flow.** The shipped stub posts to
> `/auth/token`; this package instead uses the `sso_code` + `GET /sso/token`
> handoff described above. Read `sso_code` from `window.location.search`, call
> `GET /sso/token?code=...`, then `setAccessToken(data.token)`.

📘 **Full frontend walkthrough:** the steps above are the summary. For the
complete SPA implementation — the API client, auth service, state store,
startup token rehydration, route guards, both callback patterns, and frontend
troubleshooting — see **[SETUP-FRONTEND.md](./SETUP-FRONTEND.md)**.

### 7.1.1 Single sign-on (one login for everything)

The auth server has **one** login UI and **one** session, so your app never
shows or hosts a login form, and a user who's already signed in elsewhere is not
asked to log in again:

- The auth server keeps an **SSO web session**. Once it exists (the user logged
  in at the auth server or any sibling app), every app's `/oauth/authorize`
  **auto-approves** — your `GET /auth/redirect` returns with a code and no login
  screen appears. This is what makes clicking an app tile land straight on the
  dashboard.
- The **only** login screen anywhere is the auth server's own. Don't build a
  username/password form in your app — your "login page" just kicks off
  `GET /auth/redirect` (and ideally does it automatically; see
  [SETUP-FRONTEND.md](./SETUP-FRONTEND.md) §5 Pattern C).
- Conversely, **logout must end that shared session** (§7.2) or the next
  sign-in silently re-authenticates the same user.

> ⚠️ **All subdomains must share the same scheme (use HTTPS everywhere).** The
> SSO session cookie is `SameSite=Lax; Secure`, scoped to the parent domain
> (e.g. `.bansud.gov.ph`). Browsers treat `http://app...` and `https://auth...`
> as **"schemefully cross-site,"** which silently drops that cookie on the
> cross-app XHR login — the user logs in but no SSO session is established, so the
> next app shows the login again. Serve every subdomain over HTTPS (matching
> schemes) so the cookie sticks. This is the #1 cause of "it keeps asking me to
> log in" in local/dev setups.

### 7.2 Logout (must destroy the SSO session)

Logout is three steps — the third is the one everyone forgets:

1. `POST /auth/logout` with the Bearer token → the package revokes the token's
   `jti` (Redis revocation list, rejected everywhere immediately).
2. The SPA clears its in-memory token and local state.
3. The browser is redirected to the auth server's **`GET /logout?redirect=<your
   login>`** to destroy the **SSO session cookie**, which then redirects back to
   your login.

> ⚠️ **Skipping step 3 is the "I logged out but it logs me right back in" bug.**
> The auth server holds its own session; if only local state is cleared, the next
> `/auth/redirect` passes straight through `/oauth/authorize` and re-authenticates
> the **same** user — you can't sign out or switch accounts. The package's
> built-in `/auth/logout` already redirects to the SSO `/logout?redirect=…`; if
> you roll your own API logout, have it return that URL for the SPA to navigate
> to (see [SETUP-FRONTEND.md](./SETUP-FRONTEND.md) §6). The auth server only
> honours post-logout redirects back to registered LGU domains.

**Logout is a full single sign-out.** Destroying the SSO session (step 3) makes
the auth server **revoke all of the user's access tokens**, not just this app's.
So logging out of any one app logs the user out of **every** app. Two
consequences for your SPA:

- Other apps already open in other tabs won't notice immediately (different
  origin — no shared events). Re-validate on tab focus so a stale tab logs itself
  out too — see [SETUP-FRONTEND.md](./SETUP-FRONTEND.md) §6 ("Detect logout in
  already-open tabs").
- If you only want to sign out of *this* app, that's not the default — tell the
  auth-server maintainer, as token revocation happens server-side on `/logout`.

---

## 8. Verify the integration

```bash
# 1. Token endpoint reachable and credentials valid?
#    A correct secret returns 400 "invalid_grant" for a bogus code.
#    A WRONG secret returns 401 — that's your signal the secret is off.
curl -sk -o /dev/null -w "%{http_code}\n" -X POST \
  "$SSO_SERVER_URL/oauth/token" \
  -d grant_type=authorization_code \
  -d client_id="$SSO_CLIENT_ID" \
  -d client_secret="$SSO_CLIENT_SECRET" \
  -d redirect_uri="$SSO_REDIRECT_URI" \
  -d code=bogus
# Expect: 400 (good creds) — NOT 401

# 2. Public key present?
test -s storage/sso-public.key && echo "key ok" || echo "MISSING KEY"
```

Then do a real browser login and confirm a `users` row is created with a
populated `email`.

---

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| **401 at `/oauth/token`** during callback | Wrong `client_secret`, or an inline `#comment` corrupted it | Fix `.env`; keep the secret on a clean line; `php artisan config:clear` |
| **401 on API calls right after a *successful* login** | Token has no `email`/`name` claims, so the local insert hit a NOT NULL constraint (caught → "Invalid token") | Server-side: ensure the auth server injects identity claims (auth.bansud CLAUDE.md §1.5.1) |
| **401 "Invalid token" with a `23505 users_email_unique` in the log** | A local user with that email already exists under a different/null `sso_id`; the sync tried to INSERT a duplicate email | Fixed in the middleware (§4.3) — it adopts the existing row by email. If you customized the sync, match on `sso_id` *then* `email` |
| **User logs in fine but lands on an empty dashboard / lost all access** | Roles were overwritten by the token's `roles` claim (empty claim used to wipe them) | Set `SSO_SYNC_ROLES=false` if your app owns its RBAC (§4.4), then re-grant the lost roles |
| **401 "Invalid token audience"** | Token `aud` ≠ your `client_id` | Confirm `SSO_CLIENT_ID` matches the client that issued the token |
| **500 "SSO public key not found"** | `storage/sso-public.key` missing | Re-run `php artisan sso:install` or `curl` the key manually |
| **403 "State mismatch"** at callback | `APP_KEY` changed between redirect and callback, or a tampered request | Keep `APP_KEY` stable; retry the login |
| **403 at `/oauth/authorize`** | Domain/redirect URI not registered or not verified on the SSO server | Ask an SSO admin to register + verify your domain |
| **"Invalid or expired code"** at `/sso/token` | Code already used (it's one-time) or >60s elapsed, or cache store differs between web and callback requests | Use a shared cache store; don't double-fetch the code |
| Synced field (e.g. `office`) is null on the user | Field missing from `User::$fillable` | Add it to `$fillable` (§4.1) |
| **Logs in but is immediately asked to log in again** (esp. cross-subdomain) | Subdomains on mismatched schemes (http vs https) → "schemefully cross-site" drops the `SameSite=Lax; Secure` SSO session cookie | Serve **all** subdomains over HTTPS (§7.1.1) |
| **Redirect loop on the login page after logout** | App holds a still-valid local token while the SSO session is gone, so it keeps re-entering the OAuth flow | Logout now revokes the user's tokens server-side (§7.2); ensure your app re-validates on focus (SETUP-FRONTEND.md §6) |
| **Logged out of one app but another open tab still shows logged in** | Cross-origin tab wasn't notified; it only finds out on its next request | Re-validate on tab focus (SETUP-FRONTEND.md §6 "Detect logout in already-open tabs") |

Both the middleware and the callback controller log to your default log channel
(`SSO verify: ...`, `SSO callback: ...`) — check `storage/logs/laravel.log`
first when debugging.

---

## Quick reference

**Routes added by the package:** `GET /auth/redirect`, `GET /auth/callback`,
`POST /auth/logout`
**Route you add yourself:** `GET /sso/token`
**Middleware alias:** `sso.verify`
**Install command:** `php artisan sso:install`
**Token claims consumed:** `sub` → `sso_id`, plus `name`, `email`, `office`, `roles`
