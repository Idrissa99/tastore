import { clampPercent, formatFcfa } from '../../utils/format';

const TONES = {
  brand: { bar: 'bg-primary-600', track: 'bg-primary-100' },
  success: { bar: 'bg-success-500', track: 'bg-success-100' },
  warning: { bar: 'bg-warning-500', track: 'bg-warning-100' },
  danger: { bar: 'bg-danger-500', track: 'bg-danger-100' },
  accent: { bar: 'bg-accent-500', track: 'bg-accent-100' },
  dark: { bar: 'bg-ink-900', track: 'bg-ink-200' },
};

/** Barre de progression. `label` alimente les lecteurs d'écran. */
export function ProgressBar({
  value = 0,
  total = 100,
  showLabel = false,
  size = 'md',
  tone = 'brand',
  className = '',
  label = 'Progression',
}) {
  const percent = clampPercent(value, total);

  const heights = { sm: 'h-1.5', md: 'h-2', lg: 'h-3' };
  const colors = TONES[tone] || TONES.brand;

  return (
    <div className={className}>
      {showLabel && (
        <div className="mb-1.5 flex items-baseline justify-between gap-2">
          <span className="text-xs font-medium text-ink-500">{label}</span>
          <span className="amount text-sm text-primary-700">{percent} %</span>
        </div>
      )}
      <div
        role="progressbar"
        aria-label={label}
        aria-valuenow={percent}
        aria-valuemin={0}
        aria-valuemax={100}
        className={`w-full overflow-hidden rounded-full ${heights[size] || heights.md} ${colors.track}`}
      >
        <div
          className={`h-full rounded-full ${colors.bar} transition-[width] duration-700 ease-[cubic-bezier(0.22,1,0.36,1)]`}
          style={{ width: `${percent}%` }}
        />
      </div>
    </div>
  );
}

/** Anneau de progression — utilisé pour la progression globale d'une tontine. */
export function ProgressCircle({
  value = 0,
  total = 100,
  size = 132,
  thickness = 10,
  tone = 'brand',
  label,
  sublabel,
  className = '',
}) {
  const percent = clampPercent(value, total);
  const radius = (size - thickness) / 2;
  const circumference = 2 * Math.PI * radius;
  const dash = (percent / 100) * circumference;

  const strokeColors = {
    brand: { stroke: 'stroke-primary-600', text: 'text-primary-700' },
    success: { stroke: 'stroke-success-500', text: 'text-success-600' },
    warning: { stroke: 'stroke-warning-500', text: 'text-warning-600' },
    accent: { stroke: 'stroke-accent-500', text: 'text-accent-700' },
  };
  const colors = strokeColors[tone] || strokeColors.brand;

  return (
    <div className={`relative inline-flex items-center justify-center ${className}`} style={{ width: size, height: size }}>
      <svg width={size} height={size} className="-rotate-90" aria-hidden="true">
        <circle cx={size / 2} cy={size / 2} r={radius} fill="none" strokeWidth={thickness} className="stroke-ink-100" />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          strokeWidth={thickness}
          strokeLinecap="round"
          strokeDasharray={`${dash} ${circumference}`}
          className={`${colors.stroke} transition-[stroke-dasharray] duration-1000 ease-[cubic-bezier(0.22,1,0.36,1)]`}
        />
      </svg>
      <div className="absolute inset-0 flex flex-col items-center justify-center text-center">
        <span className={`font-display text-2xl font-extrabold tracking-tight ${colors.text}`}>{percent}%</span>
        {label && <span className="mt-0.5 max-w-[80%] text-[11px] font-medium leading-tight text-ink-500">{label}</span>}
        {sublabel && <span className="amount text-xs text-ink-500">{sublabel}</span>}
      </div>
    </div>
  );
}

/**
 * Résumé « X sur Y » + barre, format utilisé partout où l'on doit
 * comprendre immédiatement combien reste à cotiser.
 */
export function ProgressSummary({ collected, target, tone = 'brand', className = '' }) {
  const percent = clampPercent(collected, target);
  const remaining = Math.max(0, Number(target || 0) - Number(collected || 0));

  return (
    <div className={className}>
      <div className="mb-3 flex flex-wrap items-end justify-between gap-x-4 gap-y-2">
        <div>
          <p className="amount text-2xl sm:text-3xl">{formatFcfa(collected)}</p>
          <p className="text-sm text-ink-500">sur {formatFcfa(target)}</p>
        </div>
        <div className="text-right">
          <p className="text-sm font-bold text-primary-700">{percent} %</p>
          {remaining > 0 && <p className="text-xs text-ink-500">reste {formatFcfa(remaining)}</p>}
        </div>
      </div>
      <ProgressBar value={collected} total={target} tone={tone} size="lg" />
    </div>
  );
}

export default ProgressBar;
