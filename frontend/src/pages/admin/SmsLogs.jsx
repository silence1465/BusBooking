import { useState } from 'react'
import { useFetch } from '../../lib/useFetch'
import { api, errorMessage } from '../../lib/api'
import { Alert, Badge, Button, PageHeader, PageLoader, Pager, Switch, Table } from '../../components/ui'
import SendSms from '../../components/SendSms'
import { dateTime } from '../../lib/format'

const CARDS = [
  ['total', 'Total SMS', 'text-slate-900'],
  ['sent', 'Sent', 'text-emerald-700'],
  ['remaining', 'Remaining', 'text-amber-800'],
  ['failed', 'Failed', 'text-red-700'],
]

// Automatic texts the admin can turn on or off. Each one is stored as "sms_<key>" in /admin/settings.
const TYPES = [
  ['tickets', 'Tickets', 'Sent when a booking is confirmed'],
  ['boarding', 'Boarding', 'Sent when the bus starts boarding'],
  ['almost_full', 'Almost full', 'Asks main-station passengers to be there'],
  ['pickup', 'Pickup', 'Sent when the bus leaves the station'],
  ['missed', 'Missed bus', 'Sent when a ticket moves to the next vehicle'],
]

export default function SmsLogs() {
  const [page, setPage] = useState(1)
  const { body, loading, error, reload } = useFetch('/admin/sms-logs', { page })
  const { data: settings, reload: reloadSettings } = useFetch('/admin/settings')
  const [toggleError, setToggleError] = useState('')
  const [composing, setComposing] = useState(false)

  const toggle = async (key, on) => {
    setToggleError('')
    try {
      await api.put('/admin/settings', { ['sms_' + key]: on ? '1' : '0' })
      reloadSettings()
      reload()
    } catch (err) {
      setToggleError(errorMessage(err))
    }
  }

  const columns = [
    { label: 'Sent', render: (l) => dateTime(l.sent_at || l.created_at) },
    { label: 'Phone', key: 'phone', primary: true },
    { label: 'Message', wide: true, render: (l) => <span className="block max-w-md whitespace-normal">{l.message}</span> },
    { label: 'Provider', key: 'provider' },
    { label: 'Status', render: (l) => <Badge>{l.status}</Badge> },
    {
      label: 'Delivered in',
      render: (l) => {
        if (!l.sent_at) return <span className="text-slate-400">—</span>
        const secs = Math.max(0, Math.round((new Date(l.sent_at) - new Date(l.created_at)) / 1000))
        return <span className={secs > 10 ? 'font-semibold text-amber-800' : 'text-slate-700'}>{secs < 1 ? 'under 1 second' : `${secs} second${secs === 1 ? '' : 's'}`}</span>
      },
    },
    { label: 'Provider response', wide: true, render: (l) => <span className="block max-w-xs truncate text-xs text-slate-500" title={l.provider_response}>{l.provider_response}</span> },
  ]

  return (
    <div>
      <PageHeader title="SMS logs" subtitle="A failed SMS never affects a paid booking." actions={<Button onClick={() => setComposing(true)}>Send SMS</Button>} />
      <SendSms open={composing} onClose={() => setComposing(false)} onSent={reload} />
      <Alert className="mb-3">{error}</Alert>
      <Alert className="mb-3">{toggleError}</Alert>
      {body?.summary && (
        <>
          <div className="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
            {CARDS.map(([key, label, tone]) => (
              <div key={key} className="rounded-lg border border-slate-200 bg-white px-3 py-2">
                <p className="truncate text-[11px] font-semibold uppercase tracking-wider text-slate-500">{label}</p>
                <p className={'text-xl font-semibold tracking-tight ' + tone}>{body.summary[key] ?? 0}</p>
              </div>
            ))}
          </div>
          <div className="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
            {TYPES.map(([key, label, hint]) => {
              const on = settings ? settings['sms_' + key] !== '0' : true
              return (
                <div key={key} className={'rounded-lg border bg-white px-3 py-2 ' + (on ? 'border-slate-200' : 'border-dashed border-slate-300 bg-slate-50')}>
                  <div className="flex items-center justify-between gap-2">
                    <p className="truncate text-[11px] font-semibold uppercase tracking-wider text-slate-500" title={hint}>{label}</p>
                    <Switch checked={on} onChange={(v) => toggle(key, v)} label={(on ? 'Turn off ' : 'Turn on ') + label + ' texts'} disabled={!settings} />
                  </div>
                  <p className={'text-xl font-semibold tracking-tight ' + (on ? 'text-brand-700' : 'text-slate-400')}>{body.summary[key] ?? 0}</p>
                  <p className="text-[11px] text-slate-500">{on ? 'On' : 'Off: not being sent'}</p>
                </div>
              )
            })}
          </div>
        </>
      )}
      {loading && !body ? <PageLoader /> : <Table columns={columns} rows={body?.data} empty="No SMS sent yet." />}
      <Pager meta={body && { current_page: body.current_page, last_page: body.last_page }} onPage={setPage} />
    </div>
  )
}
