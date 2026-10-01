import { useState } from 'react';
import { CheckCircle2, Clock, CreditCard, Info, Receipt, ShieldCheck, XCircle } from 'lucide-react';
import { api } from '../../api/client';
import { useApi } from '../../hooks/useApi';
import { useAdminCounters } from '../../context/AdminCountersContext';
import { useToast } from '../../context/ToastContext';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { PageHeader } from '../../components/ui/PageHeader';
import { Card } from '../../components/ui/Card';
import { StatCard } from '../../components/ui/StatCard';
import { Button } from '../../components/ui/Button';
import { Select } from '../../components/ui/Input';
import { StatusBadge } from '../../components/ui/Badge';
import { ConfirmationDialog } from '../../components/ui/ConfirmationDialog';
import { EmptyState, ErrorState } from '../../components/ui/EmptyState';
import { SkeletonRows } from '../../components/ui/Skeleton';
import { formatDateTime, formatFcfa, formatNumber } from '../../utils/format';

/**
 * Vérification des paiements manuels (MyNita / Amana).
 *
 * GET  /admin/payments?status=pending|accepted|rejected  → tableau JSON BRUT
 *       (non paginé), fusionnant cotisations et tranches.
 * POST /admin/payments/{contribution|installment}/{id}/accept
 * POST /admin/payments/{contribution|installment}/{id}/reject
 *
 * Le type (`contribution` ou `installment`) sélectionne la route : il fait
 * partie de la réponse et n'est jamais deviné.
 */
export default function AdminPaymentVerifications() {
  useDocumentTitle('Vérification des paiements');
  const toast = useToast();

  const [status, setStatus] = useState('pending');
  const { data, isLoading, error, reload } = useApi(() => api.get('/admin/payments', { status }), [status]);
  // La pastille « à vérifier » de la navigation doit suivre l'action sans
  // attendre un rechargement de page.
  const { refreshPendingPayments } = useAdminCounters();

  const [busyKey, setBusyKey] = useState(null);
  const [confirm, setConfirm] = useState(null);

  const payments = Array.isArray(data) ? data : [];

  const totals = {
    count: payments.length,
    amount: payments.reduce((sum, item) => sum + Number(item.amount || 0), 0),
    contributions: payments.filter((item) => item.type === 'contribution').length,
    installments: payments.filter((item) => item.type === 'installment').length,
  };

  const act = async (payment, action) => {
    const key = `${payment.type}-${payment.id}`;
    setBusyKey(key);
    try {
      const response = await api.post(`/admin/payments/${payment.type}/${payment.id}/${action}`);
      toast.success(response?.message || (action === 'accept' ? 'Paiement accepté.' : 'Paiement refusé.'));
      await reload();
      await refreshPendingPayments();
    } catch (err) {
      toast.error(err.message);
      await reload();
      await refreshPendingPayments();
    } finally {
      setBusyKey(null);
      setConfirm(null);
    }
  };

  return (
    <div>
      <PageHeader
        title="Paiements à vérifier"
        subtitle="Transferts MyNita et Amana déclarés par les membres, avant comptabilisation."
        breadcrumb={[{ label: 'Administration', to: '/admin' }, { label: 'Paiements' }]}
        actions={
          <Select value={status} onChange={(event) => setStatus(event.target.value)} className="w-48" aria-label="Filtrer par statut">
            <option value="pending">En attente</option>
            <option value="accepted">Acceptés</option>
            <option value="rejected">Refusés</option>
          </Select>
        }
      />

      <Card padding="sm" className="mb-6 flex items-start gap-3 border-info-200 bg-info-50">
        <Info className="mt-0.5 size-4.5 shrink-0 text-info-600" aria-hidden="true" />
        <p className="text-xs leading-relaxed text-ink-600">
          Cette file regroupe les <strong>cotisations de tontine</strong> et les <strong>tranches d’achat</strong>{' '}
          payées par transfert manuel. Vérifie que le code saisi correspond bien à une opération de montant
          identique, au nom du client, avant d’accepter.
        </p>
      </Card>

      {error ? (
        <ErrorState message={error.message} onRetry={reload} />
      ) : (
        <>
          {!isLoading && payments.length > 0 && (
            <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
              <StatCard label="Paiements" value={formatNumber(totals.count)} icon={CreditCard} />
              <StatCard label="Montant total" value={formatFcfa(totals.amount)} icon={Receipt} tone="brand" />
              <StatCard label="Cotisations de tontine" value={formatNumber(totals.contributions)} icon={ShieldCheck} />
              <StatCard label="Tranches d'achat" value={formatNumber(totals.installments)} icon={CreditCard} tone="info" />
            </div>
          )}

          {isLoading ? (
            <Card>
              <SkeletonRows rows={4} />
            </Card>
          ) : payments.length === 0 ? (
            <EmptyState
              icon={status === 'pending' ? CheckCircle2 : Clock}
              title={status === 'pending' ? 'Aucun paiement en attente 🎉' : 'Aucun paiement dans cette catégorie'}
              description={
                status === 'pending'
                  ? 'Tous les transferts déclarés ont été traités. Rien ne t’attend ici.'
                  : 'Change de filtre pour consulter les autres paiements.'
              }
              compact
            />
          ) : (
            <ul className="space-y-3">
              {payments.map((payment) => (
                <li key={`${payment.type}-${payment.id}`}>
                  <PaymentCard
                    payment={payment}
                    busy={busyKey === `${payment.type}-${payment.id}`}
                    onAccept={() => act(payment, 'accept')}
                    onReject={() =>
                      setConfirm({
                        payment,
                        label: payment.client_name,
                        detail: payment.label,
                        amount: payment.amount,
                      })
                    }
                  />
                </li>
              ))}
            </ul>
          )}
        </>
      )}

      <ConfirmationDialog
        open={Boolean(confirm)}
        onClose={() => setConfirm(null)}
        onConfirm={() => act(confirm.payment, 'reject')}
        title="Refuser ce paiement ?"
        message={
          <>
            Le code de transfert de <strong>{confirm?.label}</strong> ({formatFcfa(confirm?.amount)}) sera marqué
            comme non vérifié. Le membre devra soumettre un nouveau code pour débloquer son tour.
          </>
        }
        confirmLabel="Refuser le paiement"
        loading={busyKey !== null}
      />
    </div>
  );
}

