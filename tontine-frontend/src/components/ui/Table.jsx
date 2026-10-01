import { ArrowUpDown } from 'lucide-react';

/**
 * Tableau de données responsive.
 * Desktop : tableau sémantique (thead/tbody).
 * Mobile  : chaque ligne devient une carte (via <DataTable> `mobileAsCards`).
 */

export function Table({ children, className = '', containerClassName = '' }) {
  return (
    <div className={`w-full overflow-x-auto ${containerClassName}`}>
      <table className={`w-full min-w-[640px] border-collapse text-left text-sm ${className}`}>{children}</table>
    </div>
  );
}

export function THead({ children, className = '' }) {
  return (
    <thead className={`border-b border-line-strong bg-ink-50/80 ${className}`}>
      <tr>{children}</tr>
    </thead>
  );
}

export function TH({ children, align = 'left', sortable = false, sorted, onSort, className = '', ...props }) {
  const alignment = { left: 'text-left', center: 'text-center', right: 'text-right' }[align];
  const content = (
    <span className={`inline-flex items-center gap-1.5 ${alignment === 'text-right' ? 'flex-row-reverse' : ''}`}>
      {children}
      {sortable && (
        <ArrowUpDown className={`size-3.5 ${sorted ? 'text-primary-600' : 'text-ink-300'}`} aria-hidden="true" />
      )}
    </span>
  );

  if (!sortable) {
    return (
      <th scope="col" className={`px-4 py-3 text-[11px] font-bold uppercase tracking-wider text-ink-500 ${alignment} ${className}`} {...props}>
        {content}
      </th>
    );
  }

  return (
    <th
      scope="col"
      aria-sort={sorted === 'asc' ? 'ascending' : sorted === 'desc' ? 'descending' : 'none'}
      className={`p-0 ${className}`}
      {...props}
    >
      <button
        type="button"
        onClick={onSort}
        className="flex w-full items-center gap-1.5 px-4 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-ink-500 transition-colors hover:text-ink-800"
      >
        {content}
      </button>
    </th>
  );
}

export function TBody({ children, className = '' }) {
  return <tbody className={`divide-y divide-line-soft ${className}`}>{children}</tbody>;
}

export function TR({ children, className = '', onClick, ...props }) {
  const interactive = typeof onClick === 'function';
  return (
    <tr
      onClick={onClick}
      className={`transition-colors ${interactive ? 'cursor-pointer hover:bg-ink-50/70' : 'hover:bg-ink-50/40'} ${className}`}
      {...props}
    >
      {children}
    </tr>
  );
}

export function TD({ children, align = 'left', className = '', ...props }) {
  const alignment = { left: 'text-left', center: 'text-center', right: 'text-right' }[align];
  return (
    <td className={`px-4 py-3.5 align-middle text-sm text-ink-700 ${alignment} ${className}`} {...props}>
      {children}
    </td>
  );
}

/**
 * Enveloppe "tableau + états" : loading / erreur / vide.
 * `mobileAsCards` transforme chaque ligne en carte empilée sur petit écran,
 * chaque cellule recevant sa propre étiquette.
 */
export function DataTable({
  columns,
  rows,
  rowKey = (row) => row.id,
  loading = false,
  error = null,
  empty = null,
  onRetry,
  mobileAsCards = true,
  className = '',
  onRowClick,
}) {
  if (loading) {
    return (
      <div className={`rounded-2xl border border-line-strong bg-white p-5 shadow-sm ${className}`}>
        <div className="space-y-4" aria-busy="true">
          {Array.from({ length: 5 }).map((_, index) => (
            <div key={index} className="flex items-center gap-4">
              <div className="skeleton size-9 rounded-xl" />
              <div className="flex-1 space-y-2">
                <div className="skeleton h-3.5 w-1/3 rounded" />
                <div className="skeleton h-3 w-1/4 rounded" />
              </div>
              <div className="skeleton h-6 w-16 rounded-full" />
            </div>
          ))}
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className={`rounded-2xl border border-danger-300 bg-danger-50 p-6 text-center ${className}`} role="alert">
        <p className="text-sm font-semibold text-danger-700">{error.message || 'Erreur de chargement'}</p>
        {onRetry && (
          <button type="button" onClick={onRetry} className="mt-3 text-sm font-semibold text-danger-700 underline underline-offset-4">
            Réessayer
          </button>
        )}
      </div>
    );
  }

  if (!rows?.length) {
    return empty || null;
  }

  return (
    <div className={`overflow-hidden rounded-2xl border border-line-strong bg-white shadow-sm ${className}`}>
      {/* Desktop */}
      <div className={`hidden md:block ${mobileAsCards ? 'md:block' : 'block'}`}>
        <Table>
          <THead>
            {columns.map((column) => (
              <TH key={column.key} align={column.align} sortable={column.sortable} sorted={column.sorted} onSort={column.onSort}>
                {column.header}
              </TH>
            ))}
          </THead>
          <TBody>
            {rows.map((row) => (
              <TR key={rowKey(row)} onClick={onRowClick ? () => onRowClick(row) : undefined}>
                {columns.map((column) => (
                  <TD key={column.key} align={column.align} className={column.cellClassName}>
                    {column.render(row)}
                  </TD>
                ))}
              </TR>
            ))}
          </TBody>
        </Table>
      </div>

      {/* Mobile : cartes */}
      {mobileAsCards && (
        <ul className="divide-y divide-line-soft md:hidden">
          {rows.map((row) => (
            <li key={rowKey(row)}>
              <div
                role={onRowClick ? 'button' : undefined}
                tabIndex={onRowClick ? 0 : undefined}
                onClick={onRowClick ? () => onRowClick(row) : undefined}
                onKeyDown={
                  onRowClick
                    ? (event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                          event.preventDefault();
                          onRowClick(row);
                        }
                      }
                    : undefined
                }
                className={`space-y-3 p-4 ${onRowClick ? 'cursor-pointer transition-colors active:bg-ink-50' : ''}`}
              >
                {columns
                  .filter((column) => !column.hideOnMobile)
                  .map((column) => (
                    <div key={column.key} className="flex items-start justify-between gap-4">
                      {column.mobileLabel !== false && (
                        <span className="shrink-0 text-[11px] font-bold uppercase tracking-wider text-ink-400">
                          {column.header}
                        </span>
                      )}
                      <div
                        className={`min-w-0 flex-1 text-right text-sm ${
                          column.align === 'left' ? '' : ''
                        } ${column.mobileClassName || ''}`}
                      >
                        {column.render(row)}
                      </div>
                    </div>
                  ))}
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export default Table;
