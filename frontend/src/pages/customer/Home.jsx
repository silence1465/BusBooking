import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api, errorMessage } from '../../lib/api'
import { useFetch } from '../../lib/useFetch'
import { Alert, BusPhoto, Button, Card, Empty, InfoTip, OpenSeatingBadge, PageLoader, Select, cx } from '../../components/ui'
import AdSlot from '../../components/AdSlot'
import { longDate, money, shortDate, todayYmd } from '../../lib/format'

const eyebrow = 'text-xs font-semibold uppercase tracking-wider text-slate-500'

function Assurance({ d, children }) {
  return (
    <li className="flex items-start gap-2.5">
      <svg viewBox="0 0 24 24" className="mt-0.5 h-[18px] w-[18px] shrink-0 text-brand-600" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d={d} />
      </svg>
      <span>{children}</span>
    </li>
  )
}

function TripRow({ t, from, to }) {
  const low = t.available_seats > 0 && t.available_seats <= 5
  // The customer searched for a place this bus serves on its way (not where it ends): show that place and its price.
  // One start and many destinations, or many starts and one destination: the searched place is the one that varies.
  const byOrigin = t.fares_by === 'origin'
  const wanted = ((byOrigin ? from : to) || '').trim().toLowerCase()
  const match = wanted ? t.fares?.find((f) => f.place.toLowerCase().includes(wanted)) : null
  const start = match && byOrigin ? match.place : t.route.origin
  const end = match && !byOrigin ? match.place : t.route.destination
  const next = t.queue_role === 'next' // the car behind: shown, but not bookable until the one ahead is full

  return (
    <Card className={cx('relative p-0 sm:p-0', next && 'border-dashed bg-slate-50/70')} aria-disabled={next || undefined}>
      <div className={cx('flex flex-col gap-5 p-4 sm:flex-row sm:items-center sm:p-5', next && 'opacity-55 saturate-50')}>
        <BusPhoto src={t.bus.image_url} alt={t.bus.name} className="h-36 w-full sm:h-[92px] sm:w-[132px] sm:shrink-0" />
        <div className="min-w-0 flex-1 space-y-2">
          {next && <span className="inline-block rounded bg-slate-200 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-slate-600">Next car</span>}
          <div className="flex flex-wrap items-baseline gap-x-3">
            <span className={t.fill_and_go ? 'text-base font-semibold tracking-tight text-brand-700' : 'text-2xl font-semibold tracking-tight text-slate-900'}>{(byOrigin && match?.departure_time_label) || t.departure_time_label}</span>
            <span className="rounded bg-amber-100 px-2 py-0.5 text-sm font-semibold text-amber-900">{start} to {end}</span>
          </div>
          {match ? (
            <span className="inline-block rounded bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-700">On the {t.route.origin} to {t.route.destination} bus</span>
          ) : (
            t.price_from != null && <span className="inline-block rounded bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-700">{byOrigin ? 'Several starting points' : 'Several destinations'}</span>
          )}
          <div className="flex flex-wrap gap-x-4 text-sm text-slate-500">
            <span>{t.bus.name}</span>
            <span className="rounded bg-amber-100 px-1.5 py-0.5 font-mono text-xs font-semibold text-amber-900">{t.bus.registration_number}</span>
            <span>{t.bus.seat_count} seats</span>
          </div>
          {next ? (
            <p className="text-sm font-medium text-slate-600">Opens for booking when the car ahead is full.</p>
          ) : (
            <>
              <span className={`inline-block rounded px-2.5 py-0.5 text-[13px] font-semibold ${t.available_seats === 0 ? 'bg-red-100 text-red-800' : low ? 'bg-amber-100 text-amber-900' : 'bg-emerald-100 text-emerald-700'}`}>
                {t.available_seats === 0 ? 'Sold out' : low ? `Only ${t.available_seats} seat${t.available_seats === 1 ? '' : 's'} left` : `${t.available_seats} seats available`}
              </span>
              {t.bus.seat_selection === false && <div><OpenSeatingBadge>Open seating: you choose how many seats, not which seat</OpenSeatingBadge></div>}
              {t.fill_and_go && (
                <div className="max-w-xs pt-1">
                  <div className="h-2 overflow-hidden rounded bg-slate-100"><div className="h-2 bg-brand-600" style={{ width: `${Math.round(((t.seats_taken || 0) / t.bus.seat_count) * 100)}%` }} /></div>
                  <p className="mt-1 text-xs text-slate-500">{t.seats_taken} of {t.bus.seat_count} seats taken. This bus leaves when it is full.</p>
                </div>
              )}
            </>
          )}
        </div>
        <div className="flex items-center justify-between gap-4 sm:flex-col sm:items-end">
          <div className="sm:text-right">
            <p className="text-xs text-slate-500">{match ? (byOrigin ? `Per seat from ${match.place}` : `Per seat to ${match.place}`) : t.price_from != null ? 'Per seat from' : 'Per seat'}</p>
            <p className="text-2xl font-semibold tracking-tight text-slate-900">{money(match ? match.price : t.price_from ?? t.price, t.currency)}</p>
          </div>
          {next ? (
            <Button disabled className="min-h-11 px-6">Opens soon</Button>
          ) : t.available_seats > 0 ? (
            <Link to={`/trips/${t.id}${match ? `?place=${encodeURIComponent(match.place)}` : ''}`}>
              <Button className="min-h-11 px-6">Select trip</Button>
            </Link>
          ) : (
            <Button disabled className="min-h-11 px-6">Sold out</Button>
          )}
        </div>
      </div>

      {/* Good to know for the car that leaves when full, kept out of the way in the top right corner. */}
      {t.fill_and_go && !next && (
        <div className="absolute right-2 top-2">
          <InfoTip label="Good to know about boarding">
            If you choose main station but you couldn’t board on time at the station, your ticket moves to the next car at no extra cost, so your seat is never wasted. Boarding at a pickup stop? Please be ready when we text or call you because it has no refunds.
          </InfoTip>
        </div>
      )}
    </Card>
  )
}

