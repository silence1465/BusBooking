import { useEffect, useState } from 'react'
import { api, errorMessage } from '../../lib/api'
import { useFetch } from '../../lib/useFetch'
import { Alert, Badge, Button, Card, Input, PageHeader, PageLoader, Pager, Select, Table } from '../../components/ui'
import { dateTime, money, seatLabel, shortDate } from '../../lib/format'
import { useConfirm, useToast } from '../../components/feedback'

export default function Bookings() {
  const [page, setPage] = useState(1)
  const [filters, setFilters] = useState({ q: '', status: '', date: '' })
  const [applied, setApplied] = useState(filters)
  const [actionError, setActionError] = useState('')
  const confirm = useConfirm()
  const toast = useToast()
  const { data, meta, loading, error, reload } = useFetch('/admin/bookings', { ...applied, page })

  useEffect(() => {
    const t = setTimeout(() => { setApplied(filters); setPage(1) }, 350)
    return () => clearTimeout(t)
  }, [filters])

  const cancel = async (b) => {
    if (!(await confirm({ title: `Cancel booking ${b.code}?`, body: 'The seats are released straight away. Any refund must be handled separately.', confirmLabel: 'Cancel booking', cancelLabel: 'Keep booking', danger: true }))) return
    setActionError('')
    try {
      await api.post(`/admin/bookings/${b.id}/cancel`)
      toast(`Booking ${b.code} cancelled`)
      reload()
    } catch (e) {
      setActionError(errorMessage(e))
    }
  }

  const columns = [
    { label: 'Code', render: (b) => <span><span className="font-mono font-bold">{b.code}</span>{b.source === 'station' && <span className="ml-2 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-semibold text-slate-600">Station</span>}</span> },
    { label: 'Passenger', render: (b) => <div><div className="font-semibold">{b.passenger_name}</div><div className="text-xs text-slate-500">{b.phone}</div></div> },
    { label: 'Trip', render: (b) => <div><div>{b.trip.origin} → {b.trip.destination}</div><div className="text-xs text-slate-500">{shortDate(b.trip.travel_date)} · {b.trip.departure_time_label}</div></div> },
    { label: 'Pickup', render: (b) => b.pickup_point || '—' },
    { label: 'Seat', render: (b) => seatLabel(b) },
    { label: 'Amount', render: (b) => money(b.amount, b.currency) },
    { label: 'Payment', render: (b) => <Badge>{b.payment?.status || 'none'}</Badge> },
    { label: 'Status', render: (b) => <Badge>{b.status}</Badge> },
    { label: 'Arrived', render: (b) => (b.arrival.confirmed ? <span className="text-emerald-700">✓ {dateTime(b.arrival.confirmed_at)}</span> : '—') },
    { label: 'Booked', render: (b) => dateTime(b.created_at) },
    {
      label: '',
      render: (b) => (['pending', 'confirmed'].includes(b.status)
        ? <Button size="sm" variant="ghost" className="text-red-600" onClick={() => cancel(b)}>Cancel</Button>
        : null),
    },
  ]

  return (
    <div>
      <PageHeader title="Bookings" subtitle="All bookings and passengers." />
      <Card className="mb-4 grid gap-3 sm:grid-cols-3">
        <Input label="Search" placeholder="Code, name or phone" value={filters.q} onChange={(e) => setFilters({ ...filters, q: e.target.value })} />
        <Select label="Status" value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })}>
          <option value="">All</option>
          {['pending', 'confirmed', 'cancelled', 'completed', 'no_show'].map((s) => <option key={s}>{s}</option>)}
        </Select>
        <Input label="Travel date" type="date" value={filters.date} onChange={(e) => setFilters({ ...filters, date: e.target.value })} />
      </Card>
      <Alert className="mb-3">{error || actionError}</Alert>
      {loading && !data ? <PageLoader /> : <Table columns={columns} rows={data} empty="No bookings found." />}
      <Pager meta={meta} onPage={setPage} />
    </div>
  )
}
