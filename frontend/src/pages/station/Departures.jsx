import { useMemo, useState } from 'react'
import { api, errorMessage } from '../../lib/api'
import { useFetch } from '../../lib/useFetch'
import { Alert, Card, Empty, PageLoader, Tabs, cx } from '../../components/ui'
import { money, moneyExact, timeOnly, todayYmd } from '../../lib/format'
import { useToast } from '../../components/feedback'

const GROUPS = [
  ['category', 'By vehicle type'],
  ['route', 'By route'],
  ['none', 'In order of departure'],
]

function Total({ label, value, tone }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white p-4">
      <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">{label}</p>
      <p className={cx('mt-1 text-[26px] font-semibold leading-tight tracking-tight', tone || 'text-slate-900')}>{value}</p>
    </div>
  )
}

function DueCell({ row, currency, onToggle, busy }) {
  if (row.due_status === 'not_recorded') return <span className="text-xs text-slate-400">Not recorded</span>
  if (row.due_status === 'none') return <span className="text-slate-400">No due</span>
  return (
    <div className="flex items-center justify-end gap-3">
      <span className="font-semibold text-slate-900">{money(row.station_due, currency)}</span>
      {row.due_status === 'paid' ? (
        <button
          onClick={() => onToggle(row, false)}
          disabled={busy}
          title={`Collected${row.collected_by ? ` by ${row.collected_by}` : ''}. Click to undo.`}
          className="rounded bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-200"
        >
          Collected
        </button>
      ) : (
        <button
          onClick={() => onToggle(row, true)}
          disabled={busy}
          className="rounded border border-amber-400 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-900 hover:bg-amber-100"
        >
          Mark collected
        </button>
      )}
    </div>
  )
}

/**
 * One line per vehicle type (or route): how many left, how many passengers, and what they owe the station.
 * e.g. "Big bus: 4 departed, total dues GHS 100.00".
 */
function SummaryTable({ title, groups, totals, currency }) {
  const lines = groups.map((g) => {
    const owed = g.rows.filter((r) => r.station_due > 0)
    const sum = (list) => list.reduce((n, r) => n + r.station_due, 0)
    return {
      name: g.title,
      departed: g.rows.length,
      passengers: g.rows.reduce((n, r) => n + r.passengers, 0),
      dues: sum(owed),
      collected: sum(owed.filter((r) => r.due_status === 'paid')),
      outstanding: sum(owed.filter((r) => r.due_status === 'unpaid')),
    }
  })

  return (
    <section className="mb-6 break-inside-avoid">
      <h2 className="mb-2 hidden text-sm font-semibold uppercase tracking-wider text-slate-600 print:block">Summary {title}</h2>
      <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <table className="min-w-full divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-700 text-left text-xs font-semibold uppercase tracking-wider text-white">
            <tr>
              <th className="px-4 py-3">{title === 'by route' ? 'Route' : 'Vehicle type'}</th>
              <th className="px-4 py-3 text-right">Departed</th>
              <th className="px-4 py-3 text-right">Passengers</th>
              <th className="px-4 py-3 text-right">Total dues</th>
              <th className="px-4 py-3 text-right">Collected</th>
              <th className="px-4 py-3 text-right">Outstanding</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {lines.map((l) => (
              <tr key={l.name} className="odd:bg-white even:bg-slate-50 hover:bg-brand-50/60">
                <td className="whitespace-nowrap px-4 py-3 font-semibold text-slate-900">{l.name}</td>
                <td className="whitespace-nowrap px-4 py-3 text-right">{l.departed}</td>
                <td className="whitespace-nowrap px-4 py-3 text-right">{l.passengers}</td>
                <td className="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-900">{moneyExact(l.dues, currency)}</td>
                <td className="whitespace-nowrap px-4 py-3 text-right text-emerald-700">{moneyExact(l.collected, currency)}</td>
                <td className={cx('whitespace-nowrap px-4 py-3 text-right', l.outstanding > 0 ? 'font-semibold text-amber-800' : 'text-slate-500')}>{moneyExact(l.outstanding, currency)}</td>
              </tr>
            ))}
          </tbody>
          <tfoot className="border-t border-slate-200 bg-slate-50 font-semibold text-slate-900">
            <tr>
              <td className="px-4 py-3">Total</td>
              <td className="px-4 py-3 text-right">{totals.vehicles}</td>
              <td className="px-4 py-3 text-right">{totals.passengers}</td>
              <td className="px-4 py-3 text-right">{moneyExact(totals.dues, currency)}</td>
              <td className="px-4 py-3 text-right text-emerald-700">{moneyExact(totals.collected, currency)}</td>
              <td className={cx('px-4 py-3 text-right', totals.outstanding > 0 ? 'text-amber-800' : '')}>{moneyExact(totals.outstanding, currency)}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>
  )
}

