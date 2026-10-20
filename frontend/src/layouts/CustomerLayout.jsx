import { NavLink, Link, Outlet, useLocation } from 'react-router-dom'
import { useAuth, homeForRole } from '../context/AuthContext'
import { Logo, cx } from '../components/ui'
import { BRAND } from '../lib/brand'

const items = [
  { to: '/', label: 'Book a trip', short: 'Home', end: true, icon: 'M3 11l9-8 9 8v9a1 1 0 01-1 1h-5v-6H9v6H4a1 1 0 01-1-1z' },
  { to: '/bookings', label: 'Manage booking', short: 'Bookings', icon: 'M4 6h16v4a2 2 0 000 4v4H4v-4a2 2 0 000-4z' },
  { to: '/profile', label: 'Account', short: 'Profile', icon: 'M12 12a4 4 0 100-8 4 4 0 000 8zm-8 9a8 8 0 0116 0' },
]

const YEAR = new Date().getFullYear()
const pageShell = 'mx-auto w-full max-w-6xl px-4 py-6 sm:px-8 sm:py-8'

function Icon({ d }) {
  return (
    <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinejoin="round" strokeLinecap="round" aria-hidden="true">
      <path d={d} />
    </svg>
  )
}

export default function CustomerLayout() {
  const { user } = useAuth()
  const { pathname } = useLocation()

  return (
    <div className="flex min-h-screen flex-col">
      <header className="no-print sticky top-0 z-30 border-b border-slate-200 bg-white">
        <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-8">
          <Link to="/" className="flex items-center gap-3 font-display text-[16px] font-bold tracking-tight text-slate-900">
            <Logo />
            {BRAND}
          </Link>
          <nav className="hidden h-full items-center gap-7 text-sm font-medium sm:flex">
            {items.map((i) => (
              <NavLink
                key={i.to}
                to={i.to}
                end={i.end}
                className={({ isActive }) =>
                  cx('flex h-full items-center border-b-2', isActive ? 'border-brand-600 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-900')
                }
              >
                {i.label}
              </NavLink>
            ))}
            {user && user.role !== 'customer' ? (
              <Link to={homeForRole(user.role)} className="rounded-md bg-slate-900 px-4 py-2 font-semibold text-white hover:bg-slate-800">
                {user.role === 'admin' ? 'Admin console' : 'Station console'}
              </Link>
            ) : (
              !user && (
                <Link to="/login" className="rounded-md border border-slate-300 px-4 py-2 text-slate-900 hover:bg-slate-50">
                  Sign in
                </Link>
              )
            )}
          </nav>
        </div>
      </header>

      {/* Home is full-bleed (hero band); every other page gets the standard page width. */}
      <main className="flex-1 pb-10 sm:pb-12">
        {pathname === '/' ? <Outlet /> : <div className={pageShell}><Outlet /></div>}
      </main>

      <footer className="no-print border-t border-slate-200 bg-white pb-20 sm:pb-0">
        <div className="mx-auto flex max-w-6xl flex-col items-center gap-0.5 px-4 py-2 text-xs text-slate-500 sm:flex-row sm:justify-between sm:px-8 sm:py-3">
          <p className="order-2 sm:order-1">
            <span className="font-display font-bold text-slate-900">{BRAND}</span> · © {YEAR} · Payments secured by Paystack
          </p>
          <nav className="order-1 flex flex-wrap justify-center gap-x-5 font-medium sm:order-2" aria-label="Footer">
            <Link to="/" className="inline-flex min-h-11 items-center whitespace-nowrap hover:text-slate-900 sm:min-h-8">Book a trip</Link>
            <Link to="/bookings" className="inline-flex min-h-11 items-center whitespace-nowrap hover:text-slate-900 sm:min-h-8">Find my ticket</Link>
            <Link to="/contact" className="inline-flex min-h-11 items-center whitespace-nowrap hover:text-slate-900 sm:min-h-8">Contact us</Link>
            <Link to="/privacy" className="inline-flex min-h-11 items-center whitespace-nowrap hover:text-slate-900 sm:min-h-8">Privacy</Link>
            <Link to="/login" className="inline-flex min-h-11 items-center whitespace-nowrap hover:text-slate-900 sm:min-h-8">Staff sign in</Link>
          </nav>
        </div>
      </footer>

      {/* Mobile bottom navigation */}
      <nav className="no-print safe-bottom fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white sm:hidden">
        <ul className="mx-auto grid max-w-md grid-cols-3">
          {items.map((i) => (
            <li key={i.to}>
              <NavLink
                to={i.to}
                end={i.end}
                className={({ isActive }) =>
                  cx('flex h-16 flex-col items-center justify-center gap-0.5 border-t-2 text-xs', isActive ? 'border-brand-600 font-semibold text-brand-600' : 'border-transparent font-medium text-slate-500')
                }
              >
                <Icon d={i.icon} />
                {i.short}
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>
    </div>
  )
}

