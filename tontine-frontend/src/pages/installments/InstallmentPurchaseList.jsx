import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, CheckCircle2, Clock, Package, Receipt, Wallet } from 'lucide-react';
import { api } from '../../api/client';
import { useApi } from '../../hooks/useApi';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { PageHeader } from '../../components/ui/PageHeader';
import { Card } from '../../components/ui/Card';
import { ButtonLink } from '../../components/ui/Button';
import { EmptyState, ErrorState } from '../../components/ui/EmptyState';
import { Tabs } from '../../components/ui/Tabs';
import { TontineCardSkeleton } from '../../components/tontine/TontineCard';
import { formatDate, formatFcfa, formatNumber } from '../../utils/format';

/**
 * « Mes commandes » = achats par tranches (GET /mes-achats).
 *
 * LIMITATION DOCUMENTÉE : il n'existe pas d'endpoint « mes commandes » unifié.
 * Les commandes liées à une tontine produit (livraison au bénéficiaire) ne sont
 * visibles que depuis la fiche de la tontine (/tontines/:id) ; cette page couvre
 * donc les achats par tranches, seul flux exposant un état de livraison au client.
 */
export default function InstallmentPurchaseList() {
  useDocumentTitle('Mes commandes');

  const { data, isLoading, error, reload } = useApi(() => api.get('/mes-achats'), []);
  const [tab, setTab] = useState('active');

  const purchases = useMemo(() => data?.data || [], [data]);

  const filtered = useMemo(() => {
    if (tab === 'active') return purchases.filter((item) => item.status === 'active');
    if (tab === 'completed') return purchases.filter((item) => item.status === 'completed');
    return purchases;
  }, [purchases, tab]);

  const totals = useMemo(() => {
    const active = purchases.filter((item) => item.status === 'active');
    const pendingDelivery = purchases.filter((item) => item.delivery_status === 'pending');
    const remaining = active.reduce(
      (sum, item) => sum + (item.installments_count - item.paid_installments_count) * Number(item.installment_amount || 0),
      0,
    );
    return { active: active.length, pendingDelivery: pendingDelivery.length, remaining };
  }, [purchases]);

  const tabs = [
    { value: 'active', label: 'En cours', count: purchases.filter((item) => item.status === 'active').length },
    { value: 'completed', label: 'Terminées', count: purchases.filter((item) => item.status === 'completed').length },
    { value: 'all', label: 'Toutes', count: purchases.length },
  ];

  return (
    <div>
      <PageHeader
        title="Mes commandes"
        subtitle="Tes achats payés en plusieurs fois : montant, avancement et état de livraison."
        breadcrumb={[{ label: 'Mon espace', to: '/tableau-de-bord' }, { label: 'Mes commandes' }]}
        actions={
          <ButtonLink to="/produits" variant="secondary" icon={Package}>
            Voir le catalogue
          </ButtonLink>
        }
      />

      {error ? (
        <ErrorState message={error.message} onRetry={reload} />
      ) : (
        <>
          {purchases.length > 0 && (
            <div className="mb-8 grid gap-4 sm:grid-cols-3">
              <Tile label="Achats en cours" value={formatNumber(totals.active)} icon={Clock} />
              <Tile label="Restant à payer" value={formatFcfa(totals.remaining)} icon={Wallet} />
              <Tile label="En attente de livraison" value={formatNumber(totals.pendingDelivery)} icon={Package} />
            </div>
          )}

          {purchases.length > 0 && (
            <Tabs tabs={tabs} value={tab} onChange={setTab} className="mb-5" ariaLabel="Filtrer les commandes" />
          )}

          {isLoading ? (
            <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
              {Array.from({ length: 3 }).map((_, index) => (
                <TontineCardSkeleton key={index} />
              ))}
            </div>
          ) : purchases.length === 0 ? (
            <EmptyState
              icon={Package}
              title="Aucune commande pour le moment"
              description="Choisis un produit dans le catalogue et paie-le en plusieurs fois, sans intérêt."
              actionTo="/produits"
              actionLabel="Découvrir le catalogue"
            />
          ) : filtered.length === 0 ? (
            <EmptyState icon={CheckCircle2} title="Rien dans cette catégorie" description="Change d’onglet pour voir tes autres commandes." compact />
          ) : (
            <ul className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
              {filtered.map((purchase) => (
                <li key={purchase.id}>
                  <OrderCard purchase={purchase} />
                </li>
              ))}
            </ul>
          )}
        </>
      )}
    </div>
  );
}

