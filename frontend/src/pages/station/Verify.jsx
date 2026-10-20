import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api, errorMessage } from '../../lib/api'
import { useFetch } from '../../lib/useFetch'
import { Alert, Badge, Button, Card, Input, OpenSeatingBadge, SeatBadges, cx } from '../../components/ui'
import QrScanner from '../../components/QrScanner'
import { longDate, money, dateTime, seatLabel, timeOnly } from '../../lib/format'

function Detail({ label, children, mono, tone }) {
  return (
    <div>
      <dt className="text-xs font-semibold uppercase tracking-wider text-slate-500">{label}</dt>
      <dd className={cx('mt-1 text-lg font-semibold', mono && 'font-mono', tone || 'text-slate-900')}>{children}</dd>
    </div>
  )
}

const ICONS = {
  check: 'M12 21a9 9 0 100-18 9 9 0 000 18zM8 12l3 3 5-6',
  cross: 'M12 21a9 9 0 100-18 9 9 0 000 18zM9 9l6 6M15 9l-6 6',
  warn: 'M12 3l10 18H2zM12 10v5M12 18h.01',
  info: 'M12 21a9 9 0 100-18 9 9 0 000 18zM12 11v6M12 7.5h.01',
}

// One colour per outcome so the result is readable from a distance, plus an icon and words.
const PANEL = {
  valid: { title: 'Booking verified', sub: 'Paid and confirmed. Passenger has not yet been checked in.', icon: 'check', band: 'bg-emerald-600', border: 'border-emerald-600' },
  invalid: { title: 'Invalid booking', sub: 'Booking code not found. Check the code and try again.', icon: 'cross', band: 'bg-red-700', border: 'border-red-700' },
  cancelled: { title: 'Booking cancelled', sub: 'This booking is no longer valid. Do not allow boarding.', icon: 'cross', band: 'bg-red-700', border: 'border-red-700' },
  unpaid: { title: 'Payment not confirmed', sub: 'Do not allow boarding until payment is confirmed.', icon: 'warn', band: 'bg-amber-700', border: 'border-amber-700' },
  already_confirmed: { title: 'Already confirmed', sub: 'This passenger has already been checked in.', icon: 'info', band: 'bg-brand-700', border: 'border-brand-700' },
  not_assigned: { title: 'Valid ticket, different route', sub: 'This ticket is not for a route you handle. Send the passenger to the right station.', icon: 'warn', band: 'bg-amber-700', border: 'border-amber-700' },
  wrong_trip: { title: 'Wrong trip', sub: 'Valid ticket, but not for the trip you are checking in. Do not board; send the passenger to the right bus.', icon: 'warn', band: 'bg-amber-700', border: 'border-amber-700' },
  no_show: { title: 'Ticket forfeited', sub: 'This passenger did not board at their pickup point. Do not let them board on this ticket.', icon: 'cross', band: 'bg-red-700', border: 'border-red-700' },
  completed: { title: 'Trip completed', sub: 'This trip has already been completed.', icon: 'info', band: 'bg-slate-600', border: 'border-slate-600' },
}

