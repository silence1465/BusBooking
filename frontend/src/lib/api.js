import axios from 'axios'

const TOKEN_KEY = 'bb_token'

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8000/api/v1',
  headers: { Accept: 'application/json' },
  timeout: 30000,
})

export const getToken = () => {
  try {
    return localStorage.getItem(TOKEN_KEY)
  } catch {
    return null
  }
}

export const setToken = (token) => {
  try {
    if (token) localStorage.setItem(TOKEN_KEY, token)
    else localStorage.removeItem(TOKEN_KEY)
  } catch {
    /* storage unavailable */
  }
}

api.interceptors.request.use((config) => {
  const token = getToken()
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use(
  (res) => res,
  (error) => {
    // Expired/revoked token: drop it and let the auth context send the user to login.
    if (error.response?.status === 401 && getToken()) {
      setToken(null)
      window.dispatchEvent(new Event('bb:unauthorized'))
    }
    return Promise.reject(error)
  },
)

/** Human-friendly message from a Laravel error response. */
export function errorMessage(error, fallback = 'Something went wrong. Please try again.') {
  const data = error?.response?.data
  if (data?.errors) return Object.values(data.errors).flat()[0]
  if (data?.message) return data.message
  if (error?.code === 'ERR_NETWORK') return 'Cannot reach the server. Check your connection.'
  return fallback
}

/** Per-field errors for forms. */
export function fieldErrors(error) {
  const errors = error?.response?.data?.errors || {}
  return Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, v[0]]))
}
