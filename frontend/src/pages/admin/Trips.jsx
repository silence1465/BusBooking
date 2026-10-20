import { useState } from 'react'
import { Link } from 'react-router-dom'
import CrudPage from '../../components/CrudPage'
import { Badge, Button, Card, PageLoader, Tabs, cx } from '../../components/ui'
import { useFetch } from '../../lib/useFetch'
import { longDate, money, shortDate, todayYmd } from '../../lib/format'

/**
 * admin: every trip, with the online commission and the station price.
 * Station master (switch on): only their routes, and no commission or station price anywhere.
 */
/**
 * One trip with several priced places. Either one start and several destinations, or several starting places and
 * one destination. Each place has one price whatever the pickup point. Seats are shared: a seat sold for one place
 * is taken for the whole trip.
 */
function FaresEditor({ value = [], by = 'destination', onChange, onBy, stops = [] }) {
  const rows = Array.isArray(value) ? value : []
  const [open, setOpen] = useState(rows.length > 0) // stays as the user left it, also when the other direction's list is empty
  const origin = by === 'origin'
  const word = origin ? 'starting place' : 'destination'
  const change = (i, patch) => onChange(rows.map((r, n) => (n === i ? { ...r, ...patch } : r)))
  const priced = origin
    ? rows.filter((r) => r.price !== '' && r.price != null).length
    : rows.filter((r) => r.place?.trim() && r.price !== '' && r.price != null).length
  // many starts: one price per pickup point of the route
  const rowOf = (id) => rows.find((r) => r.pickup_point_id === id)
  const priceOf = (id) => rowOf(id)?.price ?? ''
  const timeOf = (id) => rowOf(id)?.departure_time ?? ''
  // price and the pickup point's own leaving time; a row with neither is dropped (a time alone is kept until it gets a price)
  const setRow = (id, patch) => {
    const next = { pickup_point_id: id, price: priceOf(id), departure_time: timeOf(id), ...patch }
    const rest = rows.filter((r) => r.pickup_point_id !== id)
    onChange(next.price === '' && !next.departure_time ? rest : [...rest, next])
  }

  return (
    <details open={open} onToggle={(e) => setOpen(e.currentTarget.open)} className="rounded-lg border border-slate-200 bg-slate-50">
      <summary className="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 px-3 text-sm font-semibold text-slate-800">
        <span>{origin ? 'Different prices by pickup point' : 'Several destinations'} <span className="font-normal text-slate-500">(optional)</span></span>
        {priced > 0 && <span className="rounded-full bg-brand-100 px-2 py-0.5 text-xs font-semibold text-brand-700">{priced} {origin ? 'pickup point' : 'destination'}{priced === 1 ? '' : 's'}</span>}
      </summary>
      <div className="space-y-3 border-t border-slate-200 p-3">
        <div className="grid grid-cols-2 gap-1 rounded-lg bg-slate-100 p-1 text-sm font-semibold" role="tablist">
          {[['destination', 'One start, many destinations'], ['origin', 'Many starts, one destination']].map(([key, label]) => (
            <button key={key} type="button" role="tab" aria-selected={by === key} onClick={() => onBy(key)} className={'min-h-10 rounded-md px-2 leading-tight ' + (by === key ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-600 hover:text-slate-900')}>
              {label}
            </button>
          ))}
        </div>
        <p className="text-xs text-slate-500">
          {origin
            ? 'For a bus that ends at one place but picks up from several. The starting places are this route’s pickup points: price each one and, if you like, give each its own leaving time (empty uses the trip’s time). A pickup point left without a price is not sold from. '
            : 'For a bus that starts from one place but goes to several. Add each destination with its price. '}
          {origin ? 'Boarding at a pickup point sets the price.' : 'The price depends on the destination only, whatever the pickup point.'} Leave everything empty to use the single ticket price. Seats are shared: a seat sold for one place is taken for the whole trip.
        </p>
        {origin ? (
          <ul className="space-y-1.5">
            {stops.length === 0 && <li className="rounded bg-amber-50 px-2 py-1.5 text-xs font-medium text-amber-900">Choose the route first. Its pickup points will be listed here.</li>}
            {stops.map((s) => (
              <li key={s.id} className="flex items-center gap-2">
                <span className="min-w-0 flex-1 truncate text-sm text-slate-800">{s.name}{s.description && <span className="text-slate-400"> · {s.description}</span>}</span>
                <input
                  type="time"
                  value={timeOf(s.id)}
                  onChange={(e) => setRow(s.id, { departure_time: e.target.value })}
                  aria-label={`Leaves ${s.name} at`}
                  title="When the bus leaves here (empty = the trip's time)"
                  className="w-[7.5rem] rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30"
                />
                <input
                  type="number"
                  min="0"
                  step="any"
                  inputMode="decimal"
                  placeholder="GHS"
                  value={priceOf(s.id)}
                  onChange={(e) => setRow(s.id, { price: e.target.value })}
                  aria-label={`Price for boarding at ${s.name}`}
                  className="w-24 rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-right text-sm text-slate-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30"
                />
              </li>
            ))}
          </ul>
        ) : (
        <ul className="space-y-1.5">
          {rows.map((r, i) => (
            <li key={i} className="flex items-center gap-2">
              <input
                value={r.place}
                placeholder={origin ? 'Starting place, e.g. Techiman' : 'Destination, e.g. Kumasi'}
                maxLength={100}
                onChange={(e) => change(i, { place: e.target.value })}
                aria-label={`${origin ? 'Starting place' : 'Destination'} ${i + 1}`}
                className="min-w-0 flex-1 rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm text-slate-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30"
              />
              <input
                type="number"
                min="0"
                step="any"
                inputMode="decimal"
                placeholder="GHS"
                value={r.price}
                onChange={(e) => change(i, { price: e.target.value })}
                aria-label={`Price for ${word} ${i + 1}`}
                className="w-24 rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-right text-sm text-slate-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30"
              />
              <button type="button" onClick={() => onChange(rows.filter((_, n) => n !== i))} className="grid h-9 w-9 shrink-0 place-items-center rounded-md text-slate-500 hover:bg-white hover:text-red-700" aria-label={`Remove ${word} ${i + 1}`}>
                <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
              </button>
            </li>
          ))}
        </ul>
        )}
        {!origin && <Button type="button" size="sm" variant="secondary" onClick={() => onChange([...rows, { place: '', price: '' }])}>+ Add {word}</Button>}
      </div>
    </details>
  )
}

export default function Trips({ admin = true }) {
  const base = admin ? '/admin' : '/manage'
  const buses = useFetch(admin ? '/admin/buses' : '/manage/bus-options')
  const routes = useFetch(admin ? '/admin/routes' : '/manage/routes')
  const [date, setDate] = useState(todayYmd()) // '' = every day
  const [kind, setKind] = useState('all') // all | scheduled | queue
  const [routeId, setRouteId] = useState('')
  const pickups = useFetch(admin ? '/admin/pickup-points' : '/manage/stops') // for pricing a many-starts trip by pickup point
  const stopsOf = (routeId) => (pickups.data || []).filter((p) => String(p.route_id) === String(routeId) && p.status === 'active').sort((a, b) => a.stop_order - b.stop_order)
  const summary = useFetch(`${base}/trips/summary`, { ...(date && { date }), ...(kind !== 'all' && { kind }) })
  const sum = summary.data
  const activeRoute = sum?.routes.some((r) => String(r.id) === routeId) ? routeId : ''
  if (!buses.data || !routes.data) return <PageLoader />

  return (
    <CrudPage
      title="Trips"
      subtitle="Create trips by choosing a bus, route, date, time and price."
      endpoint={`${base}/trips`}
      createLabel="Create trip"
      defaults={{ fares_by: 'destination' }}
      paged
      canDelete={admin}
      params={{ ...(date && { date }), ...(kind !== 'all' && { kind }), ...(activeRoute && { route_id: activeRoute }) }}
      emptyText={date ? `No trips on ${longDate(date)} here.` : 'No trips here.'}
      onChanged={summary.reload}
      toolbar={
        <div className="mb-4 space-y-3">
          <Card className="flex flex-wrap items-end gap-3">
            <label className="block">
              <span className="mb-1 block text-xs font-semibold uppercase tracking-wider text-slate-500">Date</span>
              <input type="date" value={date} onChange={(e) => setDate(e.target.value)} className="block rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm text-slate-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30" />
            </label>
            <div className="flex gap-1 rounded-lg bg-slate-100 p-1 text-sm font-semibold" role="tablist">
              {[['Today', todayYmd()], ['All dates', '']].map(([label, value]) => (
                <button key={label} role="tab" aria-selected={date === value} onClick={() => setDate(value)} className={cx('min-h-9 whitespace-nowrap rounded-md px-3', date === value ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-600 hover:text-slate-900')}>{label}</button>
              ))}
            </div>
          </Card>

          <Card className="space-y-3 p-0 sm:p-0">
            <Tabs
              className="px-3 pt-1"
              value={kind}
              onChange={(k) => { setKind(k); setRouteId('') }}
              tabs={[
                { key: 'all', label: 'All trips', count: sum?.total ?? 0 },
                { key: 'scheduled', label: 'Scheduled', count: sum?.scheduled ?? 0 },
                { key: 'queue', label: 'Queue', count: sum?.queue ?? 0 },
              ]}
            />
            {(sum?.routes.length ?? 0) > 0 && (
              <div className="flex flex-wrap gap-2 px-3">
                {[{ id: '', route: 'All routes', count: sum.routes.reduce((n, r) => n + r.count, 0) }, ...sum.routes].map((r) => (
                  <button
                    key={r.id}
                    onClick={() => setRouteId(r.id === '' ? '' : String(r.id))}
                    aria-pressed={activeRoute === String(r.id)}
                    className={cx('inline-flex min-h-9 items-center gap-2 rounded-full border px-3 text-sm font-medium', activeRoute === String(r.id) ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-slate-200 text-slate-600 hover:border-slate-300')}
                  >
                    {r.route}
                    <span className="rounded-full bg-slate-100 px-1.5 text-[11px] font-semibold text-slate-600">{r.count}</span>
                  </button>
                ))}
              </div>
            )}
            <div className="flex flex-wrap items-center gap-2 border-t border-slate-100 px-3 py-2.5 text-sm">
              <span className="font-semibold text-slate-700">Buses available: {sum?.free_buses.length ?? 0}</span>
              {sum?.free_buses.map((b) => (
                <span key={b.id} className="inline-flex items-center gap-1.5 text-slate-500" title={`${b.name}, ${b.seat_count} seats`}>
                  <span className="rounded bg-amber-100 px-1.5 py-0.5 font-mono text-xs font-semibold text-amber-900">{b.registration_number}</span>
                </span>
              ))}
              {sum && sum.free_buses.length === 0 && <span className="text-slate-500">every active bus is on a trip{date ? ' that day' : ''}</span>}
            </div>
          </Card>
        </div>
      }
      toForm={(t) => ({ fares_by: t.fares_by || 'destination', fares_destination: (t.fares_by || 'destination') === 'destination' ? (t.fares || []).map((f) => ({ place: f.place, price: f.price })) : [],
        fares_origin: t.fares_by === 'origin' ? (t.fares || []).filter((f) => f.pickup_point_id).map((f) => ({ pickup_point_id: f.pickup_point_id, price: f.price, departure_time: f.departure_time || '' })) : [], bus_id: t.bus.id, route_id: t.route.id, travel_date: t.travel_date, departure_time: t.departure_time, price: t.price, commission: t.commission ?? '', status: t.status })}
      toPayload={(payload) => {
        const { fares_editor: _editor, fares_destination: toPlaces, fares_origin: fromPlaces, ...rest } = payload
        // only the places of the chosen direction go; every named, priced one. None at all means one price for the whole trip (and clears old ones on edit)
        const origin = rest.fares_by === 'origin'
        const list = origin ? fromPlaces : toPlaces
        const fares = (Array.isArray(list) ? list : [])
          .filter((r) => r.price !== '' && r.price != null && (origin ? r.pickup_point_id : r.place?.trim()))
          .map((r) => (origin ? { pickup_point_id: r.pickup_point_id, price: r.price, departure_time: r.departure_time || null } : { place: r.place.trim(), price: r.price }))
                return { ...rest, fares }
      }}
      deriveForm={(next, changed) => {
        if (!admin) return next
        // Picking the route or the bus fills in the commission that applies, which can still be changed for this trip.
        if (changed !== 'route_id' && changed !== 'bus_id') return next
        const route = routes.data.find((r) => String(r.id) === String(next.route_id))
        const bus = buses.data.find((b) => String(b.id) === String(next.bus_id))
        if (!route) return next
        const special = bus?.category_id != null ? route.category_commissions?.[bus.category_id] : null
        return { ...next, commission: special ?? route.commission ?? 0 }
      }}
      headerAside={(form) => {
        const price = Number(form.price)
        if (!price) return <span className="text-xs text-slate-400">Total fare</span>
        if (!admin) return <div className="leading-tight"><p className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Total fare</p><p className="text-lg font-semibold tracking-tight text-slate-900">{money(price)}</p></div>
        const commission = Math.min(Number(form.commission) || 0, price)
        return (
          <div className="leading-tight">
            <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Total fare</p>
            <p className="text-lg font-semibold tracking-tight text-slate-900">{money(price)}</p>
            <p className="text-xs font-bold text-emerald-700">Station price {money(price - commission)}</p>
          </div>
        )
      }}
      columns={[
        { label: 'Route', render: (t) => <b>{t.route.origin} → {t.route.destination}</b> },
        { label: 'Departs', render: (t) => <div><div className="text-slate-900">{shortDate(t.travel_date)}</div><div className="text-xs text-slate-500">{t.departure_time_label}</div></div> },
        { label: 'Bus', render: (t) => <div><div className="text-slate-900">{t.bus.name}</div><div className="mt-0.5"><span className="rounded bg-amber-100 px-1.5 py-0.5 font-mono text-xs font-semibold text-amber-900">{t.bus.registration_number}</span></div></div> },
        { label: 'Price', render: (t) => (t.fares?.length ? <span>from {money(Math.min(...t.fares.map((f) => f.price)), t.currency)} <span className="block text-xs text-slate-500">{t.fares.length} {t.fares_by === 'origin' ? 'starting places' : 'destinations'}</span></span> : money(t.price, t.currency)) },
        { label: 'Seats left', render: (t) => `${t.available_seats ?? '—'} / ${t.bus.seat_count}` },
        { label: 'Status', render: (t) => <Badge>{t.status}</Badge> },
      ]}
      rowActions={(t) => <Link to={admin ? `/admin/trips/${t.id}/passengers` : `/station/trips/${t.id}`}><Button size="sm" variant="secondary">Passengers</Button></Link>}
      fields={[
        { name: 'bus_id', label: 'Bus', type: 'select', options: buses.data.filter((b) => b.status === 'active').map((b) => ({ value: b.id, label: `${b.name} (${b.registration_number}) · ${b.seat_count} seats` })) },
        { name: 'route_id', label: 'Route', type: 'select', options: routes.data.filter((r) => r.status === 'active').map((r) => ({ value: r.id, label: `${r.origin} → ${r.destination}` })) },
        { name: 'travel_date', label: 'Travel date', type: 'date' },
        { name: 'departure_time', label: 'Departure time', type: 'time' },
        { name: 'price', label: 'Ticket price (GHS)', type: 'number' },
        {
          name: 'fares_editor',
          type: 'custom',
          render: (form, _set, _errors, setField) => {
            const by = form.fares_by || 'destination'
            const key = by === 'origin' ? 'fares_origin' : 'fares_destination' // each direction has its own list
            return <FaresEditor value={form[key]} by={by} stops={form.route_id ? stopsOf(form.route_id) : []} onChange={(v) => setField(key, v)} onBy={(v) => setField('fares_by', v)} />
          },
        },
        { name: 'fares_destination', type: 'custom', render: () => null },
        { name: 'fares_origin', type: 'custom', render: () => null },
        { name: 'fares_by', type: 'custom', render: () => null }, // sent with the trip; chosen inside the section above
        ...(!admin ? [] : [{ name: 'commission', label: 'Online commission per seat (GHS)', type: 'number', required: false, hint: <span className="inline-block rounded bg-brand-50 px-2 py-1 font-medium text-brand-700">Taken from the ticket price for every seat sold online. Filled in from the route; change it for this trip if needed.</span> }]),
        { name: 'status', label: 'Status', type: 'select', editOnly: true, options: ['scheduled', 'boarding', 'departed', 'completed', 'cancelled'].map((s) => ({ value: s, label: s[0].toUpperCase() + s.slice(1) })) },
      ]}
    />
  )
}
