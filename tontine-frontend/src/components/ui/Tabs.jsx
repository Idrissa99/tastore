/** Onglets : navigation horizontale, défilement automatique sur mobile. */
export function Tabs({ tabs, value, onChange, className = '', variant = 'pill', ariaLabel = 'Onglets' }) {
  if (!tabs?.length) return null;

  if (variant === 'pill') {
    return (
      <div
        role="group"
        aria-label={ariaLabel}
        className={`no-scrollbar -mx-1 flex gap-1 overflow-x-auto px-1 pb-1 ${className}`}
      >
        {tabs.map((tab) => {
          const active = tab.value === value;
          const Icon = tab.icon;
          return (
            <button
              key={tab.value}
              type="button"
              aria-pressed={active}
              onClick={() => onChange(tab.value)}
              className={`inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-semibold transition-all duration-200 ${
                active
                  ? 'bg-ink-900 text-white shadow-sm'
                  : 'bg-white text-ink-600 ring-1 ring-line-strong ring-inset hover:bg-ink-50 hover:text-ink-900'
              }`}
            >
              {Icon && <Icon className="size-4" aria-hidden="true" />}
              {tab.label}
              {tab.count !== undefined && (
                <span
                  className={`ml-0.5 rounded-full px-1.5 py-0.5 text-[11px] font-bold ${
                    active ? 'bg-white/20 text-white' : 'bg-ink-100 text-ink-600'
                  }`}
                >
                  {tab.count}
                </span>
              )}
            </button>
          );
        })}
      </div>
    );
  }

  // Variante "underline"
  return (
    <div role="group" aria-label={ariaLabel} className={`no-scrollbar flex gap-6 overflow-x-auto border-b border-line-strong ${className}`}>
      {tabs.map((tab) => {
        const active = tab.value === value;
        return (
          <button
            key={tab.value}
            type="button"
            aria-pressed={active}
            onClick={() => onChange(tab.value)}
            className={`relative shrink-0 pb-3 text-sm font-semibold transition-colors ${
              active ? 'text-primary-700 after:absolute after:inset-x-0 after:-bottom-px after:h-0.5 after:rounded-full after:bg-primary-600' : 'text-ink-500 hover:text-ink-800'
            }`}
          >
            {tab.label}
            {tab.count !== undefined && (
              <span className="ml-1.5 rounded-full bg-ink-100 px-1.5 py-0.5 text-[11px] font-bold text-ink-600">{tab.count}</span>
            )}
          </button>
        );
      })}
    </div>
  );
}

/** Groupe de segments (bascule compacte, ex. vue grille / liste). */
export function SegmentedControl({ options, value, onChange, ariaLabel = 'Vue', className = '' }) {
  return (
    <div role="radiogroup" aria-label={ariaLabel} className={`inline-flex rounded-xl bg-ink-100 p-1 ${className}`}>
      {options.map((option) => {
        const Icon = option.icon;
        const active = option.value === value;
        return (
          <button
            key={option.value}
            type="button"
            role="radio"
            aria-checked={active}
            onClick={() => onChange(option.value)}
            title={option.label}
            className={`inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-all duration-200 ${
              active ? 'bg-white text-ink-900 shadow-sm' : 'text-ink-500 hover:text-ink-800'
            }`}
          >
            {Icon ? <Icon className="size-3.5" aria-hidden="true" /> : null}
            {option.label}
          </button>
        );
      })}
    </div>
  );
}

/** Étapes horizontales (parcours de paiement). */
export function Stepper({ steps, current, className = '' }) {
  const activeIndex = Math.min(steps.findIndex((step) => step.id === current), steps.length - 1);

  if (activeIndex < 0) return null;

  return (
    <ol className={`flex items-center ${className}`}>
      {steps.map((step, index) => {
        const state = index < activeIndex ? 'done' : index === activeIndex ? 'current' : 'todo';
        return (
          <li key={step.id} className={`flex items-center ${index < steps.length - 1 ? 'flex-1' : ''}`}>
            <div className="flex flex-col items-center gap-1.5 sm:flex-row sm:gap-2.5">
              <span
                aria-hidden="true"
                className={`flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-bold transition-all duration-300 sm:size-8 ${
                  state === 'done'
                    ? 'bg-primary-600 text-white'
                    : state === 'current'
                      ? 'bg-primary-700 text-white ring-4 ring-primary-600/20'
                      : 'bg-ink-100 text-ink-400 ring-1 ring-line ring-inset'
                }`}
              >
                {state === 'done' ? <CheckMark /> : index + 1}
              </span>
              <span
                className={`text-center text-[11px] font-semibold leading-tight sm:text-xs sm:text-left ${
                  state === 'todo' ? 'text-ink-400' : 'text-ink-800'
                }`}
              >
                {step.label}
              </span>
            </div>
            {index < steps.length - 1 && (
              <span
                aria-hidden="true"
                className={`mx-2 mb-5 h-0.5 flex-1 rounded-full transition-colors duration-500 sm:mb-0 ${
                  index < activeIndex ? 'bg-primary-600' : 'bg-line'
                }`}
              />
            )}
          </li>
        );
      })}
    </ol>
  );
}

function CheckMark() {
  return (
    <svg viewBox="0 0 14 14" className="size-3.5" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="m3 7.5 2.5 2.5L11 4" />
    </svg>
  );
}

export default Tabs;
