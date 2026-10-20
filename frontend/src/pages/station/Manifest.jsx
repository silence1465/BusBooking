import { useEffect, useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, errorMessage } from '../../lib/api'
import { useFetch } from '../../lib/useFetch'
import { Alert, Card, Input, PageHeader, PageLoader, Table, Tabs, cx } from '../../components/ui'
import DispatchButton from '../../components/DispatchButton'
import { useConfirm } from '../../components/feedback'
import { longDate, timeOnly } from '../../lib/format'
import { useAuth } from '../../context/AuthContext'

const FILTERS = [
  ['all', 'All'],
  ['waiting', 'Not checked in'],
  ['in', 'Checked in'],
  ['station', 'At station'],
  ['noshow', 'No-show'],
]

// Passengers who bought at the station are standing there: no phone number is taken and they are
// never chased, so they sit outside the checked-in / not-checked-in groups.
const isStation = (b) => b.source === 'station'
const isNoShow = (b) => b.status === 'no_show'

const matches = (b, key) => {
  if (key === 'all') return true
  if (key === 'noshow') return isNoShow(b)
  if (key === 'station') return isStation(b)
  if (isStation(b) || isNoShow(b)) return false
  return key === 'in' ? b.arrival.confirmed : !b.arrival.confirmed
}

// 0 = still to arrive (call them), 1 = checked in, 2 = sold at the station, 3 = no-show
const rank = (b) => (isNoShow(b) ? 3 : isStation(b) ? 2 : b.arrival.confirmed ? 1 : 0)

function useNow(ms = 15000) {
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    const t = setInterval(() => setNow(Date.now()), ms)
    return () => clearInterval(t)
  }, [ms])
  return now
}

/** One cell of the counts strip: a single divided bar on phones, separate cards from tablets up. */
function Count({ label, short, value, tone }) {
  return (
    <div className="flex-1 px-1.5 py-3 text-center sm:rounded-lg sm:border sm:border-slate-200 sm:bg-white sm:p-4 sm:text-left">
      <p className="text-[10px] font-semibold uppercase leading-tight tracking-wide text-slate-500 sm:text-xs sm:tracking-wider">
        <span className="sm:hidden">{short || label}</span>
        <span className="hidden sm:inline">{label}</span>
      </p>
      <p className={cx('mt-1 text-xl font-semibold leading-tight tracking-tight sm:text-[28px]', tone || 'text-slate-900')}>{value}</p>
    </div>
  )
}

/** Small status tag. Colour carries the meaning; the text stays short so a row never grows tall. */
function StatusPill({ b }) {
  const base = 'inline-block whitespace-nowrap rounded px-1.5 py-0.5 text-[11px] font-semibold leading-tight'
  if (isNoShow(b)) return <span className={cx(base, 'bg-red-100 text-red-800')}>No-show</span>
  if (isStation(b)) return <span className={cx(base, 'bg-slate-100 text-slate-700')}>Station sale</span>
  if (b.arrival.confirmed) return <span className={cx(base, 'bg-emerald-100 text-emerald-700')}>In {timeOnly(b.arrival.confirmed_at)}</span>
  return <span className={cx(base, 'bg-amber-100 text-amber-900')}>{b.moved ? 'Moved, not in' : 'Not in'}</span>
}

const PHONE_ICON = 'M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z'

