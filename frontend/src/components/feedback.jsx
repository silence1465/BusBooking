import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react'
import { Button, cx } from './ui'

/**
 * App-wide feedback: toasts ("Saved", "Vehicle dispatched") and a styled confirmation dialog that replaces
 * the browser's confirm() box. Use: const toast = useToast(); const confirm = useConfirm();
 *   toast('Bus saved')                        // success
 *   toast('Could not save', 'error')
 *   if (await confirm({ title, body, confirmLabel, danger })) { ... }
 */
const FeedbackContext = createContext(null)

const TOAST_STYLES = {
  success: 'border-emerald-200 bg-white text-slate-900',
  error: 'border-red-200 bg-white text-slate-900',
  info: 'border-brand-200 bg-white text-slate-900',
}
const TOAST_ICON = {
  success: { d: 'M12 21a9 9 0 100-18 9 9 0 000 18zM8 12l3 3 5-6', cls: 'text-emerald-600' },
  error: { d: 'M12 21a9 9 0 100-18 9 9 0 000 18zM12 8v5M12 16h.01', cls: 'text-red-600' },
  info: { d: 'M12 21a9 9 0 100-18 9 9 0 000 18zM12 11v6M12 7.5h.01', cls: 'text-brand-600' },
}

function ConfirmDialog({ options, onResult }) {
  const confirmRef = useRef(null)

  useEffect(() => {
    confirmRef.current?.focus()
    const onKey = (e) => e.key === 'Escape' && onResult(false)
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onResult])

  return (
    <div className="fixed inset-0 z-[60] flex items-end justify-center bg-slate-900/50 sm:items-center sm:p-4" onMouseDown={() => onResult(false)}>
      <div
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="confirm-title"
        className="w-full rounded-t-2xl bg-white p-5 shadow-xl sm:max-w-md sm:rounded-xl sm:p-6"
        onMouseDown={(e) => e.stopPropagation()}
      >
        <h2 id="confirm-title" className="text-lg font-semibold tracking-tight text-slate-900">{options.title}</h2>
        {options.body && <p className="mt-2 whitespace-pre-line text-sm leading-relaxed text-slate-600">{options.body}</p>}
        <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="secondary" className="min-h-11" onClick={() => onResult(false)}>{options.cancelLabel || 'Cancel'}</Button>
          <Button ref={confirmRef} variant={options.danger ? 'danger' : 'primary'} className="min-h-11" onClick={() => onResult(true)}>
            {options.confirmLabel || 'Confirm'}
          </Button>
        </div>
      </div>
    </div>
  )
}

export function FeedbackProvider({ children }) {
  const [toasts, setToasts] = useState([])
  const [dialog, setDialog] = useState(null) // { options, resolve }
  const nextId = useRef(1)

  const toast = useCallback((message, type = 'success') => {
    const id = nextId.current++
    setToasts((list) => [...list.slice(-2), { id, message, type }])
    setTimeout(() => setToasts((list) => list.filter((t) => t.id !== id)), type === 'error' ? 6000 : 3500)
  }, [])

  const confirm = useCallback((options) => new Promise((resolve) => setDialog({ options, resolve })), [])

  const finish = useCallback((result) => {
    setDialog((d) => {
      d?.resolve(result)
      return null
    })
  }, [])

  const value = useMemo(() => ({ toast, confirm }), [toast, confirm])

  return (
    <FeedbackContext.Provider value={value}>
      {children}

      {/* Toasts: bottom on phones (above the tab bar), top right on desktop. */}
      <div className="no-print pointer-events-none fixed inset-x-0 bottom-20 z-[70] flex flex-col items-center gap-2 px-4 sm:inset-x-auto sm:bottom-auto sm:right-6 sm:top-6 sm:items-end" aria-live="polite">
        {toasts.map((t) => (
          <div key={t.id} role="status" className={cx('pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border px-4 py-3 text-sm font-medium shadow-lg', TOAST_STYLES[t.type])}>
            <svg viewBox="0 0 24 24" className={cx('mt-0.5 h-5 w-5 shrink-0', TOAST_ICON[t.type].cls)} fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
              <path d={TOAST_ICON[t.type].d} />
            </svg>
            <span className="flex-1">{t.message}</span>
            <button onClick={() => setToasts((list) => list.filter((x) => x.id !== t.id))} className="-mr-1 rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Dismiss">
              <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
            </button>
          </div>
        ))}
      </div>

      {dialog && <ConfirmDialog options={dialog.options} onResult={finish} />}
    </FeedbackContext.Provider>
  )
}

// eslint-disable-next-line react-refresh/only-export-components
export const useToast = () => useContext(FeedbackContext).toast
// eslint-disable-next-line react-refresh/only-export-components
export const useConfirm = () => useContext(FeedbackContext).confirm
