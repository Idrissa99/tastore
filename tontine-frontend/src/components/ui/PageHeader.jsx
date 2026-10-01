import { Link } from 'react-router-dom';
import { ChevronRight } from 'lucide-react';

/** Fil d'Ariane — l'utilisateur sait toujours où il se trouve. */
export function Breadcrumb({ items = [], className = '' }) {
  if (!items.length) return null;

  return (
    <nav aria-label="Fil d'Ariane" className={`min-w-0 ${className}`}>
      <ol className="flex flex-wrap items-center gap-1 text-xs text-ink-500">
        {items.map((item, index) => {
          const isLast = index === items.length - 1;
          return (
            <li key={`${item.label}-${index}`} className="flex min-w-0 items-center gap-1">
              {index > 0 && <ChevronRight className="size-3.5 shrink-0 text-ink-300" aria-hidden="true" />}
              {item.to && !isLast ? (
                <Link to={item.to} className="truncate font-medium transition-colors hover:text-primary-700">
                  {item.label}
                </Link>
              ) : (
                <span className={`truncate ${isLast ? 'font-semibold text-ink-700' : ''}`} aria-current={isLast ? 'page' : undefined}>
                  {item.label}
                </span>
              )}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}

/**
 * En-tête de page : fil d'Ariane, titre, sous-titre, actions.
 * `children` sous le titre sert aux contenus contextuels (filtres, stats).
 */
export function PageHeader({ title, subtitle, breadcrumb, actions, children, className = '', compact = false }) {
  return (
    <header className={`${compact ? 'mb-5' : 'mb-6 sm:mb-8'} ${className}`}>
      {breadcrumb && <Breadcrumb items={breadcrumb} className="mb-2.5" />}

      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <h1 className="font-display text-2xl font-extrabold tracking-tight text-ink-900 sm:text-3xl">{title}</h1>
          {subtitle && <p className="mt-1.5 max-w-2xl text-sm leading-relaxed text-ink-500 sm:text-[15px]">{subtitle}</p>}
        </div>
        {actions && <div className="flex shrink-0 flex-wrap items-center gap-2.5">{actions}</div>}
      </div>

      {children && <div className="mt-5">{children}</div>}
    </header>
  );
}

/** En-tête de section à l'intérieur d'une page. */
export function SectionHeader({ title, subtitle, actions, className = '', as: Tag = 'h2' }) {
  return (
    <div className={`flex flex-wrap items-end justify-between gap-3 ${className}`}>
      <div className="min-w-0">
        <Tag className="font-display text-base font-bold text-ink-900 sm:text-lg">{title}</Tag>
        {subtitle && <p className="mt-1 text-sm leading-relaxed text-ink-500">{subtitle}</p>}
      </div>
      {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
    </div>
  );
}

export default PageHeader;
