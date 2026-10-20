import { cx } from './ui'

/**
 * "How many seats?" for open-seating vehicles (Express, Voxy): no seat map, the system assigns seats.
 * Any number up to what is free: step with − / +, type the number, or take every free seat in one tap.
 */
export default function SeatCountPicker({ value, onChange, max = Infinity, available }) {
  const limit = Math.max(0, Math.min(max, available))
  const btn = 'grid h-14 w-14 place-items-center rounded-lg border-[1.5px] text-2xl font-semibold transition disabled:cursor-not-allowed disabled:opacity-40'
  const set = (n) => onChange(Math.max(1, Math.min(limit || 1, Math.floor(Number(n)) || 1)))

  return (
    <div className="mx-auto max-w-xs text-center">
      <div className="flex items-center justify-center gap-6">
        <button
          type="button"
          className={cx(btn, 'border-slate-300 text-slate-900 hover:border-brand-600 hover:bg-brand-50')}
          disabled={value <= 1}
          onClick={() => set(value - 1)}
          aria-label="One seat fewer"
        >
          −
        </button>
        <div>
          <input
            type="number"
            inputMode="numeric"
            min={1}
            max={limit || 1}
            value={value}
            onChange={(e) => set(e.target.value)}
            onFocus={(e) => e.target.select()}
            aria-label="Number of seats"
            className="w-24 rounded-md border border-transparent bg-transparent text-center !text-5xl font-semibold tracking-tight text-slate-900 [appearance:textfield] hover:border-slate-300 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
          />
          <p className="text-sm text-slate-500">seat{value === 1 ? '' : 's'}</p>
        </div>
        <button
          type="button"
          className={cx(btn, 'border-brand-600 bg-brand-600 text-white hover:bg-brand-700')}
          disabled={value >= limit}
          onClick={() => set(value + 1)}
          aria-label="One seat more"
        >
          +
        </button>
      </div>

      {limit > 1 && (
        <button
          type="button"
          onClick={() => set(value >= limit ? 1 : limit)}
          aria-pressed={value >= limit}
          className={cx(
            'mt-4 inline-flex min-h-11 items-center rounded-md border-[1.5px] px-4 text-sm font-semibold',
            value >= limit ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-slate-300 text-slate-700 hover:border-brand-600 hover:bg-brand-50',
          )}
        >
          {value >= limit ? `All ${limit} seats selected` : `Take all ${limit} seats`}
        </button>
      )}

      <p className="mt-4 text-sm text-slate-500">
        {available > 0 ? `${available} seat${available === 1 ? '' : 's'} left. ` : 'No seats left. '}
        Open seating: you sit anywhere on the vehicle.
      </p>
    </div>
  )
}
