import { useState } from 'react'
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom'
import { homeForRole, useAuth } from '../context/AuthContext'
import { errorMessage, fieldErrors } from '../lib/api'
import { Alert, Button, Card, Input, Logo } from '../components/ui'
import { BRAND } from '../lib/brand'

export function AuthForm({ initialMode = 'login', onDone }) {
  const { login, register } = useAuth()
  const [mode, setMode] = useState(initialMode)
  const [form, setForm] = useState({ name: '', email: '', phone: '', password: '', password_confirmation: '' })
  const [errors, setErrors] = useState({})
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }))

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true)
    setError('')
    setErrors({})
    try {
      const user = mode === 'login' ? await login(form.email, form.password) : await register(form)
      onDone?.(user)
    } catch (err) {
      setError(errorMessage(err))
      setErrors(fieldErrors(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} className="space-y-4">
      <Alert>{error}</Alert>
      {mode === 'register' && <Input label="Full name" value={form.name} onChange={set('name')} error={errors.name} required autoComplete="name" />}
      {mode === 'login' ? (
        <Input
          label="Email or phone number"
          type="text"
          inputMode="email"
          value={form.email}
          onChange={set('email')}
          error={errors.login}
          required
          autoComplete="username"
          autoCapitalize="none"
          spellCheck={false}
          placeholder="you@example.com or 0241234567"
        />
      ) : (
        <Input label="Email" type="email" value={form.email} onChange={set('email')} error={errors.email} required autoComplete="email" />
      )}
      {mode === 'register' && <Input label="Phone" type="tel" value={form.phone} onChange={set('phone')} error={errors.phone} required autoComplete="tel" />}
      <Input label="Password" type="password" value={form.password} onChange={set('password')} error={errors.password} required autoComplete={mode === 'login' ? 'current-password' : 'new-password'} hint={mode === 'register' ? 'At least 8 characters.' : undefined} />
      {mode === 'register' && <Input label="Confirm password" type="password" value={form.password_confirmation} onChange={set('password_confirmation')} required autoComplete="new-password" />}
      <Button type="submit" size="lg" className="w-full" loading={busy}>{mode === 'login' ? 'Log in' : 'Create account'}</Button>
      <p className="text-center text-sm text-slate-600">
        {mode === 'login' ? 'New here?' : 'Already have an account?'}{' '}
        <button type="button" className="inline-flex min-h-11 items-center font-semibold text-brand-700" onClick={() => { setMode(mode === 'login' ? 'register' : 'login'); setError(''); setErrors({}) }}>
          {mode === 'login' ? 'Create an account' : 'Log in'}
        </button>
      </p>
    </form>
  )
}

export default function Login() {
  const { user, loading } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()

  if (!loading && user) return <Navigate to={homeForRole(user.role)} replace />

  return (
    <div className="grid min-h-screen place-items-center px-4 py-8">
      <Card className="w-full max-w-sm sm:p-7">
        <div className="mb-5 flex items-center gap-3">
          <Logo size={36} />
          <span className="font-display text-lg font-bold tracking-tight text-slate-900">{BRAND}</span>
        </div>
        <h1 className="text-[22px] font-semibold tracking-tight text-slate-900">Sign in</h1>
        <p className="mb-5 mt-1 text-sm text-slate-500">Customers and staff sign in here.</p>
        <AuthForm onDone={(u) => navigate(location.state?.from && u.role !== 'customer' ? location.state.from : homeForRole(u.role), { replace: true })} />
        <Link to="/" className="mt-3 flex min-h-11 items-center justify-center text-sm font-medium text-slate-500 hover:text-slate-900">Continue as guest</Link>
      </Card>
    </div>
  )
}
