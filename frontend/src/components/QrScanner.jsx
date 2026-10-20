import { useEffect, useRef, useState } from 'react'
import jsQR from 'jsqr'
import { Alert, Button } from './ui'

const CODE_RE = /BUS-[A-Z0-9]{6}/i

/** Pulls a booking code out of whatever the QR contains (plain code, or a link that includes it). */
export const extractBookingCode = (text) => text?.match(CODE_RE)?.[0]?.toUpperCase() || null

/**
 * Live camera scanner. Calls onCode(code) once and stops. The camera needs HTTPS (or localhost) and the
 * user's permission; if either is missing we say so and the code can still be typed.
 */
export default function QrScanner({ onCode, onClose }) {
  const videoRef = useRef(null)
  const canvasRef = useRef(null)
  const [error, setError] = useState('')
  const [hint, setHint] = useState('Starting camera…')
  // Keep the camera running even if the parent re-renders with a new callback.
  const onCodeRef = useRef(onCode)
  useEffect(() => {
    onCodeRef.current = onCode
  })

  useEffect(() => {
    let stream
    let raf
    let stopped = false

    const stop = () => {
      stopped = true
      cancelAnimationFrame(raf)
      stream?.getTracks().forEach((t) => t.stop())
    }

    const tick = () => {
      if (stopped) return
      const video = videoRef.current
      const canvas = canvasRef.current
      if (video && canvas && video.readyState === video.HAVE_ENOUGH_DATA) {
        // Downscale: scanning a 640px frame is fast and still reads a phone screen or paper ticket.
        const scale = Math.min(1, 640 / video.videoWidth)
        canvas.width = Math.round(video.videoWidth * scale)
        canvas.height = Math.round(video.videoHeight * scale)
        const ctx = canvas.getContext('2d', { willReadFrequently: true })
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height)
        const img = ctx.getImageData(0, 0, canvas.width, canvas.height)
        const result = jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' })
        if (result) {
          const code = extractBookingCode(result.data)
          if (code) {
            stop()
            onCodeRef.current(code)
            return
          }
          setHint('That QR code is not a booking ticket.')
        }
      }
      raf = requestAnimationFrame(tick)
    }

    ;(async () => {
      if (!navigator.mediaDevices?.getUserMedia) {
        setError('This browser cannot open the camera here. Camera scanning needs a secure (HTTPS) connection. Type the code instead.')
        return
      }
      try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false })
        if (stopped) return stream.getTracks().forEach((t) => t.stop())
        const video = videoRef.current
        video.srcObject = stream
        await video.play()
        setHint('Point the camera at the passenger’s QR code.')
        raf = requestAnimationFrame(tick)
      } catch (e) {
        setError(
          e.name === 'NotAllowedError'
            ? 'Camera permission was denied. Allow camera access for this site, or type the code instead.'
            : 'No camera could be started. Type the code instead.',
        )
      }
    })()

    return stop
  }, [])

  return (
    <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
      <div className="relative aspect-[4/3] bg-slate-900">
        <video ref={videoRef} playsInline muted className="h-full w-full object-cover" />
        <canvas ref={canvasRef} className="hidden" />
        {!error && (
          <div className="pointer-events-none absolute inset-0 grid place-items-center" aria-hidden="true">
            <div className="h-1/2 aspect-square rounded-lg border-2 border-white/90 shadow-[0_0_0_9999px_rgba(27,22,64,0.45)]" />
          </div>
        )}
      </div>
      <div className="space-y-3 p-4">
        {error ? <Alert>{error}</Alert> : <p className="text-center text-sm text-slate-600" role="status">{hint}</p>}
        <Button variant="secondary" className="w-full" onClick={onClose}>Close scanner</Button>
      </div>
    </div>
  )
}
