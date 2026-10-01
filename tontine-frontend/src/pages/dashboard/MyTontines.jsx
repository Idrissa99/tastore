import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, CheckCircle2, Clock, HandCoins, ShoppingBag, Wallet } from 'lucide-react';
import { useMemberData } from '../../hooks/useMemberData';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { PageHeader } from '../../components/ui/PageHeader';
import { Card } from '../../components/ui/Card';
import { StatusBadge } from '../../components/ui/Badge';
import { ProgressBar } from '../../components/ui/Progress';
import { Tabs } from '../../components/ui/Tabs';
import { EmptyState, ErrorState } from '../../components/ui/EmptyState';
import { TontineCardSkeleton } from '../../components/tontine/TontineCard';
import { formatCountdown, formatDate, formatFcfa, formatNumber } from '../../utils/format';
import { frequencyLabel, tontineTypeLabel } from '../../utils/status';
import { estimatedDueDate } from '../../utils/schedule';

/**
 * Mes tontines.
 *
 * L'API n'expose pas de liste « mes tontines » : elle est déduite des
 * cotisations du membre (GET /contributions), puis chaque tontine est
 * rechargée via GET /tontines/{id} pour disposer de sa progression réelle.
 */
export default function MyTontines() {
  useDocumentTitle('Mes tontines');
  const { tontines, byTontine, stats, isLoading, error, reload } = useMemberData();
  const [tab, setTab] = useState('active');

  const filtered = useMemo(() => {
    if (tab === 'active') return tontines.filter((tontine) => ['open', 'active'].includes(tontine.status));
    if (tab === 'completed') return tontines.filter((tontine) => tontine.status === 'completed');
    if (tab === 'cancelled') return tontines.filter((tontine) => tontine.status === 'cancelled');
    return tontines;
  }, [tontines, tab]);

  const tabs = [
    { value: 'active', label: 'En cours', count: tontines.filter((t) => ['open', 'active'].includes(t.status)).length },
    { value: 'completed', label: 'Terminées', count: tontines.filter((t) => t.status === 'completed').length },
    { value: 'all', label: 'Toutes', count: tontines.length },
  ];

  return (
    <div>
      <PageHeader
        title="Mes tontines"
        subtitle="Chaque tontine où tu verses, avec ta progression et ton prochain versement."
        breadcrumb={[{ label: 'Mon espace', to: '/tableau-de-bord' }, { label: 'Mes tontines' }]}
        actions={
          <Link
            to="/tontines"
            className="inline-flex h-11 items-center gap-2 rounded-xl bg-primary-700 px-5 text-sm font-semibold text-white shadow-brand transition-colors hover:bg-primary-800"
          >
            Découvrir les tontines
          </Link>
        }
      />

      {!isLoading && tontines.length > 0 && (
        <div className="mb-6 grid gap-4 sm:grid-cols-3">
          <SummaryTile label="Tontines" value={formatNumber(tontines.length)} icon={Wallet} />
          <SummaryTile
            label="Total versé"
            value={formatFcfa(stats.totalPaid)}
            icon={HandCoins}
            className="text-primary-800"
          />
          <SummaryTile
            label="Versements validés"
            value={formatNumber(stats.paidCount)}
            icon={CheckCircle2}
            className="text-success-700"
          />
        </div>
      )}

      {tontines.length > 0 && (
        <Tabs tabs={tabs} value={tab} onChange={setTab} className="mb-6" ariaLabel="Filtrer les tontines" />
      )}

      {error ? (
        <ErrorState message={error.message} onRetry={reload} />
      ) : isLoading ? (
        <div className="grid gap-4 lg:grid-cols-2">
          {Array.from({ length: 2 }).map((_, index) => (
            <TontineCardSkeleton key={index} />
          ))}
        </div>
      ) : tontines.length === 0 ? (
        <EmptyState
          icon={Wallet}
          title="Vous n’avez encore rejoint aucune tontine"
          description="Explore les tontines ouvertes et rejoignez celle qui correspond à votre budget."
          actionTo="/tontines"
          actionLabel="Découvrir les tontines"
        />
      ) : filtered.length === 0 ? (
        <EmptyState
          icon={Clock}
          title="Aucune tontine dans cette catégorie"
          description="Change d’onglet pour voir tes autres tontines."
          compact
        />
      ) : (
        <ul className="grid gap-4 lg:grid-cols-2">
          {filtered.map((tontine) => (
            <li key={tontine.id}>
              <MemberTontineCard tontine={tontine} contributions={byTontine.get(tontine.id) || []} />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function SummaryTile({ label, value, icon: Icon, className = '' }) {
  return (
    <Card padding="sm">
      <div className="flex items-center gap-3">
        <span className="flex size-10 items-center justify-center rounded-xl bg-primary-50 text-primary-700">
          <Icon className="size-4.5" aria-hidden="true" />
        </span>
        <div className="min-w-0">
          <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-500">{label}</p>
          <p className={`amount mt-0.5 truncate text-xl ${className}`}>{value}</p>
        </div>
      </div>
    </Card>
  );
}

/** Carte détaillée : progression, round, versements, prochaine échéance. */
function MemberTontineCard({ tontine, contributions }) {
  const total = Number(tontine.total_amount || 0);
  const perRound = Number(tontine.contribution_amount || 0);
  const collected = Number(tontine.round?.paid_members || 0) * perRound;
  const percent = total > 0 ? Math.min(100, Math.round((collected / total) * 100)) : 0;

  const pending = contributions
    .filter((item) => item.status !== 'completed' && item.status !== 'cancelled')
    .filter((item) => item.verification_status !== 'accepted' && item.verification_status !== 'pending')
    .sort((a, b) => Number(a.round) - Number(b.round))[0];

  const dueDate = pending ? estimatedDueDate(tontine, pending.round) : null;
  const myStatus = tontine.my_status;
  const delivery = tontine.my_delivery_status;
  const isCash = tontine.type === 'cash';

  return (
    <Card padding="none" hover className="flex h-full flex-col">
      <div className="flex items-start justify-between gap-4 p-5 pb-4">
        <div className="flex min-w-0 items-start gap-3">
          <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-xl">
            <span aria-hidden="true">{isCash ? '💰' : '🛍️'}</span>
          </span>
          <div className="min-w-0">
            <h2 className="truncate font-display text-base font-bold text-ink-900">{tontine.name}</h2>
            <p className="mt-0.5 text-xs text-ink-500">
              {tontineTypeLabel(tontine.type)} · {formatFcfa(perRound)} {frequencyLabel(tontine.frequency)}
            </p>
          </div>
        </div>
        <div className="flex shrink-0 flex-col items-end gap-1.5">
          <StatusBadge status={tontine.status} size="sm" />
          {myStatus && <StatusBadge status={myStatus} size="xs" label={`Moi : ${myStatus}`} />}
        </div>
      </div>

      <div className="px-5">
        <div className="mb-1.5 flex items-center justify-between text-[11px]">
          <span className="font-medium text-ink-500">Progression du round {tontine.round?.number}</span>
          <span className="font-bold text-ink-700">{percent} %</span>
        </div>
        <ProgressBar value={collected} total={total} size="sm" />
        <p className="mt-1.5 text-[11px] text-ink-400">
          {formatFcfa(collected)} / {formatFcfa(total)} · {tontine.round?.paid_members}/{tontine.round?.expected_members}{' '}
          membres cotisés
        </p>
      </div>

      <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 border-t border-line-soft px-5 pt-4 text-sm sm:grid-cols-3">
        <div>
          <dt className="text-[11px] text-ink-400">Round actuel</dt>
          <dd className="amount text-sm">
            {tontine.round?.number ?? tontine.current_round} / {tontine.max_members}
          </dd>
        </div>
        <div>
          <dt className="text-[11px] text-ink-400">Versements validés</dt>
          <dd className="amount text-sm">
            {formatNumber(contributions.filter((item) => item.status === 'completed' && item.verification_status !== 'rejected').length)}
          </dd>
        </div>
        {!isCash ? (
          <div>
            <dt className="text-[11px] text-ink-400">Livraison</dt>
            <dd>
              <StatusBadge status={delivery} size="xs" />
            </dd>
          </div>
        ) : (
          <div>
            <dt className="text-[11px] text-ink-400">Membres</dt>
            <dd className="amount text-sm">
              {tontine.current_members} / {tontine.max_members}
            </dd>
          </div>
        )}
      </dl>

      {pending ? (
        <div className="mt-4 flex flex-wrap items-center justify-between gap-2 rounded-xl bg-primary-50/80 px-4 py-3">
          <div className="min-w-0">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-primary-700">
              Prochain versement · round {pending.round}
            </p>
            <p className="text-xs text-ink-600">
              {dueDate ? `Échéance estimée ${formatDate(dueDate)} (${formatCountdown(dueDate)})` : 'Échéance à planifier'}
            </p>
          </div>
          <span className="amount shrink-0 text-lg text-primary-800">{formatFcfa(pending.amount)}</span>
        </div>
      ) : (
        <div className="mt-4 flex items-center gap-2 rounded-xl bg-success-50/70 px-4 py-3 text-xs font-medium text-success-700">
          <CheckCircle2 className="size-4 shrink-0" aria-hidden="true" />
          Aucun versement en attente sur cette tontine
        </div>
      )}

      <div className="mt-auto flex items-center justify-between gap-3 border-t border-line-soft p-5 pt-4">
        <Link
          to={`/tontines/${tontine.id}`}
          className="inline-flex items-center gap-1.5 text-sm font-bold text-primary-700 transition-colors hover:text-primary-900"
        >
          Voir la tontine
          <ArrowRight className="size-4" aria-hidden="true" />
        </Link>
        <Link
          to="/mes-cotisations"
          className="inline-flex items-center gap-1.5 text-xs font-semibold text-ink-500 transition-colors hover:text-ink-800"
        >
          <ShoppingBag className="size-3.5" aria-hidden="true" />
          Versements
        </Link>
      </div>
    </Card>
  );
}
