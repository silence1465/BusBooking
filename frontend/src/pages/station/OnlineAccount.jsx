import { useState } from 'react'
import { useFetch } from '../../lib/useFetch'
import { Alert, Card, Modal, PageHeader, PageLoader, Table, cx } from '../../components/ui'
import { moneyExact, shortDate, timeOnly, todayYmd } from '../../lib/format'

const ymd = (d) => {
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

const PRESETS = [
  ['Today', () => [todayYmd(), todayYmd()]],
  ['Last 7 days', () => { const d = new Date(); d.setDate(d.getDate() - 6); return [ymd(d), todayYmd()] }],
  ['This month', () => { const d = new Date(); d.setDate(1); return [ymd(d), todayYmd()] }],
]

function Stat({ label, value, tone, hint, className }) {
  return (
    <div className={cx('rounded-lg border border-slate-200 bg-white px-3 py-2.5 sm:p-4', className)}>
      <p className="truncate text-[11px] font-semibold uppercase tracking-wider text-slate-500 sm:text-xs">{label}</p>
      <p className={cx('mt-0.5 text-xl font-semibold leading-tight tracking-tight sm:text-[26px]', tone || 'text-slate-900')}>{value}</p>
      {hint && <p className="mt-0.5 text-xs text-slate-500">{hint}</p>}
    </div>
  )
}

/** What was sold online (Paystack) on each route. Counter sales are not here. Station masters see their own routes. */
export default function OnlineAccount() {
  const [range, setRange] = useState(() => PRESETS[0][1]())
  const [from, to] = range
  const { data, loading, error } = useFetch('/station/online-account', { from, to })
  const currency = data?.currency || 'GHS'
  const full = !!data?.show_commission // admins see the commission; station masters only what the station gets
  const [openRoute, setOpenRoute] = useState(null) // route whose cars are shown
  const route = data?.rows.find((r) => r.route_id === openRoute)

  const columns = [
    { label: 'Route', key: 'route', primary: true, render: (r) => <span className="font-semibold text-slate-900">{r.route}</span> },
    { label: 'Bookings', key: 'bookings', className: 'text-right' },
    { label: 'Seats', key: 'seats', className: 'text-right' },
    ...(full
      ? [
          { label: 'Online sales', className: 'text-right', render: (r) => <span className="font-semibold text-slate-900">{moneyExact(r.net, currency)}</span> },
          { label: 'Commission', className: 'text-right', render: (r) => <span className="font-semibold text-brand-700">{moneyExact(r.commission, currency)}</span> },
        ]
      : []),
    { label: 'Station gets', className: 'text-right', render: (r) => <span className="font-semibold text-emerald-700">{moneyExact(r.station, currency)}</span> },
    { label: 'Refunded', className: 'text-right', render: (r) => <span className={r.refunded > 0 ? 'text-red-700' : 'text-slate-400'}>{moneyExact(r.refunded, currency)}</span> },
    { render: () => <span className="text-xs font-semibold text-brand-700">Cars ›</span> },
  ]

  return (
    <div>
      <PageHeader title="Online account" subtitle={full ? 'Money paid online by route, split into the online commission and what the station gets. Counter sales are not included.' : 'What the station gets from tickets paid online, by route. Counter sales are not included.'} />

      <Card className="no-print mb-4 grid grid-cols-2 items-end gap-3 sm:flex sm:flex-wrap">
        <div className="col-span-2 grid grid-cols-3 gap-1 rounded-lg bg-slate-100 p-1 text-sm font-semibold sm:flex" role="tablist">
          {PRESETS.map(([label, make]) => {
            const [a, b] = make()
            const active = a === from && b === to
            return (
              <button key={label} role="tab" aria-selected={active} onClick={() => setRange([a, b])} className={cx('min-h-9 whitespace-nowrap rounded-md px-1 text-[13px] sm:px-3 sm:text-sm', active ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-600 hover:text-slate-900')}>
                {label}
              </button>
            )
          })}
        </div>
        {[['From', from, (v) => setRange([v, v > to ? v : to])], ['To', to, (v) => setRange([v < from ? v : from, v])]].map(([label, value, set]) => (
          <label key={label} className="col-span-2 block min-[380px]:col-span-1">
            <span className="mb-1 block text-xs font-semibold uppercase tracking-wider text-slate-500">{label}</span>
            <input type="date" max={todayYmd()} value={value} onChange={(e) => e.target.value && set(e.target.value)} className="block w-full min-w-0 rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm text-slate-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30" />
          </label>
        ))}
      </Card>

      <Alert className="mb-4">{error}</Alert>
      {loading && !data ? (
        <PageLoader />
      ) : (
        data && (
          <>
            <div className={cx('mb-5 grid grid-cols-2 gap-2 sm:gap-4', full ? 'lg:grid-cols-5' : 'lg:grid-cols-3')}>
              {full && <Stat label="Online sales" value={moneyExact(data.totals.net, currency)} hint="Paid online, less refunds" className="col-span-2 lg:col-span-1" />}
              {full && <Stat label="Commission" value={moneyExact(data.totals.commission, currency)} tone="text-brand-700" hint="Goes to online" />}
              <Stat label="Station gets" value={moneyExact(data.totals.station, currency)} tone="text-emerald-700" hint={full ? 'Sales less commission' : 'From tickets paid online'} className={full ? undefined : 'col-span-2 lg:col-span-1'} />
              <Stat label="Refunded" value={moneyExact(data.totals.refunded, currency)} tone={data.totals.refunded > 0 ? 'text-red-700' : undefined} />
              <Stat label="Seats sold" value={data.totals.seats} hint={`${data.totals.bookings} booking${data.totals.bookings === 1 ? '' : 's'} · ${data.totals.routes} route${data.totals.routes === 1 ? '' : 's'}`} />
            </div>
            <Table columns={columns} rows={data.rows} rowKey="route_id" empty="No online sales in this period." onRowClick={(r) => setOpenRoute(r.route_id)} />
            {data.rows.length > 0 && <p className="mt-2 text-xs text-slate-500">Select a route to see the sales of each car.</p>}
          </>
        )
      )}

      <Modal open={!!route} onClose={() => setOpenRoute(null)} title={route?.route}>
        {route && (
          <>
            <p className="mb-3 text-sm text-slate-500">{route.vehicles.length} car{route.vehicles.length === 1 ? '' : 's'} {full && <> · sales {moneyExact(route.net, currency)} · commission <b className="text-brand-700">{moneyExact(route.commission, currency)}</b></>} · station <b className="text-emerald-700">{moneyExact(route.station, currency)}</b></p>
            <ul className="divide-y divide-slate-100 overflow-hidden rounded-lg border border-slate-200">
              {route.vehicles.map((v) => (
                <li key={v.trip_id} className="flex items-center justify-between gap-3 px-3 py-2.5 odd:bg-white even:bg-slate-50">
                  <div className="min-w-0 leading-tight">
                    <p className="truncate"><span className="rounded bg-amber-100 px-1.5 py-0.5 font-mono text-[13px] font-semibold text-amber-900">{v.registration}</span> <span className="text-sm text-slate-500">{v.bus}</span></p>
                    <p className="text-xs text-slate-500">
                      {shortDate(v.travel_date)} · {v.departure_time || (v.departed_at ? 'left ' + timeOnly(v.departed_at) : 'leaves when full')} · {v.bookings} booking{v.bookings === 1 ? '' : 's'}, {v.seats} seat{v.seats === 1 ? '' : 's'}
                    </p>
                  </div>
                  <div className="shrink-0 text-right leading-tight">
                    <p className="font-semibold text-emerald-700" title="Station gets">{moneyExact(v.station, currency)}</p>
                    {full && <p className="text-xs text-slate-500">of {moneyExact(v.net, currency)} · <span className="font-semibold text-brand-700">{moneyExact(v.commission, currency)} commission</span></p>}
                    {v.refunded > 0 && <p className="text-xs text-red-700">{moneyExact(v.refunded, currency)} refunded</p>}
                  </div>
                </li>
              ))}
            </ul>
          </>
        )}
      </Modal>
    </div>
  )
}
