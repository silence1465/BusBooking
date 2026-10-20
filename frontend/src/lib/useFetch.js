import { useCallback, useEffect, useRef, useState } from 'react'
import { api, errorMessage } from './api'

/** GET helper. `params` changes trigger a refetch; `reload()` forces one. Returns the raw response body. */
export function useFetch(url, params, { enabled = true } = {}) {
  const [body, setBody] = useState(null)
  const [loading, setLoading] = useState(enabled)
  const [error, setError] = useState('')
  const key = JSON.stringify(params || {})
  const seq = useRef(0)

  const load = useCallback(async () => {
    if (!enabled || !url) return
    const mine = ++seq.current
    setLoading(true)
    setError('')
    try {
      const res = await api.get(url, { params: JSON.parse(key) })
      if (mine === seq.current) setBody(res.data)
    } catch (e) {
      if (mine === seq.current) setError(errorMessage(e))
    } finally {
      if (mine === seq.current) setLoading(false)
    }
  }, [url, key, enabled])

  useEffect(() => {
    load()
  }, [load])

  return { body, data: body?.data, meta: body?.meta, loading, error, reload: load }
}
