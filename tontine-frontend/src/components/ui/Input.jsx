import { forwardRef, useId } from 'react';
import { ChevronDown } from 'lucide-react';

const FIELD_BASE =
  'w-full rounded-xl border bg-white text-ink-900 placeholder:text-ink-400 ' +
  'transition-all duration-200 outline-none ' +
  'focus:border-primary-500 focus:ring-4 focus:ring-primary-500/12 ' +
  'disabled:cursor-not-allowed disabled:bg-ink-50 disabled:text-ink-400';

const borderFor = (invalid) => (invalid ? 'border-danger-300 focus:border-danger-500 focus:ring-danger-500/12' : 'border-line hover:border-ink-300');

const controlClasses = (invalid, extra = '') =>
  `${FIELD_BASE} ${borderFor(invalid)} h-11 px-3.5 text-sm ${extra}`;

/* ------------------------------------------------------------------ */
/* Field — libellé + aide + erreur                                     */
/* ------------------------------------------------------------------ */

export function Field({ label, error, hint, required = false, htmlFor, className = '', children }) {
  const generatedId = useId();
  const id = htmlFor || generatedId;
  const child =
    typeof children === 'function'
      ? children({ id, 'aria-invalid': !!error, 'aria-describedby': error ? `${id}-error` : hint ? `${id}-hint` : undefined })
      : children;

  return (
    <div className={className}>
      {label && (
        <label htmlFor={id} className="mb-1.5 flex items-center gap-1 text-sm font-semibold text-ink-700">
          {label}
          {required && (
            <span className="text-danger-500" aria-hidden="true">
              *
            </span>
          )}
        </label>
      )}
      {child}
      {hint && !error && (
        <p id={`${id}-hint`} className="mt-1.5 text-xs text-ink-500">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} className="mt-1.5 text-xs font-medium text-danger-600">
          {error}
        </p>
      )}
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* Input                                                               */
/* ------------------------------------------------------------------ */

export const Input = forwardRef(function Input({ className = '', invalid, inputSize = 'md', ...props }, ref) {
  const height = { sm: 'h-9 text-sm', md: 'h-11 text-sm', lg: 'h-12 text-base' }[inputSize] || 'h-11 text-sm';
  return <input ref={ref} className={controlClasses(invalid, `${height} ${className}`)} {...props} />;
});

/* ------------------------------------------------------------------ */
/* Textarea                                                            */
/* ------------------------------------------------------------------ */

export const Textarea = forwardRef(function Textarea({ className = '', invalid, rows = 4, ...props }, ref) {
  return (
    <textarea
      ref={ref}
      rows={rows}
      className={`${controlClasses(invalid)} min-h-[110px] resize-y py-3 leading-relaxed ${className}`}
      {...props}
    />
  );
});

/* ------------------------------------------------------------------ */
/* Select                                                              */
/* ------------------------------------------------------------------ */

export const Select = forwardRef(function Select({ className = '', invalid, children, ...props }, ref) {
  return (
    <div className="relative">
      <select
        ref={ref}
        className={`${controlClasses(invalid)} cursor-pointer pr-10 ${className}`}
        {...props}
      >
        {children}
      </select>
      <ChevronDown
        className="pointer-events-none absolute right-3.5 top-1/2 size-4 -translate-y-1/2 text-ink-400"
        aria-hidden="true"
      />
    </div>
  );
});

/* ------------------------------------------------------------------ */
/* SearchInput                                                         */
/* ------------------------------------------------------------------ */

export function SearchInput({ value, onChange, onClear, placeholder = 'Rechercher…', className = '', ...props }) {
  return (
    <div className={`relative ${className}`}>
      <svg
        className="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-ink-400"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        aria-hidden="true"
      >
        <circle cx="11" cy="11" r="7" />
        <path d="m20 20-3.2-3.2" />
      </svg>
      <input
        type="search"
        value={value}
        onChange={(event) => onChange(event.target.value)}
        placeholder={placeholder}
        className={`${FIELD_BASE} ${borderFor(false)} h-11 pl-10 pr-9 text-sm`}
        {...props}
      />
      {value && (
        <button
          type="button"
          onClick={() => onClear?.()}
          aria-label="Effacer la recherche"
          className="absolute right-2.5 top-1/2 -translate-y-1/2 rounded-lg p-1.5 text-ink-400 transition-colors hover:bg-ink-100 hover:text-ink-700"
        >
          <svg viewBox="0 0 24 24" className="size-3.5" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" aria-hidden="true">
            <path d="M18 6 6 18M6 6l12 12" />
          </svg>
        </button>
      )}
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* Checkbox / Radio                                                    */
/* ------------------------------------------------------------------ */

export function Checkbox({ label, description, className = '', id, ...props }) {
  const generatedId = useId();
  const inputId = id || generatedId;
  return (
    <label htmlFor={inputId} className={`flex cursor-pointer items-start gap-3 ${className}`}>
      <input
        id={inputId}
        type="checkbox"
        className="mt-0.5 size-4.5 shrink-0 cursor-pointer rounded border-ink-300 text-primary-700 accent-primary-700 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600"
        {...props}
      />
      <span className="text-sm leading-snug">
        <span className="font-medium text-ink-800">{label}</span>
        {description && <span className="mt-0.5 block text-xs text-ink-500">{description}</span>}
      </span>
    </label>
  );
}

/** Carte radio cliquable (sélection de type de tontine, moyen de paiement…). */
export function RadioCard({ name, value, checked, onChange, title, description, icon: Icon, tone = 'brand', className = '' }) {
  const toneClasses = {
    brand: 'peer-checked:border-primary-600 peer-checked:bg-primary-50 peer-checked:ring-primary-600/20',
    accent: 'peer-checked:border-accent-500 peer-checked:bg-accent-50 peer-checked:ring-accent-500/20',
  };
  return (
    <label
      className={`relative flex cursor-pointer items-start gap-3 rounded-xl border border-line bg-white p-3.5
        transition-all duration-200 hover:border-ink-300 hover:shadow-sm
        has-[:checked]:border-primary-600 has-[:checked]:bg-primary-50/60 has-[:checked]:shadow-sm ${className}`}
    >
      <input
        type="radio"
        name={name}
        value={value}
        checked={checked}
        onChange={onChange}
        className="sr-only"
      />
      <span
        aria-hidden="true"
        className={`mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg bg-ink-100 text-ink-600 transition-colors has-[:checked]:bg-white ${toneClasses[tone] || toneClasses.brand}`}
      >
        {Icon ? <Icon className="size-4.5" /> : null}
      </span>
      <span className="min-w-0">
        <span className="block text-sm font-semibold text-ink-900">{title}</span>
        {description && <span className="mt-0.5 block text-xs leading-snug text-ink-500">{description}</span>}
      </span>
      <CheckMark checked={checked} />
    </label>
  );
}

function CheckMark({ checked }) {
  return (
    <span
      aria-hidden="true"
      className={`ml-auto mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border-2 transition-all ${
        checked ? 'border-primary-600 bg-primary-600 text-white' : 'border-ink-300'
      }`}
    >
      {checked && (
        <svg viewBox="0 0 12 12" className="size-3" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
          <path d="m2.5 6.2 2.2 2.3 4.8-5" />
        </svg>
      )}
    </span>
  );
}

export default Input;