/** Everything open for booking, shown before anyone searches: cars that leave when full, then timetabled trips day by day. */
function Available() {
  const { data, loading, error } = useFetch('/trips/search')

  if (loading) return <PageLoader />
  if (error) return <Alert>{error}</Alert>

  const today = todayYmd()
  const cars = (data || []).filter((t) => t.fill_and_go)
  const days = Object.entries(
    (data || []).filter((t) => !t.fill_and_go).reduce((acc, t) => ((acc[t.travel_date] = acc[t.travel_date] || []).push(t), acc), {}),
  ).sort(([a], [b]) => a.localeCompare(b))
  const count = (data || []).filter((t) => t.queue_role !== 'next').length

  return (
    <div className="mx-auto max-w-4xl space-y-8">
      <AdSlot placement="home" />
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-xl font-semibold tracking-tight text-slate-900">Available trips</h2>
        <span className="text-sm text-slate-500">{count} open for booking</span>
      </div>

      {!data?.length && <Card><Empty>No trips are open for booking right now. Please check again soon.</Empty></Card>}

      {cars.length > 0 && (
        <section className="space-y-4">
          <h3 className={eyebrow}>Leaves when full · today</h3>
          {cars.map((t) => <TripRow key={t.id} t={t} />)}
        </section>
      )}

      {days.map(([day, trips]) => (
        <section key={day} className="space-y-4">
          <h3 className={eyebrow}>{day === today ? 'Today' : shortDate(day)} · scheduled</h3>
          {trips.map((t) => <TripRow key={t.id} t={t} />)}
        </section>
      ))}
      {data?.length > 0 && <p className="text-[13px] text-slate-500">Prices are per seat. Book as many seats as you need in one booking.</p>}
    </div>
  )
}

function Results({ params }) {
  const { data, loading, error } = useFetch('/trips/search', params)

  if (loading) return <PageLoader />
  if (error) return <Alert>{error}</Alert>

  const pickups = data?.[0]?.pickup_points || []
  const bookable = data?.filter((t) => t.queue_role !== 'next') || []

  return (
    <div className="flex flex-col gap-8 lg:flex-row lg:items-start">
      <aside className="order-2 space-y-4 lg:order-1 lg:w-72 lg:shrink-0">
        <Card>
          <h2 className={eyebrow}>Your search</h2>
          <dl className="mt-3 space-y-3">
            <div>
              <dt className="text-xs text-slate-500">Route</dt>
              <dd className="font-semibold text-slate-900">{params.from || 'Any'} to {params.to || 'Any'}</dd>
            </div>
            <div>
              <dt className="text-xs text-slate-500">Date</dt>
              <dd className="font-semibold text-slate-900">{longDate(params.date)}</dd>
            </div>
          </dl>
        </Card>
        {pickups.length > 0 && (
          <Card>
            <h2 className={eyebrow}>Pickup points on this route</h2>
            <ul className="mt-3 space-y-2.5">
              {pickups.map((p) => (
                <li key={p.id}>
                  <p className="font-medium text-slate-900">{p.name}</p>
                  {p.description && <p className="text-[13px] text-slate-500">{p.description}</p>}
                </li>
              ))}
            </ul>
          </Card>
        )}
        <ul className="space-y-3 rounded-lg border border-slate-200 p-5 text-sm text-slate-700">
          <Assurance d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6zM9 12l2 2 4-4">Payments are verified by our servers with Paystack.</Assurance>
          <Assurance d="M4 5h16v11H9l-5 4z">Your booking code arrives by SMS.</Assurance>
          <Assurance d="M12 21a9 9 0 100-18 9 9 0 000 18zM12 7v5l3 2">Seats are held for a few minutes while you pay.</Assurance>
        </ul>
      </aside>

      <section className="order-1 min-w-0 flex-1 space-y-4 lg:order-2">
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <h2 className="text-xl font-semibold tracking-tight text-slate-900">
            {bookable.length || 'No'} departure{bookable.length === 1 ? '' : 's'} on {longDate(params.date)}
          </h2>
          {bookable.length > 1 && <span className="text-sm text-slate-500">Sorted by departure time</span>}
        </div>
        {!data?.length ? (
          <Card><Empty>No buses found for this route and date. Try another date.</Empty></Card>
        ) : (
          <>
            {data.map((t) => <TripRow key={t.id} t={t} from={params.from} to={params.to} />)}
            <p className="pt-1 text-[13px] text-slate-500">Prices are per seat. Book as many seats as you need in one booking.</p>
          </>
        )}
        <AdSlot placement="results" className="pt-4" />
      </section>
    </div>
  )
}

