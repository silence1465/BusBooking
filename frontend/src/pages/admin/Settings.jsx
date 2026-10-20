import { useEffect, useState } from 'react'
import { api, errorMessage, fieldErrors } from '../../lib/api'
import { useFetch } from '../../lib/useFetch'
import { Alert, Button, Card, Input, PageHeader, PageLoader, Switch } from '../../components/ui'

export default function Settings() {
  const { data, loading, error } = useFetch('/admin/settings')
  const [form, setForm] = useState(null)
  const [saving, setSaving] = useState(false)
  const [msg, setMsg] = useState('')
  const [err, setErr] = useState('')
  const [errors, setErrors] = useState({})

  useEffect(() => { if (data) setForm(data) }, [data])

  if (loading || !form) return error ? <Alert>{error}</Alert> : <PageLoader />

  const save = async (e) => {
    e.preventDefault()
    setSaving(true)
    setMsg('')
    setErr('')
    setErrors({})
    try {
      const res = await api.put('/admin/settings', form)
      setForm(res.data.data)
      setMsg('Settings saved.')
    } catch (ex) {
      setErr(errorMessage(ex))
      setErrors(fieldErrors(ex))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="max-w-2xl">
      <PageHeader title="Settings" />
      <Card>
        <form onSubmit={save} className="space-y-4">
          <Alert type="success">{msg}</Alert>
          <Alert>{err}</Alert>
          <Input label="Company name" value={form.company_name} error={errors.company_name} onChange={(e) => setForm({ ...form, company_name: e.target.value })} required />
          <Input label="Support phone" value={form.support_phone} error={errors.support_phone} onChange={(e) => setForm({ ...form, support_phone: e.target.value })} />
          <Input label="Seat hold time (minutes)" type="number" min="3" max="60" value={form.seat_hold_minutes} error={errors.seat_hold_minutes} hint="How long a seat is held while the customer pays." onChange={(e) => setForm({ ...form, seat_hold_minutes: e.target.value })} required />

          <div className="space-y-3 border-t border-slate-200 pt-4">
            <div>
              <h2 className="font-semibold text-slate-900">Contact us page</h2>
              <p className="text-xs text-slate-500">Shown to customers at /contact and linked in the footer. The phone number above is used too. Leave a box empty to hide it.</p>
            </div>
            <label className="block">
              <span className="mb-1 block text-xs font-semibold uppercase tracking-wider text-slate-500">Welcome message</span>
              <textarea rows={2} maxLength={600} value={form.contact_intro || ''} onChange={(e) => setForm({ ...form, contact_intro: e.target.value })} placeholder="We are happy to help with bookings, tickets and trips." className="block w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm text-slate-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30" />
            </label>
            <div className="grid gap-3 sm:grid-cols-2">
              <Input label="WhatsApp number" value={form.contact_whatsapp || ''} error={errors.contact_whatsapp} placeholder="0241234567" onChange={(e) => setForm({ ...form, contact_whatsapp: e.target.value })} />
              <Input label="Email" type="email" value={form.contact_email || ''} error={errors.contact_email} placeholder="help@yourcompany.com" onChange={(e) => setForm({ ...form, contact_email: e.target.value })} />
            </div>
            <label className="block">
              <span className="mb-1 block text-xs font-semibold uppercase tracking-wider text-slate-500">Address</span>
              <textarea rows={2} maxLength={400} value={form.contact_address || ''} onChange={(e) => setForm({ ...form, contact_address: e.target.value })} placeholder={'Circle Bus Terminal\nAccra'} className="block w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm text-slate-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30" />
            </label>
            <div className="grid gap-3 sm:grid-cols-2">
              <Input label="Opening hours" value={form.contact_hours || ''} error={errors.contact_hours} placeholder="Every day, 5am to 10pm" onChange={(e) => setForm({ ...form, contact_hours: e.target.value })} />
              <Input label="Map link" value={form.contact_map_url || ''} error={errors.contact_map_url} placeholder="https://maps.google.com/..." hint="Paste a Google Maps share link." onChange={(e) => setForm({ ...form, contact_map_url: e.target.value.trim() })} />
            </div>
            <label className="block">
              <span className="mb-1 block text-xs font-semibold uppercase tracking-wider text-slate-500">Map on the page (Google Maps embed)</span>
              <textarea rows={3} value={form.contact_map_embed || ''} onChange={(e) => setForm({ ...form, contact_map_embed: e.target.value })} placeholder='<iframe src="https://www.google.com/maps/embed?pb=..." ...></iframe>' className="block w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 font-mono text-xs text-slate-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30" aria-label="Google Maps embed code" />
              <span className="mt-1 block text-xs text-slate-500">On Google Maps choose Share, then Embed a map, then copy the HTML and paste it here. Only Google Maps embeds are accepted.</span>
              {errors.contact_map_embed && <span className="mt-1 block text-xs font-medium text-red-600">That is not a Google Maps embed. Copy it from Share, Embed a map.</span>}
            </label>
          </div>

          <div className="space-y-3 border-t border-slate-200 pt-4">
            <div>
              <h2 className="font-semibold text-slate-900">Privacy page text</h2>
              <p className="text-xs text-slate-500">Optional. Leave empty to show the standard privacy text at /privacy. To use your own, write it here: start a line with ## for a heading, start lines with - for a list, and leave a blank line between paragraphs.</p>
            </div>
            <textarea rows={8} maxLength={20000} value={form.page_privacy || ''} onChange={(e) => setForm({ ...form, page_privacy: e.target.value })} placeholder={'## What we collect\n\nYour name and phone number when you book.'} className="block w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm text-slate-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30" aria-label="Privacy page text" />
          </div>

          <div className="space-y-3 border-t border-slate-200 pt-4">
            <div className="flex items-center justify-between gap-3">
              <div>
                <h2 className="font-semibold text-slate-900">Google AdSense ads</h2>
                <p className="text-xs text-slate-500">Shown on the customer pages only: above the available trips, below search results and on the booking confirmation. Never on payment, seat selection or staff screens.</p>
              </div>
              <Switch checked={form.ads_enabled === '1'} onChange={(on) => setForm({ ...form, ads_enabled: on ? '1' : '0' })} label="Show ads" />
            </div>
            <Input label="Publisher ID" value={form.ads_publisher_id || ''} error={errors.ads_publisher_id} placeholder="ca-pub-1234567890123456" hint="From your AdSense account. Ads stay hidden until this is saved." onChange={(e) => setForm({ ...form, ads_publisher_id: e.target.value.trim() })} />
            <div className="grid gap-3 sm:grid-cols-3">
              <Input label="Ad unit: home" value={form.ads_slot_home || ''} error={errors.ads_slot_home} placeholder="1234567890" onChange={(e) => setForm({ ...form, ads_slot_home: e.target.value.trim() })} />
              <Input label="Ad unit: results" value={form.ads_slot_results || ''} error={errors.ads_slot_results} placeholder="1234567890" onChange={(e) => setForm({ ...form, ads_slot_results: e.target.value.trim() })} />
              <Input label="Ad unit: confirmation" value={form.ads_slot_confirmation || ''} error={errors.ads_slot_confirmation} placeholder="1234567890" onChange={(e) => setForm({ ...form, ads_slot_confirmation: e.target.value.trim() })} />
            </div>
            <p className="text-xs text-slate-500">Leave a unit empty to skip that spot. Also put the line AdSense gives you in an ads.txt file at the root of your website.</p>
          </div>
          <Button type="submit" loading={saving}>Save settings</Button>
        </form>
      </Card>
      <p className="mt-4 text-xs text-slate-500">Paystack and Arkesel keys are configured on the server in the .env file, never in the browser.</p>
    </div>
  )
}
