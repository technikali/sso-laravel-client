/**
 * technikali/sso-client — React stub: api.ts
 *
 * Drop this into src/lib/api.ts in your React app.
 *
 * RECOMMENDED: If you have @technikali/sso-react installed, use createSsoAxios()
 * instead — it does everything below and is kept up to date:
 *
 *   import { createSsoAxios } from '@technikali/sso-react'
 *
 *   export const api = createSsoAxios({
 *     apiBaseUrl: import.meta.env.VITE_API_BASE_URL,
 *     apiPrefix: '/api/v1',
 *   })
 *   export const { setAccessToken, getAccessToken } = api
 *
 * This file is a standalone fallback for projects without the npm package.
 * See packages/sso-react/ in the monorepo for the full library.
 */
import axios from 'axios'

// Leave VITE_API_BASE_URL unset (or '') in production — nginx on the same origin
// routes /api/* to Laravel. Set it to http://127.0.0.1:8000 only when running
// Vite standalone (without the nginx proxy).
const API_BASE = (import.meta as { env?: Record<string, string> }).env?.VITE_API_BASE_URL ?? ''

export const api = axios.create({
  baseURL: `${API_BASE}/api/v1`,
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