function Tile({ label, value, icon: Icon }) {
  return (
    <Card padding="sm">
      <div className="flex items-center gap-3">
        <span className="flex size-10 items-center justify-center rounded-xl bg-primary-50 text-primary-700">
          <Icon className="size-4.5" aria-hidden="true" />
        </span>
        <div className="min-w-0">
          <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-500">{label}</p>
          <p className="amount mt-0.5 truncate text-xl">{value}</p>
        </div>
      </div>
    </Card>
  );
}

function OrderCard({ purchase }) {
  const paid = purchase.paid_installments_count || 0;
  const total = purchase.installments_count || 1;
  const percent = Math.round((paid / total) * 100);
  const isDelivered = purchase.delivery_status === 'delivered';

  return (
    <Card padding="none" hover className="flex h-full flex-col overflow-hidden">
      <Link to={`/mes-achats/${purchase.id}`} className="flex h-full flex-col">
        <div className="flex items-start justify-between gap-3 border-b border-line-soft p-4">
          <div className="min-w-0">
            <p className="truncate font-display text-sm font-bold text-ink-900">{purchase.product?.name}</p>
            <p className="mt-0.5 text-[11px] text-ink-500">Commande du {formatDate(purchase.created_at)}</p>
          </div>
          <StatusPill status={purchase.status} />
        </div>

        <div className="flex-1 p-4">
          <div className="mb-2 flex items-baseline justify-between text-xs">
            <span className="text-ink-500">
              {formatNumber(paid)} / {formatNumber(total)} tranches
            </span>
            <span className="font-bold text-primary-700">{percent} %</span>
          </div>
          <div className="h-2 w-full overflow-hidden rounded-full bg-primary-100">
            <div className="h-full rounded-full bg-primary-600 transition-[width] duration-700" style={{ width: `${percent}%` }} />
          </div>

          <dl className="mt-4 space-y-2 text-xs">
            <div className="flex justify-between">
              <dt className="text-ink-500">Prix du produit</dt>
              <dd className="amount text-ink-800">{formatFcfa(purchase.product_price)}</dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-ink-500">Montant par tranche</dt>
              <dd className="amount text-ink-800">{formatFcfa(purchase.installment_amount)}</dd>
            </div>
            <div className="flex justify-between border-t border-line-soft pt-2">
              <dt className="font-semibold text-ink-600">Reste à payer</dt>
              <dd className="amount text-primary-700">
                {formatFcfa((total - paid) * Number(purchase.installment_amount || 0))}
              </dd>
            </div>
          </dl>
        </div>

        <div className="flex items-center justify-between gap-3 border-t border-line-soft p-4">
          <span
            className={`inline-flex items-center gap-1.5 text-[11px] font-semibold ${
              isDelivered ? 'text-success-700' : 'text-ink-500'
            }`}
          >
            {isDelivered ? <CheckCircle2 className="size-3.5" aria-hidden="true" /> : <Receipt className="size-3.5" aria-hidden="true" />}
            {isDelivered ? `Livré le ${formatDate(purchase.delivered_at)}` : 'Livraison confirmée par le commerçant'}
          </span>
          <span className="inline-flex items-center gap-1 text-xs font-bold text-primary-700">
            Détails
            <ArrowRight className="size-3.5" aria-hidden="true" />
          </span>
        </div>
      </Link>
    </Card>
  );
}

function StatusPill({ status }) {
  const map = {
    active: { label: 'En cours', classes: 'bg-primary-50 text-primary-700 ring-primary-200' },
    completed: { label: 'Terminée', classes: 'bg-success-50 text-success-700 ring-success-200' },
    cancelled: { label: 'Annulée', classes: 'bg-danger-50 text-danger-700 ring-danger-200' },
  };
  const item = map[status] || { label: status, classes: 'bg-ink-100 text-ink-600 ring-ink-200' };
  return (
    <span className={`inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${item.classes}`}>
      {item.label}
    </span>
  );
}
