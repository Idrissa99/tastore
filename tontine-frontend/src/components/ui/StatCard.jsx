import { Link } from 'react-router-dom';

const TONES = {
  default: { icon: 'bg-primary-50 text-primary-700', value: 'text-ink-900' },
  brand: { icon: 'bg-primary-50 text-primary-700', value: 'text-primary-800' },
  success: { icon: 'bg-success-50 text-success-600', value: 'text-success-700' },
  warning: { icon: 'bg-warning-50 text-warning-600', value: 'text-warning-700' },
  danger: { icon: 'bg-danger-50 text-danger-600', value: 'text-danger-600' },
  info: { icon: 'bg-info-50 text-info-600', value: 'text-info-700' },
  accent: { icon: 'bg-accent-50 text-accent-600', value: 'text-accent-700' },
  neutral: { icon: 'bg-ink-100 text-ink-600', value: 'text-ink-900' },
};

/**
 * Tuile de statistique : icône, libellé, valeur, variation optionnelle.
 * Le libellé est toujours présent : une valeur seule est ambiguë.
 */
export function StatCard({
  label,
  value,
  sublabel,
  icon: Icon,
  tone = 'default',
  to,
  onClick,
  trend = null,
  className = '',
}) {
  const colors = TONES[tone] || TONES.default;
  const body = (
    <>
      <div className="flex items-start justify-between gap-3">
        <p className="text-xs font-semibold uppercase tracking-wide text-ink-500">{label}</p>
        {Icon && (
          <span className={`flex size-9 shrink-0 items-center justify-center rounded-xl ${colors.icon}`}>
            <Icon className="size-4.5" aria-hidden="true" />
          </span>
        )}
      </div>
      <p className={`amount mt-3 text-2xl leading-none sm:text-[26px] ${colors.value}`}>{value}</p>
      <div className="mt-2 flex items-center gap-2">
        {trend && (
          <span
            className={`inline-flex items-center gap-0.5 text-xs font-bold ${
              trend.direction === 'up' ? 'text-success-600' : trend.direction === 'down' ? 'text-danger-600' : 'text-ink-500'
            }`}
          >
            {trend.value}
          </span>
        )}
        {sublabel && <p className="truncate text-xs text-ink-500">{sublabel}</p>}
      </div>
    </>
  );

  const interactive =
    'transition-all duration-300 hover:-translate-y-0.5 hover:border-primary-200 hover:shadow-md';

  if (to) {
    return (
      <Link
        to={to}
        className={`block rounded-2xl border border-line-strong bg-white p-5 shadow-sm ${interactive} ${className}`}
      >
        {body}
      </Link>
    );
  }

  if (onClick) {
    return (
      <button
        type="button"
        onClick={onClick}
        className={`w-full rounded-2xl border border-line-strong bg-white p-5 text-left shadow-sm ${interactive} ${className}`}
      >
        {body}
      </button>
    );
  }

  return <div className={`rounded-2xl border border-line-strong bg-white p-5 shadow-sm ${className}`}>{body}</div>;
}

/** Version compacte pour les bandeaux de synthèse en haut de page. */
export function MiniStat({ label, value, className = '' }) {
  return (
    <div className={className}>
      <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-500">{label}</p>
      <p className="amount mt-1 text-lg">{value}</p>
    </div>
  );
}

export default StatCard;
