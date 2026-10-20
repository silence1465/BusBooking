import { useState } from 'react'
import { api, errorMessage } from '../../lib/api'
import { useFetch } from '../../lib/useFetch'
import { Alert, Badge, Button, Card, InfoTip, PageHeader, PageLoader, Pager, Select, Table } from '../../components/ui'
import { dateTime, money } from '../../lib/format'
import { useConfirm, useToast } from '../../components/feedback'

export default function Payments() {
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState('')
  const [actionError, setActionError] = useState('')
  const confirm = useConfirm()
  const toast = useToast()
  const { body, loading, error, reload } = useFetch('/admin/payments', { status, page })

  const refund = async (p) => {
    if (!(await confirm({ title: 'Mark this payment as refunded?', body: 'Do this only after the money has been returned (for example in the Paystack dashboard). This does not send any money itself.', confirmLabel: 'Mark refunded' }))) return
    setActionError('')
    try {
      await api.post(`/admin/payments/${p.id}/refunded`)
      toast('Payment marked as refunded')
      reload()
    } catch (e) {
      setActionError(errorMessage(e))
    }
  }

  const columns = [
    { label: 'Date', render: (p) => dateTime(p.paid_at || p.created_at) },
    { label: 'Booking', primary: true, render: (p) => <span className="font-mono font-semibold">{p.booking?.code}</span> },
    { label: 'Passenger', render: (p) => p.booking?.passenger_name },
    { label: 'Reference', render: (p) => <span className="font-mono text-xs">{p.reference}</span> },
    { label: 'Amount', render: (p) => money(p.amount, p.currency) },
    { label: 'Channel', render: (p) => p.channel || '—' },
    { label: 'Status', render: (p) => <Badge>{p.status}</Badge> },
    { label: 'Notes', render: (p) => (p.notes ? <InfoTip label="Payment note">{p.notes}</InfoTip> : '') },
    { label: '', render: (p) => (p.status === 'paid' ? <Button size="sm" variant="ghost" onClick={() => refund(p)}>Mark refunded</Button> : null) },
  ]

  return (
    <div>
      <PageHeader title="Payments" subtitle="Payments are confirmed by the server directly with Paystack." />
      <Card className="mb-4 max-w-xs">
        <Select label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
          <option value="">All</option>
          {['pending', 'paid', 'failed', 'refunded'].map((s) => <option key={s}>{s}</option>)}
        </Select>
      </Card>
      <Alert className="mb-3">{error || actionError}</Alert>
      {loading && !body ? <PageLoader /> : <Table columns={columns} rows={body?.data} empty="No payments found." />}
      <Pager meta={body && { current_page: body.current_page, last_page: body.last_page }} onPage={setPage} />
    </div>
  )
}
