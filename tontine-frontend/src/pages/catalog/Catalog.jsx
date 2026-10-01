import { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Package, Search } from 'lucide-react';
import { api } from '../../api/client';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { PageHeader } from '../../components/ui/PageHeader';
import { SearchInput, Select } from '../../components/ui/Input';
import { ButtonLink } from '../../components/ui/Button';
import { ProductImage, productImage } from '../../components/ui/ProductImage';
import { Badge } from '../../components/ui/Badge';
import { Pagination, normalizePagination } from '../../components/ui/Pagination';
import { EmptyState, ErrorState } from '../../components/ui/EmptyState';
import { SkeletonCard } from '../../components/ui/Skeleton';
import { formatFcfa, formatNumber } from '../../utils/format';

/**
 * Catalogue produits — GET /produits?q=&category=&page=
 * L'API ne retourne que les produits `published`.
 */
export default function Catalog() {
  useDocumentTitle('Catalogue');
  const [searchParams, setSearchParams] = useSearchParams();

  const [data, setData] = useState(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);

  const query = searchParams.get('q') || '';
  const category = searchParams.get('category') || '';
  const page = Number(searchParams.get('page') || 1);

  const [searchInput, setSearchInput] = useState(query);

  useEffect(() => {
    let cancelled = false;
    setIsLoading(true);
    setError(null);

    api
      .get('/produits', { q: query || undefined, category: category || undefined, page })
      .then((payload) => !cancelled && setData(payload))
      .catch((err) => !cancelled && setError(err))
      .finally(() => !cancelled && setIsLoading(false));

    return () => {
      cancelled = true;
    };
  }, [query, category, page]);

  // Garde l'URL et le champ de recherche synchronisés.
  useEffect(() => {
    setSearchInput(query);
  }, [query]);

  const products = data?.data || [];
  const pagination = normalizePagination(data);

  // Les catégories sont dérivées du lot courant : l'API ne fournit pas
  // d'endpoint de catégories, on ne propose donc que ce qu'on a réellement vu.
  const categories = useMemo(() => {
    const set = new Set();
    (data?.data || []).forEach((product) => product.category && set.add(product.category));
    return [...set].sort((a, b) => a.localeCompare(b, 'fr'));
  }, [data]);

  const updateParams = (patch) => {
    const next = new URLSearchParams(searchParams);
    Object.entries(patch).forEach(([key, value]) => {
      if (value) next.set(key, value);
      else next.delete(key);
    });
    if (!('page' in patch)) next.delete('page');
    setSearchParams(next);
  };

  const handleSearch = (event) => {
    event.preventDefault();
    updateParams({ q: searchInput.trim() });
  };

  return (
    <div className="mx-auto w-full max-w-7xl px-5 py-10 sm:px-8 sm:py-14">
      <PageHeader
        title="Catalogue produits"
        subtitle="Tous les produits publiés par nos commerçants, achetables en une fois ou par tontine."
        breadcrumb={[{ label: 'Produits' }]}
      />

      {/* Recherche */}
      <div className="mb-6 flex flex-col gap-3 sm:flex-row">
        <form onSubmit={handleSearch} className="flex flex-1 gap-2">
          <SearchInput
            value={searchInput}
            onChange={setSearchInput}
            onClear={() => updateParams({ q: '' })}
            placeholder="Rechercher un produit…"
            aria-label="Rechercher un produit"
          />
          <ButtonLink to={searchInput ? `/produits?q=${encodeURIComponent(searchInput)}` : '/produits'} variant="secondary" icon={Search}>
            Rechercher
          </ButtonLink>
        </form>

        {categories.length > 1 && (
          <Select
            value={category}
            onChange={(event) => updateParams({ category: event.target.value })}
            className="sm:max-w-[220px]"
            aria-label="Filtrer par catégorie"
          >
            <option value="">Toutes les catégories</option>
            {categories.map((item) => (
              <option key={item} value={item}>
                {item}
              </option>
            ))}
          </Select>
        )}
      </div>

      <p className="mb-5 text-sm text-ink-500" role="status" aria-live="polite">
        {isLoading
          ? 'Chargement du catalogue…'
          : pagination?.total
            ? `${formatNumber(pagination.total)} produit${pagination.total > 1 ? 's' : ''}`
            : `${products.length} produit${products.length > 1 ? 's' : ''}`}
      </p>

      {error ? (
        <ErrorState message={error.message} onRetry={() => updateParams({})} />
      ) : isLoading ? (
        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {Array.from({ length: 8 }).map((_, index) => (
            <SkeletonCard key={index} />
          ))}
        </div>
      ) : products.length === 0 ? (
        <EmptyState
          icon={Package}
          title="Aucun produit trouvé"
          description={
            query || category
              ? 'Aucun produit ne correspond à ta recherche. Essaie un autre terme ou retire le filtre.'
              : 'Le catalogue est vide pour le moment. Reviens bientôt.'
          }
          action={
            query || category ? (
              <button
                type="button"
                onClick={() => setSearchParams({})}
                className="text-sm font-semibold text-primary-700 underline underline-offset-4"
              >
                Réinitialiser la recherche
              </button>
            ) : null
          }
        />
      ) : (
        <>
          <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            {products.map((product) => (
              <li key={product.id}>
                <ProductCard product={product} />
              </li>
            ))}
          </ul>

          <Pagination
            meta={pagination}
            onPageChange={(nextPage) => updateParams({ page: nextPage === 1 ? '' : String(nextPage) })}
            className="mt-10"
            itemLabel="produits"
          />
        </>
      )}
    </div>
  );
}

export function ProductCard({ product }) {
  const isLowStock = Number(product.stock) > 0 && Number(product.stock) <= 3;

  return (
    <Link
      to={`/produits/${product.id}`}
      className="group flex h-full flex-col overflow-hidden rounded-2xl border border-line-strong bg-white shadow-sm transition-all duration-300 hover:-translate-y-1 hover:border-primary-300 hover:shadow-lg"
    >
      <div className="relative">
        <ProductImage
          src={productImage(product)}
          alt={product.name}
          ratio="4/3"
          rounded="rounded-none"
          imgClassName="group-hover:scale-105 transition-transform duration-500"
        />
        {isLowStock && (
          <span className="absolute left-3 top-3">
            <Badge tone="warning" size="sm">
              Plus que {product.stock}
            </Badge>
          </span>
        )}
        {Number(product.stock) === 0 && (
          <span className="absolute inset-0 flex items-center justify-center bg-ink-950/50">
            <Badge tone="neutral">Rupture de stock</Badge>
          </span>
        )}
      </div>

      <div className="flex flex-1 flex-col p-4">
        {product.category && (
          <p className="text-[11px] font-semibold uppercase tracking-wide text-primary-700">{product.category}</p>
        )}
        <h3 className="mt-1 line-clamp-2 font-display text-sm font-bold text-ink-900 transition-colors group-hover:text-primary-800">
          {product.name}
        </h3>
        <p className="amount mt-2 text-lg text-primary-800">{formatFcfa(product.price)}</p>
        <p className="mt-auto pt-3 text-[11px] text-ink-400">Voir le produit et les options de financement</p>
      </div>
    </Link>
  );
}
