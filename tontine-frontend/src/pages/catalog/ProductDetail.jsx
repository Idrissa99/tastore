import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import {
  ArrowRight,
  Building2,
  Layers,
  Package,
  Play,
  ShoppingBag,
} from 'lucide-react';
import { api } from '../../api/client';
import { useAuth } from '../../hooks/useAuth';
import { useApi } from '../../hooks/useApi';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { Breadcrumb } from '../../components/ui/PageHeader';
import { Card } from '../../components/ui/Card';
import { ButtonLink } from '../../components/ui/Button';
import { Alert } from '../../components/ui/Alert';
import { Badge } from '../../components/ui/Badge';
import { ProductImage, mediaUrl } from '../../components/ui/ProductImage';
import { Skeleton } from '../../components/ui/Skeleton';
import { ErrorState } from '../../components/ui/EmptyState';
import { formatDate, formatFcfa, formatNumber } from '../../utils/format';

/**
 * Fiche produit — GET /produits/{id} (produits `published` uniquement).
 *
 * Deux façons de l'acheter, réellement proposées par l'API :
 *  - par tontine   : POST /tontines (réservé au commerçant propriétaire/admin)
 *  - par tranches  : POST /produits/{id}/tranches (tout utilisateur connecté)
 */
export default function ProductDetail() {
  const { id } = useParams();
  const { isAuthenticated, isAdmin, isMerchant, user } = useAuth();

  const { data, isLoading, error, reload } = useApi(() => api.get(`/produits/${id}`), [id]);
  const product = data?.data;

  const [activeImage, setActiveImage] = useState(0);

  useDocumentTitle(product?.name || 'Produit');

  if (isLoading) return <ProductSkeleton />;

  if (error) {
    return (
      <div className="mx-auto w-full max-w-3xl px-5 py-10 sm:px-8">
        <ErrorState
          title={error.status === 404 ? 'Produit introuvable' : 'Impossible de charger ce produit'}
          message={error.message}
          onRetry={reload}
        />
        <div className="mt-5 text-center">
          <Link to="/produits" className="text-sm font-semibold text-primary-700 hover:underline">
            ← Retour au catalogue
          </Link>
        </div>
      </div>
    );
  }

  if (!product) return null;

  const images = product.images || [];
  // Un commerçant peut créer une tontine sur son propre produit ; l'API
  // applique de toute façon `can-create-tontine` côté serveur.
  const canCreateTontine = isAdmin || (isMerchant && user?.merchant?.id === product.merchant?.id);
  const isOutOfStock = Number(product.stock) === 0;

  return (
    <div className="mx-auto w-full max-w-7xl px-5 py-8 sm:px-8 sm:py-10">
      <Breadcrumb items={[{ label: 'Produits', to: '/produits' }, { label: product.name }]} className="mb-6" />

      <div className="grid gap-8 lg:grid-cols-[1.05fr_1fr] lg:items-start">
        {/* Médias */}
        <div>
          <div className="overflow-hidden rounded-2xl border border-line-strong bg-white shadow-sm">
            {images.length > 0 ? (
              <img
                src={mediaUrl(images[activeImage]?.url)}
                alt={product.name}
                className="aspect-[4/3] w-full object-cover"
              />
            ) : (
              <ProductImage src={null} alt={product.name} ratio="4/3" rounded="rounded-none" iconSize="size-10" />
            )}
          </div>

          {images.length > 1 && (
            <ul className="mt-3 flex flex-wrap gap-2.5">
              {images.map((image, index) => (
                <li key={image.id}>
                  <button
                    type="button"
                    onClick={() => setActiveImage(index)}
                    aria-label={`Voir l’image ${index + 1}`}
                    aria-current={index === activeImage}
                    className={`overflow-hidden rounded-xl border-2 transition-all ${
                      index === activeImage ? 'border-primary-600 ring-2 ring-primary-600/20' : 'border-line-strong hover:border-ink-400'
                    }`}
                  >
                    <img src={mediaUrl(image.url)} alt="" loading="lazy" className="size-16 object-cover" />
                  </button>
                </li>
              ))}
            </ul>
          )}

          {product.video && (
            <div className="mt-4">
              <p className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-ink-500">
                <Play className="size-3.5" aria-hidden="true" />
                Vidéo du produit
              </p>
              <video src={mediaUrl(product.video.url)} controls preload="metadata" className="w-full rounded-xl border border-line-strong" />
            </div>
          )}

          {product.description && (
            <Card className="mt-5">
              <h2 className="font-display text-base font-bold text-ink-900">Description</h2>
              <p className="mt-2.5 whitespace-pre-line text-sm leading-relaxed text-ink-600">{product.description}</p>
            </Card>
          )}
        </div>

        {/* Informations */}
        <div className="min-w-0 lg:sticky lg:top-24">
          {product.category && (
            <Badge tone="brand" size="sm" className="mb-3">
              {product.category}
            </Badge>
          )}

          <h1 className="font-display text-2xl font-extrabold tracking-tight text-ink-900 sm:text-3xl">
            {product.name}
          </h1>

          <p className="amount mt-4 text-3xl text-primary-800">{formatFcfa(product.price)}</p>

          <div className="mt-4 flex flex-wrap items-center gap-3">
            {isOutOfStock ? (
              <Badge tone="danger" dot>
                Rupture de stock
              </Badge>
            ) : (
              <Badge tone={Number(product.stock) <= 3 ? 'warning' : 'success'} dot>
                {product.stock} en stock
              </Badge>
            )}
          </div>

          {product.merchant && (
            <Link
              to={`/commercants/${product.merchant.id}`}
              className="mt-5 flex items-center gap-3 rounded-xl border border-line-strong bg-white p-3.5 transition-all hover:border-primary-300 hover:shadow-sm"
            >
              <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-700">
                <Building2 className="size-4.5" aria-hidden="true" />
              </span>
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold text-ink-900">{product.merchant.business_name}</p>
                <p className="truncate text-xs text-ink-500">
                  {product.merchant.city || 'Ville non renseignée'}
                  {product.merchant.rating ? ` · ${product.merchant.rating} / 5` : ''}
                </p>
              </div>
              <ArrowRight className="size-4 shrink-0 text-ink-300" aria-hidden="true" />
            </Link>
          )}

          {/* Options d'achat */}
          <Card className="mt-5">
            <h2 className="font-display text-base font-bold text-ink-900">Comment l’acheter ?</h2>

            {isAuthenticated ? (
              <div className="mt-4 space-y-3">
                <div className="flex items-start gap-3 rounded-xl border border-line-strong p-3.5">
                  <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-700">
                    <Layers className="size-4.5" aria-hidden="true" />
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-semibold text-ink-900">Payer en plusieurs fois</p>
                    <p className="mt-0.5 text-xs leading-relaxed text-ink-500">
                      Choisis le nombre de tranches : chaque tranche est un versement à régler, avec ou sans
                      vérification manuelle.
                    </p>
                  </div>
                </div>

                <ButtonLink
                  to={`/produits/${product.id}/tranches`}
                  className="w-full"
                  size="lg"
                  icon={Layers}
                  disabled={isOutOfStock}
                >
                  Acheter en plusieurs fois
                </ButtonLink>

                {canCreateTontine && (
                  <>
                    <div className="flex items-start gap-3 rounded-xl border border-line-strong p-3.5">
                      <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-accent-50 text-accent-700">
                        <ShoppingBag className="size-4.5" aria-hidden="true" />
                      </span>
                      <div className="min-w-0 flex-1">
                        <p className="text-sm font-semibold text-ink-900">Financer par une tontine</p>
                        <p className="mt-0.5 text-xs leading-relaxed text-ink-500">
                          Lance une tontine produit sur ce produit : chaque membre cotise à son tour jusqu’à
                          financer le montant total.
                        </p>
                      </div>
                    </div>

                    <ButtonLink
                      to={`/tontines/nouvelle?product_id=${product.id}`}
                      variant="secondary"
                      className="w-full"
                      size="lg"
                      icon={ShoppingBag}
                      disabled={isOutOfStock}
                    >
                      Créer une tontine pour ce produit
                    </ButtonLink>
                  </>
                )}

                {!canCreateTontine && !isAdmin && (
                  <p className="text-[11px] leading-relaxed text-ink-400">
                    La création d’une tontine est réservée au commerçant du produit et aux administrateurs. En
                    attendant, cherche ce produit dans les tontines ouvertes ou achète-le par tranches.
                  </p>
                )}
              </div>
            ) : (
              <div className="mt-4 space-y-3">
                <p className="text-sm leading-relaxed text-ink-600">
                  Connecte-toi pour acheter ce produit par tranches ou rejoindre une tontine qui le finance.
                </p>
                <ButtonLink to="/connexion" state={{ from: { pathname: `/produits/${product.id}` } }} className="w-full" size="lg">
                  Se connecter
                </ButtonLink>
                <ButtonLink to="/inscription" variant="secondary" className="w-full" size="lg">
                  Créer un compte
                </ButtonLink>
              </div>
            )}
          </Card>

          {isOutOfStock && (
            <Alert type="warning" title="Produit indisponible" className="mt-4">
              Le stock est à zéro : le paiement par tranches est bloqué côté API. Les tontines déjà ouvertes sur
              ce produit restent visibles.
            </Alert>
          )}

          <p className="mt-4 flex items-center gap-1.5 text-[11px] text-ink-400">
            <Package className="size-3.5" aria-hidden="true" />
            {product.created_at ? `Publié le ${formatDate(product.created_at)}` : 'Produit du catalogue'} ·{' '}
            {formatNumber(product.stock)} unité{Number(product.stock) > 1 ? 's' : ''} disponible
            {Number(product.stock) > 1 ? 's' : ''}
          </p>
        </div>
      </div>
    </div>
  );
}

function ProductSkeleton() {
  return (
    <div className="mx-auto w-full max-w-7xl px-5 py-10 sm:px-8" aria-busy="true">
      <div className="grid gap-8 lg:grid-cols-2">
        <div>
          <Skeleton className="aspect-[4/3] w-full rounded-2xl" />
          <div className="mt-3 flex gap-2">
            {[0, 1, 2].map((index) => (
              <Skeleton key={index} className="size-16 rounded-xl" />
            ))}
          </div>
        </div>
        <div className="space-y-4">
          <Skeleton className="h-4 w-24" />
          <Skeleton className="h-9 w-3/4" />
          <Skeleton className="h-9 w-40" />
          <Skeleton className="h-16 w-full rounded-xl" />
          <Skeleton className="h-64 w-full rounded-2xl" />
        </div>
      </div>
    </div>
  );
}
