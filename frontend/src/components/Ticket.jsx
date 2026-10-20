import { useState } from 'react'
import { Button, OpenSeatingBadge, SeatBadges } from './ui'
import QrCode from './QrCode'
import { BRAND } from '../lib/brand'
import { longDate, money, seatLabel } from '../lib/format'

function Cell({ label, children, mono }) {
  return (
    <div>
      <dt className="text-xs text-slate-500">{label}</dt>
      <dd className={`font-semibold text-slate-900 ${mono ? 'font-mono' : ''}`}>{children}</dd>
    </div>
  )
}

export default function Ticket({ booking }) {
  const [copied, setCopied] = useState(false)
  const t = booking.trip
  const payStatus = booking.payment?.status || 'pending'
  const paid = payStatus === 'paid'

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(booking.code)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      window.prompt('Copy your booking code:', booking.code)
    }
  }

  return (
    <div>
      <div className="print-area overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div className="bg-slate-900 px-5 py-4 text-white">
          <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-300">{BRAND} · Booking code</p>
          <p className="mt-0.5 font-mono text-3xl font-semibold tracking-wide">{booking.code}</p>
        </div>

        <div className="flex items-center justify-between gap-3 border-b border-dashed border-slate-300 px-5 py-4">
          <div>
            <p className="text-xs text-slate-500">From</p>
            <p className="text-xl font-semibold text-slate-900">{t.origin}</p>
          </div>
          <svg viewBox="0 0 56 16" className="h-4 w-14 shrink-0 text-slate-400" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M2 8h50M46 3l6 5-6 5" />
          </svg>
          <div className="text-right">
            <p className="text-xs text-slate-500">To</p>
            <p className="text-xl font-semibold text-slate-900">{t.destination}</p>
          </div>
        </div>

        <dl className="grid grid-cols-2 gap-x-4 gap-y-3.5 px-5 py-4">
          <Cell label="Passenger">{booking.passenger_name}</Cell>
          <Cell label={booking.seats.length > 1 || booking.trip.seat_selection === false ? 'Seats' : 'Seat'} mono={booking.trip.seat_selection !== false}>
            {booking.trip.seat_selection === false ? <OpenSeatingBadge>{seatLabel(booking)}</OpenSeatingBadge> : <SeatBadges seats={booking.seats} />}
          </Cell>
          <Cell label="Date">{longDate(t.travel_date)}</Cell>
          <Cell label="Departure">{t.departure_time_label}</Cell>
          <Cell label="Pickup">{booking.pickup_point}</Cell>
          {booking.dropoff_point && <Cell label="Destination">{booking.dropoff_point}</Cell>}
          <Cell label="Amount">{money(booking.amount, booking.currency)}</Cell>
        </dl>

        {t.fill_and_go && (
          <p className="border-t border-dashed border-slate-300 bg-brand-50 px-5 py-3 text-[13px] font-medium text-brand-700">
            This bus has no fixed time and leaves when it is full.{' '}
            {booking.pickup_stop?.is_main ? 'Be at the station while it fills up.' : `We will text you when it leaves the station. Be ready at ${booking.pickup_point}.`}
          </p>
        )}

        <div className="flex flex-col items-center border-t border-dashed border-slate-300 px-5 py-4">
          <QrCode value={booking.code} size={150} />
          <p className="mt-1.5 text-xs text-slate-500">Scan at the station to check in</p>
        </div>

        <div className="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3">
          <span className="text-[13px] text-slate-500">Payment</span>
          <span className={`rounded px-2.5 py-1 text-xs font-semibold uppercase tracking-wider ${paid ? 'bg-emerald-100 text-emerald-700' : payStatus === 'pending' ? 'bg-amber-100 text-amber-900' : 'bg-red-100 text-red-800'}`}>
            {payStatus}
          </span>
        </div>
      </div>

      <p className="no-print mt-3 text-center text-[13px] text-slate-500">Show this code to the station master when you arrive.</p>

      <div className="no-print mt-4 space-y-2.5">
        <Button size="lg" className="w-full" onClick={() => window.print()}>Download ticket</Button>
        <div className="grid grid-cols-2 gap-2.5">
          <Button variant="secondary" className="min-h-11" onClick={copy}>{copied ? 'Copied' : 'Copy code'}</Button>
          <Button variant="secondary" className="min-h-11" onClick={() => window.print()}>Print</Button>
        </div>
        <p className="text-center text-xs text-slate-500">To download, choose “Save as PDF” in the print window.</p>
      </div>
    </div>
  )
}
