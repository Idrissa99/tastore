import { useMemo } from 'react';
import {
  Boxes,
  CheckCircle2,
  Coins,
  Gift,
  Package,
  Plus,
  Store,
  TrendingUp,
  Truck,
  Wallet,
} from 'lucide-react';
import { api } from '../../api/client';
import { useAuth } from '../../hooks/useAuth';
import { useApi } from '../../hooks/useApi';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { PageHeader } from '../../components/ui/PageHeader';
import { StatCard } from '../../components/ui/StatCard';
import { Card } from '../../components/ui/Card';
import { ButtonLink } from '../../components/ui/Button';
import { StatusBadge } from '../../components/ui/Badge';
import { ProductImage, productImage } from '../../components/ui/ProductImage';
import { EmptyState, ErrorState } from '../../components/ui/EmptyState';
import { SkeletonStats } from '../../components/ui/Skeleton';
import { formatFcfa, formatNumber } from '../../utils/format';

/**
 * Tableau de bord commerçant — GET /merchant/dashboard.
 *
 * Chiffre d'affaires = cotisations de tontines + tranches d'achats related
 * aux produits du commerçant. Deux files de livraison coexistent :
 * les livraisons de tontine (bénéficiaire d'un round) et les achats par
 * tranches. Les deux sont présentées.
 */
