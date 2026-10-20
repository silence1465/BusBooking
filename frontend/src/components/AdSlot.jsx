import { useEffect, useRef, useState } from 'react'
import { api } from '../lib/api'

// One request and one script for the whole visit, however many ad spots a page has.
let configRequest = null
let scriptAdded = false
const loadConfig = () => (configRequest ||= api.get('/ads').then((r) => r.data.data).catch(() => ({ enabled: false })))

function addScript(publisher) {
  if (scriptAdded) return
  scriptAdded = true
  const s = document.createElement('script')
  s.async = true
  s.crossOrigin = 'anonymous'
  s.src = `https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=${publisher}`
  document.head.appendChild(s)
}

/**
 * A Google AdSense spot. Shows nothing at all until an admin has turned ads on and saved the publisher id and the
 * ad unit id for this spot (Admin, Settings). placement: home | results | confirmation.
 */
export default function AdSlot({ placement, className = '' }) {
  const [config, setConfig] = useState(null)
  const pushed = useRef(false)

  useEffect(() => {
    let current = true
    loadConfig().then((c) => current && setConfig(c))
    return () => { current = false }
  }, [])

  const slot = config?.enabled ? config.slots?.[placement] : null

  useEffect(() => {
    if (!slot || pushed.current) return
    pushed.current = true
    addScript(config.publisher_id)
    try {
      ;(window.adsbygoogle = window.adsbygoogle || []).push({})
    } catch {
      // an ad blocker or a slow script must never break the page
    }
  }, [slot, config])

  if (!slot) return null

  return (
    <aside className={`no-print ${className}`} aria-label="Advertisement">
      <p className="mb-1 text-center text-[11px] font-semibold uppercase tracking-wider text-slate-400">Advertisement</p>
      {/* Space is kept so the page does not jump when the ad arrives. */}
      <div className="min-h-[100px] overflow-hidden rounded-lg border border-slate-200 bg-white">
        <ins className="adsbygoogle" style={{ display: 'block' }} data-ad-client={config.publisher_id} data-ad-slot={slot} data-ad-format="auto" data-full-width-responsive="true" />
      </div>
    </aside>
  )
}
