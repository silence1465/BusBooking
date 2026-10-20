import { useEffect, useState } from 'react'
import QRCode from 'qrcode'

/**
 * QR of the booking code. It carries no secrets: the station master's scanner only reads the code
 * and the server decides whether the booking is valid, paid and not yet used.
 */
export default function QrCode({ value, size = 160, className = '' }) {
  const [src, setSrc] = useState('')

  useEffect(() => {
    let alive = true
    QRCode.toDataURL(value, { width: size * 2, margin: 1, errorCorrectionLevel: 'M', color: { dark: '#000000', light: '#ffffff' } })
      .then((url) => alive && setSrc(url))
      .catch(() => alive && setSrc(''))
    return () => {
      alive = false
    }
  }, [value, size])

  if (!src) return <div style={{ width: size, height: size }} className={`bg-slate-100 ${className}`} aria-hidden="true" />
  return <img src={src} width={size} height={size} alt={`QR code for booking ${value}`} className={className} />
}
