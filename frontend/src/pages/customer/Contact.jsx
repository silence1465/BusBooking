import { useFetch } from '../../lib/useFetch'
import { Alert, PageLoader } from '../../components/ui'
import { BRAND } from '../../lib/brand'

const PHONE = 'M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z'
const CHAT = 'M4 5h16v11H9l-5 4z'
const MAIL = 'M3 6h18v12H3zM3 7l9 7 9-7'
const PIN = 'M12 21s-7-5.5-7-11a7 7 0 0114 0c0 5.500-7 11-7 11zM12 12a2 2 0 100-4 2 2 0 000 4z'
const CLOCK = 'M12 21a9 9 0 100-18 9 9 0 000 18zM12 7v5l3 2'

/** One line of contact information: a small icon, a label and the value. No box around it. */
function Line({ icon, label, children, href }) {
  const body = (
    <>
      <svg viewBox="0 0 24 24" className="mt-1 h-6 w-6 shrink-0 text-brand-600" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d={icon} /></svg>
      <span className="min-w-0">
        <span className="block text-xs font-semibold uppercase tracking-wider text-slate-500">{label}</span>
        <span className="block whitespace-pre-line break-words text-[17px] font-semibold text-slate-900">{children}</span>
      </span>
    </>
  )
  const row = 'flex items-start gap-4 py-3'
  return (
    <li>
      {href ? (
        <a href={href} target={href.startsWith('http') ? '_blank' : undefined} rel="noreferrer" className={row + ' min-h-14 hover:text-brand-700'}>{body}</a>
      ) : (
        <div className={row}>{body}</div>
      )}
    </li>
  )
}

/** How to reach the company. Everything here is written by an admin under Settings. */
export default function Contact() {
  const { data, loading, error } = useFetch('/site')
  if (loading && !data) return <PageLoader />
  if (error) return <Alert>{error}</Alert>

  const c = data.contact
  const digits = (v) => (v || '').replace(/\D/g, '')
  const any = Object.values(c).some(Boolean)

  return (
    <div className="mx-auto max-w-5xl">
      <h1 className="text-[26px] font-semibold tracking-tight text-slate-900">Contact us</h1>
      <p className="mb-6 mt-1 text-slate-600">{c.intro || `How to reach ${BRAND}.`}</p>

      {!any ? (
        <p className="py-6 text-slate-500">Our contact details will be added here soon.</p>
      ) : (
        <div className="grid gap-8 md:grid-cols-2 md:items-start">
          <ul className="divide-y divide-slate-200 border-y border-slate-200">
            {c.phone && <Line icon={PHONE} label="Call us" href={`tel:${c.phone.replace(/\s+/g, '')}`}>{c.phone}</Line>}
            {c.whatsapp && <Line icon={CHAT} label="WhatsApp" href={`https://wa.me/${digits(c.whatsapp).replace(/^0/, '233')}`}>{c.whatsapp}</Line>}
            {c.email && <Line icon={MAIL} label="Email" href={`mailto:${c.email}`}>{c.email}</Line>}
            {c.hours && <Line icon={CLOCK} label="Opening hours">{c.hours}</Line>}
            {c.address && <Line icon={PIN} label="Find us" href={c.map_url || undefined}>{c.address}</Line>}
            {!c.address && c.map_url && <Line icon={PIN} label="Find us" href={c.map_url}>Open in maps</Line>}
          </ul>

          {c.map_embed && (
            <iframe
              src={c.map_embed}
              title={`Map showing where to find ${BRAND}`}
              className="h-80 w-full rounded-lg border-0 md:h-[26rem]"
              allowFullScreen
              loading="lazy"
              referrerPolicy="strict-origin-when-cross-origin"
            />
          )}
        </div>
      )}
    </div>
  )
}
