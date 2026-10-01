import { useMemo, useState } from 'react';
import {
  AlertCircle,
  ArrowRight,
  CheckCircle2,
  Clock,
  HandCoins,
  Hourglass,
  Receipt,
  Wallet,
} from 'lucide-react';
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
import { EmptyState, ErrorState } from '../../components/ui/EmptyState';
import { SkeletonStats, SkeletonRows } from '../../components/ui/Skeleton';
import PaymentFlow from '../../components/payments/PaymentFlow';
import VerificationBanner from '../../components/payments/VerificationBanner';
import { formatDate, formatDateTime, formatFcfa, formatNumber, truncate } from '../../utils/format';

/**
 * Historique des cotisations et parcours de paiement.
 *
 * Endpoints utilisés (inchangés) :
 *  - GET  /config                                        → moyens de paiement
 *  - GET  /contributions                                 → toutes les cotisations
 *  - POST /contributions/{id}/pay                        → canal à validation directe
 *  - POST /contributions/{id}/soumettre-code             → MyNita / Amana (code)
 */
export default function Contributions() {
  useDocumentTitle('Mes contributions');
  const toast = useToast();

  const { data, isLoading, error, reload } = useApi(() => api.get('/contributions'), []);
  const { data: config } = useApi(() => api.get('/config'), []);
  const channels = config?.payment_channels || {};

  const [tab, setTab] = useState('todo');
  const [activeContribution, setActiveContribution] = useState(null);

  const contributions = useMemo(() => (Array.isArray(data) ? data : []), [data]);

  const buckets = useMemo(() => {
    const isSettled = (item) => item.status === 'completed' && item.verification_status !== 'rejected';
    const awaiting = (item) => item.verification_status === 'pending';
    const rejected = (item) => item.verification_status === 'rejected';
    const todo = (item) => !isSettled(item) && !awaiting(item) && item.status !== 'cancelled';

    const settled = contributions.filter(isSettled);
    return {
      todo: contributions.filter(todo),
      awaiting: contributions.filter(awaiting),
      done: settled,
      rejected: contributions.filter(rejected),
      totalPaid: settled.reduce((sum, item) => sum + Number(item.amount || 0), 0),
      remaining: contributions
        .filter(todo)
        .reduce((sum, item) => sum + Number(item.amount || 0), 0),
    };
  }, [contributions]);

  const list = buckets[tab] || [];

  const handlePay = async (contribution, payload) => {
    await api.post(`/contributions/${contribution.id}/pay`, payload);
    toast.success('Paiement enregistré. Ton versement est mis à jour.');
    await reload();
  };

  const handleSubmitCode = async (contribution, payload) => {
    await api.post(`/contributions/${contribution.id}/soumettre-code`, payload);
    toast.success('Code de transfert envoyé. Il sera vérifié par notre équipe.');
    await reload();
  };

  const tabs = [
    { value: 'todo', label: 'À payer', count: buckets.todo.length },
    { value: 'awaiting', label: 'En vérification', count: buckets.awaiting.length },
    { value: 'done', label: 'Validés', count: buckets.done.length },
    { value: 'rejected', label: 'Refusés', count: buckets.rejected.length },
  ];

  return (
    <div>
      <PageHeader
        title="Mes contributions"
        subtitle="Suis chaque versement, règle tes tours en cours et consulte l’historique de tes paiements."
        breadcrumb={[{ label: 'Mon espace', to: '/tableau-de-bord' }, { label: 'Mes contributions' }]}
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
                label="Total versé"
                value={formatFcfa(buckets.totalPaid)}
                sublabel={`${formatNumber(buckets.done.length)} versement(s) validé(s)`}
                icon={HandCoins}
                tone="brand"
              />
              <StatCard
                label="Nombre de versements"
                value={formatNumber(contributions.length)}
                sublabel="Toutes tontines confondues"
                icon={Receipt}
              />
              <StatCard
                label="Montant restant"
                value={formatFcfa(buckets.remaining)}
                sublabel={
                  buckets.todo.length
                    ? `${formatNumber(buckets.todo.length)} versement(s) en attente`
                    : 'Rien à payer'
                }
                icon={Wallet}
                tone={buckets.todo.length ? 'warning' : 'success'}
              />
              <StatCard
                label="Prochain paiement"
                value={buckets.todo[0] ? formatFcfa(buckets.todo[0].amount) : '—'}
                sublabel={buckets.todo[0] ? buckets.todo[0].tontine?.name || 'Tontine' : 'Aucun versement dû'}
                icon={Clock}
                tone={buckets.todo.length ? 'accent' : 'neutral'}
              />
            </div>
          )}

          {buckets.rejected.length > 0 && tab !== 'rejected' && (
            <Card padding="sm" className="mb-6 flex flex-wrap items-center justify-between gap-4 border-danger-200 bg-danger-50">
              <div className="flex items-start gap-3">
                <AlertCircle className="mt-0.5 size-5 shrink-0 text-danger-600" aria-hidden="true" />
                <div>
                  <p className="text-sm font-bold text-danger-700">
                    {formatNumber(buckets.rejected.length)} paiement(s) refusé(s)
                  </p>
                  <p className="mt-0.5 text-xs text-ink-600">
                    Le code de transfert fourni n’a pas pu être vérifié. Renvoie un nouveau code pour débloquer
                    tes tours.
                  </p>
                </div>
              </div>
              <Button variant="danger-outline" size="sm" onClick={() => setTab('rejected')}>
                Voir et corriger
              </Button>
            </Card>
          )}

          {contributions.length > 0 && (
            <Tabs tabs={tabs} value={tab} onChange={setTab} className="mb-5" ariaLabel="Filtrer les versements" />
          )}

          {isLoading ? (
            <Card>
              <SkeletonRows rows={5} />
            </Card>
          ) : contributions.length === 0 ? (
            <EmptyState
              icon={HandCoins}
              title="Aucun versement pour le moment"
              description="Rejoignez une tontine pour commencer à cotiser et suivre tes versements ici."
              actionTo="/tontines"
              actionLabel="Découvrir les tontines"
            />
          ) : list.length === 0 ? (
            <EmptyState
              icon={CheckCircle2}
              title={tab === 'todo' ? 'Tout est à jour 🎉' : 'Rien dans cette catégorie'}
              description={
                tab === 'todo'
                  ? 'Aucun versement en attente. Tes prochaines échéances apparaîtront ici.'
                  : 'Change d’onglet pour voir tes autres versements.'
              }
              compact
            />
          ) : (
            <ul className="space-y-3">
              {list.map((contribution) => (
                <li key={contribution.id}>
                  <ContributionCard
                    contribution={contribution}
                    onPay={() => setActiveContribution(contribution)}
                  />
                </li>
              ))}
            </ul>
          )}
        </>
      )}

      {/* Parcours de paiement */}
      <PaymentFlow
        open={Boolean(activeContribution)}
        onClose={() => setActiveContribution(null)}
        title="Régler mon versement"
        subtitle={activeContribution?.tontine?.name}
        amount={activeContribution?.amount}
        channels={channels}
        onPay={(payload) => handlePay(activeContribution, payload)}
        onSubmitCode={(payload) => handleSubmitCode(activeContribution, payload)}
      />
    </div>
  );
}

