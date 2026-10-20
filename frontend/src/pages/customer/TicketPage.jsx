import AdSlot from '../../components/AdSlot'
import { useEffect, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { api, errorMessage } from '../../lib/api'
import { savedTickets, saveTicket } from '../../lib/myTickets'
import { Alert, Button, Card, Input, PageLoader } from '../../components/ui'
import Ticket from '../../components/Ticket'

export default function TicketPage() {
  const { code } = useParams()
  const [search] = useSearchParams()
  const isNew = search.get('new') === '1'
  const [phone, setPhone] = useState(search.get('phone') || savedTickets().find((t) => t.code === code)?.phone || '')
  const [booking, setBooking] = useState(null)
  const [loading, setLoading] = useState(!!phone)
  const [error, setError] = useState('')

  const load = async (p) => {
    setLoading(true)
    setError('')
    try {
      const res = await api.get(`/tickets/${encodeURIComponent(code)}`, { params: { phone: p } })
      setBooking(res.data.data)
      saveTicket(res.data.data.code, p)
    } catch (e) {
      setBooking(null)
      setError(errorMessage(e))
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    if (phone) load(phone)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [code])

  if (loading) return <PageLoader />

  if (!booking) {
    return (
      <Card className="mx-auto max-w-md space-y-4">
        <h1 className="text-lg font-bold">View ticket {code}</h1>
        <p className="text-sm text-slate-600">Enter the phone number used for this booking.</p>
        <Alert>{error}</Alert>
        <form onSubmit={(e) => { e.preventDefault(); load(phone) }} className="space-y-3">
          <Input label="Phone number" type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} required />
          <Button type="submit" className="w-full">Show ticket</Button>
        </form>
      </Card>
    )
  }

  const paid = booking.payment?.status === 'paid'
  return (
    <div className="mx-auto max-w-md space-y-4">
      {isNew && paid && (
        <div className="no-print flex items-center gap-3 rounded-lg border border-emerald-200 bg-emerald-100 px-4 py-3 text-emerald-800">
          <svg viewBox="0 0 24 24" className="h-6 w-6 shrink-0" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M12 21a9 9 0 100-18 9 9 0 000 18zM8 12l3 3 5-6" />
          </svg>
          <div>
            <p className="font-semibold">Booking confirmed</p>
            <p className="text-[13px]">Details sent by SMS to <span className="font-mono">{booking.phone}</span></p>
          </div>
        </div>
      )}
      {booking.status === 'cancelled' && <Alert>This booking has been cancelled.</Alert>}
      {booking.status === 'pending' && <Alert type="warning">Payment has not been confirmed for this booking.</Alert>}
      <Ticket booking={booking} />
      <Link to="/" className="no-print flex min-h-11 items-center justify-center text-sm font-semibold text-brand-700">Book another trip</Link>
      {isNew && paid && <AdSlot placement="confirmation" />}
    </div>
  )
}
