import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { AlertTriangle, CheckCircle2, Info, X, XCircle } from 'lucide-react';

const VARIANTS = {
  success: { Icon: CheckCircle2, ring: 'ring-success-200', accent: 'bg-success-500', text: 'text-success-700' },
  error: { Icon: XCircle, ring: 'ring-danger-200', accent: 'bg-danger-500', text: 'text-danger-700' },
  warning: { Icon: AlertTriangle, ring: 'ring-warning-200', accent: 'bg-warning-500', text: 'text-warning-700' },
  info: { Icon: Info, ring: 'ring-info-200', accent: 'bg-info-500', text: 'text-info-700' },
};

function ToastItem({ toast, onDismiss }) {
  const [leaving, setLeaving] = useState(false);
  const variant = VARIANTS[toast.type] || VARIANTS.info;
  const { Icon } = variant;

  useEffect(() => {
    const timer = setTimeout(() => {
      setLeaving(true);
      setTimeout(() => onDismiss(toast.id), 200);
    }, 200);
    return () => clearTimeout(timer);
  }, [toast.id, onDismiss]);

  const close = () => {
    setLeaving(true);
    setTimeout(() => onDismiss(toast.id), 180);
  };

  return (
    <div
      role={toast.type === 'error' ? 'alert' : 'status'}
      className={`pointer-events-auto flex w-full items-start gap-3 overflow-hidden rounded-xl border border-line-strong bg-white p-3.5 shadow-lg ring-1 ${
        variant.ring
      } transition-all duration-200 ${leaving ? 'translate-x-4 opacity-0' : 'translate-x-0 opacity-100'}`}
    >
      <span className={`flex size-8 shrink-0 items-center justify-center rounded-lg text-white ${variant.accent}`}>
        <Icon className="size-4" aria-hidden="true" />
      </span>
      <div className="min-w-0 flex-1 pt-0.5">
        {toast.title && <p className="text-sm font-bold text-ink-900">{toast.title}</p>}
        <p className={`text-sm leading-snug ${toast.title ? 'mt-0.5 text-ink-600' : `font-medium ${variant.text}`}`}>
          {toast.message}
        </p>
        {toast.action && (
          <button
            type="button"
            onClick={() => {
              toast.action.onClick?.();
              close();
            }}
            className="mt-1.5 text-xs font-bold text-primary-700 underline underline-offset-2"
          >
            {toast.action.label}
          </button>
        )}
      </div>
      <button
        type="button"
        onClick={close}
        aria-label="Fermer la notification"
        className="-mr-1 -mt-1 shrink-0 rounded-lg p-1.5 text-ink-400 transition-colors hover:bg-ink-100 hover:text-ink-700"
      >
        <X className="size-3.5" aria-hidden="true" />
      </button>
    </div>
  );
}

/** Portail des toasts : monté une seule fois, par le ToastProvider. */
export function ToastViewport({ toasts, onDismiss }) {
  if (typeof document === 'undefined') return null;
  return createPortal(
    <div className="pointer-events-none fixed inset-x-0 bottom-0 z-[200] flex flex-col items-center gap-2.5 px-4 pb-[max(1rem,env(safe-area-inset-bottom))] sm:inset-x-auto sm:right-0 sm:top-0 sm:bottom-auto sm:items-end sm:pb-0 sm:pr-5 sm:pt-5">
      <div className="flex w-full max-w-sm flex-col gap-2.5">
        {toasts.map((toast) => (
          <ToastItem key={toast.id} toast={toast} onDismiss={onDismiss} />
        ))}
      </div>
    </div>,
    document.body,
  );
}

export default ToastViewport;
