import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, errorMessage } from '../../lib/api'
import { useFetch } from '../../lib/useFetch'
import { Alert, Button, Card, OpenSeatingBadge, PageLoader, SeatBadges, Select, cx } from '../../components/ui'
import SeatMap, { SeatLegend } from '../../components/SeatMap'
import SeatCountPicker from '../../components/SeatCountPicker'
import { longDate, money, seatLabel, todayYmd } from '../../lib/format'

/** Seat map + Book button for one trip. Price is set by the server from the trip. */
function TripSeats({ tripId, onSold }) {
  const { data, loading, error, reload } = useFetch(`/station/trips/${tripId}/seats`)
  const [seats, setSeats] = useState([])
  const [qty, setQty] = useState(1)
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState('')
  const [sold, setSold] = useState(null)
  const [to, setTo] = useState('') // destination (fare id), on a trip with several destinations

  // A different trip means a fresh selection.
  useEffect(() => {
    setSeats([])
    setQty(1)
    setSold(null)
    setTo('')
    setFormError('')
  }, [tripId])

  if (loading && !data) return <PageLoader />
  if (error && !data) return <Alert>{error}</Alert>

  const { trip, seats: seatList } = data
  const openSeating = trip.bus.seat_selection === false // Express / Voxy: sell a number of seats, not seat numbers
  // One trip, several destinations: the trip starts from a fixed place, so the destination alone sets the price.
  const hasFares = !!trip.fares?.length
  const byStart = hasFares && trip.fares_by === 'origin' // many starts, one destination: the pickup point sets the price
  const fare = hasFares ? trip.fares.find((f) => String(f.id) === String(to))?.price ?? 0 : trip.price // "to" holds the chosen fare row
  const total = fare * (openSeating ? qty : seats.length)
  const journeyReady = !hasFares || !!to

  const toggle = (n) =>
    setSeats((cur) => (cur.includes(n) ? cur.filter((x) => x !== n) : [...cur, n].sort((a, b) => a - b)))

  // Whole vehicle (or what is left of it) in one go, e.g. a group or a charter.
  const free = seatList.filter((s) => s.status === 'available').map((s) => s.seat_number)
  const allSelected = free.length > 0 && seats.length === free.length

  const book = async () => {
    setSaving(true)
    setFormError('')
    try {
      const res = await api.post(`/station/trips/${tripId}/sell`, { ...(openSeating ? { seat_count: qty } : { seat_numbers: seats }), ...(hasFares ? (byStart ? { pickup_point_id: trip.fares.find((f) => String(f.id) === String(to))?.pickup_point_id } : { destination_id: to }) : {}) })
      setSold(res.data.data)
      setSeats([])
      setQty(1)
      setTo('')
      reload()
      onSold?.()
    } catch (err) {
      setFormError(errorMessage(err))
      if (err.response?.status === 409) {
        setSeats([]) // someone took a seat meanwhile: show the fresh map
        setQty(1)
        reload()
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
      <Card className="flex-1 sm:p-7">
        <div className="mb-6 flex flex-wrap items-baseline justify-between gap-4">
          <h2 className="font-semibold text-slate-900">
            {openSeating ? 'How many seats?' : 'Select seat number'} <span className="font-normal text-slate-500">· {trip.available_seats} of {trip.bus.seat_count} free</span>
          </h2>
          {!openSeating && <SeatLegend />}
        </div>
        {!openSeating && free.length > 0 && (
          <div className="mb-5 flex flex-wrap items-center gap-2">
            <button
              type="button"
              onClick={() => setSeats(allSelected ? [] : free)}
              aria-pressed={allSelected}
              className={cx('inline-flex min-h-10 items-center rounded-md border-[1.5px] px-3 text-sm font-semibold', allSelected ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-slate-300 text-slate-700 hover:border-brand-600 hover:bg-brand-50')}
            >
              {allSelected ? `All ${free.length} free seats selected` : `Select all ${free.length} free seats`}
            </button>
            {seats.length > 0 && (
              <button type="button" onClick={() => setSeats([])} className="inline-flex min-h-10 items-center rounded-md px-3 text-sm font-semibold text-slate-600 hover:bg-slate-100">
                Clear ({seats.length})
              </button>
            )}
          </div>
        )}
        {hasFares && (
          <div className="mb-6 max-w-sm">
            <Select label={byStart ? 'Boarding at' : 'Destination'} value={to} onChange={(e) => setTo(e.target.value)}>
              <option value="">Select…</option>
              {trip.fares.map((f) => <option key={f.id} value={f.id}>{f.place} · {money(f.price, trip.currency)}</option>)}
            </Select>
          </div>
        )}
        {openSeating ? (
          <SeatCountPicker value={qty} onChange={setQty} available={trip.available_seats} />
        ) : (
          <SeatMap seats={seatList} selected={seats} onToggle={toggle} />
        )}
      </Card>

      <div className="space-y-4 lg:w-[320px] lg:shrink-0">
        {sold && (
          <div className="rounded-lg border border-emerald-200 bg-emerald-100 px-4 py-3 text-emerald-800" role="status">
            <p className="font-semibold">Booked: {openSeating ? seatLabel(sold) : `seat ${seatLabel(sold)}`}</p>
            <p className="text-[13px]">Code <span className="font-mono font-semibold">{sold.code}</span> · {money(sold.amount, sold.currency)}</p>
          </div>
        )}
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
          <dl className="space-y-3 p-5 text-sm">
            <div className="flex justify-between gap-4"><dt className="text-slate-500">Trip</dt><dd className="text-right font-semibold text-slate-900">{trip.route.origin} to {trip.route.destination}</dd></div>
            {hasFares && <div className="flex justify-between gap-4"><dt className="text-slate-500">{byStart ? 'Boarding at' : 'Destination'}</dt><dd className="text-right font-semibold text-slate-900">{trip.fares.find((f) => String(f.id) === String(to))?.place || 'Choose one'}</dd></div>}
            <div className="flex justify-between gap-4"><dt className="text-slate-500">Departs</dt><dd className="font-semibold text-slate-900">{trip.departure_time_label}</dd></div>
            <div className="flex justify-between gap-4"><dt className="text-slate-500">Seats</dt><dd className="font-semibold text-slate-900">{openSeating ? <OpenSeatingBadge>{qty} seat{qty === 1 ? '' : 's'}, any seat</OpenSeatingBadge> : seats.length ? <SeatBadges seats={seats} className="justify-end" /> : 'None selected'}</dd></div>
          </dl>
          <div className="flex items-baseline justify-between border-t border-slate-200 bg-slate-50 p-5">
            <span className="font-medium text-slate-700">Cash</span>
            <span className="text-[26px] font-semibold tracking-tight text-slate-900">{money(total, trip.currency)}</span>
          </div>
        </div>
        <Alert>{formError}</Alert>
        <Button size="lg" variant="success" className="w-full" loading={saving} disabled={!journeyReady || (openSeating ? trip.available_seats < 1 : !seats.length)} onClick={book}>
          Book {(openSeating ? qty : seats.length) > 1 ? `${openSeating ? qty : seats.length} seats` : 'seat'}
        </Button>
      </div>
    </div>
  )
}

export default function SellSeats() {
  const { id } = useParams() // optional deep link from the trips list
  const navigate = useNavigate()
  const [date, setDate] = useState(todayYmd())
  const { data: trips, loading, error, reload } = useFetch('/station/trips', { date })
  const [route, setRoute] = useState('')
  const [tripId, setTripId] = useState(id || '')

  const open = useMemo(() => (trips || []).filter((t) => ['scheduled', 'boarding', 'queued'].includes(t.status)), [trips])
  const routes = useMemo(() => [...new Set(open.map((t) => t.route))], [open])
  const routeTrips = open.filter((t) => t.route === route)

  // Arriving from a trip's own link: preselect its route.
  useEffect(() => {
    if (id && open.length && !route) {
      const t = open.find((x) => String(x.id) === String(id))
      if (t) setRoute(t.route)
    }
  }, [id, open, route])

  // Only one departure on the route: pick it for them.
  useEffect(() => {
    if (route && routeTrips.length === 1) setTripId(String(routeTrips[0].id))
  }, [route, routeTrips])

  const changeRoute = (value) => {
    setRoute(value)
    setTripId('')
    if (id) navigate('/station/sell', { replace: true })
  }

  return (
    <div>
      <h1 className="text-[22px] font-semibold tracking-tight text-slate-900">Sell ticket</h1>
      <p className="mb-6 mt-1 text-sm text-slate-500">Choose the route, tick the seat number and book. Seats sold here are taken off online sale straight away.</p>

      <Card className="mb-6 grid gap-4 sm:grid-cols-[1fr_200px]">
        <Select label="Route" value={route} onChange={(e) => changeRoute(e.target.value)}>
          <option value="">{loading ? 'Loading routes…' : routes.length ? 'Select route' : 'No trips open for sale'}</option>
          {routes.map((r) => <option key={r}>{r}</option>)}
        </Select>
        <label className="block">
          <span className="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">Date</span>
          <input
            type="date"
            min={todayYmd()}
            value={date}
            onChange={(e) => { setDate(e.target.value); setRoute(''); setTripId('') }}
            className="block w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-slate-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30"
          />
        </label>

        {routeTrips.length > 1 && (
          <fieldset className="sm:col-span-2">
            <legend className="mb-1.5 text-xs font-semibold uppercase tracking-wider text-slate-500">Departure</legend>
            <div className="flex flex-wrap gap-2">
              {routeTrips.map((t) => (
                <button
                  key={t.id}
                  type="button"
                  onClick={() => setTripId(String(t.id))}
                  aria-pressed={String(t.id) === tripId}
                  className={cx(
                    'rounded-md border-[1.5px] px-4 py-2 text-left',
                    // the car at the front of the queue stands out in yellow; the chosen one gets the violet ring
                    t.status === 'boarding' ? 'border-amber-300 bg-amber-100 hover:border-amber-400' : 'border-slate-200 bg-white hover:border-slate-300',
                    String(t.id) === tripId && 'border-brand-600 ring-2 ring-brand-500/40',
                  )}
                >
                  <span className="block font-semibold text-slate-900">{t.fill_and_go ? `${t.status === 'boarding' ? 'At the front' : `Queue place ${t.queue_position}`}: ${t.booked_seats}/${t.total_seats} sold` : t.departure_time_label}</span>
                  <span className="block text-xs font-semibold text-blue-700">{t.bus} - <span className="font-mono text-red-600">{t.bus_registration}</span></span>
                </button>
              ))}
            </div>
          </fieldset>
        )}
      </Card>

      {error && <Alert className="mb-4">{error}</Alert>}
      {route && tripId ? (
        <TripSeats tripId={tripId} onSold={reload} />
      ) : (
        !loading && <p className="py-10 text-center text-sm text-slate-500">{route ? 'Choose a departure to see its seats.' : `Select a route to see seats for ${longDate(date)}.`}</p>
      )}

      <p className="mt-6 text-sm text-slate-500">
        To check in a passenger instead, go to <Link to="/station" className="font-medium text-brand-700">Verify booking</Link>.
      </p>
    </div>
  )
}
