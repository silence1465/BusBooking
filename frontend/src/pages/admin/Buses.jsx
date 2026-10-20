import CrudPage from '../../components/CrudPage'
import { Badge, BusPhoto, PageLoader } from '../../components/ui'
import { useFetch } from '../../lib/useFetch'
import { money } from '../../lib/format'

const STATUS = [{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]

/** admin: everything. Station master: no delete, and no station dues (those are an admin's setting). */
export default function Buses({ admin = true }) {
  const categories = useFetch(admin ? '/admin/vehicle-categories' : '/manage/vehicle-types')
  if (!categories.data) return <PageLoader />

  return (
    <CrudPage
      title="Buses"
      subtitle="Seats are created automatically from the number of seats."
      endpoint={admin ? '/admin/buses' : '/manage/buses'}
      canDelete={admin}
      createLabel="Add bus"
      defaults={{ status: 'active', category_id: '', station_due_override: '' }}
      toForm={(b) => ({ ...b, category_id: b.category_id ?? '', station_due_override: b.station_due_override ?? '' })}
      columns={[
        { label: 'Image', render: (r) => <BusPhoto src={r.image_url} alt={r.name} className="h-12 w-20" /> },
        { label: 'Name', key: 'name', primary: true },
        { label: 'Registration', render: (r) => <span className="rounded bg-amber-100 px-1.5 py-0.5 font-mono text-[13px] font-semibold text-amber-900">{r.registration_number}</span> },
        { label: 'Seats', key: 'seat_count' },
        { label: 'Type', render: (r) => r.category?.name || <span className="text-slate-400">None</span> },
        ...(!admin ? [] : [{
          label: 'Station due',
          render: (r) =>
            r.station_due_override != null ? (
              <span>{money(r.station_due_override)} <span className="text-xs text-slate-500">(special rate)</span></span>
            ) : r.category ? (
              money(r.category.station_due)
            ) : (
              <span className="text-slate-400">No due</span>
            ),
        }]),
        { label: 'Status', render: (r) => <Badge>{r.status}</Badge> },
      ]}
      fields={[
        { name: 'name', label: 'Bus name' },
        { name: 'registration_number', label: 'Registration number', hint: 'e.g. GT 1234-26' },
        { name: 'seat_count', label: 'Number of seats', type: 'number', hint: 'Cannot be changed after the bus has trips.' },
        {
          name: 'category_id',
          label: 'Vehicle type',
          type: 'select',
          required: false,
          hint: admin ? 'Decides the station due it pays when it departs.' : 'Express and Voxy let passengers sit anywhere.',
          options: categories.data.map((c) => ({ value: c.id, label: admin ? `${c.name} (${money(c.station_due)})` : c.name })),
        },
        ...(admin ? [{ name: 'station_due_override', label: 'Special station due (GHS)', type: 'number', required: false, hint: 'Optional. Overrides the vehicle type’s due for this bus only.' }] : []),
        { name: 'status', label: 'Status', type: 'select', options: STATUS },
        { name: 'image', label: 'Bus photo (optional)', type: 'file', hint: 'JPG, PNG or WebP, up to 2 MB.' },
      ]}
    />
  )
}
