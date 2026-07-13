/**
 * technikali/sso-client — React stub: SsoCallback.tsx
 *
 * ⚠️  This stub is outdated — it POSTs to /auth/token which this package
 *    does NOT expose. The correct flow uses the ?sso_code= handoff:
 *
 *      1. Your backend /auth/callback caches the JWT under a one-time code
 *         and redirects the browser to: ?sso_callback=1&sso_code=<code>
 *      2. The SPA reads sso_code and calls GET /sso/token?code=<code>
 *         (the route you add yourself, per SETUP.md §5)
 *
 * RECOMMENDED: Use @technikali/sso-react instead — it ships useSsoLogin() which
 * handles the full callback flow (Pattern A, B, and C) correctly:
 *
 *   import { useSsoLogin } from '@technikali/sso-react'
 *
 *   export function LoginPage() {
 *     const navigate = useNavigate()
 *     const { login } = useAuthStore()
 *
 *     useSsoLogin({
 *       authService,
 *       api,
 *       onLogin: (token, user) => login(token, user),
 *       onNavigate: (path) => navigate({ to: path }),
 *       dashboardPath: '/dashboard',
 *     })
 *
 *     return <p>Signing you in…</p>
 *   }
 *
 * See packages/sso-react/src/useSsoLogin.ts for the full implementation.
 * See packages/sso-client/SETUP-FRONTEND.md §5 for the flow description.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Standalone fallback (no npm package) — Pattern B: dedicated /auth/callback route
 */

import { useEffect, useRef, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { api, setAccessToken } from './api'

// Guard against React 18 StrictMode double-invoke consuming the one-time code.
let _consumedCode: string | null = null

export function SsoCallback() {
  const navigate = useNavigate()
  const [error, setError] = useState<string | null>(null)
  const busy = useRef(false)

  useEffect(() => {
    if (busy.current) return
    busy.current = true

    const params = new URLSearchParams(window.location.search)
    const code = params.get('sso_code')
    const err = params.get('error') || params.get('error_description')

    if (err) {
      setError(err)
      busy.current = false
      return
    }

    if (!code) {
      setError('No SSO code received.')
      busy.current = false
      return
    }

    if (_consumedCode === code) {
      busy.current = false
      return
    }
    _consumedCode = code

    // Exchange the one-time code for the JWT.
    // Note: baseURL:'/' ensures this hits /sso/token, not /api/v1/sso/token.
    api
      .get<{ token: string }>(`/sso/token?code=${encodeURIComponent(code)}`, { baseURL: '/' })
      .then(async ({ data }) => {
        setAccessToken(data.token)
        // Load current user from your /me endpoint, store in state, then navigate.
        // Adjust the path and how you persist user state for your app:
        const user = await api.get('/auth/me').then((r) => r.data.user)
        console.log('SSO login complete', user) // replace with your store.login(token, user)
        navigate({ to: '/dashboard' })
      })
      .catch((e: unknown) => {
        const msg = e instanceof Error ? e.message : 'Authentication failed.'
        setError(msg)
        busy.current = false
      })
  }, [navigate])

  if (error) {
    return (
      <div className="min-h-screen flex items-center justify-center p-8 text-center">
        <div>
          <p className="text-red-600 font-medium mb-4">SSO Error</p>
          <p className="text-gray-600 text-sm mb-6">{error}</p>
          <a href="/login" className="text-blue-600 underline text-sm">
            Return to login
          </a>
        </div>
      </div>
    )
  }

  return (
    <div className="min-h-screen flex items-center justify-center">
      <div className="animate-spin rounded-full h-10 w-10 border-b-2 border-blue-600" />
    </div>
  )
}
