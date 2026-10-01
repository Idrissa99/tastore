import { AlertTriangle, CheckCircle2, Info, XCircle } from 'lucide-react';

const VARIANTS = {
  success: {
    wrapper: 'border-success-200 bg-success-50 text-success-700',
    icon: 'text-success-600',
    Icon: CheckCircle2,
    title: 'Succès',
  },
  error: {
    wrapper: 'border-danger-200 bg-danger-50 text-danger-700',
    icon: 'text-danger-600',
    Icon: XCircle,
    title: 'Erreur',
  },
  warning: {
    wrapper: 'border-warning-200 bg-warning-50 text-warning-700',
    icon: 'text-warning-600',
    Icon: AlertTriangle,
    title: 'Attention',
  },
  info: {
    wrapper: 'border-info-200 bg-info-50 text-info-700',
    icon: 'text-info-600',
    Icon: Info,
    title: 'Information',
  },
};

export function Alert({ type = 'success', title, onDismiss, className = '', children }) {
  const variant = VARIANTS[type] || VARIANTS.info;
  const { Icon } = variant;

  return (
    <div
      role={type === 'error' ? 'alert' : 'status'}
      className={`flex items-start gap-3 rounded-xl border px-4 py-3.5 text-sm ${variant.wrapper} ${className}`}
    >
      <Icon className={`mt-0.5 size-4.5 shrink-0 ${variant.icon}`} aria-hidden="true" />
      <div className="min-w-0 flex-1">
        {title && <p className="font-semibold">{title}</p>}
        {children && <div className={title ? 'mt-0.5 leading-relaxed' : 'leading-relaxed'}>{children}</div>}
      </div>
      {onDismiss && (
        <button
          type="button"
          onClick={onDismiss}
          aria-label="Fermer"
          className="-mr-1 shrink-0 rounded-lg p-1 opacity-60 transition-opacity hover:opacity-100"
        >
          <svg viewBox="0 0 24 24" className="size-3.5" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" aria-hidden="true">
            <path d="M18 6 6 18M6 6l12 12" />
          </svg>
        </button>
      )}
    </div>
  );
}

export default Alert;
