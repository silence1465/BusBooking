import { Link } from 'react-router-dom'
import { useAuth, homeForRole } from '../../context/AuthContext'
import { Button, Card, DetailRow } from '../../components/ui'
import { AuthForm } from '../Login'

export default function Profile() {
  const { user, logout } = useAuth()

  if (!user) {
    return (
      <Card className="mx-auto max-w-sm">
        <h1 className="mb-1 text-xl font-bold">Your account</h1>
        <p className="mb-5 text-sm text-slate-500">An account is optional. It just keeps your bookings together.</p>
        <AuthForm />
      </Card>
    )
  }

  return (
    <Card className="mx-auto max-w-sm space-y-5">
      <h1 className="text-xl font-bold">Profile</h1>
      <dl className="space-y-4">
        <DetailRow label="Name">{user.name}</DetailRow>
        <DetailRow label="Email">{user.email}</DetailRow>
        <DetailRow label="Phone">{user.phone || '—'}</DetailRow>
      </dl>
      {user.role !== 'customer' && (
        <Link to={homeForRole(user.role)}><Button className="w-full">Open {user.role === 'admin' ? 'admin' : 'station'} dashboard</Button></Link>
      )}
      <Button variant="secondary" className="w-full" onClick={logout}>Log out</Button>
    </Card>
  )
}
