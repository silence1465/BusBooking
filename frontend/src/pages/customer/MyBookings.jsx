import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext'
import { useFetch } from '../../lib/useFetch'
import { forgetTicket, savedTickets } from '../../lib/myTickets'
import { Alert, Badge, Button, Card, Input, PageLoader } from '../../components/ui'
import { longDate, seatLabel } from '../../lib/format'

function Lookup() {
  const navigate = useNavigate()
  const [code, setCode] = useState('')
  const [phone, setPhone] = useState('')

  return (
    <Card>
      <h2 className="mb-1 font-bold">Find a ticket</h2>
      <p className="mb-3 text-sm text-slate-500">Enter your booking code and the phone number you booked with.</p>
      <form
        className="grid gap-3 sm:grid-cols-3 sm:items-end"
        onSubmit={(e) => {
          e.preventDefault()
          navigate(`/ticket/${encodeURIComponent(code.trim().toUpperCase())}?phone=${encodeURIComponent(phone.trim())}`)
        }}
      >
        <Input label="Booking code" placeholder="BUS-XXXXXX" value={code} onChange={(e) => setCode(e.target.value)} required />
        <Input label="Phone number" type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} required />
        <Button type="submit">View ticket</Button>
      </form>
    </Card>
  )
}

function AccountBookings() {
  const { data, loading, error } = useFetch('/my/bookings')
  if (loading) return <PageLoader />
  if (error) return <Alert>{error}</Alert>
  if (!data?.length) return <p className="py-4 text-sm text-slate-500">No bookings on your account yet.</p>

  return (
    <div className="space-y-3">
      {data.map((b) => (
        <Link key={b.id} to={`/ticket/${b.code}?phone=${encodeURIComponent(b.phone)}`}>
          <Card className="mb-3 flex items-center justify-between hover:border-brand-500">
            <div>
              <p className="font-bold">{b.trip.origin} → {b.trip.destination}</p>
              <p className="text-sm text-slate-500">{longDate(b.trip.travel_date)} · {b.trip.departure_time_label} · Seat: {seatLabel(b)}</p>
              <p className="text-xs font-mono text-slate-400">{b.code}</p>
            </div>
            <Badge>{b.status}</Badge>
          </Card>
        </Link>
      ))}
    </div>
  )
}

export default function MyBookings() {
  const { user } = useAuth()
  const [saved, setSaved] = useState(savedTickets())

  return (
    <div className="mx-auto max-w-2xl space-y-5">
      <h1 className="text-2xl font-bold">My bookings</h1>

      {user?.role === 'customer' && <AccountBookings />}

      {saved.length > 0 && (
        <div>
          <h2 className="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Tickets on this device</h2>
          <div className="space-y-2">
            {saved.map((t) => (
              <Card key={t.code} className="flex items-center justify-between">
                <Link to={`/ticket/${t.code}?phone=${encodeURIComponent(t.phone)}`} className="inline-flex min-h-11 items-center font-mono font-bold text-brand-700">
                  {t.code}
                </Link>
                <button className="inline-flex min-h-11 items-center px-2 text-xs text-slate-500 hover:text-red-600" onClick={() => { forgetTicket(t.code); setSaved(savedTickets()) }}>
                  Remove
                </button>
              </Card>
            ))}
          </div>
        </div>
      )}

      <Lookup />
      {!user && (
        <p className="text-center text-sm text-slate-500">
          Want your bookings on every device? <Link to="/profile" className="font-semibold text-brand-700">Create a free account</Link>
        </p>
      )}
    </div>
  )
}
