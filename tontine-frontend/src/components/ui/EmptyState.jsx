import { AlertTriangle, RefreshCw, Inbox, ArrowRight } from 'lucide-react';
import { Button, ButtonLink } from './Button';

/** État vide : explication courte + action principale. */
export function EmptyState({
  icon: Icon = Inbox,
  title,
  description,
  action,
  actionTo,
  actionLabel,
  className = '',
  compact = false,
}) {
  return (
    <div
      className={`flex flex-col items-center justify-center rounded-2xl border border-dashed border-line-strong bg-white/70 text-center ${
        compact ? 'px-5 py-10' : 'px-6 py-16'
      } ${className}`}
    >
      <span className="flex size-14 items-center justify-center rounded-2xl bg-primary-50 text-primary-600">
        <Icon className="size-6" aria-hidden="true" />
      </span>
      <h3 className="mt-4 text-base font-bold text-ink-900">{title}</h3>
      {description && <p className="mt-1.5 max-w-sm text-sm leading-relaxed text-ink-500">{description}</p>}
      {actionTo && actionLabel ? (
        <ButtonLink to={actionTo} variant="primary" iconRight={ArrowRight} className="mt-5">
          {actionLabel}
        </ButtonLink>
      ) : (
        action && <div className="mt-5">{action}</div>
      )}
    </div>
  );
}

/** État d'erreur : message compréhensible + bouton "Réessayer". */
export function ErrorState({ title = 'Impossible de charger ces données', message, onRetry, className = '', compact = false }) {
  return (
    <div
      role="alert"
      className={`flex flex-col items-center justify-center rounded-2xl border border-danger-300 bg-danger-50/70 text-center ${
        compact ? 'px-5 py-10' : 'px-6 py-14'
      } ${className}`}
    >
      <span className="flex size-14 items-center justify-center rounded-2xl bg-white text-danger-600 shadow-sm">
        <AlertTriangle className="size-6" aria-hidden="true" />
      </span>
      <h3 className="mt-4 text-base font-bold text-ink-900">{title}</h3>
      {message && <p className="mt-1.5 max-w-md text-sm leading-relaxed text-ink-600">{message}</p>}
      {onRetry && (
        <Button variant="secondary" icon={RefreshCw} className="mt-5" onClick={onRetry}>
          Réessayer
        </Button>
      )}
    </div>
  );
}

export default EmptyState;
