import { useEffect, useState } from 'react'

const cx = (...c) => c.filter(Boolean).join(' ')

export function Button({ variant = 'primary', size = 'md', className, loading, children, ...props }) {
  const variants = {
    primary: 'bg-brand-600 text-white hover:bg-brand-700 disabled:bg-brand-600/50',
    secondary: 'bg-white text-slate-800 border border-slate-300 hover:bg-slate-50 disabled:opacity-50',
    danger: 'bg-red-600 text-white hover:bg-red-700 disabled:bg-red-600/50',
    success: 'bg-emerald-600 text-white hover:bg-emerald-700 disabled:bg-emerald-600/50',
    ghost: 'text-slate-700 hover:bg-slate-100 disabled:opacity-50',
    gold: 'bg-gold-400 text-slate-900 hover:bg-gold-500 disabled:opacity-50',
    dark: 'bg-slate-900 text-white hover:bg-slate-800 disabled:opacity-50',
  }
  const sizes = { sm: 'min-h-9 px-3 py-1.5 text-sm', md: 'min-h-10 px-4 py-2.5 text-sm', lg: 'min-h-12 px-5 py-3 text-base' }
  return (
    <button
      className={cx(
        'inline-flex items-center justify-center gap-2 rounded-md font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed',
        variants[variant],
        sizes[size],
        className,
      )}
      disabled={loading || props.disabled}
      {...props}
    >
      {loading && <Spinner className="h-4 w-4" />}
      {children}
    </button>
  )
}

const BUS_PATH = 'M9 20V11a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v9M9 15h14M9 20h14v2H9zM12 22v2M20 22v2'

/** Brand mark. `tone` picks the tile colour so it reads on light and dark headers. */
export function Logo({ size = 32, tone = 'ink' }) {
  return (
    <svg width={size} height={size} viewBox="0 0 32 32" fill="none" aria-hidden="true" className="shrink-0">
      <rect width="32" height="32" rx="6" fill={tone === 'ink' ? '#1b1640' : '#6233f0'} />
      <path d={BUS_PATH} stroke="#fff" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  )
}

export function BusIcon({ className = 'h-7 w-7' }) {
  return (
    <svg viewBox="4 4 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" className={className}>
      <path d={BUS_PATH} />
    </svg>
  )
}

/** Bus photo, or a neutral placeholder when the operator has not uploaded one. */
export function BusPhoto({ src, alt, className }) {
  return src ? (
    <img src={src} alt={alt} loading="lazy" className={cx('rounded-md object-cover', className)} />
  ) : (
    <div className={cx('grid place-items-center rounded-md bg-slate-100 text-slate-400', className)} aria-hidden="true">
      <BusIcon />
    </div>
  )
}

export function Spinner({ className = 'h-6 w-6' }) {
  return (
    <svg className={cx('animate-spin', className)} viewBox="0 0 24 24" fill="none" aria-label="Loading">
      <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" className="opacity-25" />
      <path d="M4 12a8 8 0 018-8" stroke="currentColor" strokeWidth="4" strokeLinecap="round" className="opacity-90" />
    </svg>
  )
}

export function PageLoader() {
  return (
    <div className="flex justify-center py-16 text-brand-600">
      <Spinner className="h-8 w-8" />
    </div>
  )
}

export function Card({ className, children, ...props }) {
  return (
    <div className={cx('rounded-lg border border-slate-200 bg-white p-4 sm:p-5', className)} {...props}>
      {children}
    </div>
  )
}

export function Field({ label, error, hint, children }) {
  return (
    <label className="block">
      {label && <span className="mb-1 block text-xs font-semibold uppercase tracking-wider text-slate-500">{label}</span>}
      {children}
      {hint && !error && <span className="mt-1 block text-xs text-slate-500">{hint}</span>}
      {error && <span className="mt-1 block text-xs font-medium text-red-600">{error}</span>}
    </label>
  )
}

const inputCls =
  'block w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm text-slate-900 placeholder-slate-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30 disabled:bg-slate-100'

export function Input({ label, error, hint, className, ...props }) {
  return (
    <Field label={label} error={error} hint={hint}>
      <input className={cx(inputCls, error && 'border-red-400', className)} {...props} />
    </Field>
  )
}

export function Select({ label, error, hint, children, className, ...props }) {
  return (
    <Field label={label} error={error} hint={hint}>
      <select className={cx(inputCls, error && 'border-red-400', className)} {...props}>
        {children}
      </select>
    </Field>
  )
}

export function Alert({ type = 'error', title, children, className }) {
  const styles = {
    error: 'border-red-200 bg-red-50 text-red-800',
    success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    warning: 'border-amber-200 bg-amber-50 text-amber-800',
    info: 'border-brand-200 bg-brand-50 text-brand-700',
  }
  if (!children && !title) return null
  return (
    <div role="alert" className={cx('rounded-lg border px-4 py-3 text-sm', styles[type], className)}>
      {title && <p className="font-semibold">{title}</p>}
      {children && <div>{children}</div>}
    </div>
  )
}

