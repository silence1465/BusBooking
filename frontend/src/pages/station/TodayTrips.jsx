import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api, errorMessage } from '../../lib/api'
import { useFetch } from '../../lib/useFetch'
import { Alert, Badge, Button, Card, Empty, Modal, PageHeader, PageLoader, Select, Tabs, cx } from '../../components/ui'
import DispatchButton from '../../components/DispatchButton'
import { useConfirm, useToast } from '../../components/feedback'
import { longDate, money, todayYmd } from '../../lib/format'
import { useAuth } from '../../context/AuthContext'

/** Station master: add a vehicle to the end of a fill-and-go route's queue. */
function OpenBoarding({ onOpened }) {
  const { data, reload } = useFetch('/station/boarding/setup')
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ route_id: '', bus_id: '' })
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const toast = useToast()

  if (!data?.routes?.length) return null

  const submit = async (e) => {
    e.preventDefault()
    setSaving(true)
    setError('')
    try {
      await api.post(`/station/routes/${form.route_id}/open-boarding`, { bus_id: form.bus_id })
      setOpen(false)
      setForm({ route_id: '', bus_id: '' })
      toast('Vehicle added to the queue')
      reload()
      onOpened()
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setSaving(false)
    }
  }

  const fare = data.routes.find((r) => String(r.id) === form.route_id)?.fare

  return (
    <>
      <Button onClick={() => setOpen(true)}>Add vehicle to the queue</Button>
      <Modal open={open} onClose={() => setOpen(false)} title="Add a vehicle to the queue">
        <form onSubmit={submit} className="space-y-4">
          <p className="text-sm text-slate-600">It goes to the back of the queue. If nothing is boarding, it starts boarding straight away.</p>
          <Alert>{error}</Alert>
          <Select label="Route" value={form.route_id} onChange={(e) => setForm({ ...form, route_id: e.target.value })} required>
            <option value="">Select route</option>
            {data.routes.map((r) => <option key={r.id} value={r.id}>{r.label}</option>)}
          </Select>
          <Select label="Vehicle" value={form.bus_id} onChange={(e) => setForm({ ...form, bus_id: e.target.value })} required hint={fare ? `Fare per seat: ${money(fare)}` : undefined}>
            <option value="">{data.buses.length ? 'Select vehicle' : 'No free vehicles'}</option>
            {data.buses.map((b) => <option key={b.id} value={b.id}>{b.name} · {b.registration_number} · {b.seat_count} seats</option>)}
          </Select>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={() => setOpen(false)}>Cancel</Button>
            <Button type="submit" loading={saving} disabled={!form.route_id || !form.bus_id}>Add to queue</Button>
          </div>
        </form>
      </Modal>
    </>
  )
}

