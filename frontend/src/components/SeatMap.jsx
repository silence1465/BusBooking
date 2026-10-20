import { cx } from './ui'
import { pad2 } from '../lib/format'

function Seat({ seat, selected, onToggle, className }) {
  const unavailable = seat.status !== 'available'
  const state = unavailable ? 'booked' : selected ? 'selected' : 'available'
  const styles = {
    available: 'border-slate-400 bg-white text-slate-900 hover:border-brand-600 hover:bg-brand-50',
    selected: 'border-brand-600 bg-brand-600 text-white',
    booked: 'cursor-not-allowed border-slate-200 bg-slate-200 text-slate-500 line-through',
  }
  return (
    <button
      type="button"
      disabled={unavailable}
      aria-pressed={selected}
      aria-label={`Seat ${seat.seat_number}, ${state}`}
      onClick={() => onToggle(seat.seat_number)}
      className={cx('h-11 rounded-md border-[1.5px] font-mono text-sm font-medium transition', className || 'w-12', styles[state])}
    >
      {pad2(seat.seat_number)}
    </button>
  )
}

export function SeatLegend() {
  return (
    <div className="flex flex-wrap gap-x-5 gap-y-2 text-[13px] text-slate-700">
      <span className="flex items-center gap-2"><i className="h-[18px] w-[18px] rounded border-[1.5px] border-slate-400 bg-white" /> Available</span>
      <span className="flex items-center gap-2"><i className="h-[18px] w-[18px] rounded bg-brand-600" /> Selected</span>
      <span className="flex items-center gap-2"><i className="h-[18px] w-[18px] rounded bg-slate-200" /> Booked</span>
    </div>
  )
}

/** Bus-shaped map, four seats per row (2 + aisle + 2). The last row may be partial. */
export default function SeatMap({ seats, selected, onToggle }) {
  // A count of 4n + 1 (45, 33, 49...) means the back row is a five-seat bench with no aisle.
  const bench = seats.length >= 5 && seats.length % 4 === 1 ? seats.slice(-5) : []
  const regular = bench.length ? seats.slice(0, -5) : seats
  const rows = []
  for (let i = 0; i < regular.length; i += 4) rows.push(regular.slice(i, i + 4))

  return (
    <div className="mx-auto w-[300px] max-w-full rounded-b-xl rounded-t-[28px] border-[1.5px] border-slate-300 bg-slate-50 px-6 pb-6 pt-5">
      <div className="mb-4 flex items-center justify-between border-b border-dashed border-slate-300 pb-3.5 text-[11px] font-semibold uppercase tracking-widest text-slate-500">
        <span className="flex items-center gap-1.5">
          <svg viewBox="0 0 24 24" className="h-[18px] w-[18px]" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
            <circle cx="12" cy="12" r="8" />
            <circle cx="12" cy="12" r="2" />
            <path d="M12 14v6M4.6 9.5l5.6 1.7M19.4 9.5l-5.6 1.7" />
          </svg>
          Driver
        </span>
        <span>Front</span>
      </div>
      <div className="space-y-2">
        {rows.map((row, idx) => (
          <div key={idx} className="flex justify-between">
            <div className="flex gap-2">
              {row.slice(0, 2).map((s) => (
                <Seat key={s.seat_number} seat={s} selected={selected.includes(s.seat_number)} onToggle={onToggle} />
              ))}
            </div>
            <div className="flex gap-2">
              {row.slice(2).map((s) => (
                <Seat key={s.seat_number} seat={s} selected={selected.includes(s.seat_number)} onToggle={onToggle} />
              ))}
            </div>
          </div>
        ))}
        {bench.length > 0 && (
          <div className="flex gap-1">
            {bench.map((s) => (
              <Seat key={s.seat_number} seat={s} selected={selected.includes(s.seat_number)} onToggle={onToggle} className="w-auto min-w-0 flex-1" />
            ))}
          </div>
        )}
      </div>
    </div>
  )
}
