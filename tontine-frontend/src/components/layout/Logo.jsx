import { Link } from 'react-router-dom';

/** Marque de l'application — glyphe geometrical simple, sans ressource externe. */
export default function Logo({ className = '', size = 'md', withText = true, to = '/' }) {
  const dimensions = { sm: 'size-8', md: 'size-9', lg: 'size-11' }[size] || 'size-9';
  const text = { sm: 'text-base', md: 'text-lg', lg: 'text-xl' }[size] || 'text-lg';

  return (
    <Link to={to} className={`group inline-flex items-center gap-2.5 ${className}`} aria-label="Tontine Achat Store — accueil">
      <span
        className={`${dimensions} relative flex shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-primary-600 to-primary-800 shadow-brand transition-transform duration-300 group-hover:scale-105`}
      >
        <svg viewBox="0 0 24 24" className="size-[55%] text-white" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
          <path d="M3 9.5 12 4l9 5.5" />
          <path d="M5 10v7.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V10" />
          <circle cx="12" cy="14.5" r="2.4" />
        </svg>
      </span>

      {withText && (
        <span className={`font-display ${text} font-extrabold leading-none tracking-tight text-ink-900`}>
          Tontine<span className="text-primary-700"> Achat</span>
          <span className="block text-[10px] font-semibold uppercase tracking-[0.18em] text-ink-400">Store</span>
        </span>
      )}
    </Link>
  );
}
