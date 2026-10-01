import { useEffect, useState } from 'react';
import { BadgePercent, Percent, Store, Wallet } from 'lucide-react';
import { api } from '../../api/client';
import { useToast } from '../../context/ToastContext';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { PageHeader } from '../../components/ui/PageHeader';
import { Button } from '../../components/ui/Button';
import { Card } from '../../components/ui/Card';
import { Field, Input } from '../../components/ui/Input';
import { Alert } from '../../components/ui/Alert';
import { StatCard } from '../../components/ui/StatCard';
import { SkeletonStats } from '../../components/ui/Skeleton';
import { ErrorState } from '../../components/ui/EmptyState';
import { EmptyState } from '../../components/ui/EmptyState';
import { ProgressBar } from '../../components/ui/Progress';
import { formatFcfa, formatNumber } from '../../utils/format';

/**
 * Administration — commissions.
 * GET  /admin/commissions        → agrégats par commerçant + tontines argent
 * POST /admin/commissions/rate   → nouveau taux (0-100), appliqué aux
 *                                 prochains versements uniquement.
 */
function AdminCommissions() {
  const toast = useToast();
  useDocumentTitle('Commissions');

  const [data, setData] = useState(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);
  const [ratePercent, setRatePercent] = useState('');
  const [isSaving, setIsSaving] = useState(false);

  const load = async () => {
    setIsLoading(true);
    setError(null);
    try {
      const payload = await api.get('/admin/commissions');
      setData(payload);
      setRatePercent((Number(payload.current_rate) * 100).toFixed(2));
    } catch (err) {
      setError(err);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    load();
  }, []);

  const handleSubmit = async (event) => {
    event.preventDefault();
    setIsSaving(true);
    try {
      const response = await api.post('/admin/commissions/rate', { rate_percent: Number(ratePercent) });
      toast.success(response?.message || 'Taux de commission mis à jour.');
      await load();
    } catch (err) {
      toast.error(err.message);
    } finally {
      setIsSaving(false);
    }
  };

  const maxCommission = Math.max(1, ...(data?.merchants || []).map((row) => Number(row.total_commission || 0)));

  return (
    <div>
      <PageHeader
        title="Commissions"
        subtitle="Revenus de la plateforme sur chaque versement encaissé."
        breadcrumb={[{ label: 'Administration', to: '/admin' }, { label: 'Commissions' }]}
      />

      {error ? (
        <ErrorState message={error.message} onRetry={load} />
      ) : isLoading || !data ? (
        <SkeletonStats count={4} />
      ) : (
        <div className="space-y-6">
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
              label="Commission totale"
              value={formatFcfa(data.total_commission)}
              sublabel={`Taux actuel : ${(Number(data.current_rate) * 100).toFixed(2)} %`}
              icon={BadgePercent}
              tone="accent"
            />
            <StatCard label="Montant total collecté" value={formatFcfa(data.total_collected)} icon={Wallet} tone="brand" />
            <StatCard
              label="Cotisations de tontines"
              value={formatFcfa(data.contributions.commission)}
              sublabel={`${formatNumber(data.contributions.count)} versement(s)`}
            />
            <StatCard
              label="Tranches d'achats"
              value={formatFcfa(data.installments.commission)}
              sublabel={`${formatNumber(data.installments.count)} tranche(s)`}
            />
          </div>

          {/* Réglage du taux */}
          <Card>
            <div className="flex flex-wrap items-start justify-between gap-6">
              <div className="max-w-md">
                <h3 className="font-display text-base font-bold text-ink-900">Taux de commission</h3>
                <p className="mt-1.5 text-sm leading-relaxed text-ink-500">
                  Le taux s’applique uniquement aux <strong>prochains</strong> versements. Les montants ci-dessous
                  utilisent le taux gelé sur chaque transaction : modifier le taux ne réécrit pas l’historique.
                </p>
              </div>

              <form onSubmit={handleSubmit} className="flex items-end gap-3">
                <Field label="Nouveau taux (%)" htmlFor="commission-rate" className="w-40">
                  <Input
                    id="commission-rate"
                    type="number"
                    step="0.01"
                    min="0"
                    max="100"
                    value={ratePercent}
                    onChange={(event) => setRatePercent(event.target.value)}
                    required
                  />
                </Field>
                <Button type="submit" icon={Percent} loading={isSaving}>
                  Mettre à jour
                </Button>
              </form>
            </div>
          </Card>

          {/* Détail par commerçant */}
          <section>
            <div className="mb-4 flex items-center justify-between gap-3">
              <h3 className="font-display text-lg font-bold text-ink-900">Commissions par commerçant</h3>
              <span className="text-xs text-ink-500">
                {formatNumber(data.merchants.length)} commerçant(s) concerné(s)
              </span>
            </div>

            {data.merchants.length === 0 ? (
              <EmptyState
                icon={Store}
                title="Aucune commission enregistrée"
                description="Les commissions apparaissent dès qu’un commerçant encaisse un premier versement."
                compact
              />
            ) : (
              <ul className="space-y-3">
                {data.merchants.map((row) => (
                  <li key={row.merchant.id}>
                    <Card padding="sm" className="hover:border-primary-200">
                      <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
                        <div className="min-w-0 flex-1">
                          <p className="truncate font-semibold text-ink-900">{row.merchant.business_name}</p>
                          <p className="mt-0.5 text-xs text-ink-500">
                            {formatNumber(row.contributions_count)} cotisation(s) ·{' '}
                            {formatNumber(row.installments_count)} tranche(s) ·{' '}
                            <span className="amount">{formatFcfa(row.total_collected)}</span> encaissés
                          </p>
                          <div className="mt-2.5 max-w-xs">
                            <ProgressBar
                              value={Number(row.total_commission || 0)}
                              total={maxCommission}
                              size="sm"
                              tone="accent"
                              label={`Commission de ${row.merchant.business_name}`}
                            />
                          </div>
                        </div>
                        <p className="amount shrink-0 text-lg text-accent-700">{formatFcfa(row.total_commission)}</p>
                      </div>
                    </Card>
                  </li>
                ))}
              </ul>
            )}
          </section>

          {/* Tontines argent */}
          <Alert type="info" title="Tontines argent">
            Les cotisations de tontines argent n’appartiennent à aucun commerçant :{' '}
            <strong>{formatNumber(data.cash_stats.contributions_count)}</strong> versement(s) pour{' '}
            <strong>{formatFcfa(data.cash_stats.total_collected)}</strong> collectés et{' '}
            <strong>{formatFcfa(data.cash_stats.total_commission)}</strong> de commission.
          </Alert>
        </div>
      )}
    </div>
  );
}

export default AdminCommissions;
