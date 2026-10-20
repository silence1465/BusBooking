import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { api, errorMessage } from '../../lib/api'
import { saveTicket } from '../../lib/myTickets'
import { Alert, Button, Card, PageLoader } from '../../components/ui'

/**
 * Landing page after Paystack. The query string is NOT trusted: we only pass the
 * payment reference to our backend, which asks Paystack for the real result.
 */
export default function PaymentCallback() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const reference = params.get('reference') || params.get('trxref')
  const [state, setState] = useState({ status: 'checking', message: '' })
  const started = useRef(false)

  const verify = useCallback(async () => {
    setState({ status: 'checking', message: '' })
    try {
      const res = await api.post('/payments/verify', { reference })
      const { payment_status: status, booking } = res.data.data
      if (status === 'paid' && booking.status === 'confirmed') {
        saveTicket(booking.code, booking.phone)
        navigate(`/ticket/${booking.code}?phone=${encodeURIComponent(booking.phone)}&new=1`, { replace: true })
      } else if (status === 'paid') {
        setState({ status: 'problem', message: 'Your payment was received but we could not confirm the seats (they may have been released). Please contact support with your payment reference: ' + reference })
      } else if (status === 'failed') {
        setState({ status: 'failed', message: 'The payment was not successful. You have not been charged for this booking.' })
      } else {
        setState({ status: 'pending', message: 'We have not received a confirmed payment yet.' })
      }
    } catch (e) {
      setState({ status: 'error', message: errorMessage(e) })
    }
  }, [reference, navigate])

  useEffect(() => {
    if (started.current || !reference) return
    started.current = true
    verify()
  }, [reference, verify])

  if (!reference) {
    return (
      <Card className="mx-auto max-w-md text-center">
        <p className="mb-3">No payment reference found.</p>
        <Link to="/"><Button>Back to home</Button></Link>
      </Card>
    )
  }

  if (state.status === 'checking') {
    return (
      <div className="mx-auto max-w-md text-center">
        <PageLoader />
        <p className="font-semibold">Confirming your payment…</p>
        <p className="text-sm text-slate-500">Please don’t close this page.</p>
      </div>
    )
  }

  return (
    <Card className="mx-auto max-w-md space-y-4 text-center">
      <Alert type={state.status === 'pending' ? 'warning' : 'error'}>{state.message}</Alert>
      <div className="flex flex-col gap-2">
        {(state.status === 'pending' || state.status === 'error') && <Button onClick={verify}>Check payment again</Button>}
        <Link to="/"><Button variant="secondary" className="w-full">Back to home</Button></Link>
      </div>
    </Card>
  )
}
