/**
 * technikali/sso-client — React stub: AuthContext.tsx
 *
 * Drop this into src/contexts/AuthContext.tsx in your React app.
 *
 * RECOMMENDED: Use @technikali/sso-react instead — it ships SsoProvider and
 * useSso() which do the same thing, plus tab-focus re-validation and typed
 * generics for your user shape:
 *
 *   import { SsoProvider, useSso } from '@technikali/sso-react'
 *
 *   // Wrap your app:
 *   <SsoProvider authService={authService} api={api} revalidateOnFocus>
 *     <App />
 *   </SsoProvider>
 *
 *   // In any component:
 *   const { user, isAuthenticated, logout, hasRole } = useSso()
 *
 * See packages/sso-react/src/SsoProvider.tsx for the full implementation.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Standalone fallback (no npm package)
 */

import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { api, setAccessToken } from './api'   // adjust path if needed

// ── Types ─────────────────────────────────────────────────────────────────────

export interface SsoUser {
  id: string
  name: string
  email: string
  office?: string
  employee_id?: string
  is_active: boolean
  roles: string[]
  permissions: string[]
  apps?: Array<{
    id: string
    name: string
    slug: string
    url: string
    icon?: string
  }>
}

interface AuthContextValue {
  user: SsoUser | null
  isLoading: boolean
  isAuthenticated: boolean
  hasRole: (role: string) => boolean
  hasPermission: (permission: string) => boolean
  refresh: () => Promise<void>
  logout: () => Promise<void>
}

// ── Context ───────────────────────────────────────────────────────────────────

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<SsoUser | null>(null)
  const [isLoading, setIsLoading] = useState(true)

  const fetchUser = useCallback(async () => {
    try {
      const { data } = await api.get<{ user: SsoUser }>('/auth/me')
      setUser(data.user)
    } catch {
      setUser(null)
    } finally {
      setIsLoading(false)
    }
  }, [])

  useEffect(() => {
    fetchUser()
  }, [fetchUser])

  // Re-validate on tab focus to detect cross-app SSO logout
  useEffect(() => {
    const revalidate = () => {
      if (document.visibilityState === 'visible') void fetchUser()
    }
    document.addEventListener('visibilitychange', revalidate)
    window.addEventListener('focus', revalidate)
    return () => {
      document.removeEventListener('visibilitychange', revalidate)
      window.removeEventListener('focus', revalidate)
    }
  }, [fetchUser])

  const logout = async () => {
    let logoutUrl: string | undefined
    try {
      const { data } = await api.post<{ logout_url?: string }>('/auth/logout')
      logoutUrl = data.logout_url
    } catch { /* ignore */ }

    setAccessToken(null)
    setUser(null)
    // Step 3: destroy the SSO session — without this, /auth/redirect re-logs
    // the same user back in immediately.
    window.location.href = logoutUrl ?? '/login'
  }

  const hasRole = (role: string) => user?.roles?.includes(role) ?? false
  const hasPermission = (perm: string) => user?.permissions?.includes(perm) ?? false

  return (
    <AuthContext.Provider
      value={{
        user,
        isLoading,
        isAuthenticated: !!user,
        hasRole,
        hasPermission,
        refresh: fetchUser,
        logout,
      }}
    >
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>')
  return ctx
}
