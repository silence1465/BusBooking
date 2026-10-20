import { Navigate, Route, Routes } from 'react-router-dom'
import RequireRole from './components/RequireRole'
import CustomerLayout from './layouts/CustomerLayout'
import PanelLayout from './layouts/PanelLayout'
import StationLayout from './layouts/StationLayout'
import Login from './pages/Login'
import Home from './pages/customer/Home'
import TripBooking from './pages/customer/TripBooking'
import PaymentCallback from './pages/customer/PaymentCallback'
import TicketPage from './pages/customer/TicketPage'
import MyBookings from './pages/customer/MyBookings'
import Profile from './pages/customer/Profile'
import Privacy from './pages/customer/Privacy'
import Contact from './pages/customer/Contact'
import Verify from './pages/station/Verify'
import SellSeats from './pages/station/SellSeats'
import Departures from './pages/station/Departures'
import OnlineAccount from './pages/station/OnlineAccount'
import Manage, { ManageIndex, ManageSection } from './pages/station/Manage'
import TodayTrips from './pages/station/TodayTrips'
import Manifest from './pages/station/Manifest'
import Dashboard from './pages/admin/Dashboard'
import Buses from './pages/admin/Buses'
import VehicleCategories from './pages/admin/VehicleCategories'
import AdminRoutes from './pages/admin/Routes'
import PickupPoints from './pages/admin/PickupPoints'
import Trips from './pages/admin/Trips'
import Bookings from './pages/admin/Bookings'
import Payments from './pages/admin/Payments'
import StationMasters from './pages/admin/StationMasters'
import SmsLogs from './pages/admin/SmsLogs'
import Settings from './pages/admin/Settings'

const adminNav = [
  { label: 'Overview', items: [{ to: '/admin', label: 'Dashboard', end: true }] },
  {
    label: 'Fleet and schedule',
    items: [
      { to: '/admin/buses', label: 'Buses' },
      { to: '/admin/vehicle-categories', label: 'Vehicle types and dues' },
      { to: '/admin/routes', label: 'Routes' },
      { to: '/admin/pickup-points', label: 'Pickup points' },
      { to: '/admin/trips', label: 'Trips' },
    ],
  },
  {
    label: 'Sales',
    items: [
      { to: '/admin/bookings', label: 'Bookings' },
      { to: '/admin/payments', label: 'Payments' },
      { to: '/admin/departures', label: 'Departures and dues' },
      { to: '/admin/online-account', label: 'Online account' },
    ],
  },
  {
    label: 'System',
    items: [
      { to: '/admin/station-masters', label: 'Staff' },
      { to: '/admin/sms-logs', label: 'SMS logs' },
      { to: '/admin/settings', label: 'Settings' },
      { to: '/station', label: 'Station console' },
    ],
  },
]

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />

      <Route element={<CustomerLayout />}>
        <Route index element={<Home />} />
        <Route path="trips/:id" element={<TripBooking />} />
        <Route path="payment/callback" element={<PaymentCallback />} />
        <Route path="ticket/:code" element={<TicketPage />} />
        <Route path="bookings" element={<MyBookings />} />
        <Route path="profile" element={<Profile />} />
        <Route path="privacy" element={<Privacy />} />
        <Route path="contact" element={<Contact />} />
      </Route>

      <Route element={<RequireRole roles={['station_master', 'driver', 'admin']} />}>
        <Route path="station" element={<StationLayout />}>
          <Route index element={<Verify />} />
          <Route path="trips" element={<TodayTrips />} />
          <Route path="trips/:id" element={<Manifest />} />
          <Route path="sell" element={<SellSeats />} />
          <Route path="departures" element={<Departures />} />
          <Route path="online-account" element={<OnlineAccount />} />
          <Route path="manage" element={<Manage />}>
            <Route index element={<ManageIndex />} />
            <Route path="buses" element={<ManageSection what="buses" />} />
            <Route path="pickup-points" element={<ManageSection what="pickup_points" />} />
            <Route path="trips" element={<ManageSection what="trips" />} />
          </Route>
          <Route path="trips/:id/sell" element={<SellSeats />} />
        </Route>
      </Route>

      <Route element={<RequireRole roles={['admin']} />}>
        <Route path="admin" element={<PanelLayout title="Administration" groups={adminNav} />}>
          <Route index element={<Dashboard />} />
          <Route path="buses" element={<Buses />} />
          <Route path="vehicle-categories" element={<VehicleCategories />} />
          <Route path="departures" element={<Departures />} />
          <Route path="online-account" element={<OnlineAccount />} />
          <Route path="routes" element={<AdminRoutes />} />
          <Route path="pickup-points" element={<PickupPoints />} />
          <Route path="trips" element={<Trips />} />
          <Route path="trips/:id/passengers" element={<Manifest />} />
          <Route path="bookings" element={<Bookings />} />
          <Route path="payments" element={<Payments />} />
          <Route path="station-masters" element={<StationMasters />} />
          <Route path="sms-logs" element={<SmsLogs />} />
          <Route path="settings" element={<Settings />} />
        </Route>
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
