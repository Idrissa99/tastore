import { Link } from 'react-router-dom';
import {
  AlertCircle,
  ArrowRight,
  CalendarClock,
  CheckCircle2,
  Clock,
  HandCoins,
  Hourglass,
  Package,
  ShieldCheck,
  ShoppingBag,
  Store,
  Wallet,
} from 'lucide-react';
import { useMemo } from 'react';
import { api } from '../../api/client';
import { useAuth } from '../../hooks/useAuth';
import { useApi } from '../../hooks/useApi';
import { useMemberData } from '../../hooks/useMemberData';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { PageHeader } from '../../components/ui/PageHeader';
import { SectionHeader } from '../../components/ui/PageHeader';
import { StatCard } from '../../components/ui/StatCard';
import { Card } from '../../components/ui/Card';
import { ButtonLink } from '../../components/ui/Button';
import { StatusBadge } from '../../components/ui/Badge';
import { TontineMiniCard } from '../../components/tontine/TontineCard';
import { SkeletonGrid, SkeletonStats } from '../../components/ui/Skeleton';
import { EmptyState, ErrorState } from '../../components/ui/EmptyState';
import { formatCountdown, formatDate, formatFcfa, formatNumber } from '../../utils/format';
import { estimatedDueDate } from '../../utils/schedule';

/**
 * Tableau de bord client.
 *
 * Toutes les statistiques sont calculées à partir de données réelles :
 *  - GET /contributions  → montants, compteurs de versements, prochaines dues
 *  - GET /tontines/{id}  → progression et round de chaque tontine
 *  - GET /mes-achats     → commandes en cours
 * L'API ne fournit pas d'endpoint « synthèse client » : ces agrégats sont
 * dérivés ici, et nowhere ailleurs dans l'application.
 */
