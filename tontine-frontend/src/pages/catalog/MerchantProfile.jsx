import { Link, useParams } from 'react-router-dom';
import { MapPin, MessageSquare, Package, Star, Store } from 'lucide-react';
import { api } from '../../api/client';
import { useApi } from '../../hooks/useApi';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { Breadcrumb } from '../../components/ui/PageHeader';
import { Card } from '../../components/ui/Card';
import { Badge } from '../../components/ui/Badge';
import { Avatar } from '../../components/ui/Avatar';
import { ProductImage, productImage } from '../../components/ui/ProductImage';
import { Skeleton } from '../../components/ui/Skeleton';
import { EmptyState, ErrorState } from '../../components/ui/EmptyState';
import { formatFcfa, formatNumber } from '../../utils/format';

/** Fiche commerçant — GET /commercants/{id}. */
export default function MerchantProfile() {
  const { id } = useParams();
  const { data, isLoading, error, reload } = useApi(() => api.get(`/commercants/${id}`), [id]);
  const merchant = data?.data;

  useDocumentTitle(merchant?.business_name || 'Commerçant');

  if (isLoading) return <ProfileSkeleton />;

  if (error) {
    return (
      <div className="mx-auto w-full max-w-3xl px-5 py-10 sm:px-8">
        <ErrorState title="Commerçant introuvable" message={error.message} onRetry={reload} />
        <div className="mt-5 text-center">
          <Link to="/produits" className="text-sm font-semibold text-primary-700 hover:underline">
            ← Retour au catalogue
          </Link>
        </div>
      </div>
    );
  }

  if (!merchant) return null;

  const products = merchant.products || [];
  const reviews = merchant.reviews || [];
  const rating = Number(merchant.rating || 0);

  return (
    <div className="mx-auto w-full max-w-7xl px-5 py-8 sm:px-8 sm:py-10">
      <Breadcrumb items={[{ label: 'Produits', to: '/produits' }, { label: merchant.business_name }]} className="mb-6" />

      {/* En-tête */}
      <Card padding="lg" className="mb-8">
        <div className="flex flex-col gap-5 sm:flex-row sm:items-center">
          <span className="flex size-16 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-primary-600 to-primary-800 text-white">
            <Store className="size-8" aria-hidden="true" />
          </span>

          <div className="min-w-0 flex-1">
            <h1 className="font-display text-2xl font-extrabold tracking-tight text-ink-900">{merchant.business_name}</h1>
            <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-ink-500">
              {merchant.city && (
                <span className="inline-flex items-center gap-1.5">
                  <MapPin className="size-4 text-ink-400" aria-hidden="true" />
                  {merchant.city}
                </span>
              )}
              <span className="inline-flex items-center gap-1.5">
                <Package className="size-4 text-ink-400" aria-hidden="true" />
                {formatNumber(products.length)} produit{products.length > 1 ? 's' : ''}
              </span>
            </div>

            {rating > 0 && (
              <div className="mt-3 inline-flex items-center gap-2 rounded-xl bg-accent-50 px-3 py-1.5">
                <StarStars rating={rating} />
                <span className="amount text-sm text-accent-700">{rating.toFixed(1)} / 5</span>
                <span className="text-xs text-ink-500">
                  ({formatNumber(merchant.reviews_count ?? reviews.length)} avis)
                </span>
              </div>
            )}
          </div>
        </div>

        {merchant.description && (
          <p className="mt-5 border-t border-line-soft pt-5 text-sm leading-relaxed text-ink-600">{merchant.description}</p>
        )}

        {merchant.address && (
          <p className="mt-3 flex items-center gap-1.5 text-xs text-ink-500">
            <MapPin className="size-3.5" aria-hidden="true" />
            {merchant.address}
          </p>
        )}
      </Card>

      {/* Produits */}
      <section>
        <h2 className="font-display text-lg font-bold text-ink-900">Produits en vente</h2>

        {products.length === 0 ? (
          <EmptyState icon={Package} title="Aucun produit publié" description="Ce commerçant n’a pas encore de produit disponible." className="mt-4" />
        ) : (
          <ul className="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            {products.map((product) => (
              <li key={product.id}>
                <Link
                  to={`/produits/${product.id}`}
                  className="group flex h-full flex-col overflow-hidden rounded-2xl border border-line-strong bg-white shadow-sm transition-all duration-300 hover:-translate-y-1 hover:border-primary-300 hover:shadow-lg"
                >
                  <ProductImage
                    src={productImage(product)}
                    alt={product.name}
                    ratio="4/3"
                    rounded="rounded-none"
                    imgClassName="group-hover:scale-105 transition-transform duration-500"
                  />
                  <div className="flex flex-1 flex-col p-4">
                    {product.category && (
                      <p className="text-[11px] font-semibold uppercase tracking-wide text-primary-700">{product.category}</p>
                    )}
                    <h3 className="mt-1 line-clamp-2 font-display text-sm font-bold text-ink-900">{product.name}</h3>
                    <p className="amount mt-2 text-lg text-primary-800">{formatFcfa(product.price)}</p>
                  </div>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </section>

      {/* Avis */}
      <section className="mt-10">
        <h2 className="font-display text-lg font-bold text-ink-900">Avis des clients</h2>

        {reviews.length === 0 ? (
          <EmptyState
            icon={MessageSquare}
            title="Aucun avis pour le moment"
            description="Les avis sont laissés par les membres à la fin d’une tontine terminée."
            className="mt-4"
            compact
          />
        ) : (
          <ul className="mt-4 grid gap-4 md:grid-cols-2">
            {reviews.map((review) => (
              <li key={review.id}>
                <Card padding="sm">
                  <div className="flex items-start gap-3">
                    <Avatar name={review.user_name} size="sm" />
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <p className="truncate text-sm font-semibold text-ink-900">{review.user_name}</p>
                        <Badge tone={review.rating >= 4 ? 'success' : review.rating >= 3 ? 'warning' : 'danger'} size="xs">
                          {review.rating} / 5
                        </Badge>
                      </div>
                      {review.comment && (
                        <p className="mt-1.5 text-sm leading-relaxed text-ink-600">{review.comment}</p>
                      )}
                      {review.created_at && (
                        <p className="mt-2 text-[11px] text-ink-400">
                          {new Date(review.created_at).toLocaleDateString('fr-FR')}
                        </p>
                      )}
                    </div>
                  </div>
                </Card>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}

function StarStars({ rating }) {
  return (
    <span className="inline-flex gap-0.5" aria-hidden="true">
      {[1, 2, 3, 4, 5].map((index) => (
        <Star
          key={index}
          className={`size-3.5 ${index <= Math.round(rating) ? 'fill-accent-500 text-accent-500' : 'text-accent-200'}`}
        />
      ))}
    </span>
  );
}

function ProfileSkeleton() {
  return (
    <div className="mx-auto w-full max-w-7xl px-5 py-10 sm:px-8" aria-busy="true">
      <Skeleton className="h-32 w-full rounded-2xl" />
      <div className="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        {[0, 1, 2, 3].map((index) => (
          <Skeleton key={index} className="h-64 rounded-2xl" />
        ))}
      </div>
    </div>
  );
}
