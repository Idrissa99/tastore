import { useCallback, useEffect, useState } from 'react';
import { Download, Filter, FileSpreadsheet } from 'lucide-react';
import { api, API_BASE_URL, ApiError, getToken } from '../../api/client';
import { useToast } from '../../context/ToastContext';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { PageHeader } from '../../components/ui/PageHeader';
import { Button } from '../../components/ui/Button';
import { Field, Input } from '../../components/ui/Input';
import { Alert } from '../../components/ui/Alert';
import { Card } from '../../components/ui/Card';
import { SkeletonStats } from '../../components/ui/Skeleton';
import { ErrorState } from '../../components/ui/EmptyState';
import { StatCard } from '../../components/ui/StatCard';
import { formatFcfa, formatNumber } from '../../utils/format';

/**
 * Administration — rapports financiers.
 * GET /admin/reports?from&to  (défauts : début du mois → aujourd'hui)
 * GET /admin/reports/export?from&to → CSV streamé.
 *
 * L'export passe volontairement par un fetch brut : la réponse est un fichier,
 * pas du JSON, et le client `api` ne peut pas le traiter.
 */
function AdminReports() {
  const toast = useToast();
  useDocumentTitle('Rapports');

  const [range, setRange] = useState({ from: '', to: '' });
  const [stats, setStats] = useState(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);
  const [isExporting, setIsExporting] = useState(false);

  const load = useCallback(async (params) => {
    setIsLoading(true);
    setError(null);
    try {
      const data = await api.get('/admin/reports', params);
      setStats(data);
      setRange({ from: data.from, to: data.to });
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError('Rapport indisponible.', 0));
    } finally {
      setIsLoading(false);
    }
  }, []);

  // Sans paramètres, l'API renvoie la période par défaut (début du mois → aujourd'hui).
  useEffect(() => {
    load();
  }, [load]);

  const handleFilter = (event) => {
    event.preventDefault();
    load(range);
  };

  const handleExport = async () => {
    setIsExporting(true);
    try {
      const params = new URLSearchParams(range);
      const token = getToken();
      const response = await fetch(`${API_BASE_URL}/admin/reports/export?${params.toString()}`, {
        headers: {
          Accept: 'text/csv',
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
      });

      if (!response.ok) {
        const data = await response.json().catch(() => null);
        throw new ApiError(data?.message || 'Export impossible.', response.status);
      }

      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `rapport-${range.from}-au-${range.to}.csv`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
      toast.success('Export CSV téléchargé.');
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : 'Export impossible.');
    } finally {
      setIsExporting(false);
    }
  };

  return (
    <div>
      <PageHeader
        title="Rapports financiers"
        subtitle="Encaissements et commissions sur une période, tontines et achats par tranches distingués."
        breadcrumb={[{ label: 'Administration', to: '/admin' }, { label: 'Rapports' }]}
        actions={
          <Button variant="secondary" icon={Download} loading={isExporting} onClick={handleExport} disabled={!stats}>
            Exporter en CSV
          </Button>
        }
      />

      <form onSubmit={handleFilter} className="mb-6 flex flex-wrap items-end gap-3 rounded-2xl border border-line-strong bg-white p-5 shadow-sm">
        <Field label="Du" htmlFor="report-from">
          <Input id="report-from" type="date" value={range.from} onChange={(event) => setRange((r) => ({ ...r, from: event.target.value }))} className="sm:w-44" />
        </Field>
        <Field label="Au" htmlFor="report-to">
          <Input id="report-to" type="date" value={range.to} onChange={(event) => setRange((r) => ({ ...r, to: event.target.value }))} className="sm:w-44" />
        </Field>
        <Button type="submit" icon={Filter} loading={isLoading} className="sm:mb-0">
          Filtrer
        </Button>
        {stats && (
          <p className="text-xs text-ink-500 sm:mb-3 sm:ml-2">
            Période analysée : <span className="font-semibold text-ink-700">{stats.from}</span> →{' '}
            <span className="font-semibold text-ink-700">{stats.to}</span>
          </p>
        )}
      </form>

      {error ? (
        <ErrorState message={error.message} onRetry={() => load(range)} />
      ) : isLoading || !stats ? (
        <SkeletonStats count={4} />
      ) : (
        <div className="space-y-6">
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Montant encaissé" value={formatFcfa(stats.totals.collected)} icon={FileSpreadsheet} tone="brand" />
            <StatCard label="Commissions" value={formatFcfa(stats.totals.commission)} icon={Download} tone="accent" />
            <StatCard label="Nombre d'encaissements" value={formatNumber(stats.totals.count)} icon={Filter} />
            <StatCard
              label="Remboursements en attente"
              value={formatFcfa(stats.refunds.pending_amount)}
              sublabel={`${formatNumber(stats.refunds.pending_count)} demande(s)`}
              tone={stats.refunds.pending_count ? 'warning' : 'neutral'}
            />
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <h3 className="font-display text-base font-bold text-ink-900">Cotisations de tontines</h3>
              <dl className="mt-4 space-y-3">
                <Line label="Versements" value={formatNumber(stats.contributions.count)} />
                <Line label="Encaissé" value={formatFcfa(stats.contributions.collected)} strong />
                <Line label="Commissions" value={formatFcfa(stats.contributions.commission)} />
              </dl>
            </Card>

            <Card>
              <h3 className="font-display text-base font-bold text-ink-900">Tranches d'achats</h3>
              <dl className="mt-4 space-y-3">
                <Line label="Tranches" value={formatNumber(stats.installments.count)} />
                <Line label="Encaissé" value={formatFcfa(stats.installments.collected)} strong />
                <Line label="Commissions" value={formatFcfa(stats.installments.commission)} />
              </dl>
            </Card>
          </div>

          <Alert type="info" title="Comment lire ce rapport">
            Les cotisations de tontines et les tranches d’achats sont comptées séparément ; le total est leur
            somme explicite. Les commissions utilisent le taux <strong>gelé sur chaque transaction</strong>, jamais
            le taux global actuel — modifier le taux ne réécrit donc pas l’historique.
          </Alert>

          <Card>
            <h3 className="font-display text-base font-bold text-ink-900">Remboursements de la période</h3>
            <dl className="mt-4 grid gap-4 sm:grid-cols-2">
              <div className="rounded-xl bg-warning-50/70 px-4 py-3">
                <dt className="text-xs font-semibold uppercase tracking-wide text-warning-700">En attente</dt>
                <dd className="amount mt-1 text-lg text-warning-700">
                  {formatFcfa(stats.refunds.pending_amount)}
                  <span className="ml-2 text-xs font-medium text-ink-500">
                    ({formatNumber(stats.refunds.pending_count)})
                  </span>
                </dd>
              </div>
              <div className="rounded-xl bg-success-50/70 px-4 py-3">
                <dt className="text-xs font-semibold uppercase tracking-wide text-success-700">Traités</dt>
                <dd className="amount mt-1 text-lg text-success-700">
                  {formatFcfa(stats.refunds.processed_amount)}
                  <span className="ml-2 text-xs font-medium text-ink-500">
                    ({formatNumber(stats.refunds.processed_count)})
                  </span>
                </dd>
              </div>
            </dl>
          </Card>
        </div>
      )}
    </div>
  );
}

function Line({ label, value, strong = false }) {
  return (
    <div className="flex items-center justify-between gap-4 border-b border-line-soft pb-2.5 last:border-0 last:pb-0">
      <dt className="text-sm text-ink-500">{label}</dt>
      <dd className={strong ? 'amount text-base' : 'amount text-sm'}>{value}</dd>
    </div>
  );
}

export default AdminReports;