export default function Home() {
  const [searchParams, setSearchParams] = useSearchParams()
  const [locations, setLocations] = useState({ origins: [], destinations: [], pairs: [] })
  const [form, setForm] = useState({
    from: searchParams.get('from') || '',
    to: searchParams.get('to') || '',
    date: searchParams.get('date') || todayYmd(),
  })
  const [error, setError] = useState('')

  useEffect(() => {
    api
      .get('/locations')
      .then((r) => setLocations(r.data.data))
      .catch((e) => setError(errorMessage(e)))
  }, [])

  const submit = (e) => {
    e.preventDefault()
    setSearchParams({ from: form.from, to: form.to, date: form.date })
  }

  const searched = searchParams.get('date')
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }))

  // Once a "From" is chosen, "To" lists only the places that go with it.
  const destinationsFor = (from) =>
    from
      ? [...new Set(locations.pairs.filter((p) => p.from.toLowerCase() === from.toLowerCase()).map((p) => p.to))].sort((a, b) => a.localeCompare(b))
      : locations.destinations
  const toOptions = destinationsFor(form.from)
  const pickFrom = (e) => {
    const from = e.target.value
    setForm((f) => ({ ...f, from, to: from && f.to && !destinationsFor(from).some((d) => d.toLowerCase() === f.to.toLowerCase()) ? '' : f.to }))
  }

  return (
    <>
      <section className="relative isolate overflow-hidden bg-slate-900 text-white">
        {/* The station photo sits behind the search box, faded so the text stays easy to read. */}
        <img src="/images/station.png" alt="" aria-hidden="true" className="absolute inset-0 -z-10 h-full w-full object-cover opacity-40" />
        <div className="absolute inset-0 -z-10 bg-gradient-to-r from-slate-900/85 via-slate-900/50 to-slate-900/20" aria-hidden="true" />
        <div className="mx-auto max-w-6xl px-4 py-9 sm:px-8 sm:py-10">
          <h1 className="text-[28px] font-semibold leading-tight tracking-tight">Book your bus ticket</h1>
          <p className="mt-1.5 text-[15px] text-slate-300">Choose your seat and pay securely. No account needed.</p>

          <form onSubmit={submit} className="mt-6 grid gap-4 rounded-lg bg-white p-5 text-slate-900 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1fr_200px] lg:items-end">
            <Select label="From" value={form.from} onChange={pickFrom} required className="h-[46px]">
              <option value="">Select origin</option>
              {locations.origins.map((o) => <option key={o}>{o}</option>)}
            </Select>
            <Select label="To" value={form.to} onChange={set('to')} required className="h-[46px]">
              <option value="">Select destination</option>
              {toOptions.map((o) => <option key={o}>{o}</option>)}
            </Select>
            <label className="block">
              <span className={`mb-1.5 block ${eyebrow}`}>Travel date</span>
              <input
                type="date"
                required
                min={todayYmd()}
                value={form.date}
                onChange={set('date')}
                className="block h-[46px] w-full rounded-md border border-slate-300 bg-white px-3 text-slate-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30"
              />
            </label>
            <Button type="submit" variant="gold" className="h-[46px] w-full text-base">Search buses</Button>
          </form>
          {error && <Alert className="mt-3">{error}</Alert>}
        </div>
      </section>

      <div className="mx-auto max-w-6xl px-4 py-8 sm:px-8">
        {searched ? <Results params={{ from: searchParams.get('from') || '', to: searchParams.get('to') || '', date: searched }} /> : <Available />}
      </div>
    </>
  )
}