export default function Dashboard() {
  useDocumentTitle('Tableau de bord');
  const { user, isAdmin, isMerchant } = useAuth();

  const { contributions, tontines, stats, isLoading, error, reload } = useMemberData();
  const { data: purchases, isLoading: isLoadingOrders } = useApi(() => api.get('/mes-achats'), []);

  const firstName = user?.name?.split(' ')[0] || 'bonjour';

  const orders = useMemo(() => purchases?.data || [], [purchases]);

  const orderStats = useMemo(() => {
    const active = orders.filter((order) => order.status === 'active' && order.delivery_status !== 'delivered');
    return {
      total: orders.length,
      active: active.length,
      pendingDelivery: orders.filter((order) => order.delivery_status === 'pending').length,
    };
  }, [orders]);

  /* Prochaine échéance : date estimée depuis start_date + fréquence. */
  const nextDue = useMemo(() => {
    const contribution = stats.nextContribution;
    if (!contribution) return null;
    const tontine = tontines.find((item) => item.id === contribution.tontine?.id);
    const date = estimatedDueDate(tontine, contribution.round);
    return {
      contribution,
      tontineName: contribution.tontine?.name || 'Tontine',
      date,
      countdown: date ? formatCountdown(date) : null,
    };
  }, [stats.nextContribution, tontines]);

  const activeTontines = useMemo(
    () => tontines.filter((tontine) => ['open', 'active'].includes(tontine.status)),
    [tontines],
  );

  return (
    <div>
      <PageHeader
        title={`Bonjour, ${firstName} 👋`}
        subtitle="Voici un aperçu de ton activité et de ce qui attend ta prochaine action."
        breadcrumb={[{ label: 'Mon espace' }]}
        actions={
          <>
            <ButtonLink to="/tontines" variant="secondary" icon={ShoppingBag}>
              Explorer
            </ButtonLink>
            {(isAdmin || isMerchant) && (
              <ButtonLink to="/tontines/nouvelle" icon={Wallet}>
                Créer une tontine
              </ButtonLink>
            )}
          </>
        }
      />

      {error ? (
        <ErrorState message={error.message} onRetry={reload} />
      ) : (
        <>
          {/* Accès direct à l'espace métier : un administrateur ou un
              commerçant qui atterrit ici ne doit pas croire que ses
              sections ont disparu. */}
          {(isAdmin || isMerchant) && (
            <Card padding="sm" className="mb-6 flex flex-wrap items-center justify-between gap-4 border-primary-200 bg-primary-50/60">
              <div className="flex items-start gap-3">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-700 text-white">
                  {isAdmin ? <ShieldCheck className="size-5" aria-hidden="true" /> : <Store className="size-5" aria-hidden="true" />}
                </span>
                <div className="min-w-0">
                  <p className="text-sm font-bold text-ink-900">
                    {isAdmin ? 'Espace administration' : 'Espace commerçant'}
                  </p>
                  <p className="mt-0.5 text-xs leading-relaxed text-ink-600">
                    {isAdmin
                      ? 'Utilisateurs, commerçants, tontines, paiements, litiges, produits, remboursements, commissions et rapports.'
                      : 'Produits, commandes et confirmations de livraison de ta boutique.'}
                  </p>
                </div>
              </div>
              <Link
                to={isAdmin ? '/admin' : '/commercant'}
                className="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-xl bg-primary-700 px-4 text-sm font-semibold text-white shadow-brand transition-colors hover:bg-primary-800"
              >
                Ouvrir
                <ArrowRight className="size-4" aria-hidden="true" />
              </Link>
            </Card>
          )}

          {/* Statistiques */}
          {isLoading ? (
            <SkeletonStats count={4} className="mb-8" />
          ) : (
            <div className="mb-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
              <StatCard
                label="Total versé"
                value={formatFcfa(stats.totalPaid)}
                sublabel={`${formatNumber(stats.paidCount)} versement(s) validé(s)`}
                icon={HandCoins}
                tone="brand"
                to="/mes-cotisations"
              />
              <StatCard
                label="Tontines actives"
                value={formatNumber(stats.activeTontineCount)}
                sublabel={`${formatNumber(stats.tontineCount)} au total`}
                icon={Wallet}
                to="/mes-tontines"
              />
              <StatCard
                label="Prochaine échéance"
                value={nextDue ? formatFcfa(nextDue.contribution.amount) : '—'}
                sublabel={nextDue?.date ? `Estimée ${formatDate(nextDue.date)}` : 'Aucun versement en attente'}
                icon={CalendarClock}
                tone={nextDue ? 'accent' : 'neutral'}
                to="/mes-cotisations"
              />
              <StatCard
                label="Commandes"
                value={formatNumber(orderStats.active)}
                sublabel={
                  isLoadingOrders
                    ? 'Chargement…'
                    : orderStats.pendingDelivery
                      ? `${formatNumber(orderStats.pendingDelivery)} en attente de livraison`
                      : `${formatNumber(orderStats.total)} au total`
                }
                icon={Package}
                tone="info"
                to="/mes-achats"
              />
            </div>
          )}

          {/* Bandeau d'action prioritaire */}
          {!isLoading && (stats.pendingCount > 0 || stats.awaitingCount > 0 || stats.rejectedCount > 0) && (
            <div className="mb-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {stats.rejectedCount > 0 && (
                <ActionBanner
                  tone="danger"
                  icon={AlertCircle}
                  title={`${formatNumber(stats.rejectedCount)} paiement(s) refusé(s)`}
                  text="Le code de transfert n’a pas pu être vérifié. Soumets-en un nouveau."
                  to="/mes-cotisations"
                  cta="Reprendre le paiement"
                />
              )}
              {stats.pendingCount > 0 && (
                <ActionBanner
                  tone="warning"
                  icon={Clock}
                  title={`${formatNumber(stats.pendingCount)} versement(s) à régler`}
                  text={`Soit ${formatFcfa(stats.pendingAmount)} à payer pour valider tes tours.`}
                  to="/mes-cotisations"
                  cta="Payer maintenant"
                />
              )}
              {stats.awaitingCount > 0 && (
                <ActionBanner
                  tone="info"
                  icon={Hourglass}
                  title={`${formatNumber(stats.awaitingCount)} en vérification`}
                  text="Tes codes de transfert sont en cours de contrôle par notre équipe."
                  to="/mes-cotisations"
                  cta="Voir l'avancement"
                />
              )}
            </div>
          )}

          {/* Mes tontines */}
          <section>
            <SectionHeader
              title="Mes tontines"
              subtitle="Progression, round en cours et prochain versement de chaque tontine."
              actions={
                <Link to="/mes-tontines" className="inline-flex items-center gap-1 text-sm font-semibold text-primary-700 hover:text-primary-900">
                  Tout voir
                  <ArrowRight className="size-4" aria-hidden="true" />
                </Link>
              }
              className="mb-4"
            />

            {isLoading ? (
              <SkeletonGrid count={3} />
            ) : activeTontines.length === 0 ? (
              <EmptyState
                icon={Wallet}
                title="Vous n’avez encore rejoint aucune tontine"
                description="Découvrez les tontines ouvertes et rejoignez celle qui correspond à votre objectif."
                actionTo="/tontines"
                actionLabel="Découvrir les tontines"
              />
            ) : (
              <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {activeTontines.map((tontine) => (
                  <TontineMiniCard
                    key={tontine.id}
                    tontine={tontine}
                    nextContribution={stats.nextContribution?.tontine?.id === tontine.id ? stats.nextContribution.amount : null}
                    dueDate={
                      estimatedDueDate(tontine, tontine.current_round)
                        ? `${formatDate(estimatedDueDate(tontine, tontine.current_round))} (estimée)`
                        : null
                    }
                  />
                ))}
              </div>
            )}
          </section>

          {/* Activité récente */}
          <section className="mt-10">
            <SectionHeader
              title="Derniers versements"
              subtitle="Les cotisations les plus récentes, tous tontines confondues."
              className="mb-4"
              actions={
                <Link to="/mes-cotisations" className="inline-flex items-center gap-1 text-sm font-semibold text-primary-700 hover:text-primary-900">
                  Historique complet
                  <ArrowRight className="size-4" aria-hidden="true" />
                </Link>
              }
            />

            {isLoading ? (
              <Card>
                <div className="space-y-3" aria-hidden="true">
                  {Array.from({ length: 3 }).map((_, index) => (
                    <div key={index} className="skeleton h-12 rounded-xl" />
                  ))}
                </div>
              </Card>
            ) : contributions.length === 0 ? (
              <EmptyState
                icon={HandCoins}
                title="Aucun versement pour le moment"
                description="Rejoignez une tontine pour commencer à cotiser."
                actionTo="/tontines"
                actionLabel="Découvrir les tontines"
                compact
              />
            ) : (
              <Card padding="none">
                <ul className="divide-y divide-line-soft">
                  {contributions.slice(0, 5).map((contribution) => (
                    <li key={contribution.id} className="flex items-center gap-4 p-4">
                      <span
                        className={`flex size-10 shrink-0 items-center justify-center rounded-xl ${
                          contribution.status === 'completed'
                            ? 'bg-success-50 text-success-600'
                            : contribution.verification_status === 'pending'
                              ? 'bg-warning-50 text-warning-600'
                              : 'bg-primary-50 text-primary-600'
                        }`}
                      >
                        {contribution.status === 'completed' ? (
                          <CheckCircle2 className="size-4.5" aria-hidden="true" />
                        ) : (
                          <HandCoins className="size-4.5" aria-hidden="true" />
                        )}
                      </span>

                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold text-ink-900">
                          {contribution.tontine?.name || 'Tontine'}
                        </p>
                        <p className="text-xs text-ink-500">
                          Round {contribution.round} · {formatDate(contribution.paid_at || contribution.submitted_at)}
                        </p>
                      </div>

                      <div className="shrink-0 text-right">
                        <p className="amount text-sm">{formatFcfa(contribution.amount)}</p>
                        <ContributionStatus contribution={contribution} />
                      </div>
                    </li>
                  ))}
                </ul>
              </Card>
            )}
          </section>
        </>
      )}
    </div>
  );
}

