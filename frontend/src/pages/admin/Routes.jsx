import CrudPage from '../../components/CrudPage'
import { Badge } from '../../components/ui'
import { useFetch } from '../../lib/useFetch'
import { money } from '../../lib/format'

const STATUS = [{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]
const MODES = [
  { value: 'scheduled', label: 'Scheduled: trips have a departure time' },
  { value: 'fill_and_go', label: 'Fill and go: leaves when the vehicle is full' },
]

// The form keeps one flat field per vehicle type ("cc_3"); the API takes them as { category_commissions: { 3: 15 } }.
const CC = 'cc_'

export default function Routes() {
  const { data: types } = useFetch('/admin/vehicle-categories')

  const commissionCell = (r) => {
    const special = (types || []).filter((t) => r.category_commissions?.[t.id] != null)
    if (!Number(r.commission) && special.length === 0) return <span className="text-slate-400">None</span>
    return (
      <div className="leading-tight">
        <p>{money(r.commission)} <span className="text-xs text-slate-500">per seat</span></p>
        {special.map((t) => <p key={t.id} className="text-xs text-slate-500">{t.name}: {money(r.category_commissions[t.id])}</p>)}
      </div>
    )
  }

  return (
    <CrudPage
      title="Routes"
      subtitle="Fill-and-go routes have no timetable: station masters open a vehicle and it leaves when full."
      endpoint="/admin/routes"
      createLabel="Add route"
      defaults={{ status: 'active', mode: 'scheduled', commission: 0 }}
      toForm={(row) => ({ ...row, ...Object.fromEntries(Object.entries(row.category_commissions || {}).map(([id, amount]) => [CC + id, amount])) })}
      toPayload={(payload) => {
        const body = { category_commissions: {} }
        for (const [key, value] of Object.entries(payload)) {
          if (key.startsWith(CC)) body.category_commissions[key.slice(CC.length)] = value === '' ? null : value
          else body[key] = value
        }
        return body
      }}
      columns={[
        { label: 'Route', render: (r) => <b>{r.origin} → {r.destination}</b> },
        {
          label: 'Type',
          render: (r) => (r.mode === 'fill_and_go' ? <Badge color="blue">Fill and go</Badge> : <Badge color="gray">Scheduled</Badge>),
        },
        { label: 'Fare', render: (r) => (r.mode === 'fill_and_go' && r.fare ? money(r.fare) : '—') },
        { label: 'Online commission', render: commissionCell },
        { label: 'Pickup points', key: 'pickup_points_count' },
        { label: 'Status', render: (r) => <Badge>{r.status}</Badge> },
      ]}
      fields={[
        { name: 'origin', label: 'Origin' },
        { name: 'destination', label: 'Destination' },
        { name: 'mode', label: 'Departure type', type: 'select', options: MODES },
        {
          name: 'fare',
          label: 'Fare per seat (GHS)',
          type: 'number',
          required: false,
          hint: 'Required for fill-and-go routes. Scheduled trips set their own price.',
        },
        {
          name: 'commission',
          label: 'Online commission per seat (GHS)',
          type: 'number',
          required: false,
          hint: 'Taken out of each seat sold online. Fare 150 with commission 10: the station gets 140, online gets 10.',
        },
        ...(types || []).map((t) => ({
          name: CC + t.id,
          label: `Commission for ${t.name} (GHS)`,
          type: 'number',
          required: false,
          hint: 'Leave empty to use the route’s commission.',
        })),
        { name: 'status', label: 'Status', type: 'select', options: STATUS },
      ]}
    />
  )
}