export default function Manifest() {
  const { id } = useParams()
  const { user } = useAuth()
  const [q, setQ] = useState('')
  const [filter, setFilter] = useState('all')
  const [stop, setStop] = useState('all')
  const [copied, setCopied] = useState(false)
  const [notice, setNotice] = useState('')
  const [actionError, setActionError] = useState('')
  const now = useNow()
  const confirm = useConfirm()
  const { data, loading, error, reload } = useFetch(`/station/trips/${id}/manifest`)
  const back = user?.role === 'admin' ? '/admin/trips' : '/station/trips'

  const bookings = useMemo(() => data?.bookings || [], [data])

  // Counts are in seats (a booking can hold several): booked = checked in + not yet + sold at station.
  const stats = useMemo(() => {
    const sum = (list) => list.reduce((n, b) => n + b.seats.length, 0)
    const online = bookings.filter((b) => !isStation(b) && !isNoShow(b))
    return {
      total: sum(bookings.filter((b) => !isNoShow(b))),
      station: sum(bookings.filter(isStation)),
      arrived: sum(online.filter((b) => b.arrival.confirmed)),
      waiting: sum(online.filter((b) => !b.arrival.confirmed)),
      noShow: sum(bookings.filter(isNoShow)),
    }
  }, [bookings])

  const rows = useMemo(() => {
    const needle = q.trim().toLowerCase()
    const digits = needle.replace(/\D/g, '')
    return bookings
      .filter((b) => matches(b, filter))
      .filter(
        (b) =>
          !needle ||
          b.passenger_name.toLowerCase().includes(needle) ||
          b.code.toLowerCase().includes(needle) ||
          (digits.length >= 3 && (b.phone || '').replace(/\D/g, '').includes(digits)),
      )
      // Online passengers still to arrive come first: those are the ones to call.
      .sort((a, b) => rank(a) - rank(b) || Math.min(...a.seats) - Math.min(...b.seats))
  }, [bookings, q, filter])

  if (loading && !data) return <PageLoader />
  if (error && !data) return <Alert>{error}</Alert>

  const { trip } = data
  const fillAndGo = trip.fill_and_go
  const boarding = trip.status === 'boarding'
  const queued = trip.status === 'queued'
  const departed = trip.status === 'departed'
  const isDriver = user?.role === 'driver'
  const waitingPhones = [...new Set(bookings.filter((b) => !isStation(b) && !isNoShow(b) && !b.arrival.confirmed && b.phone).map((b) => b.phone))]

  // Main-station passengers the full vehicle is waiting for.
  const atStationWaiting = bookings.filter((b) => b.pickup_stop?.is_main && !b.arrival.confirmed && !isStation(b) && !isNoShow(b))
  const graceEnds = trip.full_at ? new Date(trip.full_at).getTime() + trip.grace_minutes * 60000 : null
  const minutesLeft = graceEnds ? Math.ceil((graceEnds - now) / 60000) : null

  const copyWaiting = async () => {
    try {
      await navigator.clipboard.writeText(waitingPhones.join('\n'))
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      window.prompt('Copy these phone numbers:', waitingPhones.join(', '))
    }
  }

  // What the no-show button does depends on where the passenger boards, and it is explained before it happens.
  const noShowFor = (b) => {
    if (!fillAndGo || isStation(b) || isNoShow(b) || b.arrival.confirmed) return null
    const main = b.pickup_stop?.is_main
    if (main && boarding) {
      const again = b.moved
      return {
        label: again ? 'No-show (forfeit)' : 'No-show (move to next vehicle)',
        run: () => markNoShow(b, again
          ? `${b.passenger_name} already moved once. Marking no-show forfeits the ticket and frees the seat.`
          : `${b.passenger_name} did not turn up. Their ticket moves to the next vehicle and the seat goes back on sale.`),
      }
    }
    if (!main && departed) {
      return {
        label: 'No-show (forfeit)',
        run: () => markNoShow(b, `${b.passenger_name} did not board at ${b.pickup_point}. The ticket is forfeited and the seat stays empty.`),
      }
    }
    return null
  }

  const markNoShow = async (b, warning) => {
    if (!(await confirm({ title: 'Mark as no-show?', body: warning, confirmLabel: 'Mark no-show', danger: true }))) return
    setActionError('')
    setNotice('')
    try {
      const res = await api.post(`/station/bookings/${encodeURIComponent(b.code)}/no-show`)
      setNotice(res.data.data.outcome === 'moved' ? `${b.passenger_name}’s ticket moved to the next vehicle.` : `${b.passenger_name}’s ticket was forfeited.`)
      reload()
    } catch (err) {
      setActionError(errorMessage(err))
    }
  }

  // Fill-and-go vehicles: one section per stop, in the order the vehicle visits them.
  const groups = fillAndGo
    ? Object.values(
        rows.reduce((acc, b) => {
          const key = b.pickup_stop ? b.pickup_stop.order : 0
          const label = b.pickup_stop ? (b.pickup_stop.is_main ? `${b.pickup_point} (main station)` : b.pickup_point) : 'Sold at the station'
          acc[key] = acc[key] || { key, label, order: key, rows: [] }
          acc[key].rows.push(b)
          return acc
        }, {}),
      ).sort((a, b) => a.order - b.order)
    : [{ key: 'all', label: null, rows }]

  // One tab per stop (in visiting order); the table below shows only that stop's passengers.
  const stopTabs = fillAndGo ? [{ key: 'all', label: 'All stops', count: rows.length }, ...groups.map((g) => ({ key: String(g.key), label: g.label, count: g.rows.length }))] : []
  const activeStop = stopTabs.some((t) => t.key === stop) ? stop : 'all'
  const shown = fillAndGo && activeStop !== 'all' ? groups.find((g) => String(g.key) === activeStop)?.rows || [] : groups.flatMap((g) => g.rows)

  // Open-seating vehicles (Express, Voxy) have no seat numbers to show: only how many seats each booking holds.
  const openSeating = trip.seat_selection === false
  const columns = [
    { key: 'seat', label: openSeating ? 'Seats' : 'Seat', className: 'w-16', render: (b) => <span className="inline-grid h-8 min-w-8 place-items-center rounded-md border border-slate-200 bg-white px-1.5 font-mono text-sm font-semibold text-slate-900">{openSeating ? `×${b.seat_total ?? b.seats.length}` : b.seats.join(', ')}</span> },
    {
      key: 'passenger',
      label: 'Passenger',
      primary: true,
      render: (b) => (
        <div className="leading-tight">
          <p className="font-semibold text-slate-900">{isStation(b) ? 'Sold at station' : b.passenger_name}</p>
          <p className="text-xs text-slate-500">
            <span className="font-mono">{b.code}</span>
            {b.payment?.status !== 'paid' && <span className="ml-1 font-semibold text-amber-800">· {b.payment?.status || 'unpaid'}</span>}
          </p>
        </div>
      ),
    },
    ...(fillAndGo ? [{ key: 'stop', label: 'Stop', render: (b) => b.pickup_point || 'Station' }] : []),
    ...(bookings.some((b) => b.dropoff_point) ? [{ key: 'dropoff', label: 'Destination', render: (b) => b.dropoff_point || <span className="text-slate-400">–</span> }] : []),
    { key: 'status', label: 'Status', render: (b) => <StatusPill b={b} /> },
    {
      key: 'phone',
      label: 'Phone',
      render: (b) =>
        !isStation(b) && !isNoShow(b) && b.phone ? (
          <a
            href={`tel:${b.phone}`}
            aria-label={`Call ${b.passenger_name} on ${b.phone}`}
            className={cx('inline-flex min-h-8 items-center gap-1.5 rounded-md px-2 font-mono text-xs font-semibold', b.arrival.confirmed ? 'border border-slate-300 text-slate-600 hover:bg-slate-50' : 'bg-brand-600 text-white hover:bg-brand-700')}
          >
            <svg viewBox="0 0 24 24" className="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d={PHONE_ICON} /></svg>
            {b.phone}
          </a>
        ) : (
          <span className="text-slate-400">–</span>
        ),
    },
    ...(fillAndGo
      ? [{
          key: 'action',
          render: (b) => {
            const n = noShowFor(b)
            return n ? <button onClick={n.run} className="min-h-8 whitespace-nowrap rounded-md border border-red-300 px-2.5 text-xs font-semibold text-red-700 hover:bg-red-50">{n.label}</button> : null
          },
        }]
      : []),
  ]

  return (
    <div>
      <Link to={back} className="inline-flex min-h-11 items-center text-sm font-medium text-slate-500 hover:text-slate-900">Back to trips</Link>
      <PageHeader
        title={trip.route}
        subtitle={`${longDate(trip.travel_date)} · ${trip.departure_time_label} · ${trip.bus}${boarding ? ' · boarding' : queued ? ' · waiting in the queue' : departed ? ' · left the station' : ''}`}
        actions={
          <>
            <button onClick={reload} className="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Refresh</button>
            {!isDriver && (trip.status === 'scheduled' || boarding || queued) && (
              <Link to={`/station/trips/${trip.id}/sell`} className="rounded-md bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">Sell seats at station</Link>
            )}
          </>
        }
      />

      {fillAndGo && boarding && !isDriver && (
        <div className="mb-5 space-y-3">
          {trip.full_at && (
            <Alert type="warning" title="The vehicle is full">
              {atStationWaiting.length > 0
                ? `Waiting for ${atStationWaiting.length} passenger${atStationWaiting.length === 1 ? '' : 's'} booked to board at the station. ${minutesLeft > 0 ? `About ${minutesLeft} minute${minutesLeft === 1 ? '' : 's'} left` : 'The waiting time is over'}: call them, then mark no-show for anyone who has not come.`
                : 'Everyone booked at the station has checked in. You can dispatch.'}
            </Alert>
          )}
          <DispatchButton tripId={trip.id} onDone={reload} className="max-w-xs" />
        </div>
      )}
      {fillAndGo && departed && <Alert type="info" className="mb-5" title="On the road">Check passengers in as they board at each stop. If someone is not at their stop after a few minutes, mark them no-show and carry on.</Alert>}
      {notice && <Alert type="success" className="mb-4">{notice}</Alert>}
      <Alert className="mb-4">{actionError}</Alert>

      <div className={cx('mb-5 flex divide-x divide-slate-200 overflow-hidden rounded-lg border border-slate-200 bg-white sm:grid sm:gap-4 sm:divide-x-0 sm:overflow-visible sm:rounded-none sm:border-0 sm:bg-transparent', stats.noShow ? 'sm:grid-cols-5' : 'sm:grid-cols-4')}>
        <Count label="Booked seats" short="Booked" value={stats.total} />
        <Count label="Checked in" short="In" value={stats.arrived} tone="text-emerald-700" />
        <Count label="Not yet" value={stats.waiting} tone={stats.waiting ? 'text-amber-800' : undefined} />
        <Count label="Sold at station" short="Station" value={stats.station} />
        {stats.noShow > 0 && <Count label="No-show" value={stats.noShow} tone="text-red-700" />}
      </div>

      <Card className="mb-4 space-y-4">
        <div className="flex flex-wrap items-end gap-3">
          <div className="min-w-48 flex-1">
            <Input label="Search passengers" placeholder="Name, phone or booking code" value={q} onChange={(e) => setQ(e.target.value)} />
          </div>
          {waitingPhones.length > 0 && (
            <button onClick={copyWaiting} className="min-h-11 rounded-md border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">
              {copied ? 'Copied' : `Copy ${waitingPhones.length} number${waitingPhones.length === 1 ? '' : 's'} not checked in`}
            </button>
          )}
        </div>
        <div className="flex gap-1 overflow-x-auto rounded-lg bg-slate-100 p-1 text-sm font-semibold" role="tablist">
          {FILTERS.filter(([key]) => key !== 'noshow' || stats.noShow > 0).map(([key, label]) => (
            <button
              key={key}
              role="tab"
              aria-selected={filter === key}
              onClick={() => setFilter(key)}
              className={cx('min-h-10 shrink-0 whitespace-nowrap rounded-md px-3 sm:flex-1', filter === key ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-600 hover:text-slate-900')}
            >
              {label} <span className="font-normal text-slate-500">({bookings.filter((b) => matches(b, key)).length})</span>
            </button>
          ))}
        </div>
        {fillAndGo && stopTabs.length > 2 && <Tabs tabs={stopTabs} value={activeStop} onChange={setStop} />}
        <Table columns={columns} rows={shown} empty={bookings.length === 0 ? 'No passengers booked on this trip yet.' : 'No passengers match.'} />
      </Card>

    </div>
  )
}