const badgeColors = {
  green: 'bg-emerald-100 text-emerald-800',
  red: 'bg-red-100 text-red-800',
  amber: 'bg-amber-100 text-amber-800',
  blue: 'bg-brand-100 text-brand-700',
  gray: 'bg-slate-100 text-slate-700',
}
const statusColor = {
  paid: 'green', confirmed: 'green', active: 'green', scheduled: 'blue', sent: 'green', completed: 'gray',
  pending: 'amber', held: 'amber', departed: 'green', boarding: 'blue', queued: 'amber', no_show: 'red',
  failed: 'red', cancelled: 'red', inactive: 'gray', refunded: 'gray',
}

export function Badge({ children, color }) {
  const c = color || statusColor[String(children).toLowerCase()] || 'gray'
  return (
    <span className={cx('inline-block rounded px-2.5 py-0.5 text-xs font-semibold capitalize', badgeColors[c])}>
      {children}
    </span>
  )
}

export function Modal({ open, title, aside, onClose, children }) {
  useEffect(() => {
    if (!open) return
    const onKey = (e) => e.key === 'Escape' && onClose()
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, onClose])

  if (!open) return null
  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 sm:items-center sm:p-4" onMouseDown={onClose}>
      <div
        role="dialog"
        aria-modal="true"
        className="flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-2xl [@supports(height:1dvh)]:max-h-[92dvh] bg-white shadow-xl sm:max-w-lg sm:rounded-xl"
        onMouseDown={(e) => e.stopPropagation()}
      >
        <div className="flex shrink-0 items-center justify-between border-b border-slate-200 px-5 py-4">
          <h2 className="text-lg font-semibold tracking-tight text-slate-900">{title}</h2>
          {aside && <div className="ml-auto mr-2 text-right">{aside}</div>}
          <button onClick={onClose} className="-mr-2 grid h-10 w-10 place-items-center rounded-full text-slate-500 hover:bg-slate-100" aria-label="Close">
            <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
          </button>
        </div>
        <div className="overflow-y-auto p-5">{children}</div>
      </div>
    </div>
  )
}

/** Seat numbers as yellow badges, so they stand out wherever a seat is shown. */
export function SeatBadges({ seats = [], className }) {
  return (
    <span className={cx('inline-flex flex-wrap gap-1.5', className)}>
      {seats.map((n) => (
        <span key={n} className="inline-block min-w-8 rounded-md bg-gold-400 px-2 py-0.5 text-center font-mono text-[13px] font-semibold text-slate-900">
          {String(n).padStart(2, '0')}
        </span>
      ))}
    </span>
  )
}

/** Violet badge for vehicles with open seating (Express, Voxy): a different colour from seat numbers on purpose. */
export function OpenSeatingBadge({ children, className }) {
  return (
    <span className={cx('inline-flex items-center gap-1.5 rounded-md border border-brand-200 bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-700', className)}>
      <svg viewBox="0 0 24 24" className="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="9" />
        <path d="M8 12l3 3 5-6" />
      </svg>
      {children}
    </span>
  )
}

/**
 * Tab bar for switching between sections instead of stacking them down the page.
 * tabs: [{ key, label, count? }]. Scrolls sideways on narrow screens.
 */
export function Tabs({ tabs, value, onChange, className }) {
  return (
    <div className={cx('flex gap-1 overflow-x-auto border-b border-slate-200', className)} role="tablist">
      {tabs.map((t) => {
        const active = t.key === value
        return (
          <button
            key={t.key}
            type="button"
            role="tab"
            aria-selected={active}
            onClick={() => onChange(t.key)}
            className={cx(
              '-mb-px flex min-h-10 shrink-0 items-center gap-2 whitespace-nowrap border-b-2 px-3 text-sm',
              active ? 'border-brand-600 font-bold text-slate-900' : 'border-transparent font-medium text-slate-500 hover:text-slate-900',
            )}
          >
            {t.label}
            {t.count != null && (
              <span className={cx('rounded-full px-1.5 py-px text-[11px] font-semibold', active ? 'bg-brand-100 text-brand-700' : 'bg-slate-100 text-slate-600')}>{t.count}</span>
            )}
          </button>
        )
      })}
    </div>
  )
}

export function Empty({ children }) {
  return <p className="py-10 text-center text-sm text-slate-500">{children}</p>
}

export function PageHeader({ title, subtitle, actions }) {
  return (
    <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 className="text-xl font-semibold tracking-tight text-slate-900 sm:text-2xl">{title}</h1>
        {subtitle && <p className="mt-0.5 text-sm text-slate-500">{subtitle}</p>}
      </div>
      {actions && <div className="flex gap-2">{actions}</div>}
    </div>
  )
}

/** Simple responsive table: scrolls horizontally on small screens. */
/**
 * Data table. On tablets and up it is a normal table. On phones each row becomes a card (label on the left,
 * value on the right) so nothing scrolls sideways; the `primary` (or first) column is the card's heading and unlabeled
 * columns (row actions) sit along the bottom.
 */
