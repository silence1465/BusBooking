import { NavLink, Navigate, Outlet } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext'
import { Alert, Card, cx } from '../../components/ui'
import Buses from '../admin/Buses'
import PickupPoints from '../admin/PickupPoints'
import Trips from '../admin/Trips'

// What an admin can switch on for a station master, in the order shown.
const SECTIONS = [
  ['buses', 'Buses', 'buses'],
  ['pickup_points', 'Pickup points', 'pickup-points'],
  ['trips', 'Trips', 'trips'],
]

const useAllowed = () => {
  const { user } = useAuth()
  return SECTIONS.filter(([key]) => user?.permissions?.[key])
}

/** Shell for the create-and-edit pages a station master gets when an admin switches them on. */
export default function Manage() {
  const allowed = useAllowed()

  if (allowed.length === 0) {
    return <Card><Alert type="info">An admin has not let you create buses, pickup points or trips. Ask them to switch it on for you.</Alert></Card>
  }

  return (
    <div>
      <div className="mb-5 flex gap-1 overflow-x-auto border-b border-slate-200" role="tablist">
        {allowed.map(([key, label, path]) => (
          <NavLink
            key={key}
            to={`/station/manage/${path}`}
            className={({ isActive }) => cx('-mb-px flex min-h-11 shrink-0 items-center border-b-2 px-4 text-sm', isActive ? 'border-brand-600 font-bold text-slate-900' : 'border-transparent font-medium text-slate-500 hover:text-slate-900')}
          >
            {label}
          </NavLink>
        ))}
      </div>
      <Outlet />
    </div>
  )
}

export function ManageIndex() {
  const allowed = useAllowed()
  return allowed.length ? <Navigate to={`/station/manage/${allowed[0][2]}`} replace /> : null
}

const PAGES = { buses: Buses, pickup_points: PickupPoints, trips: Trips }

/** One section, only if it is switched on for this person. */
export function ManageSection({ what }) {
  const { user } = useAuth()
  const Page = PAGES[what]

  if (!user?.permissions?.[what]) return <Card><Alert type="info">An admin has not switched this on for you.</Alert></Card>

  return <Page admin={false} />
}