/* ------------------------------------------------------------------ */

function PaymentCard({ payment, busy, onAccept, onReject }) {
  const isPending = payment.verification_status === 'pending';

  return (
    <Card padding="sm" hover={false} className={isPending ? 'border-warning-200' : 'opacity-90'}>
      <div className="flex flex-col gap-4 lg:flex-row lg:items-center">
        {/* Client + objet */}
        <div className="flex min-w-0 flex-1 items-start gap-3.5">
          <span
            className={`flex size-11 shrink-0 items-center justify-center rounded-xl ${
              isPending ? 'bg-warning-50 text-warning-600' : 'bg-ink-100 text-ink-500'
            }`}
          >
            {isPending ? <Clock className="size-5" aria-hidden="true" /> : <CheckCircle2 className="size-5" aria-hidden="true" />}
          </span>

          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <p className="truncate font-display text-sm font-bold text-ink-900">{payment.client_name}</p>
              <StatusBadge status={payment.verification_status} size="xs" dot />
              <span className="inline-flex items-center rounded-full bg-ink-100 px-2 py-0.5 text-[10px] font-semibold text-ink-600">
                {payment.type === 'contribution' ? 'Cotisation de tontine' : "Tranche d'achat"}
              </span>
            </div>

            <p className="mt-1 truncate text-sm text-ink-700">{payment.label}</p>
            <p className="truncate text-xs text-ink-500">{payment.sub_label}</p>

            <div className="mt-2.5 flex flex-wrap items-center gap-x-5 gap-y-1.5 text-xs">
              <span className="inline-flex items-center gap-1.5">
                <span className="text-ink-400">Moyen</span>
                <span className="font-semibold text-ink-800">
                  {payment.payment_method === 'mynita' ? 'MyNita' : payment.payment_method === 'amana' ? 'Amana' : payment.payment_method}
                </span>
              </span>
              <span className="inline-flex items-center gap-1.5">
                <span className="text-ink-400">Soumis le</span>
                <span className="text-ink-700">{formatDateTime(payment.submitted_at)}</span>
              </span>
            </div>
          </div>
        </div>

        {/* Montant + code */}
        <div className="flex shrink-0 flex-col items-start gap-2 lg:items-end">
          <p className="amount text-xl text-ink-900">{formatFcfa(payment.amount)}</p>
          <div className="flex items-center gap-2">
            <span className="text-[11px] text-ink-400">Code</span>
            <code className="select-all rounded-lg bg-ink-900 px-2.5 py-1 font-mono text-xs font-semibold tracking-wide text-white">
              {payment.transfer_code}
            </code>
          </div>
        </div>

        {/* Actions */}
        {isPending && (
          <div className="flex shrink-0 gap-2.5 lg:flex-col xl:flex-row">
            <Button size="sm" variant="success" icon={CheckCircle2} loading={busy} onClick={onAccept}>
              Accepter
            </Button>
            <Button size="sm" variant="danger-outline" icon={XCircle} disabled={busy} onClick={onReject}>
              Refuser
            </Button>
          </div>
        )}
      </div>
    </Card>
  );
}
