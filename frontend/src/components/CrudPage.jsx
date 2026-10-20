import { useState } from 'react'
import { api, errorMessage, fieldErrors } from '../lib/api'
import { useFetch } from '../lib/useFetch'
import { Alert, Button, Input, Modal, PageHeader, PageLoader, Pager, Select, Table } from './ui'
import { useConfirm, useToast } from './feedback'

/**
 * Reusable admin list + create/edit modal.
 *  fields: [{ name, label, type?: text|number|date|time|password|select|checkbox, options?, hint?, createOnly?, editOnly?, required? }]
 */
export default function CrudPage({
  title,
  subtitle,
  endpoint,
  columns,
  fields,
  createLabel = 'Add new',
  defaults = {},
  canDelete = true,
  paged = false,
  rowActions,
  toForm = (row) => row,
  toPayload = (payload) => payload,
  params, // extra query filters, e.g. { date }. Changing them goes back to page 1.
  toolbar, // anything shown between the header and the table, e.g. a date picker
  headerAside, // (form, isNew) => node shown at the right of the pop-up heading, e.g. a live total
  deriveForm, // (nextForm, changedName) => form: lets one field fill in another, e.g. a default amount
  onCreate, // replaces the built-in add pop-up: onCreate({ reload }) opens your own
  onChanged, // called after something is saved or deleted, so a page can refresh its own numbers
  emptyText,
}) {
  const filterKey = JSON.stringify(params || {})
  const [pageAt, setPageAt] = useState({ key: filterKey, page: 1 })
  const page = pageAt.key === filterKey ? pageAt.page : 1
  const setPage = (n) => setPageAt({ key: filterKey, page: n })
  const { data, meta, loading, error, reload } = useFetch(endpoint, paged ? { ...params, page } : params)
  const [editing, setEditing] = useState(null) // null | 'new' | row
  const [form, setForm] = useState({})
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState('')
  const [saving, setSaving] = useState(false)
  const [actionError, setActionError] = useState('')
  const confirm = useConfirm()
  const toast = useToast()

  const change = (name, value) =>
    setForm((cur) => {
      const next = { ...cur, [name]: value }
      return deriveForm ? deriveForm(next, name) : next
    })

  const isNew = editing === 'new'
  const visible = fields.filter((f) => (isNew ? !f.editOnly : !f.createOnly) && (!f.showIf || f.showIf(form)))

  const open = (row) => {
    setEditing(row)
    setForm(row === 'new' ? { ...defaults } : { ...toForm(row) })
    setErrors({})
    setFormError('')
  }

  const submit = async (e) => {
    e.preventDefault()
    setSaving(true)
    setFormError('')
    setErrors({})
    const payload = {}
    const files = []
    visible.forEach((f) => {
      if (f.type === 'file') {
        if (form[f.name] instanceof File) files.push([f, form[f.name]])
        return
      }
      payload[f.name] = form[f.name] ?? (f.type === 'checkbox' ? false : f.type === 'multiselect' ? [] : '')
    })
    try {
      const body = toPayload(payload)
      const res = isNew ? await api.post(endpoint, body) : await api.put(`${endpoint}/${editing.id}`, body)
      const id = isNew ? res.data.data.id : editing.id
      for (const [f, file] of files) {
        const body = new FormData()
        body.append(f.name, file)
        await api.post(`${endpoint}/${id}/${f.name}`, body)
      }
      setEditing(null)
      toast(isNew ? 'Added' : 'Changes saved')
      reload()
      onChanged?.()
    } catch (err) {
      setFormError(errorMessage(err))
      setErrors(fieldErrors(err))
    } finally {
      setSaving(false)
    }
  }

  const remove = async (row) => {
    if (!(await confirm({ title: 'Delete this item?', body: 'This cannot be undone.', confirmLabel: 'Delete', danger: true }))) return
    setActionError('')
    try {
      await api.delete(`${endpoint}/${row.id}`)
      toast('Deleted')
      reload()
      onChanged?.()
    } catch (err) {
      setActionError(errorMessage(err))
    }
  }

  const allColumns = [
    ...columns.map((col) => (col.render ? { ...col, render: (row) => col.render(row, { reload }) } : col)),
    {
      label: '',
      className: 'text-right',
      render: (row) => (
        <div className="flex justify-end gap-1">
          {rowActions?.(row)}
          <Button size="sm" variant="secondary" onClick={() => open(row)}>Edit</Button>
          {canDelete && <Button size="sm" variant="ghost" className="text-red-700" onClick={() => remove(row)}>Delete</Button>}
        </div>
      ),
    },
  ]

  return (
    <div>
      <PageHeader title={title} subtitle={subtitle} actions={<Button onClick={() => (onCreate ? onCreate({ reload }) : open('new'))}>+ {createLabel}</Button>} />
      {toolbar}
      <Alert className="mb-4">{error || actionError}</Alert>
      {loading && !data ? <PageLoader /> : <Table columns={allColumns} rows={data} empty={emptyText || 'Nothing here yet. Add the first one.'} />}
      {paged && <Pager meta={meta} onPage={setPage} />}

      <Modal open={!!editing} onClose={() => setEditing(null)} title={isNew ? createLabel : `Edit ${title.toLowerCase()}`} aside={headerAside?.(form, isNew)}>
        <form onSubmit={submit} className="space-y-3">
          <Alert>{formError}</Alert>
          {visible.map((f) => {
            const common = { label: f.label, error: errors[f.name], hint: f.hint }
            if (f.type === 'custom') {
              const node = f.render(form, (v) => change(f.name, v), errors, change)
              return node ? <div key={f.name}>{node}</div> : null
            }
            if (f.type === 'select')
              return (
                <Select key={f.name} {...common} value={form[f.name] ?? ''} required={f.required !== false} onChange={(e) => change(f.name, e.target.value)}>
                  <option value="">Select…</option>
                  {f.options?.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                </Select>
              )
            if (f.type === 'file')
              return (
                <Input
                  key={f.name}
                  label={f.label}
                  type="file"
                  accept="image/png,image/jpeg,image/webp"
                  error={errors[f.name]}
                  hint={f.hint}
                  onChange={(e) => change(f.name, e.target.files[0])}
                />
              )
            if (f.type === 'multiselect') {
              const chosen = form[f.name] || []
              const toggle = (v) => change(f.name, chosen.includes(v) ? chosen.filter((x) => x !== v) : [...chosen, v])
              return (
                <fieldset key={f.name}>
                  <legend className="mb-1.5 text-xs font-semibold uppercase tracking-wider text-slate-500">{f.label}</legend>
                  <div className="space-y-1 rounded-md border border-slate-300 p-2">
                    {f.options?.length === 0 && <p className="px-1 py-2 text-sm text-slate-500">Nothing to choose yet.</p>}
                    {f.options?.map((o) => (
                      <label key={o.value} className="flex cursor-pointer items-center gap-2.5 rounded px-2 py-2 text-sm hover:bg-slate-50">
                        <input type="checkbox" className="h-4 w-4 accent-brand-600" checked={chosen.includes(o.value)} onChange={() => toggle(o.value)} />
                        {o.label}
                      </label>
                    ))}
                  </div>
                  {f.hint && <span className="mt-1 block text-xs text-slate-500">{f.hint}</span>}
                  {errors[f.name] && <span className="mt-1 block text-xs font-medium text-red-600">{errors[f.name]}</span>}
                </fieldset>
              )
            }
            if (f.type === 'checkbox')
              return (
                <label key={f.name} className="flex items-center gap-2 text-sm font-medium">
                  <input type="checkbox" className="h-4 w-4 accent-brand-600" checked={!!form[f.name]} onChange={(e) => change(f.name, e.target.checked)} />
                  {f.label}
                </label>
              )
            return (
              <Input
                key={f.name}
                {...common}
                type={f.type || 'text'}
                step={f.type === 'number' ? 'any' : undefined}
                value={form[f.name] ?? ''}
                required={f.required ?? !f.optional}
                autoComplete={f.type === 'password' ? 'new-password' : 'off'}
                onChange={(e) => change(f.name, e.target.value)}
              />
            )
          })}
          <div className="sticky -bottom-5 -mx-5 -mb-5 flex justify-end gap-2 border-t border-slate-200 bg-white px-5 py-3">
            <Button type="button" variant="secondary" onClick={() => setEditing(null)}>Cancel</Button>
            <Button type="submit" loading={saving}>Save</Button>
          </div>
        </form>
      </Modal>
    </div>
  )
}
