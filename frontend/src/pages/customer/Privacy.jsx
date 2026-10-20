import { Card, PageLoader } from '../../components/ui'
import { useFetch } from '../../lib/useFetch'
import { BRAND } from '../../lib/brand'

const UPDATED = '11 October 2026'

function Section({ title, children }) {
  return (
    <section className="space-y-2">
      <h2 className="text-lg font-semibold tracking-tight text-slate-900">{title}</h2>
      <div className="space-y-2 text-[15px] leading-relaxed text-slate-700">{children}</div>
    </section>
  )
}

/** Privacy and cookies. Kept plain on purpose; it also covers advertising, which Google AdSense requires. */
/** Plain text from the admin: a line starting with "## " is a heading, "- " lines are a list, blank lines separate paragraphs. */
function CustomText({ text }) {
  const blocks = text.replace(/\r/g, '').split(/\n{2,}/).map((b) => b.trim()).filter(Boolean)
  return (
    <>
      {blocks.map((b, i) => {
        if (b.startsWith('## ')) return <h2 key={i} className="text-lg font-semibold tracking-tight text-slate-900">{b.slice(3)}</h2>
        const lines = b.split('\n')
        if (lines.every((l) => l.trim().startsWith('- '))) {
          return <ul key={i} className="list-disc space-y-1 pl-5 text-[15px] leading-relaxed text-slate-700">{lines.map((l, n) => <li key={n}>{l.trim().slice(2)}</li>)}</ul>
        }
        return <p key={i} className="whitespace-pre-line text-[15px] leading-relaxed text-slate-700">{b}</p>
      })}
    </>
  )
}

export default function Privacy() {
  const { data, loading } = useFetch('/site')
  if (loading && !data) return <PageLoader />
  const custom = data?.privacy_text

  return (
    <div className="mx-auto max-w-3xl">
      <h1 className="text-[26px] font-semibold tracking-tight text-slate-900">Privacy and cookies</h1>
      <p className="mb-6 mt-1 text-sm text-slate-500">{BRAND} · Last updated {UPDATED}</p>

      <Card className="space-y-6 sm:p-8">
        {custom ? (
          <CustomText text={custom} />
        ) : (
        <>
        <Section title="What we collect">
          <p>When you book a seat we ask for your name and phone number, and an email address if you want to give one. We also keep the trip you chose, your pickup point, your seats, your booking code and the payment status.</p>
          <p>If you create an account we also keep your sign-in details so you can see your bookings later. Booking as a guest does not need an account.</p>
        </Section>

        <Section title="How we use it">
          <ul className="list-disc space-y-1 pl-5">
            <li>To reserve your seat and issue your ticket and booking code.</li>
            <li>To send you text messages about your booking: confirmation, boarding and pickup updates, and changes to your trip.</li>
            <li>To let station staff check your ticket when you travel.</li>
            <li>To keep records of sales and to sort out any problem with a booking or a payment.</li>
          </ul>
          <p>We do not sell your personal details.</p>
        </Section>

        <Section title="Payments">
          <p>Payments are handled by Paystack. Your card or mobile money details go to Paystack and are never seen or stored by us. We only receive confirmation that the payment was made.</p>
        </Section>

        <Section title="Text messages">
          <p>Booking texts are sent through our SMS provider, who receives your phone number and the message so it can be delivered.</p>
        </Section>

        <Section title="Cookies, storage and advertising">
          <p>We store a small amount of information in your browser so the site works: for example, to keep you signed in and to remember tickets you have looked up on this device.</p>
          <p>This site may show advertisements provided by Google AdSense. Google and its partners may use cookies to show ads based on your visits to this and other websites. You can manage or switch off personalised ads in your Google Ads Settings at <span className="font-mono text-[13px]">adssettings.google.com</span>, or learn more at <span className="font-mono text-[13px]">policies.google.com/technologies/ads</span>.</p>
          <p>Ads are never shown on payment or seat selection pages.</p>
        </Section>

        <Section title="How long we keep it">
          <p>We keep booking and payment records for as long as we need them to run the service, handle disputes and meet our record-keeping duties.</p>
        </Section>

        <Section title="Your choices">
          <p>You can ask us to correct your details or to remove your account. Please contact us through the station or the phone number shown on your booking text, and tell us the phone number you booked with.</p>
        </Section>

        <Section title="Changes">
          <p>If we change this page we will update the date at the top.</p>
        </Section>
        </>
        )}
      </Card>
    </div>
  )
}
