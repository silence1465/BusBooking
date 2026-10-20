import CrudPage from '../../components/CrudPage'
import { money } from '../../lib/format'

export default function VehicleCategories() {
  return (
    <CrudPage
      title="Vehicle types and station dues"
      subtitle="Each type pays its own due to the station every time a vehicle departs. Set a special rate on a single bus under Buses."
      endpoint="/admin/vehicle-categories"
      createLabel="Add vehicle type"
      defaults={{ station_due: 0, seat_selection: true }}
      columns={[
        { label: 'Type', render: (c) => <b>{c.name}</b> },
        { label: 'Station due per departure', render: (c) => money(c.station_due) },
        { label: 'Seat booking', render: (c) => (c.seat_selection ? 'Passengers choose a seat' : <span className="font-semibold text-brand-700">Open seating</span>) },
        { label: 'Buses', key: 'buses_count' },
      ]}
      fields={[
        { name: 'name', label: 'Type name', hint: 'e.g. Minibus, Coaster, Big bus' },
        { name: 'station_due', label: 'Station due (GHS)', type: 'number', hint: 'Charged each time a vehicle of this type departs. 0 means no due.' },
        { name: 'seat_selection', label: 'Passengers choose their seat when booking', type: 'checkbox' },
      ]}
    />
  )
}
