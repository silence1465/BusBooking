import { useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { api, errorMessage, fieldErrors } from '../../lib/api'
import { useFetch } from '../../lib/useFetch'
import { Alert, Button, Card, Input, OpenSeatingBadge, PageLoader, SeatBadges, cx } from '../../components/ui'
import SeatMap, { SeatLegend } from '../../components/SeatMap'
import SeatCountPicker from '../../components/SeatCountPicker'
import { longDate, money } from '../../lib/format'
import { useAuth } from '../../context/AuthContext'

const STEPS = ['Pickup point', 'Seat', 'Passenger details', 'Review and pay']

function Stepper({ step, steps }) {
  return (
    <>
      {/* Phones: one line and a progress bar, so it never wraps */}
      <div className="mb-5 rounded-lg border border-slate-200 bg-white px-4 py-3 sm:hidden" aria-label={`Step ${step + 1} of ${steps.length}: ${steps[step]}`}>
        <div className="flex items-baseline justify-between gap-3">
          <p className="font-semibold text-slate-900">{steps[step]}</p>
          <p className="shrink-0 text-xs font-medium text-slate-500">Step {step + 1} of {steps.length}</p>
        </div>
        <div className="mt-2.5 flex gap-1.5" aria-hidden="true">
          {steps.map((s, i) => <span key={s} className={cx('h-1.5 flex-1 rounded-full', i <= step ? 'bg-brand-600' : 'bg-slate-200')} />)}
        </div>
      </div>

      <ol className="mb-6 hidden flex-wrap gap-x-8 gap-y-2 rounded-lg border border-slate-200 bg-white px-5 py-4 text-sm font-medium sm:flex">
        {steps.map((s, i) => (
          <li key={s} aria-current={i === step ? 'step' : undefined} className={cx('flex items-center gap-2.5', i === step ? 'font-semibold text-slate-900' : i < step ? 'text-brand-700' : 'text-slate-500')}>
            <span
              className={cx(
                'grid h-6 w-6 place-items-center rounded-full text-[13px]',
                i === step ? 'bg-slate-900 text-white' : i < step ? 'bg-brand-600 text-white' : 'border border-slate-300',
              )}
            >
              {i < step ? (
                <svg viewBox="0 0 24 24" className="h-3.5 w-3.5" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M5 12l5 5 9-10" /></svg>
              ) : (
                i + 1
              )}
            </span>
            {s}
          </li>
        ))}
      </ol>
    </>
  )
}

function SummaryRow({ label, children, mono }) {
  return (
    <div className="flex justify-between gap-4">
      <dt className="text-slate-500">{label}</dt>
      <dd className={cx('text-right font-semibold text-slate-900', mono && 'font-mono')}>{children}</dd>
    </div>
  )
}

export default function TripBooking() {
  const { id } = useParams()
  const [query] = useSearchParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const { data, loading, error, reload } = useFetch(`/trips/${id}`)

  const [step, setStep] = useState(0)
  const [pickedPickup, setPickup] = useState(null)
  const [destination, setDestination] = useState(null) // only on trips with several destinations: the fare id
  const [showAllDestinations, setShowAllDestinations] = useState(false)
  const [seats, setSeats] = useState([])
  const [qty, setQty] = useState(1) // open seating: how many seats
  const [form, setForm] = useState({ passenger_name: user?.name || '', phone: user?.phone || '', email: user?.email || '' })
  const [errors, setErrors] = useState({})
  const [submitting, setSubmitting] = useState(false)
  const [submitError, setSubmitError] = useState('')

  if (loading && !data) return <PageLoader />
  if (error)
    return (
      <div className="space-y-3">
        <Alert>{error}</Alert>
        <Link to="/" className="inline-flex min-h-11 items-center text-sm font-semibold text-brand-700">Back to search</Link>
      </div>
    )

  const { trip, seats: seatList } = data
  const openSeating = trip.bus.seat_selection === false // Express / Voxy: no seat numbers, seats are assigned
  // A trip with several priced places goes one of two ways:
  //  - one start, many destinations: choose a pickup point AND a destination, the destination sets the price;
  //  - many starts, one destination: the pickup points are the starting places, each with its own price.
  const hasFares = !!trip.fares?.length
  const byStart = hasFares && trip.fares_by === 'origin'
  const byDestination = hasFares && !byStart
  const placeWord = byStart ? 'pickup point' : 'destination'
  // Coming from a search for one of the bus's places (?place=Techiman) has it ticked already.
  const asked = (query.get('place') || '').toLowerCase()

  const startFares = byStart ? trip.fares.filter((f) => f.pickup_point_id) : []
  const pickupList = byStart ? trip.pickup_points.filter((p) => startFares.some((f) => f.pickup_point_id === p.id)) : trip.pickup_points
  const askedPickup = byStart ? pickupList.find((p) => p.name.toLowerCase() === asked)?.id : null
  const pickup = pickedPickup ?? askedPickup ?? null
  const startFare = byStart ? startFares.find((f) => f.pickup_point_id === pickup) : null

  const selected = destination ?? (byDestination ? trip.fares.find((f) => f.place.toLowerCase() === asked)?.id : null) ?? null
  const chosen = byDestination ? trip.fares.find((f) => f.id === selected) : null
  // They searched for this place, so only it is shown (with a way back to the others).
  const fromSearch = byDestination ? !!asked && trip.fares.some((f) => f.place.toLowerCase() === asked) : !!askedPickup
  const listedDestinations = fromSearch && !showAllDestinations ? trip.fares.filter((f) => f.id === selected) : trip.fares
  const listedPickups = byStart && fromSearch && !showAllDestinations ? pickupList.filter((p) => p.id === askedPickup) : pickupList

  const fare = byStart ? (startFare?.price ?? 0) : byDestination ? (chosen?.price ?? 0) : trip.price
  const total = fare * (openSeating ? qty : seats.length)
  const pickupPoint = trip.pickup_points.find((p) => p.id === pickup)

    const toggleSeat = (n) =>
    setSeats((cur) => (cur.includes(n) ? cur.filter((x) => x !== n) : [...cur, n].sort((a, b) => a - b)))

  const validateDetails = () => {
    const e = {}
    if (form.passenger_name.trim().length < 2) e.passenger_name = 'Enter the passenger’s full name.'
    if (!/^[0-9+\s-]{9,16}$/.test(form.phone.trim())) e.phone = 'Enter a valid phone number, e.g. 0241234567.'
    if (!/^\S+@\S+\.\S+$/.test(form.email.trim())) e.email = 'Enter a valid email address.'
    setErrors(e)
    return Object.keys(e).length === 0
  }

  const pay = async () => {
    setSubmitting(true)
    setSubmitError('')
    try {
      const res = await api.post('/bookings', {
        trip_id: trip.id,
        pickup_point_id: pickup,
        ...(byDestination ? { destination_id: selected } : {}),
        ...(openSeating ? { seat_count: qty } : { seat_numbers: seats }),
        passenger_name: form.passenger_name.trim(),
        phone: form.phone.trim(),
        email: form.email.trim(),
      })
      const { authorization_url, booking } = res.data.data
      sessionStorage.setItem('bb_pending_phone', booking.phone)
      window.location.assign(authorization_url) // Paystack hosted checkout
    } catch (e) {
      setSubmitting(false)
      setSubmitError(errorMessage(e))
      setErrors(fieldErrors(e))
      if (e.response?.status === 409) {
        // Someone else took a seat while we were choosing: refresh the map.
        setSeats([])
        setQty(1)
        setStep(1)
        reload()
      } else if (e.response?.status === 422 && fieldErrors(e).passenger_name) setStep(2)
    }
  }

  // One primary action per step, shown under the summary.
  const action = [
    { label: openSeating ? 'Continue to number of seats' : 'Continue to seat selection', disabled: !pickup || (byDestination && !selected), run: () => setStep(1) },
    { label: 'Continue to passenger details', disabled: openSeating ? trip.available_seats < 1 : !seats.length, run: () => setStep(2) },
    { label: 'Review booking', disabled: false, run: () => validateDetails() && setStep(3) },
    { label: `Pay ${money(total, trip.currency)} with Paystack`, disabled: false, run: pay },
  ][step]

  return (
    <div>
      <button onClick={() => (step === 0 ? navigate(-1) : setStep(step - 1))} className="mb-2 inline-flex min-h-11 items-center text-sm font-medium text-slate-500 hover:text-slate-900">
        {step === 0 ? 'Back to search results' : 'Back to previous step'}
      </button>

      <Stepper step={step} steps={STEPS.map((s, i) => (i === 1 && openSeating ? 'Seats' : s))} />
      {submitError && <Alert className="mb-5">{submitError}</Alert>}

      <div className="flex flex-col gap-8 lg:flex-row lg:items-start">
        <div className="min-w-0 flex-1 space-y-4">
        <Card className="sm:p-7">
          {step === 0 && (
            <>
              <h1 className="text-[22px] font-semibold tracking-tight text-slate-900">{byDestination ? 'Pickup point and destination' : 'Choose your pickup point'}</h1>
              <p className="mb-5 mt-1 text-sm text-slate-500">{byStart ? 'The price depends on where you board.' : byDestination ? 'Choose where you board and where you are travelling to. The price depends on the destination.' : 'Where will you board the bus?'}</p>
              {byDestination && <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Pickup point</p>}
              {trip.fill_and_go && (
                <Alert type="error" className="mb-5" title="This bus has no fixed time">
                  It leaves when it is full. Choose the main station if you will wait there for it to fill. Choose a pickup point and we will text you when the bus leaves the station, so be ready at your pickup point.
                </Alert>
              )}
              {trip.pickup_points.length === 0 && <Alert type="warning">No pickup points are set up for this route yet.</Alert>}
              <div className="space-y-2.5">
                {listedPickups.map((p) => (
                  <label
                    key={p.id}
                    className={cx('flex cursor-pointer items-start gap-3 rounded-md border-[1.5px] p-4', pickup === p.id ? 'border-brand-600 bg-brand-50' : 'border-slate-200 hover:border-slate-300')}
                  >
                    <input type="radio" name="pickup" className="mt-1 h-4 w-4 accent-[#6233f0]" checked={pickup === p.id} onChange={() => setPickup(p.id)} />
                    <span className="min-w-0 flex-1">
                      <span className="block font-semibold text-slate-900">{p.name}{p.is_main_station && <span className="ml-2 rounded bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-700">Main station</span>}</span>
                      {p.description && <span className="block text-sm text-slate-500">{p.description}</span>}
                    </span>
                    {byStart && (
                      <span className="shrink-0 text-right">
                        <span className="block font-semibold text-slate-900">{money(startFares.find((f) => f.pickup_point_id === p.id)?.price, trip.currency)}</span>
                        {startFares.find((f) => f.pickup_point_id === p.id)?.departure_time_label && <span className="block text-xs text-slate-500">Leaves {startFares.find((f) => f.pickup_point_id === p.id).departure_time_label}</span>}
                      </span>
                    )}
                  </label>
                ))}
              </div>
              {byStart && fromSearch && !showAllDestinations && pickupList.length > 1 && (
                <button type="button" onClick={() => setShowAllDestinations(true)} className="mt-2 inline-flex min-h-11 items-center text-sm font-semibold text-brand-700">Change pickup point</button>
              )}
              {byDestination && (
                <div className="mt-6">
                  <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Destination</p>
                  <div className="space-y-2.5">
                    {listedDestinations.map((f) => (
                      <label key={f.id} className={cx('flex cursor-pointer items-center gap-3 rounded-md border-[1.5px] p-4', selected === f.id ? 'border-brand-600 bg-brand-50' : 'border-slate-200 hover:border-slate-300')}>
                        <input type="radio" name="destination" className="h-4 w-4 accent-[#6233f0]" checked={selected === f.id} onChange={() => setDestination(f.id)} />
                        <span className="flex-1 font-semibold text-slate-900">{f.place}</span>
                        <span className="font-semibold text-slate-900">{money(f.price, trip.currency)}</span>
                      </label>
                    ))}
                  </div>
                  {fromSearch && !showAllDestinations && trip.fares.length > 1 && (
                    <button type="button" onClick={() => setShowAllDestinations(true)} className="mt-2 inline-flex min-h-11 items-center text-sm font-semibold text-brand-700">Change destination</button>
                  )}
                </div>
              )}
            </>
          )}

          {step === 1 && openSeating && (
            <>
              <h1 className="text-[22px] font-semibold tracking-tight text-slate-900">How many seats?</h1>
              <p className="mb-8 mt-1 text-sm text-slate-500">Book as many seats as you need, up to the {trip.available_seats} available.</p>
              <SeatCountPicker value={qty} onChange={setQty} available={trip.available_seats} />
            </>
          )}

          {step === 1 && !openSeating && (
            <>
              <div className="mb-6 flex flex-wrap items-baseline justify-between gap-4">
                <div>
                  <h1 className="text-[22px] font-semibold tracking-tight text-slate-900">Choose your seat</h1>
                  <p className="mt-1 text-sm text-slate-500">
                    Tap every seat you want. {trip.available_seats} of {trip.bus.seat_count} available.
                  </p>
                </div>
                <SeatLegend />
              </div>
              <SeatMap seats={seatList} selected={seats} onToggle={toggleSeat} />
            </>
          )}

          {step === 2 && (
            <>
              <h1 className="text-[22px] font-semibold tracking-tight text-slate-900">Passenger details</h1>
              <p className="mb-5 mt-1 text-sm text-slate-500">We send the booking code to this phone number.</p>
              <div className="max-w-md space-y-4">
                <Input label="Full name" value={form.passenger_name} error={errors.passenger_name} onChange={(e) => setForm({ ...form, passenger_name: e.target.value })} autoComplete="name" />
                <Input label="Phone number" type="tel" inputMode="tel" value={form.phone} error={errors.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} autoComplete="tel" />
                <Input label="Email Address *" type="email" required value={form.email} error={errors.email} onChange={(e) => setForm({ ...form, email: e.target.value })} autoComplete="email" />
              </div>
            </>
          )}

          {step === 3 && (
            <>
              <h1 className="text-[22px] font-semibold tracking-tight text-slate-900">Review and pay</h1>
              <p className="mb-5 mt-1 text-sm text-slate-500">Check the details, then pay securely with Paystack.</p>
              <dl className="grid gap-5 sm:grid-cols-2">
                {[
                  ['Passenger', form.passenger_name],
                  ['Phone', form.phone],
                  ['Email', form.email],
                  ['Pickup point', pickupPoint?.name],
                  ...(byDestination ? [['Destination', chosen?.place]] : []),
                  [openSeating ? 'Seats' : 'Seat numbers', openSeating ? <OpenSeatingBadge key="seats">{qty} seat{qty === 1 ? '' : 's'}, sit anywhere</OpenSeatingBadge> : <SeatBadges key="seats" seats={seats} />],
                ].map(([k, v]) => (
                  <div key={k}>
                    <dt className="text-xs font-semibold uppercase tracking-wider text-slate-500">{k}</dt>
                    <dd className="mt-1 text-lg font-semibold text-slate-900">{v}</dd>
                  </div>
                ))}
              </dl>
              <Alert type="info" className="mt-6">
                Your seats are held for a few minutes while you pay. If payment isn’t completed they are released.
              </Alert>
            </>
          )}
        </Card>
        {/* On phones the action sits directly under the step, above the summary. */}
        <Button size="lg" className="w-full lg:hidden" disabled={action.disabled} loading={submitting} onClick={action.run}>
          {action.label}
        </Button>
        </div>

        <aside className="space-y-4 lg:w-[340px] lg:shrink-0">
          <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <div className="border-b border-slate-200 p-5">
              <h2 className="text-xs font-semibold uppercase tracking-wider text-slate-500">Trip summary</h2>
              <p className="mt-3 text-lg font-semibold text-slate-900">{trip.route.origin} to {trip.route.destination}</p>
              <p className="text-slate-700">{longDate(trip.travel_date)}</p>
            </div>
            <dl className="space-y-3 p-5 text-sm">
              <SummaryRow label="Departure">{startFare?.departure_time_label || trip.departure_time_label}</SummaryRow>
              <SummaryRow label="Bus">{trip.bus.name}</SummaryRow>
              <SummaryRow label="Pickup">{pickupPoint?.name || 'Not chosen'}</SummaryRow>
              {byDestination && <SummaryRow label="Destination">{chosen?.place || 'Not chosen'}</SummaryRow>}
              {openSeating ? (
                <SummaryRow label="Seats"><OpenSeatingBadge>{qty} seat{qty === 1 ? '' : 's'}, sit anywhere</OpenSeatingBadge></SummaryRow>
              ) : (
                <SummaryRow label="Seats">{seats.length ? <SeatBadges seats={seats} className="justify-end" /> : 'None selected'}</SummaryRow>
              )}
              <SummaryRow label="Price per seat">{(byDestination && !selected) || (byStart && !pickup) ? `Choose a ${placeWord}` : money(fare, trip.currency)}</SummaryRow>
            </dl>
            <div className="flex items-baseline justify-between border-t border-slate-200 bg-slate-50 p-5">
              <span className="font-medium text-slate-700">Total</span>
              <span className="text-[26px] font-semibold tracking-tight text-slate-900">{money(total, trip.currency)}</span>
            </div>
          </div>
          <div className="hidden lg:block">
            <Button size="lg" className="w-full" disabled={action.disabled} loading={submitting} onClick={action.run}>
              {action.label}
            </Button>
          </div>
        </aside>
      </div>
    </div>
  )
}
