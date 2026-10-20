import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { api, getToken, setToken } from '../lib/api'

const AuthContext = createContext(null)

export const homeForRole = (role) =>
  role === 'admin' ? '/admin' : role === 'station_master' || role === 'driver' ? '/station' : '/'

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(!!getToken())

  useEffect(() => {
    if (!getToken()) return
    api
      .get('/auth/me')
      .then((res) => setUser(res.data.data))
      .catch(() => setToken(null))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    const onUnauthorized = () => setUser(null)
    window.addEventListener('bb:unauthorized', onUnauthorized)
    return () => window.removeEventListener('bb:unauthorized', onUnauthorized)
  }, [])

  const login = useCallback(async (identifier, password) => {
    const res = await api.post('/auth/login', { login: identifier.trim(), password })
    setToken(res.data.data.token)
    setUser(res.data.data.user)
    return res.data.data.user
  }, [])

  const register = useCallback(async (payload) => {
    const res = await api.post('/auth/register', payload)
    setToken(res.data.data.token)
    setUser(res.data.data.user)
    return res.data.data.user
  }, [])

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout')
    } catch {
      /* token may already be invalid */
    }
    setToken(null)
    setUser(null)
  }, [])

  const value = useMemo(() => ({ user, loading, login, register, logout }), [user, loading, login, register, logout])
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export const useAuth = () => useContext(AuthContext)
