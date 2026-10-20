import { useState } from 'react'
import { api, errorMessage } from '../lib/api'
import { Alert, Button, Modal } from './ui'
import { money } from '../lib/format'
import { useConfirm, useToast } from './feedback'

/**
 * Sends a vehicle off and records its station due. Asks before leaving unchecked station passengers behind.
 * `due` is what this vehicle pays the station when it departs (from its category or special rate).
 */
export default function DispatchButton({ tripId, vehicle, due = 0, onDone, className = '', label = 'Dispatch vehicle', size = 'md' }) {
  const [open, setOpen] = useState(false)
  const [collected, setCollected] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const confirm = useConfirm()
  const toast = useToast()

  const dispatch = async (leaveBehind = false) => {
    setBusy(true)
    setError('')
    try {
      await api.post(`/station/trips/${tripId}/dispatch`, { leave_behind: leaveBehind, due_collected: due > 0 && collected })
      setOpen(false)
      toast(due > 0 ? `Vehicle dispatched. Station due ${money(due)} ${collected ? 'collected' : 'recorded as outstanding'}.` : 'Vehicle dispatched')
      onDone?.()
    } catch (err) {
      if (err.response?.data?.unresolved && !leaveBehind) {
        const ok = await confirm({
          title: 'Leave passengers behind?',
          body: `${err.response.data.message}\n\nIf you send the vehicle now, their tickets move to the next vehicle in the queue.`,
          confirmLabel: 'Send it anyway',
          danger: true,
        })
        if (ok) return dispatch(true)
      } else {
        setError(errorMessage(err))
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className={className}>
      <Button variant="dark" size={size} className="w-full" onClick={() => setOpen(true)}>{label}</Button>
      <Modal open={open} onClose={() => setOpen(false)} title="Send this vehicle off?">
        <div className="space-y-4">
          {vehicle && <p className="font-semibold text-slate-900">{vehicle}</p>}
          <p className="text-sm text-slate-600">It leaves the station now. The next vehicle in the queue moves to the front.</p>

          <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
            <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">Station due for this vehicle (recorded automatically)</p>
            <p className="mt-1 text-2xl font-semibold tracking-tight text-slate-900">{due > 0 ? money(due) : 'None'}</p>
            {due > 0 && (
              <label className="mt-3 flex cursor-pointer items-center gap-2.5 text-sm font-medium text-slate-800">
                <input type="checkbox" className="h-4 w-4 accent-brand-600" checked={collected} onChange={(e) => setCollected(e.target.checked)} />
                I have collected the cash now
              </label>
            )}
            {due > 0 && !collected && <p className="mt-2 text-xs text-amber-800">It is still recorded against this vehicle as outstanding. Mark it collected later from Departures.</p>}
          </div>

          <Alert>{error}</Alert>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setOpen(false)}>Cancel</Button>
            <Button variant="dark" loading={busy} onClick={() => dispatch(false)}>Dispatch</Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
