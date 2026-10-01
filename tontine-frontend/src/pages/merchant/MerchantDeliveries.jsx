import { useMemo, useState } from 'react';
import { CheckCircle2, Gift, Info, Package, Truck, XCircle } from 'lucide-react';
import { api } from '../../api/client';
import { useApi } from '../../hooks/useApi';
import { useToast } from '../../context/ToastContext';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { PageHeader } from '../../components/ui/PageHeader';
import { Card } from '../../components/ui/Card';
import { StatCard } from '../../components/ui/StatCard';
import { Button } from '../../components/ui/Button';
import { StatusBadge } from '../../components/ui/Badge';
import { Tabs } from '../../components/ui/Tabs';
import { Modal } from '../../components/ui/Modal';
import { EmptyState, ErrorState } from '../../components/ui/EmptyState';
import { SkeletonRows } from '../../components/ui/Skeleton';
import { formatDate, formatNumber } from '../../utils/format';
import { DELIVERY_BLOCKERS } from '../../utils/status';

/**
 * Espace commerçant — commandes & livraisons.
 *
 * Deux files distinctes, deux endpoints :
 *  - GET /merchant/orders                → livraisons de tontine
 *  - GET /merchant/installment-orders    → livraisons d'achats par tranches
 *
 * Le contrôle d'éligibilité est fait par le serveur (DeliveryService) : les
 * `blockers` affichés expliquent pourquoi une livraison ne peut pas encore être
 * confirmée. Masquer le bouton ne remplace pas cette vérification.
 */
