import { useState } from 'react'
import CrudPage from '../../components/CrudPage'
import { Alert, Badge, Button, Input, Modal, PageLoader, Select } from '../../components/ui'
import { api, errorMessage } from '../../lib/api'
import { useFetch } from '../../lib/useFetch'
import { useToast } from '../../components/feedback'

const blank = () => ({ name: '', description: '', is_main_station: false })

/** Add several stops to a route in one go. They are numbered in the order listed, after the stops it already has. */
function AddStops({ open, routes, onClose, onSaved }) {
  const toast = useToast()
  const [routeId, setRouteId] = useState('')
  const [rows, setRows] = useState([blank()])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  const change = (i, patch) => setRows((list) => list.map((r, n) => (n === i ? { ...r, ...patch } : r)))
  const filled = rows.filter((r) => r.name.trim())

  const save = async (e) => {
    e.preventDefault()
    setBusy(true)
    setError('')
    try {
      const res = await api.post(`${routes.base}/pickup-points/bulk`, { route_id: routeId, points: filled })
      toast(`${res.data.data.length} pickup point${res.data.data.length === 1 ? '' : 's'} added`)
      setRows([blank()])
      onSaved()
      onClose()
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} title="Add pickup points">
      <form onSubmit={save} className="space-y-3">
        <Alert>{error}</Alert>
        <Select label="Route" value={routeId} onChange={(e) => setRouteId(e.target.value)} required>
          <option value="">Select…</option>
          {routes.list.map((r) => <option key={r.id} value={r.id}>{r.origin} → {r.destination}</option>)}
        </Select>

        <ol className="space-y-2">
          {rows.map((r, i) => (
            <li key={i} className="space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
              <div className="flex items-end gap-2">
                <span className="mb-2 grid h-7 w-7 shrink-0 place-items-center rounded-full bg-white text-xs font-semibold text-slate-600 ring-1 ring-slate-200">{i + 1}</span>
                <div className="min-w-0 flex-1">
                  <Input
                    label={i === 0 ? 'Pickup point name' : undefined}
                    placeholder="e.g. Achimota"
                    value={r.name}
                    autoFocus={i === rows.length - 1 && i > 0}
                    onChange={(e) => change(i, { name: e.target.value })}
                    onKeyDown={(e) => { if (e.key === 'Enter' && i === rows.length - 1 && r.name.trim()) { e.preventDefault(); setRows((list) => [...list, blank()]) } }}
                    maxLength={120}
                  />
                </div>
                {rows.length > 1 && (
                  <button type="button" onClick={() => setRows((list) => list.filter((_, n) => n !== i))} className="mb-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-md text-slate-500 hover:bg-white hover:text-red-700" aria-label={`Remove stop ${i + 1}`}>
                    <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
                  </button>
                )}
              </div>
              <div className="ml-9 space-y-2">
                <Input placeholder="Description / location (optional)" value={r.description} onChange={(e) => change(i, { description: e.target.value })} maxLength={255} />
                <label className="flex items-center gap-2 text-sm text-slate-700">
                  <input type="checkbox" className="h-4 w-4 accent-brand-600" checked={r.is_main_station} onChange={(e) => change(i, { is_main_station: e.target.checked })} />
                  Main station (the vehicle fills up and leaves from here)
                </label>
              </div>
            </li>
          ))}
        </ol>

        <Button type="button" variant="secondary" onClick={() => setRows((list) => [...list, blank()])}>+ Add another stop</Button>
        <p className="text-xs text-slate-500">Stops are numbered in the order listed here, after the stops the route already has.</p>

        <div className="sticky -bottom-5 -mx-5 -mb-5 flex justify-end gap-2 border-t border-slate-200 bg-white px-5 py-3">
          <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" loading={busy} disabled={!routeId || filled.length === 0}>{filled.length > 1 ? `Save ${filled.length} stops` : 'Save'}</Button>
        </div>
      </form>
    </Modal>
  )
}

/** admin: all routes. Station master: only the routes assigned to them, and no delete. */
export default function PickupPoints({ admin = true }) {
  const base = admin ? '/admin' : '/manage'
  const routes = useFetch(`${base}/routes`)
  const [adding, setAdding] = useState(null) // { reload } while the add pop-up is open
  if (!routes.data) return <PageLoader />

  return (
    <>
    <AddStops open={!!adding} routes={{ base, list: routes.data.filter((r) => r.status === 'active') }} onClose={() => setAdding(null)} onSaved={() => adding?.reload()} />
    <CrudPage
      title="Pickup points"
      subtitle="Stop order is the order the vehicle visits them. Tick “main station” for the place the vehicle fills up and leaves from."
      endpoint={admin ? '/admin/pickup-points' : '/manage/pickup-points'}
      canDelete={admin}
      createLabel="Add pickup points"
      onCreate={setAdding}
      defaults={{ status: 'active', stop_order: 1, is_main_station: false }}
      columns={[
        { label: 'Route', render: (r) => (r.route ? `${r.route.origin} → ${r.route.destination}` : '—') },
        { label: 'Stop', render: (r) => r.stop_order },
        {
          label: 'Pickup point',
          primary: true,
          render: (r) => (
            <span>
              <b>{r.name}</b>
              {r.is_main_station && <span className="ml-2 rounded bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-700">Main station</span>}
            </span>
          ),
        },
        { label: 'Location / description', render: (r) => r.description || '—' },
        { label: 'Status', render: (r) => <Badge>{r.status}</Badge> },
      ]}
      fields={[
        { name: 'route_id', label: 'Route', type: 'select', createOnly: true, options: routes.data.map((r) => ({ value: r.id, label: `${r.origin} → ${r.destination}` })) },
        { name: 'name', label: 'Pickup point name' },
        { name: 'description', label: 'Description / location (optional)', optional: true },
        { name: 'stop_order', label: 'Stop order', type: 'number', hint: '1 is first, then 2, 3 and so on along the route.' },
        { name: 'is_main_station', label: 'Main station (passengers board here before the vehicle leaves)', type: 'checkbox' },
        { name: 'status', label: 'Status', type: 'select', options: [{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }] },
      ]}
    />
    </>
  )
}