/* ------------------------------------------------------------------ */

function ContributionCard({ contribution, onPay }) {
  const tontine = contribution.tontine;
  const isSettled = contribution.status === 'completed' && contribution.verification_status !== 'rejected';
  const isAwaiting = contribution.verification_status === 'pending';

  return (
    <Card padding="sm" hover={false} className="transition-all duration-200 hover:border-primary-200">
      <div className="flex flex-wrap items-start gap-4">
        {/* Round */}
        <div className="flex shrink-0 flex-col items-center rounded-xl bg-ink-50 px-3.5 py-2">
          <span className="text-[10px] font-bold uppercase tracking-wide text-ink-400">Round</span>
          <span className="amount text-lg leading-none">{contribution.round}</span>
        </div>

        {/* Informations */}
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <p className="truncate font-display text-sm font-bold text-ink-900">{tontine?.name || 'Tontine'}</p>
            {isSettled && <StatusBadge status="accepted" label="Validé" size="xs" dot />}
            {isAwaiting && <StatusBadge status="pending" label="En vérification" size="xs" dot />}
            {contribution.verification_status === 'rejected' && <StatusBadge status="rejected" size="xs" dot />}
            {!isSettled && !isAwaiting && contribution.verification_status !== 'rejected' && (
              <StatusBadge status="pending" label="À payer" size="xs" dot />
            )}
          </div>

          <p className="mt-1 text-xs text-ink-500">
            {tontine?.type === 'cash' ? 'Tontine argent' : 'Tontine produit'}
            {contribution.paid_at && ` · réglé le ${formatDate(contribution.paid_at)}`}
            {contribution.submitted_at && !contribution.paid_at && ` · soumis le ${formatDate(contribution.submitted_at)}`}
          </p>

          <VerificationBanner
            status={contribution.status}
            verificationStatus={contribution.verification_status}
            reason={contribution.payment_failure_reason}
            className="mt-3"
          />
        </div>

        {/* Montant + action */}
        <div className="flex shrink-0 flex-col items-end gap-2.5">
          <p className="amount text-xl text-ink-900">{formatFcfa(contribution.amount)}</p>
          <p className="text-[10px] text-ink-400">
            dont {formatFcfa(contribution.commission_amount)} de commission
          </p>

          {!isSettled && !isAwaiting && (
            <Button size="sm" icon={ArrowRight} onClick={onPay}>
              {contribution.verification_status === 'rejected' ? 'Nouveau code' : 'Payer'}
            </Button>
          )}

          {isAwaiting && (
            <span className="inline-flex items-center gap-1.5 text-[11px] font-medium text-warning-700">
              <Hourglass className="size-3.5" aria-hidden="true" />
              Vérification en cours
            </span>
          )}
        </div>
      </div>

      {/* Références */}
      {(contribution.transfer_code || contribution.transaction_reference) && (
        <dl className="mt-4 flex flex-wrap gap-x-6 gap-y-2 border-t border-line-soft pt-3 text-xs">
          {contribution.transfer_code && (
            <div className="flex items-center gap-1.5">
              <dt className="text-ink-400">Code de transfert</dt>
              <dd className="rounded-md bg-ink-100 px-2 py-0.5 font-mono text-[11px] font-semibold text-ink-800">
                {truncate(contribution.transfer_code, 24)}
              </dd>
            </div>
          )}
          {contribution.transaction_reference && (
            <div className="flex items-center gap-1.5">
              <dt className="text-ink-400">Référence</dt>
              <dd className="font-mono text-[11px] text-ink-600">{truncate(contribution.transaction_reference, 28)}</dd>
            </div>
          )}
          {contribution.submitted_at && (
            <div className="flex items-center gap-1.5">
              <dt className="text-ink-400">Soumis le</dt>
              <dd className="text-ink-600">{formatDateTime(contribution.submitted_at)}</dd>
            </div>
          )}
        </dl>
      )}
    </Card>
  );
}