/** Move up / down / to the front / remove: for a vehicle waiting its turn. */
function QueueControls({ t, isFirstQueued, isLastQueued, frontIsEmpty, reload, onError }) {
  const [busy, setBusy] = useState(false)
  const confirm = useConfirm()
  const toast = useToast()

  const act = async (action) => {
    if (action === 'remove' && !(await confirm({ title: 'Remove from the queue?', body: `${t.bus} (${t.bus_registration}) will be taken out of the queue.`, confirmLabel: 'Remove', danger: true }))) return
    setBusy(true)
    onError('')
    try {
      await api.post(`/station/trips/${t.id}/queue`, { action })
      toast(action === 'remove' ? 'Removed from the queue' : 'Queue updated')
      reload()
    } catch (err) {
      onError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  const btn = 'min-h-9 flex-1 rounded-md border border-slate-300 bg-white px-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40'
  return (
    <div className="flex w-full gap-1.5">
      <button className={btn} disabled={busy || isFirstQueued} onClick={() => act('up')} aria-label="Move up the queue">▲ Up</button>
      <button className={btn} disabled={busy || isLastQueued} onClick={() => act('down')} aria-label="Move down the queue">▼ Down</button>
      <button className={btn} disabled={busy || !frontIsEmpty} onClick={() => act('front')} title={frontIsEmpty ? '' : 'The vehicle at the front already has passengers'}>Front</button>
      <button className={cx(btn, 'text-red-700')} disabled={busy} onClick={() => act('remove')}>Remove</button>
    </div>
  )
}

/** One trip = one card: details on top, its own actions in the footer, nothing floating outside it. */
function TripCard({ t, canSell, canDispatch, queueProps, reload }) {
  const boarding = t.status === 'boarding'
  const queued = t.status === 'queued'
  const sell = canSell && (t.status === 'scheduled' || boarding || queued)
  const dispatch = canDispatch && (boarding || t.status === 'scheduled')
  const queue = canDispatch && queued && queueProps

  return (
    <article className="flex flex-col overflow-hidden rounded-lg border border-slate-200 bg-white">
      <Link to={`/station/trips/${t.id}`} className="block flex-1 p-3.5 hover:bg-slate-50">
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0">
            <p className="truncate text-[15px] font-semibold text-slate-900">{t.route}</p>
            <p className="mt-0.5 truncate text-xs text-slate-500">{t.bus} <span className="ml-1 rounded bg-amber-100 px-1.5 py-0.5 font-mono font-semibold text-amber-900">{t.bus_registration}</span></p>
          </div>
          <Badge>{t.status}</Badge>
        </div>

        <div className="mt-2 flex items-baseline justify-between gap-2">
          <p className={cx('font-semibold text-brand-700', t.fill_and_go ? 'text-sm' : 'text-lg')}>{t.departure_time_label}</p>
          {(boarding || queued) && <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{boarding ? 'At the front' : `Queue place ${queueProps?.place ?? ''}`}</span>}
        </div>

        <dl className="mt-2.5 flex divide-x divide-slate-200 rounded-md bg-slate-50 text-center">
          {[['Seats', t.total_seats], ['Booked', t.booked_seats], ['Arrived', t.confirmed_arrivals, 'text-emerald-700']].map(([label, value, tone]) => (
            <div key={label} className="flex-1 py-1.5">
              <dd className={cx('text-sm font-semibold', tone || 'text-slate-900')}>{value}</dd>
              <dt className="text-[11px] text-slate-500">{label}</dt>
            </div>
          ))}
        </dl>

        {t.station_due != null && t.station_due > 0 && (
          <p className="mt-2 text-xs text-slate-600">
            Station due {money(t.station_due)}: <b className={t.due_paid ? 'text-emerald-700' : 'text-amber-800'}>{t.due_paid ? 'collected' : 'outstanding'}</b>
          </p>
        )}
      </Link>

      {(sell || dispatch || queue) && (
        <div className="flex flex-wrap gap-2 border-t border-slate-200 bg-slate-50 p-2.5">
          {sell && (
            <Link to={`/station/trips/${t.id}/sell`} className="grid min-h-9 flex-1 place-items-center rounded-md border border-brand-600 bg-white px-2 text-center text-sm font-semibold text-brand-700 hover:bg-brand-50">
              Sell seats
            </Link>
          )}
          {dispatch && (
            <DispatchButton tripId={t.id} vehicle={`${t.route}: ${t.bus} (${t.bus_registration})`} due={t.due_to_collect} onDone={reload} label="Dispatch" size="sm" className="flex-1" />
          )}
          {queue && <QueueControls t={t} {...queueProps} reload={reload} />}
        </div>
      )}
    </article>
  )
}

export default function TodayTrips() {
  const { user } = useAuth()
  const { data, loading, error, reload } = useFetch('/station/trips')
  const [actionError, setActionError] = useState('')
  const [tab, setTab] = useState(null) // null = the first section that has something in it
  const isDriver = user?.role === 'driver'
  const canSell = !isDriver
  const trips = data || []

  // Drivers care about vehicles that are on the road or about to leave.
  const visible = isDriver ? trips.filter((t) => t.fill_and_go) : trips
  const boardingNow = visible.filter((t) => t.fill_and_go && t.status === 'boarding')
  const waiting = visible.filter((t) => t.fill_and_go && t.status === 'queued')
  const road = visible.filter((t) => t.fill_and_go && !['boarding', 'queued'].includes(t.status)).reverse() // latest first
  const timetabled = visible.filter((t) => !t.fill_and_go)

  // Queue controls depend on the vehicle's neighbours on the same route.
  const queueFor = (t) => {
    const sameRoute = waiting.filter((x) => x.route_id === t.route_id)
    const i = sameRoute.findIndex((x) => x.id === t.id)
    const front = boardingNow.find((x) => x.route_id === t.route_id)
    return {
      place: i + 2, // the boarding vehicle is place 1
      isFirstQueued: i === 0,
      isLastQueued: i === sameRoute.length - 1,
      frontIsEmpty: !front || front.booked_seats === 0,
      onError: setActionError,
    }
  }

  const sections = [
    { key: 'boarding', label: 'Boarding', items: boardingNow, note: 'The vehicle at the front of the queue. Customers book it first; when it is full they book the next one.', empty: 'Nothing is boarding right now.' },
    !isDriver && { key: 'queue', label: 'Queue', items: waiting, withQueue: true, note: 'In order. When the front vehicle leaves, the first one here moves up automatically.', empty: 'No vehicles are waiting in the queue.' },
    { key: 'left', label: isDriver ? 'On the road' : 'Departed', items: road, empty: 'No vehicle has left yet today.' },
    !isDriver && { key: 'timetabled', label: 'Scheduled', items: timetabled, empty: 'No scheduled trips today.' },
  ].filter(Boolean)

  const active = sections.find((s) => s.key === tab) || sections.find((s) => s.items.length > 0) || sections[0]

  return (
    <div>
      <PageHeader
        title={isDriver ? 'My vehicle' : 'Vehicles and trips'}
        subtitle={longDate(todayYmd())}
        actions={
          <>
            <button onClick={reload} className="min-h-10 rounded-md border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">Refresh</button>
            {!isDriver && <OpenBoarding onOpened={reload} />}
          </>
        }
      />
      {loading && !data && <PageLoader />}
      <Alert className="mb-4">{error || actionError}</Alert>

      {data && visible.length === 0 ? (
        <Card><Empty>{isDriver ? 'No vehicles on your routes today.' : 'No trips for your routes today. If you expected to see trips, ask an admin to check the routes assigned to you.'}</Empty></Card>
      ) : data && (
        <>
          <Tabs className="mb-4" value={active.key} onChange={setTab} tabs={sections.map((s) => ({ key: s.key, label: s.label, count: s.items.length }))} />

          {active.note && !isDriver && active.items.length > 0 && <p className="mb-3 text-[13px] text-slate-500">{active.note}</p>}

          {active.items.length === 0 ? (
            <Card><Empty>{active.empty}</Empty></Card>
          ) : (
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
              {active.items.map((t) => (
                <TripCard key={t.id} t={t} canSell={canSell} canDispatch={!isDriver} queueProps={active.withQueue ? queueFor(t) : undefined} reload={reload} />
              ))}
            </div>
          )}

          {active.key === 'left' && !isDriver && road.length > 0 && (
            <p className="mt-4 text-sm"><Link to="/station/departures" className="font-semibold text-brand-700">Open Departures for the day’s record and dues</Link></p>
          )}
        </>
      )}
    </div>
  )
}