export default function MerchantDashboard() {
  useDocumentTitle('Dashboard commerçant');
  const { user } = useAuth();
  const { data, isLoading, error, reload } = useApi(() => api.get('/merchant/dashboard'), []);

  const dashboard = data;

  const stats = useMemo(() => {
    const products = dashboard?.products || [];
    const tontineQueue = [
      ...(dashboard?.pending_deliveries_tontine || []),
      ...(dashboard?.waiting_for_payment_tontine || []),
    ];
    const uniqueTontine = new Map();
    tontineQueue.forEach((item) => uniqueTontine.set(item.id, item));

    return {
      revenue: dashboard?.revenue?.total ?? 0,
      revenueContributions: dashboard?.revenue?.contributions ?? 0,
      revenueInstallments: dashboard?.revenue?.installments ?? 0,
      products: products.length,
      published: products.filter((product) => product.status === 'published').length,
      outOfStock: products.filter((product) => Number(product.stock) === 0).length,
      deliveriesTontine: uniqueTontine.size,
      deliveriesInstallment: (dashboard?.pending_deliveries_installment || []).filter(
        (item) => item.delivery_status === 'pending',
      ).length,
    };
  }, [dashboard]);

  const pendingTontine = (dashboard?.pending_deliveries_tontine || []).filter((item) => item.is_eligible);
  const waitingTontine = dashboard?.waiting_for_payment_tontine || [];
  const pendingInstallments = (dashboard?.pending_deliveries_installment || []).filter(
    (item) => item.delivery_status === 'pending',
  );

  return (
    <div>
      <PageHeader
        title={user?.merchant?.business_name || 'Mon espace commerçant'}
        subtitle="Ventes, catalogue et livraisons à confirmer depuis un seul écran."
        breadcrumb={[{ label: 'Espace commerçant' }]}
        actions={
          <>
            <ButtonLink to="/tontines/nouvelle" variant="secondary" icon={Gift}>
              Créer une tontine
            </ButtonLink>
            <ButtonLink to="/commercant/produits" icon={Plus}>
              Ajouter un produit
            </ButtonLink>
          </>
        }
      />

      {error ? (
        <ErrorState message={error.message} onRetry={reload} />
      ) : (
        <>
          {isLoading ? (
            <SkeletonStats count={4} className="mb-8" />
          ) : (
            <div className="mb-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
              <StatCard
                label="Chiffre d'affaires"
                value={formatFcfa(stats.revenue)}
                sublabel={`${formatFcfa(stats.revenueContributions)} tontines · ${formatFcfa(stats.revenueInstallments)} tranches`}
                icon={TrendingUp}
                tone="brand"
              />
              <StatCard label="Commandes" value={formatNumber(stats.deliveriesTontine + stats.deliveriesInstallments)} icon={Package} />
              <StatCard label="Produits" value={formatNumber(stats.products)} sublabel={`${stats.published} en ligne`} icon={Boxes} />
              <StatCard
                label="Livraisons en attente"
                value={formatNumber(stats.deliveriesTontine + stats.deliveriesInstallments)}
                sublabel={
                  stats.outOfStock ? `${stats.outOfStock} produit(s) en rupture de stock` : 'Aucune rupture de stock'
                }
                icon={Truck}
                tone={stats.deliveriesTontine + stats.deliveriesInstallments ? 'warning' : 'success'}
                to="/commercant/livraisons"
              />
            </div>
          )}

          <div className="grid gap-6 lg:grid-cols-2">
            {/* Livraisons prêtes */}
            <Card padding="none">
              <div className="flex items-center justify-between gap-3 border-b border-line-soft p-5">
                <div>
                  <h2 className="font-display text-base font-bold text-ink-900">Livraisons à confirmer</h2>
                  <p className="mt-0.5 text-xs text-ink-500">Bénéficiaires dont le round est intégralement payé</p>
                </div>
                <ButtonLink to="/commercant/livraisons" variant="ghost" size="sm">
                  Tout voir
                </ButtonLink>
              </div>

              {isLoading ? (
                <div className="space-y-3 p-5" aria-busy="true">
                  {[0, 1].map((index) => (
                    <div key={index} className="skeleton h-14 rounded-xl" />
                  ))}
                </div>
              ) : pendingTontine.length === 0 && pendingInstallments.length === 0 ? (
                <div className="p-5">
                  <EmptyState
                    icon={CheckCircle2}
                    title="Aucune livraison en attente"
                    description="Tout est à jour. Les nouveaux bénéficiaires apparaîtront ici automatiquement."
                    compact
                  />
                </div>
              ) : (
                <ul className="divide-y divide-line-soft">
                  {pendingTontine.slice(0, 4).map((item) => (
                    <li key={`t-${item.id}`} className="flex items-center gap-3 p-4">
                      <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-accent-50 text-accent-700">
                        <Gift className="size-4" aria-hidden="true" />
                      </span>
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold text-ink-900">{item.user_name}</p>
                        <p className="truncate text-xs text-ink-500">
                          {item.tontine?.product?.name || 'Produit indisponible'} · tour {item.beneficiary_round}
                        </p>
                      </div>
                      <StatusBadge status="pending" label="À livrer" size="xs" />
                    </li>
                  ))}
                  {pendingInstallments.slice(0, 3).map((item) => (
                    <li key={`i-${item.id}`} className="flex items-center gap-3 p-4">
                      <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-700">
                        <Package className="size-4" aria-hidden="true" />
                      </span>
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold text-ink-900">{item.user?.name}</p>
                        <p className="truncate text-xs text-ink-500">
                          {item.product?.name} · {item.installments_count} tranches
                        </p>
                      </div>
                      <StatusBadge status="pending" label="À livrer" size="xs" />
                    </li>
                  ))}
                </ul>
              )}
            </Card>

            {/* En attente du round */}
            <Card padding="none">
              <div className="border-b border-line-soft p-5">
                <h2 className="font-display text-base font-bold text-ink-900">En attente du round</h2>
                <p className="mt-0.5 text-xs text-ink-500">
                  Bénéficiaires désignés dont les membres n’ont pas tous cotisé
                </p>
              </div>

              {isLoading ? (
                <div className="space-y-3 p-5" aria-busy="true">
                  {[0, 1].map((index) => (
                    <div key={index} className="skeleton h-14 rounded-xl" />
                  ))}
                </div>
              ) : waitingTontine.length === 0 ? (
                <div className="p-5">
                  <EmptyState icon={Wallet} title="Rien en attente" description="Aucun bénéficiaire bloqué pour l’instant." compact />
                </div>
              ) : (
                <ul className="divide-y divide-line-soft">
                  {waitingTontine.slice(0, 5).map((item) => (
                    <li key={item.id} className="flex items-center gap-3 p-4">
                      <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-ink-100 text-ink-500">
                        <Coins className="size-4" aria-hidden="true" />
                      </span>
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold text-ink-900">{item.user_name}</p>
                        <p className="truncate text-xs text-ink-500">
                          {item.tontine?.product?.name || 'Produit indisponible'} · tour {item.beneficiary_round}
                        </p>
                      </div>
                      <StatusBadge status="awaiting_payment" size="xs" />
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          </div>

          {/* Catalogue */}
          <section className="mt-8">
            <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
              <div>
                <h2 className="font-display text-lg font-bold text-ink-900">Mon catalogue</h2>
                <p className="mt-0.5 text-sm text-ink-500">Stock et visibilité de tes produits</p>
              </div>
              <ButtonLink to="/commercant/produits" variant="secondary" size="sm" icon={Store}>
                Gérer les produits
              </ButtonLink>
            </div>

            {isLoading ? (
              <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-busy="true">
                {Array.from({ length: 4 }).map((_, index) => (
                  <div key={index} className="skeleton h-52 rounded-2xl" />
                ))}
              </div>
            ) : (dashboard?.products || []).length === 0 ? (
              <EmptyState
                icon={Boxes}
                title="Aucun produit"
                description="Ajoute ton premier produit pour qu’il puisse être financé par une tontine."
                actionTo="/commercant/produits"
                actionLabel="Ajouter un produit"
                compact
              />
            ) : (
              <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {(dashboard?.products || []).slice(0, 8).map((product) => (
                  <li key={product.id}>
                    <Card padding="none" hover className="h-full overflow-hidden">
                      <ProductImage src={productImage(product)} alt={product.name} ratio="4/3" rounded="rounded-none" iconSize="size-6" />
                      <div className="p-4">
                        <p className="truncate text-sm font-semibold text-ink-900">{product.name}</p>
                        <p className="amount mt-1 text-base text-primary-800">{formatFcfa(product.price)}</p>
                        <div className="mt-3 flex items-center justify-between gap-2">
                          <span
                            className={`inline-flex items-center gap-1 text-[11px] font-semibold ${
                              Number(product.stock) === 0 ? 'text-danger-600' : 'text-ink-500'
                            }`}
                          >
                            {Number(product.stock) === 0 ? 'Rupture' : `${product.stock} en stock`}
                          </span>
                          <StatusBadge status={product.status} size="xs" />
                        </div>
                      </div>
                    </Card>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </>
      )}
    </div>
  );
}
