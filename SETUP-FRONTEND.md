# SSO Client — Frontend (React SPA) Integration Guide

Companion to [SETUP.md](./SETUP.md) (the backend guide). This covers the
**React single-page-app** side: how the SPA kicks off login, picks up the JWT,
attaches it to every request, survives reloads, and logs out.

It is written against the **actual working implementation** in `hris.bansud`, not
the older `stubs/react/*` files (which predate the current handoff flow — see
[§7](#7-about-the-shipped-stubs)).

> **The SPA never sees the SSO server, a password, or the OAuth code exchange.**
> Its entire job is: (1) send the browser to your backend's `/auth/redirect`,
> (2) when the browser comes back with a one-time `sso_code`, swap it for the
> JWT at `/sso/token`, (3) keep that JWT in memory and send it as
> `Authorization: Bearer …` on every API call.

---

## The flow, from the SPA's point of view

```
[Login page]
   │  user clicks "Sign in with SSO"
   │  → window.location = /auth/redirect?intended=<spa-url>/login?sso_callback=1
   ▼
[Backend] ──redirect──► [SSO server login] ──redirect──► [Backend /auth/callback]
   │  backend exchanges code for JWT, caches it under a one-time code
   ▼
[Login page again]  ?sso_callback=1&sso_code=<code>
   │  GET /sso/token?code=<code>   → { token: "<JWT>" }
   │  setAccessToken(token); fetch /api/.../me; store user
   ▼
[Dashboard]  every request now carries  Authorization: Bearer <JWT>
```

The redirect comes back to **whatever URL you passed as `intended`**. In
`hris.bansud` that's the login page itself with a `?sso_callback=1` marker, so
the login page doubles as the callback handler. You can instead point `intended`
at a dedicated `/auth/callback` route — both patterns are shown below.

> **The `[SSO server login]` step usually doesn't appear.** The auth server has
> a single login UI and a shared SSO session. If the user already signed in
> there (or at any sibling app), `/oauth/authorize` auto-approves and the
> round-trip is invisible — they go straight to your dashboard. The login form
> only shows on the very first sign-in of the session. So **don't build your own
> username/password form**: your login page exists only to kick off (and finish)
> the redirect — make it automatic (§5 Pattern C).

---

## 1. The API client (`src/lib/api.ts`)

An Axios instance with three responsibilities: a base URL, an **in-memory**
Bearer token, and a 401 interceptor that bounces the user back to login.

```ts
import axios from 'axios'

// Leave VITE_API_BASE_URL empty in production — nginx serves the SPA and the API
// on the same origin and routes /api/* to Laravel. Set it (e.g.
// http://127.0.0.1:8000) only when running Vite standalone without the proxy.
const API_BASE = import.meta.env.VITE_API_BASE_URL ?? ''

export const api = axios.create({
  baseURL: `${API_BASE}/api/v1`,          // match your backend's API prefix
  withCredentials: true,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

// ── In-memory token store (not localStorage → not reachable by XSS) ──────────
let _accessToken: string | null = null

export function setAccessToken(token: string | null): void {
  _accessToken = token
  if (token) {
    api.defaults.headers.common['Authorization'] = `Bearer ${token}`
  } else {
    delete api.defaults.headers.common['Authorization']
  }
}

export function getAccessToken(): string | null {
  return _accessToken
}

// ── On 401, drop the token and send the user back to login ───────────────────
api.interceptors.response.use(
  (res) => res,
  (err) => {
    if (err.response?.status === 401 && !window.location.pathname.startsWith('/login')) {
      setAccessToken(null)
      window.location.href = '/login'
    }
    return Promise.reject(err)
  },
)

export default api
```

> **Base URL gotcha.** The token-pickup route `/sso/token` is a **web** route at
> the site root, *not* under your `/api/v1` prefix. Call it with an overridden
> `baseURL` (shown next), or it will resolve to `/api/v1/sso/token` and 404.

---

## 2. The auth service (`src/services/auth.service.ts`)

Thin wrappers around the SSO endpoints. `pickupSsoToken` is the important one.

```ts
import api from '@/lib/api'

export const authService = {
  /** Send the browser to the backend, which redirects to the SSO login page. */
  redirectToSso(intended?: string) {
    const q = intended ? `?intended=${encodeURIComponent(intended)}` : ''
    window.location.href = `/auth/redirect${q}`
  },

  /** Exchange the one-time ?sso_code= for the JWT. Note baseURL:'/' — this
   *  endpoint lives at the site root, not under /api/v1. */
  pickupSsoToken(code: string) {
    return api
      .get<{ token: string }>(`/sso/token?code=${encodeURIComponent(code)}`, { baseURL: '/' })
      .then((r) => r.data.token)
  },

  /** Current user — backend route protected by `sso.verify`. */
  me() {
    return api.get('/auth/me').then((r) => r.data.user)   // adjust to your endpoint
  },

  /** Revoke the token server-side, then the SPA forgets it. */
  logout() {
    return api.post('/auth/logout')
  },
}
```

---

## 3. Auth state (store or context — pick one)

You need somewhere to hold `{ token, user, isAuthenticated }` for route guards
and the UI. Two common choices:

### 3a. Zustand store (the `hris.bansud` approach)

```ts
import { create } from 'zustand'
import { persist } from 'zustand/middleware'

export const useAuthStore = create(
  persist(
    (set, get) => ({
      token: null,
      user: null,
      isAuthenticated: false,
      login: (token, user) => set({ token, user, isAuthenticated: true }),
      logout: () => set({ token: null, user: null, isAuthenticated: false }),
      hasRole: (r) => get().user?.roles?.includes(r) ?? false,
    }),
    { name: 'myapp-auth' },   // persisted to localStorage
  ),
)
```

> ⚠️ **Persistence trade-off — read this.** `persist` writes the **token to
> localStorage** so a page reload keeps the user logged in. That contradicts the
> project security rule *"access token in-memory only, never localStorage"*
> (auth.bansud CLAUDE.md → Security Checklist). Two ways to resolve it:
>
> - **Most secure:** persist only `user`/`isAuthenticated`, keep the token
>   **in-memory only** (`api.ts`). On reload the token is gone, so silently
>   re-run the SSO flow (§5) — the SSO server still has a session cookie, so the
>   user isn't asked for a password again.
> - **Convenience (HRIS today):** persist the token too. Then you **must
>   rehydrate it into Axios on startup** (§4), or the first request after a
>   reload 401s.

### 3b. Context provider

If you prefer context over a store, wrap the app in an `AuthProvider` that loads
`/me` on mount and exposes `useAuth()`. See `stubs/react/AuthContext.tsx` for a
starting point — just swap its `/auth/token` POST for the `pickupSsoToken` flow.

---

## 4. Rehydrate the token on app start (only if you persist it)

If the store persists the token, push it into Axios **before** anything renders,
otherwise `api.defaults.headers` has no `Authorization` after a reload:

```ts
// src/main.tsx — before ReactDOM.createRoot(...).render(...)
import { setAccessToken } from '@/lib/api'
import { useAuthStore } from '@/stores/auth.store'

const persisted = useAuthStore.getState().token
if (persisted) setAccessToken(persisted)
```

If you keep the token in-memory only (recommended), skip this — there's nothing
to rehydrate; a reload just re-runs SSO.

---

## 5. Trigger login + handle the callback

### Pattern A — pickup on the login page (HRIS pattern)

The login button points `intended` back at the login page with a marker. The
same page detects the marker + `sso_code` and finishes the login.

```tsx
import { useEffect, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { authService } from '@/services/auth.service'
import { useAuthStore } from '@/stores/auth.store'
import { setAccessToken } from '@/lib/api'

export function LoginPage() {
  const navigate = useNavigate()
  const login = useAuthStore((s) => s.login)
  const [busy, setBusy] = useState(false)

  // Runs when the SSO callback redirects back here.
  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    const code = params.get('sso_code')
    if (!params.has('sso_callback') || !code) return

    setBusy(true)
    authService.pickupSsoToken(code)
      .then(async (token) => {
        setAccessToken(token)
        const user = await authService.me()
        login(token, user)
        navigate({ to: '/dashboard' })
      })
      .catch(() => setBusy(false))   // show an error toast here
  }, [])

  const start = () =>
    authService.redirectToSso(window.location.origin + '/login?sso_callback=1')

  return busy
    ? <p>Completing sign-in…</p>
    : <button onClick={start}>Sign in with Bansud LGU SSO</button>
}
```

### Pattern B — dedicated `/auth/callback` route

Point `intended` at a standalone route instead, and read `sso_code` there. Same
three calls (`pickupSsoToken` → `setAccessToken` → `me` → store), just on its own
component. Use this if you don't want pickup logic living on the login page.

```ts
// login button:
authService.redirectToSso(window.location.origin + '/auth/callback')
// then in a route mounted at /auth/callback, read ?sso_code= and do the pickup.
```

> Whichever you choose, the `intended` URL you pass to `redirectToSso` **must** be
> on a registered/verified domain for this app, and is where the browser lands
> after login.

### Pattern C — seamless auto sign-in (no button)

For true SSO UX, drop the "Sign in" button entirely: when an unauthenticated user
lands on the login page, **start the SSO redirect automatically**. The browser
can't tell cross-origin whether the SSO session is alive, so the rule is simply
"auto-redirect whenever not authenticated locally." If the SSO session exists the
round-trip is invisible and the user lands on the dashboard; if not, they see the
auth server's own login form. This is what makes clicking an app tile go straight
in without a second login screen.

```tsx
// One-time guard for StrictMode's double effect (the handoff code is single-use).
let consumedSsoCode: string | null = null
const RETRY_THROTTLE_MS = 8000

export function LoginPage() {
  const navigate = useNavigate()
  const login = useAuthStore((s) => s.login)

  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    const code = params.get('sso_code')
    const ssoError = params.get('error') || params.get('error_description')

    // 1. Finishing a callback → exchange the code.
    if (params.has('sso_callback') && code) {
      if (consumedSsoCode === code) return
      consumedSsoCode = code
      authService.pickupSsoToken(code)
        .then(async (token) => {
          setAccessToken(token)
          login(token, await authService.me())
          navigate({ to: '/dashboard' })
        })
        .catch(() => autoSignIn(true))   // keep retrying
      return
    }

    // 2. Error came back → keep retrying.  3. Otherwise → start SSO.
    autoSignIn(Boolean(ssoError))
  }, [])

  function autoSignIn(isRetry: boolean) {
    const go = () => {
      sessionStorage.setItem('sso_last_attempt', String(Date.now()))
      authService.redirectToSso(window.location.origin + '/login?sso_callback=1')
    }
    if (!isRetry) return go()
    // Throttle retries so a persistent failure is a slow retry, not a storm.
    const last = Number(sessionStorage.getItem('sso_last_attempt') || 0)
    window.setTimeout(go, Math.max(0, RETRY_THROTTLE_MS - (Date.now() - last)))
  }

  return <p>Signing you in…</p>   // a spinner; there is no button
}
```

Notes:

- **The login page never "rests."** Since logout lands the browser back on
  `/login` unauthenticated, this pattern will immediately bounce to SSO again —
  i.e. logout sends the user to the auth server's login form. That's intended for
  a pure-SSO app; if you'd rather show a "signed out" screen, special-case a
  `?logged_out=1` marker and skip the auto-redirect when it's present.
- **Throttle retries.** Auto-retrying with no delay can become a redirect storm
  if the backend keeps failing while the SSO session is alive. The
  `RETRY_THROTTLE_MS` gap above keeps it a retry, not a loop.
- The login route's guard should still send **already-authenticated** users
  straight to the dashboard (§6) so this page only runs for signed-out users.

---

## 6. Route guards & logout

**Guarding routes** (TanStack Router example — adapt to your router):

```ts
beforeLoad: () => {
  if (!useAuthStore.getState().isAuthenticated) {
    throw redirect({ to: '/login' })
  }
}
```

…and on the login route, redirect to the dashboard if already authenticated.

**Logout** — this is a **three-part** operation, and skipping the third part is
the classic "I signed out but it logs me straight back in" bug:

```ts
async function handleLogout() {
  let logoutUrl: string | undefined
  try {
    const res = await authService.logout()   // 1. revoke the token server-side
    logoutUrl = res?.logout_url
  } catch { /* ignore */ }

  setAccessToken(null)                         // 2. clear local SPA state
  useAuthStore.getState().logout()

  // 3. Destroy the SSO session on the auth server, then return to login.
  if (logoutUrl) window.location.href = logoutUrl
  else window.location.href = '/login'
}
```

1. **Revoke the token** — `POST /auth/logout` adds the token's `jti` to the
   revocation list (rejected across all SSO apps) and returns a `logout_url`.
2. **Clear local state** — drop the in-memory token and the store.
3. **End the SSO session** — full-page redirect to `logout_url` (the auth
   server's `/logout?redirect=<your-login>`). **This step is essential.** The
   auth server keeps its own session cookie; if you only clear local state, the
   next `/auth/redirect` sails straight through `/oauth/authorize` and logs the
   **same** user back in — so you can never sign out or switch accounts. The
   redirect destroys that session and brings the browser back to your login.

> Have your backend's logout return the `logout_url` (built from the SSO server
> URL + a validated redirect back to your login) so the SPA never has to know
> the auth server's address.

### Detect logout in already-open tabs (cross-app single sign-out)

Logging out of one app revokes the user's tokens **server-side**, but another
app already open in a different tab can't be notified (different origin — no
shared storage events). That tab keeps *showing* logged-in until it makes a
request that 401s. Fix it by **re-validating when the tab regains focus**:

```ts
// In your AuthProvider (or wherever you load the current user):
useEffect(() => {
  const revalidate = () => {
    if (document.visibilityState === 'visible') fetchUser() // GET /…/me
  }
  document.addEventListener('visibilitychange', revalidate)
  window.addEventListener('focus', revalidate)
  return () => {
    document.removeEventListener('visibilitychange', revalidate)
    window.removeEventListener('focus', revalidate)
  }
}, [])
```

If the token was revoked elsewhere, `GET /…/me` returns 401 → your api 401
interceptor sends the user to login. So switching back to a stale tab after
logging out elsewhere cleanly logs that tab out too.

---

## 7. About the shipped stubs

`php artisan vendor:publish --tag=sso-client-react` drops three starter files:

| Stub | Use it for | Caveat |
|---|---|---|
| `api.ts` | Axios + in-memory token + 401 interceptor | Ready to use as-is |
| `AuthContext.tsx` | `useAuth()` hook, loads the current user | Solid; or use a store instead (§3a) |
| `SsoCallback.tsx` | A callback component | **Outdated** — it `POST`s `/auth/token`, which this package does **not** expose. Replace that call with the `sso_code` + `GET /sso/token` pickup shown in §5. |

Treat the stubs as scaffolding; the **canonical flow is §1–§6 of this guide.**

---

## 8. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Logged in, but the **first request after a page reload returns 401** | Token persisted in the store but never pushed into Axios | Rehydrate on startup (§4), or keep the token in-memory and re-run SSO on reload |
| **Redirect loop** between `/login` and the app | `isAuthenticated` is true but Axios has no token, so every call 401s → interceptor → `/login` | Same as above — sync the persisted token into Axios, or don't persist it |
| `/sso/token` returns **404** | Called through the `/api/v1` base URL | Override `baseURL: '/'` for that one call (§2) |
| `/sso/token` returns **401 "Invalid or expired code"** | Code already used (one-time) or >60s old; or `useEffect` ran twice in React 18 StrictMode and the second call finds the code already consumed | Add a **module-level guard** so the code is exchanged once even across StrictMode remounts: `let consumedSsoCode = null` outside the component, then `if (consumedSsoCode === code) return; consumedSsoCode = code` before the pickup |
| **CORS / cookie errors** on API calls | SPA and API on different origins without proper config | Serve both on the same origin via nginx, or configure CORS + `withCredentials` and a shared `SESSION_DOMAIN` |
| Stuck on "Completing sign-in…" | `pickupSsoToken` or `me()` rejected | Check the Network tab: a failing `/sso/token` is a backend/cache issue (see SETUP.md §9); a failing `me()` is a token/claims issue |
| User loaded but **roles/permissions empty** | Your `/me` endpoint doesn't return them, or the token carried no `roles` | Confirm the backend includes roles (and that the SSO token has the `roles` claim — auth.bansud CLAUDE.md §1.5.1) |

---

## Checklist

- [ ] `api.ts` — Axios instance, in-memory token, 401 interceptor
- [ ] `auth.service.ts` — `redirectToSso`, `pickupSsoToken` (with `baseURL:'/'`), `me`, `logout`
- [ ] Auth store/context holding `{ token, user, isAuthenticated }`
- [ ] Login trigger → `/auth/redirect?intended=…`
- [ ] Callback pickup (login page **or** `/auth/callback`) → set token → load user → go to dashboard
- [ ] Startup rehydration **if** the token is persisted
- [ ] Route guards on protected + login routes
- [ ] Logout → `/auth/logout` → clear token + store
- [ ] `VITE_API_BASE_URL` empty in prod, set only for standalone Vite