export default function MerchantDeliveries() {
  useDocumentTitle('Commandes & livraisons');
  const toast = useToast();

  const tontineQuery = useApi(() => api.get('/merchant/orders'), []);
  const installmentQuery = useApi(() => api.get('/merchant/installment-orders'), []);

  const [tab, setTab] = useState('ready');
  const [busyId, setBusyId] = useState(null);
  const [confirm, setConfirm] = useState(null);

  const tontineOrders = useMemo(() => (Array.isArray(tontineQuery.data) ? tontineQuery.data : []), [tontineQuery.data]);
  const installmentOrders = useMemo(
    () => (Array.isArray(installmentQuery.data) ? installmentQuery.data : []),
    [installmentQuery.data],
  );

  const isLoading = tontineQuery.isLoading || installmentQuery.isLoading;
  const error = tontineQuery.error || installmentQuery.error;

  const confirmDelivery = async () => {
    setBusyId(confirm.id);
    try {
      const response = await api.post(confirm.path);
      toast.success(response?.message || 'Livraison confirmée.');
      await Promise.all([tontineQuery.reload(), installmentQuery.reload()]);
    } catch (err) {
      toast.error(err.message);
      await Promise.all([tontineQuery.reload(), installmentQuery.reload()]);
    } finally {
      setBusyId(null);
      setConfirm(null);
    }
  };

  const eligible = tontineOrders.filter((item) => item.is_eligible);
  const blocked = tontineOrders.filter((item) => !item.is_eligible && item.delivery_status !== 'delivered');
  const delivered = tontineOrders.filter((item) => item.delivery_status === 'delivered');
  const installmentPending = installmentOrders.filter((item) => item.delivery_status === 'pending');

  return (
    <div>
      <PageHeader
        title="Commandes & livraisons"
        subtitle="Confirme la remise des produits lorsque le round du bénéficiaire est intégralement payé."
        breadcrumb={[{ label: 'Espace commerçant' }, { label: 'Commandes & livraisons' }]}
      />

      {error ? (
        <ErrorState message={error.message} onRetry={() => Promise.all([tontineQuery.reload(), installmentQuery.reload()])} />
      ) : (
        <>
          {!isLoading && (
            <div className="mb-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
              <StatCard label="À livrer maintenant" value={formatNumber(eligible.length + installmentPending.length)} icon={Truck} tone="warning" />
              <StatCard label="Bénéficiaires en attente du round" value={formatNumber(blocked.length)} icon={Gift} />
              <StatCard label="Livraisons confirmées" value={formatNumber(delivered.length)} icon={CheckCircle2} tone="success" />
              <StatCard label="Achats par tranches" value={formatNumber(installmentOrders.length)} icon={Package} tone="brand" />
            </div>
          )}

          <Card padding="sm" className="mb-6 flex items-start gap-3 border-info-200 bg-info-50">
            <Info className="mt-0.5 size-4.5 shrink-0 text-info-600" aria-hidden="true" />
            <p className="text-xs leading-relaxed text-ink-600">
              La confirmation de livraison est irréversible : elle notifie le client et clôt son dossier. Le
              serveur refuse toute livraison dont le round n’est pas entièrement réglé.
            </p>
          </Card>

          <Tabs
            className="mb-5"
            ariaLabel="Files de livraison"
            tabs={[
              { value: 'ready', label: 'À livrer', count: eligible.length + installmentPending.length },
              { value: 'waiting', label: 'En attente du round', count: blocked.length },
              { value: 'done', label: 'Livrées', count: delivered.length },
            ]}
            value={tab}
            onChange={setTab}
          />

          {isLoading ? (
            <Card>
              <SkeletonRows rows={5} />
            </Card>
          ) : tab === 'ready' ? (
            <ReadyList
              tontine={eligible}
              installments={installmentPending}
              onConfirm={(target) =>
                setConfirm({
                  id: `t-${target.id}`,
                  path: `/merchant/orders/${target.id}/deliver`,
                  label: target.user_name,
                  detail: target.tontine?.product?.name || target.tontine?.name,
                })
              }
              onConfirmInstallment={(target) =>
                setConfirm({
                  id: `i-${target.id}`,
                  path: `/merchant/installment-orders/${target.id}/deliver`,
                  label: target.user?.name,
                  detail: target.product?.name,
                })
              }
            />
          ) : tab === 'waiting' ? (
            <WaitingList items={blocked} />
          ) : (
            <DoneList items={delivered} installments={installmentOrders.filter((item) => item.delivery_status === 'delivered')} />
          )}
        </>
      )}

      <Modal
        open={Boolean(confirm)}
        onClose={() => setConfirm(null)}
        size="sm"
        title="Confirmer la livraison ?"
        footer={
          <>
            <Button variant="ghost" onClick={() => setConfirm(null)} disabled={busyId !== null}>
              Annuler
            </Button>
            <Button onClick={confirmDelivery} loading={busyId !== null} data-autofocus>
              Confirmer la remise
            </Button>
          </>
        }
      >
        <p className="text-sm leading-relaxed text-ink-600">
          Tu déclares avoir remis le produit à <strong className="text-ink-900">{confirm?.label}</strong>
          {confirm?.detail && <> ({confirm.detail})</>}. Cette action est définitive et notifie le client.
        </p>
      </Modal>
    </div>
  );
}

/* ------------------------------------------------------------------ */

function ReadyList({ tontine, installments, onConfirm, onConfirmInstallment }) {
  if (tontine.length === 0 && installments.length === 0) {
    return (
      <EmptyState
        icon={CheckCircle2}
        title="Rien à livrer pour le moment"
        description="Les bénéficiaires dont le round est intégralement payé apparaîtront ici automatiquement."
      />
    );
  }

  return (
    <ul className="space-y-3">
      {tontine.map((item) => (
        <li key={`t-${item.id}`}>
          <Card padding="sm" className="border-accent-200 bg-accent-50/30">
            <div className="flex flex-wrap items-center gap-4">
              <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-accent-500 text-ink-900">
                <Gift className="size-5" aria-hidden="true" />
              </span>

              <div className="min-w-0 flex-1">
                <p className="truncate font-display text-sm font-bold text-ink-900">{item.user_name}</p>
                <p className="truncate text-xs text-ink-600">
                  {item.tontine?.product?.name || 'Produit indisponible'} · {item.tontine?.name}
                </p>
                <p className="mt-1 text-[11px] text-ink-500">
                  Bénéficiaire du tour {item.beneficiary_round} · position {item.tontine?.current_round}
                </p>
              </div>

              <Button size="sm" icon={CheckCircle2} onClick={() => onConfirm(item)}>
                Confirmer la livraison
              </Button>
            </div>
          </Card>
        </li>
      ))}

      {installments.map((item) => (
        <li key={`i-${item.id}`}>
          <Card padding="sm" className="border-primary-200 bg-primary-50/30">
            <div className="flex flex-wrap items-center gap-4">
              <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary-600 text-white">
                <Package className="size-5" aria-hidden="true" />
              </span>

              <div className="min-w-0 flex-1">
                <p className="truncate font-display text-sm font-bold text-ink-900">{item.user?.name}</p>
                <p className="truncate text-xs text-ink-600">{item.product?.name}</p>
                <p className="mt-1 text-[11px] text-ink-500">
                  Achat par tranches · {item.paid_installments_count ?? item.installments_count} /
                  {item.installments_count} tranches réglées
                </p>
              </div>

              <Button size="sm" variant="primary" icon={CheckCircle2} onClick={() => onConfirmInstallment(item)}>
                Confirmer la livraison
              </Button>
            </div>
          </Card>
        </li>
      ))}
    </ul>
  );
}

