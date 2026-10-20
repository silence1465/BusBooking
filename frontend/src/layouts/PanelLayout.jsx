import { useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { Logo, cx } from '../components/ui'
import { BRAND } from '../lib/brand'

const initials = (name = '') => name.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase()

/**
 * Admin shell: grouped sidebar on desktop, slide-in drawer on phones, and a top bar that always says where
 * you are. groups: [{ label, items: [{ to, label, end }] }]
 */
export default function PanelLayout({ title, groups }) {
  const [open, setOpen] = useState(false)
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const { pathname } = useLocation()

  // Where am I? The longest matching menu path wins (so /admin/trips/5/passengers is still "Trips").
  const current = groups
    .flatMap((g) => g.items.map((i) => ({ ...i, group: g.label })))
    .filter((i) => (i.end ? pathname === i.to : pathname === i.to || pathname.startsWith(i.to + '/')))
    .sort((a, b) => b.to.length - a.to.length)[0]

  // Escape closes the drawer; the page behind it does not scroll while it is open.
  useEffect(() => {
    if (!open) return
    const onKey = (e) => e.key === 'Escape' && setOpen(false)
    document.addEventListener('keydown', onKey)
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = ''
    }
  }, [open])

  const signOut = async () => {
    await logout()
    navigate('/login')
  }

  const sidebar = (
    <>
      <div className="flex h-16 shrink-0 items-center gap-3 border-b border-white/10 px-5">
        <Logo size={30} tone="brand" />
        <div className="min-w-0 leading-tight">
          <p className="truncate font-display text-[14px] font-bold tracking-tight text-white">{BRAND}</p>
          <p className="text-xs text-slate-300">{title}</p>
        </div>
      </div>
      <nav className="flex-1 overflow-y-auto p-3" aria-label={title}>
        {groups.map((g) => (
          <div key={g.label} className="mb-3 lg:mb-2">
            <p className="px-3 pb-1.5 pt-2 text-[11px] font-semibold uppercase tracking-widest text-slate-400 lg:pb-1 lg:pt-1.5">{g.label}</p>
            {g.items.map((i) => (
              <NavLink
                key={i.to}
                to={i.to}
                end={i.end}
                onClick={() => setOpen(false)}
                className={({ isActive }) =>
                  cx(
                    'flex min-h-11 items-center rounded-md px-3 text-sm font-medium lg:min-h-9',
                    isActive ? 'bg-slate-800 text-white shadow-[inset_3px_0_0_var(--color-gold-400)]' : 'text-slate-300 hover:bg-white/5 hover:text-white',
                  )
                }
              >
                {i.label}
              </NavLink>
            ))}
          </div>
        ))}
      </nav>
      <div className="shrink-0 border-t border-white/10 p-3">
        <button onClick={() => navigate('/')} className="flex min-h-11 w-full items-center rounded-md px-3 text-left text-sm font-medium text-slate-300 hover:bg-white/5 hover:text-white lg:min-h-9">
          Customer website
        </button>
        <button onClick={signOut} className="flex min-h-11 w-full items-center rounded-md px-3 text-left text-sm font-medium text-slate-300 hover:bg-white/5 hover:text-white lg:min-h-9">
          Sign out
        </button>
      </div>
    </>
  )

  return (
    <div className="min-h-screen lg:flex">
      <aside className="sticky top-0 hidden h-screen w-60 shrink-0 flex-col bg-slate-900 lg:flex">{sidebar}</aside>

      {open && (
        <div className="fixed inset-0 z-40 lg:hidden" onClick={() => setOpen(false)}>
          <div className="absolute inset-0 bg-slate-900/60" />
          <aside className="absolute inset-y-0 left-0 flex w-[280px] max-w-[85vw] flex-col bg-slate-900 shadow-2xl" onClick={(e) => e.stopPropagation()}>
            {sidebar}
          </aside>
        </div>
      )}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-30 flex h-16 items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 sm:px-7">
          <div className="flex min-w-0 items-center gap-2">
            <button onClick={() => setOpen(true)} aria-label="Open menu" aria-expanded={open} className="-ml-1 grid h-11 w-11 place-items-center rounded-md text-slate-700 hover:bg-slate-100 lg:hidden">
              <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                <path d="M4 6h16M4 12h16M4 18h16" strokeLinecap="round" />
              </svg>
            </button>
            {/* Breadcrumb: section, then page */}
            <nav aria-label="Breadcrumb" className="flex min-w-0 items-center gap-2 text-sm">
              <span className="hidden text-slate-500 sm:inline">{current?.group || title}</span>
              <svg viewBox="0 0 24 24" className="hidden h-4 w-4 shrink-0 text-slate-300 sm:block" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><path d="M9 6l6 6-6 6" strokeLinecap="round" strokeLinejoin="round" /></svg>
              <span className="truncate font-semibold text-slate-900" aria-current="page">{current?.label || title}</span>
            </nav>
          </div>
          <div className="flex shrink-0 items-center gap-3">
            <span className="grid h-9 w-9 place-items-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700" aria-hidden="true">{initials(user?.name)}</span>
            <div className="hidden leading-tight sm:block">
              <p className="text-sm font-semibold text-slate-900">{user?.name}</p>
              <p className="text-xs text-slate-500">{user?.role === 'admin' ? 'Administrator' : 'Station master'}</p>
            </div>
          </div>
        </header>
        <main className="min-w-0 flex-1 p-4 sm:p-7">
          <div className="mx-auto max-w-6xl">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  )
}
