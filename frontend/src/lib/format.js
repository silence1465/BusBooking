export const money = (amount, currency = 'GHS') =>
  `${currency} ${Number(amount || 0).toLocaleString('en-GB', { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`

/** "2026-10-10" -> "10 October 2026" (parsed as a local date, never shifted by timezone). */
export const longDate = (ymd) => {
  if (!ymd) return ''
  const [y, m, d] = ymd.slice(0, 10).split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' })
}

export const shortDate = (ymd) => {
  if (!ymd) return ''
  const [y, m, d] = ymd.slice(0, 10).split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' })
}

export const dateTime = (iso) =>
  iso
    ? new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit', hour12: true })
    : '—'

export const timeOnly = (iso) =>
  iso ? new Date(iso).toLocaleTimeString('en-GB', { hour: 'numeric', minute: '2-digit', hour12: true }) : '—'

export const todayYmd = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export const pad2 = (n) => String(n).padStart(2, '0')

/** Always two decimals, for accounts and dues: "GHS 100.00". */
export const moneyExact = (amount, currency = 'GHS') =>
  `${currency} ${Number(amount || 0).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

/** "12" or "12, 13", or "2 seats, any seat" on open-seating vehicles (Express, Voxy) where there are no seat numbers. */
export const seatLabel = (b) => {
  if (b.trip?.seat_selection === false) {
    const n = b.seat_total ?? b.seats.length
    return `${n} seat${n === 1 ? '' : 's'}, any seat`
  }
  return b.seats.join(', ')
}