function GroupTable({ title, rows, currency, onToggle, busy, showCategory, showRoute }) {
  const sum = (key, list = rows) => list.reduce((n, r) => n + (r[key] || 0), 0)
  const owed = rows.filter((r) => r.station_due > 0)
  const outstanding = owed.filter((r) => r.due_status === 'unpaid').reduce((n, r) => n + r.station_due, 0)

  return (
    <section className="mb-6 break-inside-avoid">
      {title && (
        <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
          <h2 className="text-sm font-semibold uppercase tracking-wider text-slate-600">{title}</h2>
          <p className="text-[13px] text-slate-500">
            {rows.length} vehicle{rows.length === 1 ? '' : 's'} · {sum('passengers')} passengers · dues {money(sum('station_due', owed), currency)}
            {outstanding > 0 && <span className="font-semibold text-amber-800"> · {money(outstanding, currency)} outstanding</span>}
          </p>
        </div>
      )}
      <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <table className="min-w-full divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-700 text-left text-xs font-semibold uppercase tracking-wider text-white">
            <tr>
              <th className="px-4 py-3">Left</th>
              <th className="px-4 py-3">Vehicle</th>
              {showCategory && <th className="px-4 py-3">Type</th>}
              {showRoute && <th className="px-4 py-3">Route</th>}
              <th className="px-4 py-3 text-right">Passengers</th>
              <th className="px-4 py-3 text-right">Station due</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {rows.map((r) => (
              <tr key={r.id} className="odd:bg-white even:bg-slate-50 hover:bg-brand-50/60">
                <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-900">{timeOnly(r.departed_at)}</td>
                <td className="whitespace-nowrap px-4 py-3">
                  <span className="rounded bg-amber-100 px-1.5 py-0.5 font-mono text-[13px] font-semibold text-amber-900">{r.registration}</span>
                  <span className="ml-2 text-slate-500">{r.bus}</span>
                </td>
                {showCategory && <td className="whitespace-nowrap px-4 py-3">{r.category}</td>}
                {showRoute && <td className="whitespace-nowrap px-4 py-3">{r.route}</td>}
                <td className="whitespace-nowrap px-4 py-3 text-right">{r.passengers} <span className="text-slate-400">/ {r.seat_count}</span></td>
                <td className="whitespace-nowrap px-4 py-3 text-right"><DueCell row={r} currency={currency} onToggle={onToggle} busy={busy} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}

/** The day's record of departed vehicles and the station dues they pay. Used by station masters and admins. */
export default function Departures() {
  const [date, setDate] = useState(todayYmd())
  const [group, setGroup] = useState('category')
  const [groupTab, setGroupTab] = useState('summary') // the summary, or one vehicle type / route
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState('')
  const toast = useToast()
  const { data, loading, error, reload } = useFetch('/station/departures', { date })

  const rows = useMemo(() => data?.rows || [], [data])
  const currency = data?.currency || 'GHS'

  const groups = useMemo(() => {
    if (group === 'none') return [{ key: 'all', title: null, rows }]
    const by = rows.reduce((acc, r) => {
      const key = group === 'category' ? r.category : r.route
      ;(acc[key] = acc[key] || []).push(r)
      return acc
    }, {})
    return Object.entries(by).sort(([a], [b]) => a.localeCompare(b)).map(([title, list]) => ({ key: title, title, rows: list }))
  }, [rows, group])

  const summaryBy = group === 'route' ? 'by route' : 'by vehicle type'
  const summaryGroups = useMemo(() => {
    const key = group === 'route' ? 'route' : 'category'
    const by = rows.reduce((acc, r) => {
      ;(acc[r[key]] = acc[r[key]] || []).push(r)
      return acc
    }, {})
    return Object.entries(by).sort(([a], [b]) => a.localeCompare(b)).map(([title, list]) => ({ title, rows: list }))
  }, [rows, group])

  // Tabs: the summary first, then one per vehicle type / route (or the plain list when not grouping).
  const tabs = [
    { key: 'summary', label: 'Summary' },
    ...groups.map((g) => ({ key: g.key, label: g.title || 'All departures', count: g.rows.length })),
  ]
  const activeTab = tabs.some((t) => t.key === groupTab) ? groupTab : 'summary'
  const picked = groups.find((g) => g.key === activeTab)

  const toggleDue = async (row, paid) => {
    setBusy(true)
    setActionError('')
    try {
      await api.post(`/station/trips/${row.id}/due`, { paid })
      toast(paid ? `Due from ${row.registration} marked collected` : `Due from ${row.registration} marked outstanding`)
      reload()
    } catch (err) {
      setActionError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <Card className="no-print mb-5 flex flex-wrap items-end gap-4">
        <label className="block">
          <span className="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-slate-500">Day</span>
          <input
            type="date"
            max={todayYmd()}
            value={date}
            onChange={(e) => e.target.value && setDate(e.target.value)}
            className="block rounded-md border border-slate-300 bg-white px-3 py-2.5 text-slate-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30"
          />
        </label>
        <div className="grid grid-cols-3 gap-1 rounded-lg bg-slate-100 p-1 text-sm font-semibold" role="tablist">
          {GROUPS.map(([key, label]) => (
            <button
              key={key}
              role="tab"
              aria-selected={group === key}
              onClick={() => { setGroup(key); setGroupTab('summary') }}
              className={cx('min-h-10 rounded-md px-3', group === key ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-600 hover:text-slate-900')}
            >
              {label}
            </button>
          ))}
        </div>
      </Card>

      {loading && !data && <PageLoader />}
      <Alert className="mb-4">{error || actionError}</Alert>

      {data && (
        <>
          <div className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4 sm:gap-4">
            <Total label="Vehicles left" value={data.totals.vehicles} />
            <Total label="Station dues" value={moneyExact(data.totals.dues, currency)} />
            <Total label="Collected" value={moneyExact(data.totals.collected, currency)} tone="text-emerald-700" />
            <Total label="Outstanding" value={moneyExact(data.totals.outstanding, currency)} tone={data.totals.outstanding > 0 ? 'text-amber-800' : undefined} />
          </div>

          {rows.length === 0 ? (
            <Card><Empty>No vehicles left on this day.</Empty></Card>
          ) : (
            <>
              <Tabs className="no-print mb-4" value={activeTab} onChange={setGroupTab} tabs={tabs} />

              {/* Summary: the first tab on screen, always on the printed sheet */}
              <div className={activeTab === 'summary' ? '' : 'hidden print:block'}>
                <SummaryTable title={summaryBy} groups={summaryGroups} totals={data.totals} currency={currency} />
              </div>

              {picked && (
                <div className="print:hidden">
                  <GroupTable title={null} rows={picked.rows} currency={currency} onToggle={toggleDue} busy={busy} showCategory={group !== 'category'} showRoute={group !== 'route'} />
                </div>
              )}

              {/* The printed sheet always carries every vehicle, whatever tab was open */}
              <div className="hidden print:block">
                <GroupTable title="All departures" rows={rows} currency={currency} onToggle={toggleDue} busy={busy} showCategory showRoute />
              </div>
            </>
          )}
        </>
      )}
    </div>
  )
}
