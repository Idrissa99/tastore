import { Link } from 'react-router-dom';
import {
  ArrowRight,
  BadgePercent,
  CreditCard,
  RefreshCcw,
  Scale,
  ShoppingBag,
  Store,
  Users,
  Wallet,
} from 'lucide-react';
import { api } from '../../api/client';
import { useApi } from '../../hooks/useApi';
import { useAdminCounters } from '../../context/AdminCountersContext';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { PageHeader } from '../../components/ui/PageHeader';
import { StatCard } from '../../components/ui/StatCard';
import { Card } from '../../components/ui/Card';
import { ErrorState } from '../../components/ui/EmptyState';
import { SkeletonStats } from '../../components/ui/Skeleton';
import { formatFcfa, formatNumber } from '../../utils/format';

/**
 * Tableau de bord administrateur — GET /admin/dashboard.
 *
 * Note : le backend ne fournit pas de compteur de « toutes les contributions »
 * (GET /contributions ne renvoie que celles de l'utilisateur connecté). Le
 * nombre de paiements en attente est donc recoveries via GET /admin/payments,
 * qui est de toute façon la file de travail prioritaire de l'administrateur.
 */
export default function AdminDashboard() {
  useDocumentTitle('Administration');
  const { data: stats, isLoading, error, reload } = useApi(() => api.get('/admin/dashboard'), []);
  const { pendingPayments } = useAdminCounters();

  const pendingPaymentsCount = pendingPayments;

  // L'API renvoie les deux sources séparément et des totaux à plat
  // (total_collected / total_commissions) : le nombre d'encaissements
  // n'est pas exposé, on le reconstitue comme somme explicite.
  // Le contrôleur admin renvoie les deux sources séparément et des totaux
  // À PLAT (total_collected / total_commissions) : il n'existe pas de clé
  // « totals ». Toute lecture est défensive pour qu'une évolution du
  // contrat n'entraîne jamais un écran blanc.
  const contributions = stats?.contributions ?? {};
  const installments = stats?.installments ?? {};
  const totalCount = (contributions.count ?? 0) + (installments.count ?? 0);

  const quickLinks = [
    { to: '/admin/paiements', label: 'Vérifier les paiements', icon: CreditCard, count: pendingPaymentsCount, tone: 'warning' },
    { to: '/admin/utilisateurs', label: 'Gérer les utilisateurs', icon: Users },
    { to: '/admin/commercants', label: 'Valider les commerçants', icon: Store, count: stats?.pending_merchants },
    { to: '/admin/tontines', label: 'Superviser les tontines', icon: Wallet },
    { to: '/admin/litiges', label: 'Traiter les litiges', icon: Scale, count: stats?.open_disputes },
    { to: '/admin/remboursements', label: 'Remboursements', icon: RefreshCcw, count: stats?.pending_refunds },
    { to: '/admin/produits', label: 'Modérer les produits', icon: ShoppingBag },
    { to: '/admin/commissions', label: 'Commissions', icon: BadgePercent },
  ];

  return (
    <div>
      <PageHeader
        title="Administration"
        subtitle="Pilotage de la plateforme : encaissements, comptes à valider et files de travail."
        breadcrumb={[{ label: 'Administration' }]}
      />

      {error ? (
        <ErrorState message={error.message} onRetry={reload} />
      ) : (
        <>
          {isLoading || !stats ? (
            <SkeletonStats count={4} className="mb-8" />
          ) : (
            <>
              <div className="mb-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                  label="Utilisateurs"
                  value={formatNumber(stats.total_users)}
                  sublabel={`${formatNumber(stats.total_merchants)} commerçants`}
                  icon={Users}
                  to="/admin/utilisateurs"
                />
                <StatCard
                  label="Tontines actives"
                  value={formatNumber(stats.active_tontines)}
                  sublabel={`${formatNumber(stats.open_tontines)} ouvertes · ${formatNumber(stats.completed_tontines)} terminées`}
                  icon={Wallet}
                  tone="brand"
                  to="/admin/tontines"
                />
                <StatCard
                  label="Volume encaissé"
                  value={formatFcfa(stats.total_collected)}
                  sublabel={`${formatNumber(totalCount)} encaissement(s)`}
                  icon={CreditCard}
                  tone="info"
                  to="/admin/rapports"
                />
                <StatCard
                  label="Commissions"
                  value={formatFcfa(stats.total_commissions)}
                  sublabel="Taux gelé par transaction"
                  icon={BadgePercent}
                  tone="accent"
                  to="/admin/commissions"
                />
              </div>

              {/* Files de travail prioritaires */}
              <div className="mb-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <StatCard
                  label="Paiements en attente"
                  value={formatNumber(pendingPaymentsCount)}
                  sublabel={pendingPaymentsCount ? 'Vérification manuelle requise' : 'Aucun transfert à contrôler'}
                  icon={CreditCard}
                  tone={pendingPaymentsCount ? 'danger' : 'success'}
                  to="/admin/paiements"
                />
                <StatCard
                  label="Litiges ouverts"
                  value={formatNumber(stats.open_disputes)}
                  sublabel={stats.open_disputes ? 'Dossiers à traiter' : 'Aucun litige en cours'}
                  icon={Scale}
                  tone={stats.open_disputes ? 'warning' : 'success'}
                  to="/admin/litiges"
                />
                <StatCard
                  label="Remboursements en attente"
                  value={formatFcfa(stats.pending_refunds_amount)}
                  sublabel={`${formatNumber(stats.pending_refunds)} dossier(s)`}
                  icon={RefreshCcw}
                  tone={stats.pending_refunds ? 'warning' : 'success'}
                  to="/admin/remboursements"
                />
              </div>

              {/* Encaissements */}
              <section>
                <h2 className="font-display text-lg font-bold text-ink-900">Encaissements</h2>
                <p className="mt-1 text-sm text-ink-500">
                  Le taux de commission est figé sur chaque transaction : modifier le taux global ne réécrit pas
                  l’historique.
                </p>

                <div className="mt-4 grid gap-4 lg:grid-cols-3">
                  <MoneyCard
                    title="Cotisations de tontines"
                    count={contributions.count}
                    collected={contributions.collected}
                    commission={contributions.commission}
                    to="/admin/rapports"
                  />
                  <MoneyCard
                    title="Tranches d'achats"
                    count={installments.count}
                    collected={installments.collected}
                    commission={installments.commission}
                    to="/admin/rapports"
                  />
                  <MoneyCard
                    title="Total plateforme"
                    count={totalCount}
                    collected={stats.total_collected}
                    commission={stats.total_commissions}
                    emphasis
                  />
                </div>
              </section>

              {/* Raccourcis */}
              <section className="mt-10">
                <h2 className="font-display text-lg font-bold text-ink-900">Accès rapides</h2>
                <ul className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                  {quickLinks.map((link) => (
                    <li key={link.to}>
                      <Card padding="sm" hover className="flex h-full items-center gap-3">
                        <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-ink-100 text-ink-600">
                          <link.icon className="size-4.5" aria-hidden="true" />
                        </span>
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm font-semibold text-ink-900">{link.label}</p>
                          {link.count ? (
                            <p
                              className={`mt-0.5 inline-flex items-center gap-1 text-[11px] font-bold ${
                                link.tone === 'warning' ? 'text-warning-700' : 'text-danger-600'
                              }`}
                            >
                              {formatNumber(link.count)} à traiter
                            </p>
                          ) : (
                            <p className="mt-0.5 text-[11px] text-ink-400">Rien à signaler</p>
                          )}
                        </div>
                        <ArrowRight className="size-4 shrink-0 text-ink-300" aria-hidden="true" />
                      </Card>
                    </li>
                  ))}
                </ul>
              </section>
            </>
          )}
        </>
      )}
    </div>
  );
}