function WaitingList({ items }) {
  if (items.length === 0) {
    return (
      <EmptyState
        icon={CheckCircle2}
        title="Aucun bénéficiaire bloqué"
        description="Tous les tours en cours sont intégralement cotisés."
        compact
      />
    );
  }

  return (
    <ul className="space-y-3">
      {items.map((item) => (
        <li key={item.id}>
          <Card padding="sm">
            <div className="flex flex-wrap items-center gap-4">
              <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-ink-100 text-ink-500">
                <Gift className="size-5" aria-hidden="true" />
              </span>

              <div className="min-w-0 flex-1">
                <p className="truncate font-display text-sm font-bold text-ink-900">{item.user_name}</p>
                <p className="truncate text-xs text-ink-600">
                  {item.tontine?.product?.name || 'Produit indisponible'} · {item.tontine?.name}
                </p>
                {item.blockers?.length > 0 && (
                  <ul className="mt-2 space-y-1">
                    {item.blockers.map((code) => (
                      <li key={code} className="flex items-start gap-1.5 text-[11px] text-warning-700">
                        <XCircle className="mt-px size-3 shrink-0" aria-hidden="true" />
                        {DELIVERY_BLOCKERS[code] || code}
                      </li>
                    ))}
                  </ul>
                )}
              </div>

              <StatusBadge status="awaiting_payment" size="sm" />
            </div>
          </Card>
        </li>
      ))}
    </ul>
  );
}

function DoneList({ items, installments }) {
  const rows = [
    ...items.map((item) => ({
      key: `t-${item.id}`,
      name: item.user_name,
      product: item.tontine?.product?.name || 'Produit indisponible',
      tontine: item.tontine?.name,
      date: item.delivered_at,
      type: 'Tontine',
    })),
    ...installments.map((item) => ({
      key: `i-${item.id}`,
      name: item.user?.name,
      product: item.product?.name,
      tontine: 'Achat par tranches',
      date: item.delivered_at,
      type: 'Tranches',
    })),
  ];

  if (rows.length === 0) {
    return <EmptyState icon={Truck} title="Aucune livraison confirmée" description="Tes livraisons validées apparaîtront ici." compact />;
  }

  return (
    <Card padding="none">
      <ul className="divide-y divide-line-soft">
        {rows.map((row) => (
          <li key={row.key} className="flex flex-wrap items-center gap-4 p-4">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-success-50 text-success-600">
              <CheckCircle2 className="size-4" aria-hidden="true" />
            </span>
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-semibold text-ink-900">{row.name}</p>
              <p className="truncate text-xs text-ink-500">
                {row.product} · {row.tontine}
              </p>
            </div>
            <div className="flex shrink-0 items-center gap-3">
              <StatusBadge status="delivered" label="Livré" size="xs" />
              {row.date && <span className="text-[11px] text-ink-400">{formatDate(row.date)}</span>}
            </div>
          </li>
        ))}
      </ul>
    </Card>
  );
}