function Result({ result, onConfirm, confirming, confirmError }) {
  const p = PANEL[result.status]
  const b = result.booking
  const paid = b?.payment?.status === 'paid'
  const t = result.ticket_trip

  return (
    <div className={cx('overflow-hidden rounded-lg border-[1.5px] bg-white', p.border)} role="status">
      <div className={cx('flex items-center gap-3 px-6 py-4 text-white', p.band)}>
        <svg viewBox="0 0 24 24" className="h-7 w-7 shrink-0" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
          <path d={ICONS[p.icon]} />
        </svg>
        <div>
          <h2 className="text-xl font-semibold tracking-wide">{p.title}</h2>
          <p className="text-[13px] text-white/90">{p.sub}</p>
        </div>
      </div>

      {result.warning && <p className="border-b border-amber-200 bg-amber-50 px-6 py-3 text-sm font-semibold text-amber-900">{result.warning}</p>}

      {t && (
        <div className="border-b border-slate-200 bg-slate-50 px-6 py-4">
          <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">This ticket is for</p>
          <p className="mt-1 text-lg font-semibold text-slate-900">{t.origin} to {t.destination}</p>
          <p className="text-slate-700">{longDate(t.travel_date)}, {t.departure_time_label}</p>
          {result.status === 'not_assigned' && t.ticket_state !== 'valid' && (
            <p className="mt-1 text-sm font-medium text-red-700">The ticket itself is also {t.ticket_state === 'unpaid' ? 'unpaid' : t.ticket_state === 'already_confirmed' ? 'already used' : t.ticket_state}.</p>
          )}
        </div>
      )}

      {b && result.status === 'already_confirmed' && (
        <dl className="grid gap-x-6 gap-y-5 p-6 sm:grid-cols-2">
          <Detail label="Passenger">{b.passenger_name}</Detail>
          <Detail label="Booking code" mono>{b.code}</Detail>
          <Detail label="Confirmed at">{timeOnly(b.arrival.confirmed_at)}</Detail>
          <Detail label="Confirmed by">{b.arrival.confirmed_by || 'Unknown'}</Detail>
        </dl>
      )}

      {b && result.status !== 'already_confirmed' && (
        <dl className="grid grid-cols-2 gap-x-6 gap-y-5 p-6 lg:grid-cols-3">
          <Detail label="Passenger">{b.passenger_name}</Detail>
          <Detail label="Phone" mono>{b.phone}</Detail>
          <Detail label={b.seats.length > 1 || b.trip.seat_selection === false ? 'Seats' : 'Seat'} mono={b.trip.seat_selection !== false}>
            {b.trip.seat_selection === false ? <OpenSeatingBadge>{seatLabel(b)}</OpenSeatingBadge> : <SeatBadges seats={b.seats} />}
          </Detail>
          <Detail label="Route">{b.trip.origin} to {b.trip.destination}</Detail>
          <Detail label="Travel date">{longDate(b.trip.travel_date)}</Detail>
          <Detail label="Departure">{b.trip.departure_time_label}</Detail>
          <Detail label="Pickup">{b.pickup_point}</Detail>
          {b.dropoff_point && <Detail label="Destination">{b.dropoff_point}</Detail>}
          <Detail label="Payment" tone={paid ? 'text-emerald-700' : 'text-amber-800'}>
            <span className="capitalize">{b.payment?.status || 'none'}</span>, {money(b.amount, b.currency)}
          </Detail>
          <Detail label="Booking status" tone={b.status === 'confirmed' ? 'text-emerald-700' : b.status === 'cancelled' ? 'text-red-700' : 'text-slate-900'}>
            <span className="capitalize">{b.status}</span>
          </Detail>
        </dl>
      )}

      {result.status === 'valid' && (
        <div className="border-t border-slate-200 bg-slate-50 px-6 py-5">
          {confirmError && <Alert className="mb-4">{confirmError}</Alert>}
          <div className="flex flex-wrap items-center justify-between gap-3">
            <span className="text-sm text-slate-700">Check the passenger’s name before confirming.</span>
            <Button variant="success" className="min-h-[52px] w-full px-7 text-base sm:w-auto" loading={confirming} onClick={onConfirm}>
              Confirm passenger arrival
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}

function useCodeTab(tripId) {
  const [code, setCode] = useState('')
  const [result, setResult] = useState(null)
  const [busy, setBusy] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [error, setError] = useState('')
  const [confirmError, setConfirmError] = useState('')
  const [scanning, setScanning] = useState(false)

  // Works as a form submit handler, or called with a code string (from the scanner / after confirming).
  const verify = async (arg) => {
    arg?.preventDefault?.()
    const value = typeof arg === 'string' ? arg : code
    setBusy(true)
    setError('')
    setConfirmError('')
    try {
      const res = await api.post('/station/verify', { code: value, trip_id: tripId || null })
      setResult(res.data.data)
    } catch (err) {
      setResult(null)
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  const confirm = async () => {
    setConfirming(true)
    setConfirmError('')
    try {
      await api.post(`/station/bookings/${encodeURIComponent(result.booking.code)}/confirm-arrival`, { trip_id: tripId || null })
      await verify(result.booking.code) // show the authoritative state from the server
    } catch (err) {
      setConfirmError(errorMessage(err))
      verify(result.booking.code)
    } finally {
      setConfirming(false)
    }
  }

  const onScan = (scanned) => {
    setScanning(false)
    setCode(scanned)
    verify(scanned)
  }

  return {
    form: scanning ? (
      <QrScanner onCode={onScan} onClose={() => setScanning(false)} />
    ) : (
      <div className="space-y-3">
      <Button type="button" variant="gold" size="lg" className="w-full" onClick={() => { setResult(null); setScanning(true) }}>
        Scan ticket QR code
      </Button>
      <form onSubmit={verify} className="flex flex-col gap-3 sm:flex-row sm:items-end">
        <div className="flex-1">
          <Input
            label="Booking code"
            placeholder="BUS-XXXXXX"
            value={code}
            onChange={(e) => setCode(e.target.value.toUpperCase())}
            autoCapitalize="characters"
            autoComplete="off"
            autoFocus
            className="h-14 border-[1.5px] border-slate-900 font-mono text-2xl font-semibold tracking-widest"
          />
        </div>
        <Button type="submit" variant="dark" className="h-14 px-10 text-base" loading={busy} disabled={!code.trim()}>Verify</Button>
      </form>
      </div>
    ),
    below: (
      <>
        <Alert>{error}</Alert>
        {result && <Result result={result} onConfirm={confirm} confirming={confirming} confirmError={confirmError} />}
        {result && !scanning && (
          <Button variant="secondary" size="lg" className="w-full" onClick={() => { setResult(null); setCode(''); setScanning(true) }}>
            Scan next ticket
          </Button>
        )}
      </>
    ),
  }
}

function usePhoneTab() {
  const [phone, setPhone] = useState('')
  const [results, setResults] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  const search = async (e) => {
    e.preventDefault()
    setBusy(true)
    setError('')
    try {
      const res = await api.get('/station/search', { params: { phone } })
      setResults(res.data.data)
    } catch (err) {
      setResults(null)
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return {
    form: (
      <form onSubmit={search} className="flex flex-col gap-3 sm:flex-row sm:items-end">
        <div className="flex-1">
          <Input label="Passenger phone number" type="tel" inputMode="tel" placeholder="024XXXXXXX" value={phone} onChange={(e) => setPhone(e.target.value)} className="h-14 border-[1.5px] border-slate-900 font-mono text-2xl font-semibold tracking-wider" />
        </div>
        <Button type="submit" variant="dark" className="h-14 px-10 text-base" loading={busy} disabled={phone.replace(/\D/g, '').length < 7}>Search</Button>
      </form>
    ),
    below: (
      <>
        <Alert>{error}</Alert>
        {results && results.length === 0 && <Alert type="info">No upcoming bookings found for this number.</Alert>}
        {results?.map((b) => (
          <Card key={b.id} className="space-y-1.5">
            <div className="flex items-center justify-between">
              <p className="font-mono text-lg font-semibold text-slate-900">{b.code}</p>
              <Badge>{b.arrival.confirmed ? 'arrived' : b.status}</Badge>
            </div>
            <p className="font-semibold text-slate-900">{b.passenger_name} <span className="font-mono font-medium text-slate-500">{b.phone}</span></p>
            <p className="text-sm text-slate-600">{b.trip.origin} to {b.trip.destination}, {longDate(b.trip.travel_date)}, {b.trip.departure_time_label}</p>
            <p className="text-sm text-slate-600">Pickup {b.pickup_point}. Seat: {seatLabel(b)}. Payment <b className="capitalize">{b.payment?.status}</b>.</p>
            {b.arrival.confirmed && <p className="text-xs text-brand-700">Confirmed {dateTime(b.arrival.confirmed_at)} by {b.arrival.confirmed_by}</p>}
          </Card>
        ))}
      </>
    ),
  }
}

function TodaySummary() {
  const { data } = useFetch('/station/trips')
  return (
    <div className="rounded-lg border border-slate-200 bg-white p-3">
      <div className="flex items-baseline justify-between px-2 pb-3 pt-1.5">
        <h2 className="font-semibold text-slate-900">Today’s trips</h2>
        <Link to="/station/trips" className="inline-flex min-h-11 items-center text-sm font-medium text-brand-700">View all</Link>
      </div>
      {data?.length === 0 && <p className="px-2 pb-2 text-sm text-slate-500">No trips scheduled for today.</p>}
      {/* Each trip is its own card inside this one */}
      <div className="space-y-2.5">
        {data?.slice(0, 4).map((t) => {
          const pct = t.booked_seats ? Math.round((t.confirmed_arrivals / t.booked_seats) * 100) : 0
          return (
            <Link key={t.id} to={`/station/trips/${t.id}`} className="block space-y-1.5 rounded-lg border border-slate-200 bg-slate-50/60 p-3 transition hover:border-brand-300 hover:bg-white">
              <div className="flex items-baseline justify-between gap-3">
                <span className="truncate text-sm font-semibold text-slate-900">{t.route}</span>
                <span className="shrink-0 text-sm font-semibold text-brand-700">{t.departure_time_label}</span>
              </div>
              <p className="mt-0.5 truncate text-xs text-slate-500">{t.bus} <span className="ml-1 rounded bg-amber-100 px-1.5 py-0.5 font-mono font-semibold text-amber-900">{t.bus_registration}</span></p>
              <div className="h-1.5 overflow-hidden rounded-full bg-slate-200">
                <div className="h-full rounded-full bg-brand-600" style={{ width: `${pct}%` }} />
              </div>
              <div className="flex justify-between text-xs text-slate-600">
                <span>{t.confirmed_arrivals} of {t.booked_seats} arrived</span>
                <span>{t.total_seats} seats</span>
              </div>
            </Link>
          )
        })}
      </div>
    </div>
  )
}

const GUIDE = [
  ['bg-emerald-600', 'Verified: confirm arrival'],
  ['bg-brand-700', 'Already confirmed: do not board twice'],
  ['bg-amber-700', 'Payment not confirmed: no boarding'],
  ['bg-red-700', 'Invalid or cancelled: refuse'],
]

const TRIP_KEY = 'bb_station_trip'

export default function Verify() {
  const [tab, setTab] = useState('code')
  const { data: todays } = useFetch('/station/trips')
  const { data: mine } = useFetch('/station/my-vehicle')
  const [tripId, setTripId] = useState(() => {
    try { return localStorage.getItem(TRIP_KEY) || '' } catch { return '' }
  })
  const pickTrip = (v) => {
    setTripId(v)
    try { localStorage.setItem(TRIP_KEY, v) } catch { /* not remembered */ }
  }
  // A remembered trip that is not in today's list (yesterday's, or no longer assigned) is ignored.
  const picked = todays?.some((x) => String(x.id) === tripId) ? tripId : ''
  // A driver linked to a bus does not choose: it is always their bus's current trip.
  const activeTripId = mine?.linked ? (mine.trip ? String(mine.trip.id) : '') : picked
  // Both tabs stay mounted in state so switching does not lose a result.
  const codeTab = useCodeTab(activeTripId ? Number(activeTripId) : null)
  const phoneTab = usePhoneTab()
  const active = tab === 'code' ? codeTab : phoneTab

  return (
    <div className="flex flex-col gap-8 lg:flex-row lg:items-start">
      <section className="min-w-0 flex-1 space-y-5">
        <Card className="sm:p-6">
          <h1 className="text-[22px] font-semibold tracking-tight text-slate-900">Verify booking</h1>
          <p className="mb-4 mt-1 text-sm text-slate-500">Scan the ticket or type the code. No need to choose a vehicle first.</p>

          <div className="mb-5 flex border-b border-slate-200 text-sm" role="tablist">
            {[['code', 'Scan or code'], ['phone', 'Phone number']].map(([k, label]) => (
              <button
                key={k}
                role="tab"
                aria-selected={tab === k}
                onClick={() => setTab(k)}
                className={cx('-mb-px min-h-11 border-b-2 px-4', tab === k ? 'border-brand-600 font-semibold text-slate-900' : 'border-transparent font-medium text-slate-500 hover:text-slate-900')}
              >
                {label}
              </button>
            ))}
          </div>

          {active.form}

          {/* Optional: only matters when several vehicles are boarding and tickets for the others must be refused. */}
          <div className="mt-5 border-t border-slate-100 pt-4">
            {mine?.linked ? (
              <div className={'rounded-lg border px-4 py-3 text-sm ' + (mine.trip ? 'border-brand-200 bg-brand-50 text-brand-700' : 'border-amber-200 bg-amber-50 text-amber-900')}>
                {mine.trip ? (
                  <>
                    <p className="text-xs font-semibold uppercase tracking-wider opacity-80">Your vehicle</p>
                    <p className="font-semibold">{mine.trip.route} · {mine.bus.registration_number}</p>
                    <p className="text-[13px]">{mine.trip.status === 'departed' ? 'On the road' : 'Boarding at the station'}. Tickets for other vehicles are flagged.</p>
                  </>
                ) : (
                  <p><b>{mine.bus.name} ({mine.bus.registration_number})</b> has no trip today yet. Ask the station master to open it for boarding.</p>
                )}
              </div>
            ) : (
              <details open={!!activeTripId} className="group text-sm">
                <summary className="flex min-h-10 cursor-pointer list-none items-center justify-between gap-3 text-slate-600 hover:text-slate-900">
                  <span>
                    {activeTripId
                      ? <>Only accepting tickets for <b className="text-slate-900">{todays?.find((x) => String(x.id) === activeTripId)?.bus}</b></>
                      : 'Accepting tickets for any of my vehicles'}
                  </span>
                  <span className="shrink-0 font-semibold text-brand-700 group-open:hidden">Change</span>
                </summary>
                <label className="mt-2 block">
                  <span className="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">Only accept tickets for this vehicle (optional)</span>
                  <select
                    value={activeTripId}
                    onChange={(e) => pickTrip(e.target.value)}
                    className="block w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-slate-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30"
                  >
                    <option value="">Any of my vehicles</option>
                    {todays?.map((x) => <option key={x.id} value={x.id}>{x.route}, {x.departure_time_label}, {x.bus}</option>)}
                  </select>
                  <span className="mt-1 block text-xs text-slate-500">Useful when several vehicles are boarding at once: a ticket for any other one is flagged as the wrong trip.</span>
                </label>
              </details>
            )}
          </div>
        </Card>
        <div className="space-y-4">{active.below}</div>
      </section>

      <aside className="space-y-4 lg:w-[340px] lg:shrink-0">
        <TodaySummary />
        <div className="rounded-lg border border-slate-200 p-5 text-sm text-slate-700">
          <h2 className="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Result guide</h2>
          <ul className="space-y-2.5">
            {GUIDE.map(([dot, text]) => (
              <li key={text} className="flex items-center gap-2.5">
                <span className={cx('h-3 w-3 shrink-0 rounded-full', dot)} />
                {text}
              </li>
            ))}
          </ul>
        </div>
      </aside>
    </div>
  )
}
