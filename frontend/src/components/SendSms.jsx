import { useEffect, useMemo, useState } from 'react'
import { api, errorMessage } from '../lib/api'
import { useFetch } from '../lib/useFetch'
import { Alert, Button, Input, Modal, Select, cx } from './ui'
import { useConfirm, useToast } from './feedback'
import { todayYmd } from '../lib/format'

const PLACEHOLDERS = [
  ['name', 'Passenger name'],
  ['code', 'Booking code'],
  ['route', 'Route'],
  ['pickup', 'Pickup point'],
  ['date', 'Travel date'],
]

const TABS = [['route', 'Route passengers'], ['numbers', 'Individuals'], ['templates', 'Templates']]

const field = 'block w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm text-slate-900 placeholder-slate-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30'

/** Splits whatever was typed or pasted into separate phone numbers. */
const parseNumbers = (text) => [...new Set(text.split(/[\n,;]+/).map((n) => n.trim()).filter(Boolean))]

const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`

/** Create, edit and delete the saved messages. "Use" drops one into the message box. */
function Templates({ templates, onUse, onChanged, setError }) {
  const confirm = useConfirm()
  const toast = useToast()
  const [editing, setEditing] = useState(null) // null | 'new' | template
  const [form, setForm] = useState({ name: '', body: '' })
  const [busy, setBusy] = useState(false)

  const open = (t) => { setEditing(t); setForm(t === 'new' ? { name: '', body: '' } : { name: t.name, body: t.body }); setError('') }

  const save = async (e) => {
    e.preventDefault()
    setBusy(true)
    setError('')
    try {
      if (editing === 'new') await api.post('/admin/sms-templates', form)
      else await api.put(`/admin/sms-templates/${editing.id}`, form)
      toast(editing === 'new' ? 'Template created' : 'Template saved')
      setEditing(null)
      onChanged()
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  const remove = async (t) => {
    if (!(await confirm({ title: 'Delete this template?', body: `“${t.name}” will be removed. Messages already sent are not affected.`, confirmLabel: 'Delete', danger: true }))) return
    try {
      await api.delete(`/admin/sms-templates/${t.id}`)
      toast('Template deleted')
      onChanged()
    } catch (err) {
      setError(errorMessage(err))
    }
  }

  if (editing) {
    return (
      <form onSubmit={save} className="space-y-3">
        <Input label="Template name" value={form.name} maxLength={80} required onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="e.g. Bus running late" />
        <label className="block">
          <span className="mb-1 block text-xs font-semibold uppercase tracking-wider text-slate-500">Message</span>
          <textarea rows={5} required maxLength={480} value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} className={field} placeholder="Hi {name}, your bus is running 20 minutes late. Thank you for waiting." />
          <span className="mt-1 block text-xs text-slate-500">
            {form.body.length}/480. Words in {'{ }'} are filled in for each passenger when sending to a route: {PLACEHOLDERS.map(([k]) => `{${k}}`).join(' ')}
          </span>
        </label>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={() => setEditing(null)}>Cancel</Button>
          <Button type="submit" loading={busy}>Save template</Button>
        </div>
      </form>
    )
  }

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between gap-3">
        <p className="text-sm text-slate-500">Messages you send often. Pick one when you send an SMS.</p>
        <Button type="button" size="sm" onClick={() => open('new')}>+ New template</Button>
      </div>
      {templates.length === 0 ? (
        <p className="rounded-lg border border-dashed border-slate-300 px-4 py-8 text-center text-sm text-slate-500">No templates yet. Create the first one.</p>
      ) : (
        <ul className="divide-y divide-slate-100 overflow-hidden rounded-lg border border-slate-200">
          {templates.map((t) => (
            <li key={t.id} className="space-y-1.5 px-3 py-2.5 odd:bg-white even:bg-slate-50">
              <p className="font-semibold text-slate-900">{t.name}</p>
              <p className="whitespace-pre-line text-sm text-slate-600">{t.body}</p>
              <div className="flex flex-wrap gap-1">
                <Button type="button" size="sm" variant="secondary" onClick={() => onUse(t)}>Use</Button>
                <Button type="button" size="sm" variant="ghost" onClick={() => open(t)}>Edit</Button>
                <Button type="button" size="sm" variant="ghost" className="text-red-700" onClick={() => remove(t)}>Delete</Button>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

/**
 * Admin: write a message (or pick a saved template) and send it to the passengers of a route (or one car) on a day,
 * or to numbers typed in. Texts go out through the same non-blocking queue as every other SMS.
 */
export default function SendSms({ open, onClose, onSent }) {
  const toast = useToast()
  const confirm = useConfirm()
  const templates = useFetch('/admin/sms-templates', undefined, { enabled: open })
  const routes = useFetch('/admin/routes', undefined, { enabled: open })

  const [tab, setTab] = useState('route')
  const [composeTab, setComposeTab] = useState('route') // where "Use" on a template returns to
  const [routeId, setRouteId] = useState('')
  const [tripId, setTripId] = useState('') // '' = every car on the route
  const [date, setDate] = useState(todayYmd())
  const [onlyWaiting, setOnlyWaiting] = useState(false)
  const [numbersText, setNumbersText] = useState('')
  const [message, setMessage] = useState('')
  const [templateId, setTemplateId] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  // Passenger counts for the day: per route and per car.
  const audience = useFetch('/admin/sms/audience', { date, only_not_checked_in: onlyWaiting ? 1 : 0 }, { enabled: open })
  const counts = audience.data
  const max = counts?.max ?? 500
  const route = counts?.routes.find((r) => String(r.id) === routeId)
  const trip = route?.trips.find((t) => String(t.id) === tripId)

  const numbers = useMemo(() => parseNumbers(numbersText), [numbersText])
  const audienceTab = tab === 'templates' ? composeTab : tab
  const recipients = audienceTab === 'route' ? (trip ? trip.passengers : route ? route.passengers : 0) : numbers.length
  const length = message.length
  const parts = Math.max(1, Math.ceil(length / 160))

  // Changing the day or filter can drop the chosen car off the list.
  useEffect(() => {
    if (counts && tripId && !trip) setTripId('')
  }, [counts, tripId, trip])

  const switchTab = (key) => {
    setTab(key)
    setError('')
    if (key !== 'templates') setComposeTab(key)
  }

  const pickTemplate = (id) => {
    setTemplateId(id)
    const t = templates.data?.find((x) => String(x.id) === id)
    if (t) setMessage(t.body)
  }

  const useTemplate = (t) => {
    setMessage(t.body)
    setTemplateId(String(t.id))
    setTab(composeTab)
  }

  const addPlaceholder = (key) => setMessage((m) => `${m}${m && !m.endsWith(' ') ? ' ' : ''}{${key}}`)

  const send = async () => {
    const what = audienceTab === 'route' ? plural(recipients, 'passenger') : plural(recipients, 'number')
    if (!(await confirm({ title: `Send to ${what}?`, body: 'The text goes out straight away and cannot be recalled.', confirmLabel: 'Send SMS' }))) return
    setBusy(true)
    setError('')
    try {
      const body = audienceTab === 'route'
        ? { audience: 'route', message, route_id: routeId, trip_id: tripId || null, date, only_not_checked_in: onlyWaiting }
        : { audience: 'numbers', message, numbers }
      const res = await api.post('/admin/sms/send', body)
      toast(`Sending ${plural(res.data.data.queued, 'message')}`)
      onSent?.()
      onClose()
      setMessage('')
      setTemplateId('')
      setNumbersText('')
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  const canSend = message.trim().length >= 2 && recipients > 0 && recipients <= max && !busy

  return (
    <Modal open={open} onClose={onClose} title="Send SMS">
      <div className="space-y-4">
        <Alert>{error}</Alert>

        <div className="flex overflow-x-auto border-b border-slate-200 text-sm" role="tablist">
          {TABS.map(([key, label]) => (
            <button
              key={key}
              role="tab"
              aria-selected={tab === key}
              onClick={() => switchTab(key)}
              className={cx('-mb-px min-h-10 shrink-0 border-b-2 px-4', tab === key ? 'border-brand-600 font-bold text-slate-900' : 'border-transparent font-medium text-slate-500 hover:text-slate-900')}
            >
              {label}
              {key === 'templates' && templates.data && <span className="ml-1.5 rounded-full bg-slate-100 px-1.5 py-px text-[11px] font-semibold text-slate-600">{templates.data.length}</span>}
            </button>
          ))}
        </div>

        {tab === 'templates' ? (
          <Templates templates={templates.data || []} onUse={useTemplate} onChanged={templates.reload} setError={setError} />
        ) : (
          <>
            {tab === 'route' ? (
              <div className="space-y-3">
                <div className="grid gap-3 sm:grid-cols-2">
                  <Input label="Travel date" type="date" value={date} onChange={(e) => e.target.value && setDate(e.target.value)} />
                  <Select label="Route" value={routeId} onChange={(e) => { setRouteId(e.target.value); setTripId('') }}>
                    <option value="">Select route…</option>
                    {routes.data?.map((r) => {
                      const n = counts?.routes.find((c) => c.id === r.id)?.passengers ?? 0
                      return <option key={r.id} value={r.id}>{r.origin} → {r.destination} · {plural(n, 'passenger')}</option>
                    })}
                  </Select>
                </div>
                <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                  <input type="checkbox" className="h-4 w-4 accent-brand-600" checked={onlyWaiting} onChange={(e) => setOnlyWaiting(e.target.checked)} />
                  Only passengers who have not checked in yet
                </label>

                {route && (
                  <div>
                    <span className="mb-1 block text-xs font-semibold uppercase tracking-wider text-slate-500">Which car</span>
                    <div className="flex flex-wrap gap-2">
                      {[{ id: '', label: 'Every car on this route', passengers: route.passengers }, ...route.trips].map((t) => (
                        <button
                          key={t.id}
                          type="button"
                          aria-pressed={tripId === String(t.id)}
                          onClick={() => setTripId(String(t.id))}
                          className={cx('rounded-md border-[1.5px] px-3 py-1.5 text-left text-sm', tripId === String(t.id) ? 'border-brand-600 bg-brand-50' : 'border-slate-200 hover:border-slate-300')}
                        >
                          {t.id === '' ? (
                            <span className="font-semibold text-slate-900">{t.label}</span>
                          ) : (
                            <>
                              <span className="rounded bg-amber-100 px-1.5 py-0.5 font-mono text-xs font-semibold text-amber-900">{t.registration}</span>
                              <span className="ml-1.5 text-slate-500">{t.time}</span>
                            </>
                          )}
                          <span className="block text-xs font-semibold text-brand-700">{plural(t.passengers, 'passenger')}</span>
                        </button>
                      ))}
                    </div>
                  </div>
                )}

                <p className="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600" aria-live="polite">
                  {!routeId
                    ? 'Choose a route to see how many passengers it has.'
                    : recipients === 0
                      ? 'Nobody has a booking here for that day.'
                      : <><b className="text-lg text-slate-900">{recipients}</b> {recipients === 1 ? 'passenger' : 'passengers'} will get this text{trip ? ` (${trip.registration})` : ''}. Each person gets one, even with several seats.</>}
                </p>
                {recipients > max && <Alert type="warning">That is more than {max} people. Pick one car, or narrow it down by day or by checked-in status.</Alert>}
              </div>
            ) : (
              <label className="block">
                <span className="mb-1 block text-xs font-semibold uppercase tracking-wider text-slate-500">Phone numbers</span>
                <textarea rows={3} value={numbersText} onChange={(e) => setNumbersText(e.target.value)} placeholder={'0241234567\n0551234567'} className={cx(field, 'font-mono')} />
                <span className="mt-1 block text-xs text-slate-500">One per line, or separated by commas. {numbers.length > 0 && <b className="text-slate-700">{plural(numbers.length, 'number')}.</b>}</span>
              </label>
            )}

            <div className="space-y-2 border-t border-slate-100 pt-4">
              <div className="flex flex-wrap items-end gap-2">
                <div className="min-w-48 flex-1">
                  <Select label="Template" value={templateId} onChange={(e) => pickTemplate(e.target.value)}>
                    <option value="">Write my own</option>
                    {templates.data?.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                  </Select>
                </div>
                <Button type="button" variant="secondary" className="min-h-[34px]" onClick={() => setTab('templates')}>Create or edit templates</Button>
              </div>

              <label className="block">
                <span className="mb-1 block text-xs font-semibold uppercase tracking-wider text-slate-500">Message</span>
                <textarea
                  rows={4}
                  value={message}
                  maxLength={480}
                  onChange={(e) => setMessage(e.target.value)}
                  placeholder={audienceTab === 'route' ? 'Hi {name}, your bus is running 20 minutes late. Thank you for waiting.' : 'Type your message…'}
                  className={field}
                />
              </label>
              <p className="text-xs text-slate-500">{length}/480 characters · {plural(parts, 'SMS part')} each</p>

              {audienceTab === 'route' && (
                <div className="flex flex-wrap items-center gap-1.5">
                  <span className="text-xs text-slate-500">Fill in for each passenger:</span>
                  {PLACEHOLDERS.map(([key, label]) => (
                    <button key={key} type="button" title={label} onClick={() => addPlaceholder(key)} className="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 font-mono text-xs text-slate-700 hover:border-brand-500 hover:text-brand-700">
                      {`{${key}}`}
                    </button>
                  ))}
                </div>
              )}
            </div>

            <div className="sticky -bottom-5 -mx-5 -mb-5 flex justify-end gap-2 border-t border-slate-200 bg-white px-5 py-3">
              <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
              <Button type="button" loading={busy} disabled={!canSend} onClick={send}>
                {recipients > 0 ? `Send to ${recipients}` : 'Send SMS'}
              </Button>
            </div>
          </>
        )}
      </div>
    </Modal>
  )
}