function ContributionStatus({ contribution }) {
  if (contribution.status === 'completed' && contribution.verification_status !== 'rejected') {
    return <StatusBadge status="accepted" label="Validé" size="xs" />;
  }
  if (contribution.verification_status === 'pending') {
    return <StatusBadge status="pending" label="En vérification" size="xs" />;
  }
  if (contribution.verification_status === 'rejected') {
    return <StatusBadge status="rejected" size="xs" />;
  }
  return <StatusBadge status="pending" label="À payer" size="xs" />;
}

function ActionBanner({ tone, icon: Icon, title, text, to, cta }) {
  const tones = {
    danger: 'border-danger-200 bg-danger-50 text-danger-700',
    warning: 'border-warning-200 bg-warning-50 text-warning-700',
    info: 'border-info-200 bg-info-50 text-info-700',
  }[tone];

  return (
    <Card padding="sm" className={`flex flex-col justify-between border ${tones}`}>
      <div className="flex items-start gap-3">
        <Icon className="mt-0.5 size-5 shrink-0" aria-hidden="true" />
        <div className="min-w-0">
          <p className="text-sm font-bold">{title}</p>
          <p className="mt-1 text-xs leading-relaxed text-ink-600">{text}</p>
        </div>
      </div>
      <Link to={to} className="mt-4 inline-flex items-center gap-1.5 text-sm font-bold hover:underline">
        {cta}
        <ArrowRight className="size-4" aria-hidden="true" />
      </Link>
    </Card>
  );
}