function MoneyCard({ title, count, collected, commission, to, emphasis = false }) {
  const body = (
    <>
      <div className="flex items-center justify-between gap-3">
        <p className="font-display text-sm font-bold text-ink-900">{title}</p>
        {emphasis && <span className="size-2 rounded-full bg-accent-500" aria-hidden="true" />}
      </div>

      <p className="amount mt-3 text-2xl text-primary-800">{formatFcfa(collected)}</p>
      <p className="mt-1 text-xs text-ink-500">{formatNumber(count)} encaissement(s)</p>

      <dl className="mt-4 space-y-1.5 border-t border-line-soft pt-3 text-xs">
        <div className="flex justify-between">
          <dt className="text-ink-500">Commission</dt>
          <dd className="amount text-ink-800">{formatFcfa(commission)}</dd>
        </div>
      </dl>
    </>
  );

  if (to) {
    return (
      <Link
        to={to}
        className={`block rounded-2xl border bg-white p-5 shadow-sm transition-all duration-300 hover:-translate-y-0.5 hover:shadow-md ${
          emphasis ? 'border-accent-200' : 'border-line-strong hover:border-primary-300'
        }`}
      >
        {body}
      </Link>
    );
  }

  return (
    <div className={`rounded-2xl border bg-white p-5 shadow-sm ${emphasis ? 'border-accent-200' : 'border-line-strong'}`}>{body}</div>
  );
}
