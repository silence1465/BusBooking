import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { Logo, cx } from '../components/ui'

const ICONS = {
  verify: 'M4 7V5a1 1 0 011-1h2M17 4h2a1 1 0 011 1v2M20 17v2a1 1 0 01-1 1h-2M7 20H5a1 1 0 01-1-1v-2M8 12l3 3 5-6',
  sell: 'M4 6h16v4a2 2 0 000 4v4H4v-4a2 2 0 000-4zM10 6v12',
  trips: 'M5 16V6a2 2 0 012-2h10a2 2 0 012 2v10M5 11h14M5 16h14v2H5zM8 18v2M16 18v2',
  departures: 'M5 4h14v16H5zM9 9h6M9 13h6M9 17h3',
  manage: 'M12 8v8M8 12h8M4 5a1 1 0 011-1h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1z',
  online: 'M12 3a9 9 0 100 18 9 9 0 000-18zM3 12h18M12 3c2.5 2.5 3.5 5.5 3.5 9S14.500 18.500 12 21c-2.500-2.500-3.500-5.500-3.500-9S9.500 5.500 12 3z',
}

const NAV = {
  station_master: [
    { to: '/station', label: 'Verify booking', short: 'Verify', icon: 'verify', end: true },
    { to: '/station/sell', label: 'Sell ticket', short: 'Sell', icon: 'sell' },
    { to: '/station/trips', label: 'Vehicles and trips', short: 'Vehicles', icon: 'trips' },
    { to: '/station/departures', label: 'Departures', short: 'Departures', icon: 'departures' },
    { to: '/station/online-account', label: 'Online account', short: 'Online', icon: 'online' },
  ],
  // The driver rides the vehicle: check passengers in and mark no-shows. No selling, no dispatching.
  driver: [
    { to: '/station', label: 'Check in', short: 'Check in', icon: 'verify', end: true },
    { to: '/station/trips', label: 'My vehicle', short: 'My vehicle', icon: 'trips' },
  ],
}

function Icon({ name }) {
  return (
    <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d={ICONS[name]} />
    </svg>
  )
}

/**
 * Station / driver console. Desktop: one top bar with the sections. Phone: a slim top bar and a thumb-reach
 * tab bar at the bottom, because this is used one-handed at the gate or in the driver's seat.
 */
export default function StationLayout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const canManage = user?.role === 'station_master' && Object.values(user.permissions || {}).some(Boolean)
  // Only when an admin has switched something on for this station master.
  const items = [...(NAV[user?.role] || NAV.station_master), ...(canManage ? [{ to: '/station/manage', label: 'Create', short: 'Create', icon: 'manage' }] : [])]
  const isAdmin = user?.role === 'admin'

  const signOut = async () => {
    await logout()
    navigate('/login')
  }

  return (
    <div className="min-h-screen">
      <header className="no-print sticky top-0 z-30 bg-slate-900 text-white">
        <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-8">
          <div className="flex min-w-0 items-center gap-3">
            <Logo size={30} tone="brand" />
            <div className="min-w-0 leading-tight">
              <p className="truncate font-semibold">{user?.role === 'driver' ? 'Driver' : 'Station console'}</p>
              <p className="truncate text-xs text-slate-300">{user?.name}</p>
            </div>
          </div>

          <nav className="hidden items-center gap-1 text-sm font-medium md:flex" aria-label="Sections">
            {items.map((i) => (
              <NavLink
                key={i.to}
                to={i.to}
                end={i.end}
                className={({ isActive }) => cx('whitespace-nowrap rounded-md px-3 py-2.5', isActive ? 'bg-slate-800 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white')}
              >
                {i.label}
              </NavLink>
            ))}
            {isAdmin && <NavLink to="/admin" className="rounded-md px-4 py-2.5 text-slate-300 hover:bg-white/5 hover:text-white">Admin</NavLink>}
          </nav>

          <div className="flex shrink-0 items-center gap-2">
            {isAdmin && <NavLink to="/admin" className="rounded-md border border-white/20 px-3 py-2 text-sm font-medium text-slate-200 hover:bg-white/5 md:hidden">Admin</NavLink>}
            <button onClick={signOut} className="min-h-10 rounded-md border border-white/20 px-3 text-sm font-medium text-slate-200 hover:bg-white/5">Sign out</button>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-6xl px-4 py-6 pb-28 sm:px-8 sm:py-8 md:pb-10">
        <Outlet />
      </main>

      {/* Phone tab bar */}
      <nav className="no-print safe-bottom fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white md:hidden" aria-label="Sections">
        <ul className="mx-auto grid max-w-lg" style={{ gridTemplateColumns: `repeat(${items.length}, minmax(0, 1fr))` }}>
          {items.map((i) => (
            <li key={i.to}>
              <NavLink
                to={i.to}
                end={i.end}
                className={({ isActive }) =>
                  cx('flex h-16 flex-col items-center justify-center gap-0.5 border-t-2 text-[11px]', isActive ? 'border-brand-600 font-semibold text-brand-600' : 'border-transparent font-medium text-slate-500')
                }
              >
                <Icon name={i.icon} />
                {i.short}
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>
    </div>
  )
}
