import { Link } from 'react-router-dom'
import { useFetch } from '../../lib/useFetch'
import { Alert, Badge, Button, PageHeader, PageLoader, Table, cx } from '../../components/ui'
import { longDate, money, seatLabel, shortDate, todayYmd } from '../../lib/format'

function Stat({ label, value, warn }) {
  return (
    <div className={cx('rounded-lg border bg-white p-[18px]', warn ? 'border-amber-300' : 'border-slate-200')}>
      <p className={cx('text-xs font-semibold uppercase tracking-wider', warn ? 'text-amber-800' : 'text-slate-500')}>{label}</p>
      <p className={cx('mt-1.5 text-[28px] font-semibold leading-tight tracking-tight', warn ? 'text-amber-800' : 'text-slate-900')}>{value}</p>
    </div>
  )
}

const compact = (n) => (n >= 1000 ? `${(n / 1000).toFixed(1)}k` : String(Math.round(n)))

function CommissionChart({ days, currency }) {
  const max = Math.max(...days.map((d) => d.commission), 1)
  return (
    <section className="rounded-lg border border-slate-200 bg-white p-[22px]">
      <div className="mb-5 flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="font-semibold text-slate-900">Daily online commission, last 14 days</h2>
        <span className="text-[13px] text-slate-500">Paid online bookings only, {currency}</span>
      </div>
      <div className="flex h-44 items-end gap-1.5 border-b border-slate-300 sm:gap-2.5" role="img" aria-label={`Daily online commission for the last 14 days. Today: ${money(days[days.length - 1].commission, currency)}.`}>
        {days.map((d, i) => (
          <div key={d.date} className="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1.5" title={`${shortDate(d.date)}: ${money(d.commission, currency)}`}>
            <span className="hidden font-mono text-[11px] text-slate-700 sm:block">{d.commission > 0 ? compact(d.commission) : ''}</span>
            <div
              className={cx('w-full max-w-11 rounded-t-[3px]', i === days.length - 1 ? 'bg-brand-600' : 'bg-brand-300')}
              style={{ height: `${d.commission > 0 ? Math.max((d.commission / max) * 130, 3) : 0}px` }}
            />
          </div>
        ))}
      </div>
      <div className="mt-2 flex gap-1.5 sm:gap-2.5">
        {days.map((d) => (
          <span key={d.date} className="min-w-0 flex-1 text-center text-xs text-slate-500">{d.date.slice(8)}</span>
        ))}
      </div>
    </section>
  )
}

function RecentBookings() {
  const { data } = useFetch('/admin/bookings')
  const rows = (data || []).slice(0, 6)

  return (
    <section className="overflow-hidden rounded-lg border border-slate-200 bg-white">
      <div className="flex flex-wrap items-center justify-between gap-3 px-[22px] py-[18px]">
        <h2 className="font-semibold text-slate-900">Recent bookings</h2>
        <Link to="/admin/bookings" className="inline-flex min-h-11 items-center text-sm font-medium text-brand-700">View all bookings</Link>
      </div>
      <div className="[&>div]:rounded-none [&>div]:border-x-0 [&>div]:border-b-0">
        <Table
          rows={rows}
          empty="No bookings yet."
          columns={[
            { label: 'Code', render: (b) => <span className="font-mono font-medium text-slate-900">{b.code}</span> },
            { label: 'Passenger', render: (b) => <div><div className="font-medium text-slate-900">{b.passenger_name}</div><div className="font-mono text-xs text-slate-500">{b.phone}</div></div> },
            { label: 'Trip', render: (b) => <div><div className="text-slate-900">{b.trip.origin} to {b.trip.destination}</div><div className="text-xs text-slate-500">{shortDate(b.trip.travel_date)}, {b.trip.departure_time_label}</div></div> },
            { label: 'Seat', render: (b) => <span className={b.trip.seat_selection === false ? '' : 'font-mono'}>{seatLabel(b)}</span> },
            { label: 'Amount', className: 'text-right', render: (b) => <span className="font-medium text-slate-900">{money(b.amount, b.currency)}</span> },
            { label: 'Payment', render: (b) => <Badge>{b.payment?.status || 'none'}</Badge> },
            { label: 'Status', render: (b) => <Badge>{b.status}</Badge> },
          ]}
        />
      </div>
    </section>
  )
}

export default function Dashboard() {
  const { data, loading, error } = useFetch('/admin/dashboard')
  if (loading && !data) return <PageLoader />
  if (error) return <Alert>{error}</Alert>

  return (
    <div className="space-y-6">
      <PageHeader
        title="Dashboard"
        subtitle={longDate(todayYmd())}
        actions={<Link to="/admin/trips"><Button className="min-h-10">Create trip</Button></Link>}
      />
      <div className="!mt-0 grid grid-cols-2 gap-4 lg:grid-cols-5">
        <Stat label="Today’s bookings" value={data.todays_bookings} />
        <Stat label="Today’s commission" value={money(data.todays_commission, data.currency)} />
        <Stat label="Upcoming trips" value={data.upcoming_trips} />
        <Stat label="Total passengers" value={data.total_passengers.toLocaleString()} />
        <Stat label="Pending payments" value={data.pending_payments} warn={data.pending_payments > 0} />
      </div>
      <CommissionChart days={data.daily_commission} currency={data.currency} />
      <RecentBookings />
    </div>
  )
}
