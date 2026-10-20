import CrudPage from '../../components/CrudPage'
import { Badge, PageLoader, Switch } from '../../components/ui'
import { api, errorMessage } from '../../lib/api'
import { useFetch } from '../../lib/useFetch'
import { useToast } from '../../components/feedback'

// What a station master may create when an admin switches it on.
const CAN = [
  ['can_manage_buses', 'Buses'],
  ['can_manage_pickup_points', 'Pickup points'],
  ['can_manage_trips', 'Trips'],
]

/** One switch per thing, saved the moment it is flipped. */
function Permissions({ user, reload }) {
  const toast = useToast()
  if (user.role !== 'station_master') return <span className="text-slate-400">—</span>

  const flip = async (key, label, on) => {
    try {
      await api.put(`/admin/station-masters/${user.id}`, { [key]: on })
      toast(`${user.name} ${on ? 'can now create' : 'can no longer create'} ${label.toLowerCase()}`)
      reload()
    } catch (err) {
      toast(errorMessage(err), 'error')
    }
  }

  return (
    <ul className="space-y-1.5">
      {CAN.map(([key, label]) => (
        <li key={key} className="flex items-center justify-between gap-4">
          <span className="text-[13px] text-slate-700">{label}</span>
          <Switch checked={!!user[key]} onChange={(on) => flip(key, label, on)} label={`${user[key] ? 'Turn off' : 'Turn on'} ${label} for ${user.name}`} />
        </li>
      ))}
    </ul>
  )
}

export default function StationMasters() {
  const routes = useFetch('/admin/routes')
  const buses = useFetch('/admin/buses')
  if (!routes.data || !buses.data) return <PageLoader />

  return (
    <CrudPage
      title="Staff"
      subtitle="Station masters sell and verify at the station. Drivers ride the vehicle and check passengers in at pickup points. Each can only work on the routes ticked for them."
      endpoint="/admin/station-masters"
      createLabel="Add staff member"
      defaults={{ route_ids: [], role: 'station_master' }}
      toForm={(u) => ({ name: u.name, email: u.email, phone: u.phone || '', password: '', is_active: u.is_active, route_ids: u.route_ids || [], bus_id: u.bus_id ?? '' })}
      columns={[
        { label: 'Name', render: (u) => <b>{u.name}</b> },
        { label: 'Role', render: (u) => <Badge color={u.role === 'driver' ? 'blue' : 'gray'}>{u.role === 'driver' ? 'Driver' : 'Station master'}</Badge> },
        { label: 'Can create', wide: true, render: (u, ctx) => <Permissions user={u} reload={ctx.reload} /> },
        { label: 'Bus', render: (u) => (u.role === 'driver' ? u.bus || <span className="text-xs text-slate-500">Not linked: picks a vehicle each day</span> : '—') },
        { label: 'Email', key: 'email' },
        { label: 'Phone', render: (u) => u.phone || '—' },
        {
          label: 'Assigned routes',
          render: (u) =>
            u.routes?.length ? (
              <div className="flex flex-wrap gap-1.5">
                {u.routes.map((r) => <span key={r} className="rounded bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-700">{r}</span>)}
              </div>
            ) : (
              <span className="text-xs font-semibold text-amber-800">None: cannot see any trips</span>
            ),
        },
        { label: 'Status', render: (u) => <Badge>{u.is_active ? 'active' : 'inactive'}</Badge> },
      ]}
      fields={[
        { name: 'role', label: 'Role', type: 'select', createOnly: true, options: [{ value: 'station_master', label: 'Station master' }, { value: 'driver', label: 'Driver (on the vehicle)' }] },
        { name: 'name', label: 'Full name' },
        { name: 'email', label: 'Email (login)', type: 'email' },
        { name: 'phone', label: 'Phone (also works to sign in)', type: 'tel', optional: true },
        { name: 'password', label: 'Password', type: 'password', hint: 'Min 8 characters. When editing, leave blank to keep the current password.', required: false },
        {
          name: 'route_ids',
          label: 'Routes this person can work on',
          type: 'multiselect',
          hint: 'They can only see and check in trips on the ticked routes.',
          options: routes.data.filter((r) => r.status === 'active').map((r) => ({ value: r.id, label: `${r.origin} → ${r.destination}` })),
        },
        {
          name: 'bus_id',
          label: 'Bus this driver drives (drivers only)',
          type: 'select',
          required: false,
          hint: 'Optional. Linked drivers only see this bus, and their phone opens straight to its current trip.',
          options: buses.data.filter((b) => b.status === 'active').map((b) => ({ value: b.id, label: `${b.name} · ${b.registration_number}` })),
        },
        { name: 'is_active', label: 'Account active', type: 'checkbox', editOnly: true },
      ]}
    />
  )
}
