import { resolveStatus, TONES } from '../../utils/status';

const DOT_TONES = {
  success: 'bg-success-500',
  warning: 'bg-warning-500',
  danger: 'bg-danger-500',
  info: 'bg-info-500',
  brand: 'bg-primary-600',
  accent: 'bg-accent-500',
  neutral: 'bg-ink-400',
};

/** Pastille générique : couleur, icône et/ou point d'état. */
export function Badge({ tone = 'neutral', size = 'md', icon: Icon, dot = false, className = '', children }) {
  const sizes = {
    xs: 'px-1.5 py-0.5 text-[11px] gap-1',
    sm: 'px-2 py-0.5 text-xs gap-1',
    md: 'px-2.5 py-1 text-xs gap-1.5',
  };
  return (
    <span
      className={`inline-flex items-center rounded-full font-semibold ring-1 ring-inset whitespace-nowrap ${
        TONES[tone] || TONES.neutral
      } ${sizes[size] || sizes.md} ${className}`}
    >
      {dot && <span className={`size-1.5 shrink-0 rounded-full ${DOT_TONES[tone] || DOT_TONES.neutral}`} aria-hidden="true" />}
      {Icon && <Icon className="size-3" aria-hidden="true" />}
      {children}
    </span>
  );
}

/**
 * Pastille d'état. Le statut peut être une chaîne backend ou un objet
 * { label, tone } déjà résolu. `dot` ajoute un point coloré (lisible
 * même daltonien, contrairement à une couleur seule).
 */
export function StatusBadge({ status, label, tone, dot = false, size = 'md', className = '' }) {
  const resolved = typeof status === 'object' && status !== null ? status : resolveStatus(status);
  return (
    <Badge tone={tone || resolved.tone} dot={dot} size={size} className={className}>
      {label || resolved.label}
    </Badge>
  );
}

export default Badge;
