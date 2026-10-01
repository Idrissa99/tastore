import { forwardRef } from 'react';
import { Link } from 'react-router-dom';
import { Loader2 } from 'lucide-react';

/*
 * Bouton unifié. `variant` porte la couleur, `size` la densité.
 * On conserve `variant="secondary"` (historique) et des dimensions proches
 * de l'ancienne base pour que les pages non encore refondues restent
 * visuellement cohérentes.
 */

const VARIANTS = {
  primary:
    'bg-primary-700 text-white shadow-brand hover:bg-primary-800 active:bg-primary-900',
  secondary:
    'bg-white text-ink-800 border border-line-strong shadow-sm hover:border-primary-400 hover:text-primary-800 hover:bg-primary-50',
  ghost: 'text-ink-600 hover:bg-ink-100 hover:text-ink-900',
  subtle: 'bg-ink-100 text-ink-700 hover:bg-ink-200',
  accent: 'bg-accent-500 text-ink-900 shadow-sm hover:bg-accent-400 active:bg-accent-600',
  success: 'bg-success-500 text-white hover:bg-success-600',
  danger: 'bg-danger-500 text-white hover:bg-danger-600',
  'danger-outline':
    'bg-white text-danger-600 border border-danger-200 hover:bg-danger-50 hover:border-danger-500',
  outline: 'bg-transparent text-primary-800 border border-primary-300 hover:bg-primary-50',
  dark: 'bg-ink-900 text-white hover:bg-ink-800',
};

const SIZES = {
  xs: 'h-8 px-2.5 text-xs gap-1.5 rounded-lg',
  sm: 'h-9 px-3.5 text-sm gap-1.5 rounded-lg',
  md: 'h-11 px-5 text-sm gap-2 rounded-xl',
  lg: 'h-12 px-6 text-base gap-2 rounded-xl',
  xl: 'h-14 px-7 text-base gap-2.5 rounded-2xl',
};

const BASE =
  'relative inline-flex items-center justify-center font-semibold whitespace-nowrap select-none ' +
  'transition-all duration-200 ease-[cubic-bezier(0.22,1,0.36,1)] ' +
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 ' +
  'disabled:opacity-50 disabled:pointer-events-none active:scale-[0.985]';

export function buttonClasses({ variant = 'primary', size = 'md', className = '' } = {}) {
  return `${BASE} ${SIZES[size] || SIZES.md} ${VARIANTS[variant] || VARIANTS.primary} ${className}`;
}

/**
 * Les props d'icône acceptent un composant. Un attribut écrit sans valeur
 * (`iconRight` au lieu de `iconRight={ArrowRight}`) transmet `true`, ce qui
 * ferait rendre `<true />` à React et blanks toute la page. On ne retient
 * donc qu'un type d'élément réellement utilisable, et on ignore le reste.
 */
const isUsableIcon = (value) => typeof value === 'function' || (typeof value === 'object' && value !== null);

const Button = forwardRef(function Button(
  {
    variant = 'primary',
    size = 'md',
    className = '',
    loading = false,
    disabled = false,
    icon,
    iconRight,
    children,
    ...props
  },
  ref,
) {
  const classes = buttonClasses({ variant, size, className });
  const Icon = icon;
  const IconRight = iconRight;

  if (loading) {
    return (
      <span className={classes} aria-busy="true" aria-live="polite">
        <Loader2 className="size-4 animate-spin" aria-hidden="true" />
        {children}
      </span>
    );
  }

  return (
    <button ref={ref} className={classes} disabled={disabled || loading} {...props}>
      {isUsableIcon(icon) && <Icon className="size-4 shrink-0" aria-hidden="true" />}
      {children}
      {isUsableIcon(iconRight) && <IconRight className="size-4 shrink-0" aria-hidden="true" />}
    </button>
  );
});

/** Même apparence que Button, rendu en <Link> (navigation). */
export function ButtonLink({
  to,
  variant = 'primary',
  size = 'md',
  className = '',
  icon,
  iconRight,
  children,
  ...props
}) {
  const Icon = icon;
  const IconRight = iconRight;

  return (
    <Link to={to} className={buttonClasses({ variant, size, className })} {...props}>
      {isUsableIcon(icon) && <Icon className="size-4 shrink-0" aria-hidden="true" />}
      {children}
      {isUsableIcon(iconRight) && <IconRight className="size-4 shrink-0" aria-hidden="true" />}
    </Link>
  );
}

/** Bouton icône seul — toujours accompagné d'un libellé accessible. */
export const IconButton = forwardRef(function IconButton(
  { label, icon: Icon, variant = 'ghost', size = 'md', className = '', children, ...props },
  ref,
) {
  const dimension = { sm: 'size-8', md: 'size-10', lg: 'size-12' }[size] || 'size-10';
  const variants = {
    ghost: 'text-ink-500 hover:bg-ink-100 hover:text-ink-900',
    secondary: 'bg-white text-ink-700 border border-line-strong shadow-sm hover:bg-ink-50',
    primary: 'bg-primary-700 text-white hover:bg-primary-800',
    danger: 'bg-danger-50 text-danger-600 hover:bg-danger-100',
  };
  return (
    <button
      ref={ref}
      type="button"
      aria-label={label}
      title={label}
      className={`inline-flex ${dimension} items-center justify-center rounded-xl transition-all duration-200 disabled:opacity-50 disabled:pointer-events-none active:scale-95 ${variants[variant] || variants.ghost} ${className}`}
      {...props}
    >
      {Icon ? <Icon className="size-[18px]" aria-hidden="true" /> : null}
      {children}
    </button>
  );
});

export { Button };
export default Button;