export function Table({ columns, rows, rowKey = 'id', empty = 'Nothing here yet.', onRowClick }) {
  if (!rows?.length) return <Empty>{empty}</Empty>

  const cell = (c, row) => (c.render ? c.render(row) : row[c.key])
  // On phones the heading is the column marked `primary`, else the first one.
  const head = columns.find((c) => c.primary) || columns[0]
  const rest = columns.filter((c) => c !== head)
  const fields = rest.filter((c) => c.label)
  const actions = rest.filter((c) => !c.label)

  return (
    <>
      <div className="hidden overflow-x-auto rounded-lg border border-slate-200 bg-white md:block">
        <table className="min-w-full divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-700 text-left text-xs font-semibold uppercase tracking-wider text-white">
            <tr>
              {columns.map((c) => (
                <th key={c.key || c.label} scope="col" className={cx('whitespace-nowrap px-4 py-3', c.className)}>
                  {c.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {rows.map((row, i) => (
              <tr
                key={row[rowKey] ?? i}
                className={cx('odd:bg-white even:bg-slate-50 hover:bg-brand-50/60', onRowClick && 'cursor-pointer')}
                onClick={onRowClick ? () => onRowClick(row) : undefined}
              >
                {columns.map((c) => (
                  <td key={c.key || c.label} className={cx('whitespace-nowrap px-4 py-3 align-middle', c.className)}>
                    {cell(c, row)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <ul className="space-y-2.5 md:hidden">
        {rows.map((row, i) => (
          <li key={row[rowKey] ?? i} className={cx('overflow-hidden rounded-lg border border-slate-200 bg-white', onRowClick && 'cursor-pointer active:bg-slate-50')} onClick={onRowClick ? () => onRowClick(row) : undefined}>
            <div className="border-b border-slate-100 bg-slate-50 px-3 py-2 text-[15px] font-semibold text-slate-900">{cell(head, row)}</div>
            <dl className="grid grid-cols-2 gap-x-3 gap-y-2 px-3 py-2.5 text-sm">
              {fields.map((c) => (
                <div key={c.key || c.label} className={cx('min-w-0', c.wide && 'col-span-2')}>
                  <dt className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{c.label}</dt>
                  <dd className="min-w-0 break-words text-slate-900">{cell(c, row) || <span className="text-slate-400">–</span>}</dd>
                </div>
              ))}
            </dl>
            {actions.some((c) => cell(c, row)) && (
              <div className="flex flex-wrap justify-end gap-2 border-t border-slate-100 px-3 py-2">
                {actions.map((c, n) => <div key={n} className="contents">{cell(c, row)}</div>)}
              </div>
            )}
          </li>
        ))}
      </ul>
    </>
  )
}

export function Pager({ meta, onPage }) {
  if (!meta || meta.last_page <= 1) return null
  return (
    <div className="mt-4 flex items-center justify-between text-sm">
      <Button variant="secondary" size="sm" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>
        ← Previous
      </Button>
      <span className="text-slate-500">
        Page {meta.current_page} of {meta.last_page}
      </span>
      <Button variant="secondary" size="sm" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>
        Next →
      </Button>
    </div>
  )
}

export function DetailRow({ label, children }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</dt>
      <dd className="mt-0.5 font-semibold text-slate-900">{children}</dd>
    </div>
  )
}

export { cx }

/** Small "i" icon that reveals a note on hover, focus or tap. Fixed-positioned so scrolling tables never clip it. */
export function InfoTip({ children, label = 'More information' }) {
  const [pos, setPos] = useState(null)
  const show = (e) => {
    const r = e.currentTarget.getBoundingClientRect()
    const width = Math.min(260, window.innerWidth - 24)
    setPos({ top: r.bottom + 6, left: Math.max(12, Math.min(r.left + r.width / 2 - width / 2, window.innerWidth - width - 12)), width })
  }

  return (
    <span className="inline-flex" onMouseLeave={() => setPos(null)}>
      <button
        type="button"
        aria-label={label}
        onMouseEnter={show}
        onFocus={show}
        onBlur={() => setPos(null)}
        onClick={(e) => (pos ? setPos(null) : show(e))}
        className="relative grid h-5 w-5 place-items-center rounded-full border border-amber-300 before:absolute before:-inset-3 before:content-[''] bg-amber-50 font-serif text-xs font-bold italic leading-none text-amber-700 hover:bg-amber-100"
      >
        i
      </button>
      {pos && (
        <span role="tooltip" style={{ position: 'fixed', top: pos.top, left: pos.left, width: pos.width }} className="z-50 whitespace-normal rounded-md bg-slate-900 px-3 py-2 text-left text-xs font-normal normal-case leading-relaxed tracking-normal text-white shadow-lg">
          {children}
        </span>
      )}
    </span>
  )
}

/** On/off switch. */
export function Switch({ checked, onChange, label, disabled }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      aria-label={label}
      disabled={disabled}
      onClick={() => onChange(!checked)}
      className={cx("relative h-5 w-9 shrink-0 rounded-full transition-colors before:absolute before:-inset-3 before:content-[''] disabled:opacity-50", checked ? 'bg-brand-600' : 'bg-slate-300')}
    >
      <span className={cx('absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition-all', checked ? 'left-[18px]' : 'left-0.5')} />
    </button>
  )
}
