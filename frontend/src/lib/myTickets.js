// Guests have no account, so we remember their tickets (code + phone) in this browser only.
const KEY = 'bb_tickets'

export function savedTickets() {
  try {
    return JSON.parse(localStorage.getItem(KEY)) || []
  } catch {
    return []
  }
}

export function saveTicket(code, phone) {
  try {
    const list = savedTickets().filter((t) => t.code !== code)
    list.unshift({ code, phone })
    localStorage.setItem(KEY, JSON.stringify(list.slice(0, 20)))
  } catch {
    /* storage unavailable: the ticket is still shown, just not remembered */
  }
}

export function forgetTicket(code) {
  try {
    localStorage.setItem(KEY, JSON.stringify(savedTickets().filter((t) => t.code !== code)))
  } catch {
    /* ignore */
  }
}
