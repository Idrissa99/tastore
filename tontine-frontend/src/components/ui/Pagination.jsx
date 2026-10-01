import { ChevronLeft, ChevronRight } from 'lucide-react';

/**
 * Pagination compatible avec les deux formats de l'API Laravel :
 *  - pagination de ressource : { current_page, last_page } éventuellement
 *    imbriquée dans { meta }
 *  - paginator brut (endpoint /notifications) : pagination au premier niveau
 * Expédit : current, last, total, from, to
 */
export function normalizePagination(payload) {
  if (!payload || typeof payload !== 'object') return null;
  if (Array.isArray(payload)) return null;

  const meta = payload.meta || payload;
  const current = Number(meta.current_page || payload.current_page || 1);
  const last = Number(meta.last_page || payload.last_page || 1);
  const total = Number(meta.total ?? payload.total ?? 0);
  if (!Number.isFinite(current) || !Number.isFinite(last)) return null;
  return { current, last, total, perPage: Number(meta.per_page || payload.per_page || 0) };
}

export function Pagination({ meta, onPageChange, className = '', itemLabel = 'résultats' }) {
  const info = normalizePagination(meta);
  if (!info || info.last <= 1) return null;

  const { current, last, total } = info;
  const pages = pageWindow(current, last);

  return (
    <nav
      aria-label="Pagination"
      className={`flex flex-col-reverse items-center justify-between gap-4 sm:flex-row ${className}`}
    >
      {total > 0 && (
        <p className="text-xs text-ink-500">
          Page <span className="font-semibold text-ink-700">{current}</span> sur{' '}
          <span className="font-semibold text-ink-700">{last}</span> · {total.toLocaleString('fr-FR')} {itemLabel}
        </p>
      )}

      <div className="flex items-center gap-1.5">
        <PageButton
          onClick={() => onPageChange(current - 1)}
          disabled={current <= 1}
          aria-label="Page précédente"
          icon={ChevronLeft}
        >
          <span className="hidden sm:inline">Précédent</span>
        </PageButton>

        <div className="flex items-center gap-1">
          {pages.map((page, index) =>
            page === '…' ? (
              <span key={`gap-${index}`} className="px-1.5 text-sm text-ink-400" aria-hidden="true">
                …
              </span>
            ) : (
              <button
                key={page}
                type="button"
                onClick={() => onPageChange(page)}
                aria-current={page === current ? 'page' : undefined}
                className={`min-w-9 rounded-lg px-2.5 py-2 text-sm font-semibold transition-all ${
                  page === current
                    ? 'bg-primary-700 text-white shadow-sm'
                    : 'text-ink-600 hover:bg-ink-100 hover:text-ink-900'
                }`}
              >
                {page}
              </button>
            ),
          )}
        </div>

        <PageButton
          onClick={() => onPageChange(current + 1)}
          disabled={current >= last}
          aria-label="Page suivante"
          icon={ChevronRight}
          iconAfter
        >
          <span className="hidden sm:inline">Suivant</span>
        </PageButton>
      </div>
    </nav>
  );
}

function PageButton({ children, icon: Icon, iconAfter, ...props }) {
  return (
    <button
      type="button"
      className="inline-flex h-9 items-center gap-1 rounded-lg border border-line-strong bg-white px-2.5 text-sm font-semibold text-ink-700 shadow-sm transition-all hover:bg-ink-50 disabled:pointer-events-none disabled:opacity-40"
      {...props}
    >
      {!iconAfter && Icon ? <Icon className="size-4" aria-hidden="true" /> : null}
      {children}
      {iconAfter && Icon ? <Icon className="size-4" aria-hidden="true" /> : null}
    </button>
  );
}

/** Fenêtre glissante de numéros de page (1 … 4 5 6 … 12). */
function pageWindow(current, last) {
  if (last <= 7) return Array.from({ length: last }, (_, index) => index + 1);
  const pages = [1];
  const start = Math.max(2, current - 1);
  const end = Math.min(last - 1, current + 1);
  if (start > 2) pages.push('…');
  for (let page = start; page <= end; page += 1) pages.push(page);
  if (end < last - 1) pages.push('…');
  pages.push(last);
  return pages;
}

export default Pagination;
