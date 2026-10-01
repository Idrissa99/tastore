import { forwardRef } from 'react';

const TONES = {
  default: 'bg-white border border-line-strong shadow-sm',
  flat: 'bg-white border border-line-strong',
  muted: 'bg-ink-50 border border-line',
  brand: 'bg-primary-50/60 border border-primary-200',
  glass: 'bg-white/70 backdrop-blur-md border border-white/70 shadow-sm',
  dark: 'bg-ink-900 border border-ink-900 text-white',
};

const PADDING = {
  none: '',
  sm: 'p-3.5',
  md: 'p-5',
  lg: 'p-6 sm:p-7',
};

/*
 * Surface de base. `variant` porte la couleur, `padding` la densité interne.
 * La résolution utilise `??` et non `||` : `padding="none"` correspond à la
 * chaîne vide, qui serait sinon interprétée comme une valeur manquante.
 */
const Card = forwardRef(function Card(
  { as: Tag = 'div', variant = 'default', padding = 'md', className = '', hover = false, children, ...props },
  ref,
) {
  return (
    <Tag
      ref={ref}
      className={`rounded-2xl ${TONES[variant] ?? TONES.default} ${PADDING[padding] ?? PADDING.md} ${
        hover ? 'transition-all duration-300 hover:-translate-y-0.5 hover:shadow-lg' : ''
      } ${className}`}
      {...props}
    >
      {children}
    </Tag>
  );
});

export { Card };
export default Card;
