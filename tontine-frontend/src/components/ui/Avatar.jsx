import { initials } from '../../utils/format';

const SIZES = {
  xs: 'size-7 text-[11px]',
  sm: 'size-9 text-xs',
  md: 'size-11 text-sm',
  lg: 'size-14 text-base',
  xl: 'size-20 text-xl',
};

/** Dégradés déterministes : même personne → même couleur d'une page à l'autre. */
const GRADIENTS = [
  'from-primary-600 to-primary-800',
  'from-ink-700 to-ink-900',
  'from-accent-500 to-accent-700',
  'from-primary-800 to-ink-900',
  'from-success-600 to-primary-800',
  'from-ink-800 to-primary-900',
];

function gradientFor(seed) {
  const str = String(seed || '');
  let hash = 0;
  for (let i = 0; i < str.length; i += 1) hash = (hash * 31 + str.charCodeAt(i)) % 100000;
  return GRADIENTS[hash % GRADIENTS.length];
}

/** Avatar : photo réelle si disponible, sinon initiales sur dégradé. */
export function Avatar({ src, name, size = 'md', className = '', ring = false, alt }) {
  const dimension = SIZES[size] || SIZES.md;
  const styles = `inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full font-bold text-white ${dimension} ${
    ring ? 'ring-2 ring-white' : ''
  } ${className}`;

  if (src) {
    return (
      <img
        src={src}
        alt={alt ?? name ?? ''}
        loading="lazy"
        decoding="async"
        className={`${styles} bg-ink-100 object-cover`}
      />
    );
  }

  return (
    <span className={`${styles} bg-gradient-to-br ${gradientFor(name)}`} aria-hidden={alt ? undefined : 'true'} title={name}>
      {initials(name)}
    </span>
  );
}

/** Avatar + nom + rôle : bloc d'identification réutilisé (header, profil…). */
export function AvatarName({ src, name, subtitle, size = 'sm', className = '' }) {
  return (
    <div className={`flex min-w-0 items-center gap-3 ${className}`}>
      <Avatar src={src} name={name} size={size} />
      <div className="min-w-0">
        <p className="truncate text-sm font-semibold text-ink-900">{name}</p>
        {subtitle && <p className="truncate text-xs text-ink-500">{subtitle}</p>}
      </div>
    </div>
  );
}

export default Avatar;
