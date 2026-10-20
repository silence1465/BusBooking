import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { homeForRole, useAuth } from '../context/AuthContext'
import { PageLoader } from './ui'

/** UX guard only. The API enforces every permission again on the server. */
export default function RequireRole({ roles }) {
  const { user, loading } = useAuth()
  const location = useLocation()

  if (loading) return <PageLoader />
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname }} />
  if (!roles.includes(user.role)) return <Navigate to={homeForRole(user.role)} replace />
  return <Outlet />
}
