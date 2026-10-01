import { useState } from 'react';
import { CircleCheck, RotateCcw } from 'lucide-react';
import { api } from '../../api/client';
import { useToast } from '../../context/ToastContext';
import { useApi } from '../../hooks/useApi';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { PageHeader } from '../../components/ui/PageHeader';
import { Button } from '../../components/ui/Button';
import { Alert } from '../../components/ui/Alert';
import { StatusBadge } from '../../components/ui/Badge';
import { DataTable } from '../../components/ui/Table';
import { Pagination, normalizePagination } from '../../components/ui/Pagination';
import { ConfirmationDialog } from '../../components/ui/ConfirmationDialog';
import { EmptyState, ErrorState } from '../../components/ui/EmptyState';
import { formatDate, formatFcfa } from '../../utils/format';

const METHOD_LABELS = { manual: 'Règlement manuel hors plateforme' };

/**
 * Administration — remboursements.
 *
 * LIMITATION CONNUE ET VOLONTAIRE : aucun fournisseur Mobile Money réel
 * n'est branché. Marquer un remboursement « traité » déclare un règlement
 * effectué manuellement ; l'application ne déclenche aucun transfert.
 * Ce message est affiché à l'administrateur pour qu'il ne puisse pas
 * croire qu'un virement automatique est parti.
 */
function AdminRefunds() {
  const toast = useToast();
  useDocumentTitle('Remboursements');

  const [status, setStatus] = useState('pending');
  const [page, setPage] = useState(1);
  const [busyId, setBusyId] = useState(null);
  const [confirm, setConfirm] = useState(null);

  const { data, isLoading, error, reload } = useApi(
    () => api.get('/admin/refunds', { page, status: status || undefined }),
    [page, status],
  );

  const refunds = data?.data || [];
  const pagination = normalizePagination(data);

  const totals = {
    shown: refunds.length,
    pending: refunds.filter((item) => item.status === 'pending').length,
    pendingAmount: refunds
      .filter((item) => item.status === 'pending')
      .reduce((sum, item) => sum + Number(item.amount || 0), 0),
  };

  const process = async (target) => {
    setBusyId(target.id);
    try {
      await api.post(`/admin/refunds/${target.id}/process`);
      toast.success('Remboursement marqué comme traité.');
      await reload();
    } catch (err) {
      toast.error(err.message);
    } finally {
      setBusyId(null);
      setConfirm(null);
    }
  };

  const columns = [
    {
      key: 'beneficiary',
      header: 'Bénéficiaire',
      render: (row) => (
        <div className="min-w-0">
          <p className="truncate font-semibold text-ink-900">{row.user?.name || '—'}</p>
          <p className="truncate text-xs text-ink-500">{row.user?.email}</p>
        </div>
      ),
      mobileClassName: 'text-left',
    },
    {
      key: 'tontine',
      header: 'Tontine',
      hideOnMobile: true,
      render: (row) => <span className="text-ink-600">{row.tontine?.name || '—'}</span>,
    },
    { key: 'amount', header: 'Montant', align: 'right', render: (row) => <span className="amount">{formatFcfa(row.amount)}</span> },
    {
      key: 'method',
      header: 'Mode',
      hideOnMobile: true,
      render: (row) => (
        <div className="min-w-0">
          <p className="text-ink-600">{METHOD_LABELS[row.method] || row.method}</p>
          {row.payment_reference && <p className="truncate font-mono text-xs text-ink-400">{row.payment_reference}</p>}
        </div>
      ),
    },
    { key: 'created', header: 'Créé le', hideOnMobile: true, render: (row) => <span className="text-ink-600">{formatDate(row.created_at)}</span> },
    {
      key: 'status',
      header: 'Statut',
      render: (row) => <StatusBadge status={row.status} size="sm" dot />,
    },
    {
      key: 'actions',
      header: <span className="sr-only">Actions</span>,
      align: 'right',
      mobileLabel: false,
      render: (row) =>
        row.status === 'pending' ? (
          <Button variant="success" size="xs" icon={CircleCheck} onClick={() => setConfirm({ target: row })}>
            Marquer traité
          </Button>
        ) : (
          <span className="text-xs text-ink-400">Traité {formatDate(row.processed_at)}</span>
        ),
    },
  ];

  return (
    <div>
      <PageHeader
        title="Remboursements"
        subtitle="Demandes de restitution de cotisations, à régler hors plateforme."
        breadcrumb={[{ label: 'Administration', to: '/admin' }, { label: 'Remboursements' }]}
      />

      <Alert type="warning" title="Aucun transfert automatique" className="mb-6">
        Aucun fournisseur Mobile Money réel n’est connecté. Marquer un remboursement « traité » déclare un
        règlement effectué <strong>manuellement hors plateforme</strong> : l’application ne déclenche aucun
        transfert d’argent.
      </Alert>

      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        <StatBox label="Sur cette page" value={refunds.length} />
        <StatBox label="En attente" value={totals.pending} tone={totals.pending ? 'warning' : 'default'} />
        <StatBox label="Montant en attente" value={formatFcfa(totals.pendingAmount)} />
      </div>

      <div className="mb-5 flex flex-wrap gap-1 rounded-xl bg-ink-100 p-1 sm:w-fit">
        {[
          { value: 'pending', label: 'En attente' },
          { value: 'processed', label: 'Traités' },
          { value: '', label: 'Tous' },
        ].map((option) => (
          <button
            key={option.value || 'all'}
            type="button"
            onClick={() => { setStatus(option.value); setPage(1); }}
            className={`rounded-lg px-3.5 py-2 text-sm font-semibold transition-all ${
              status === option.value ? 'bg-white text-ink-900 shadow-sm' : 'text-ink-500 hover:text-ink-800'
            }`}
          >
            {option.label}
          </button>
        ))}
      </div>

      {error ? (
        <ErrorState message={error.message} onRetry={reload} />
      ) : (
        <DataTable
          columns={columns}
          rows={refunds}
          loading={isLoading}
          empty={
            <EmptyState
              icon={RotateCcw}
              title="Aucun remboursement"
              description="Aucune demande de remboursement à traiter avec ces filtres."
              compact
            />
          }
        />
      )}

      <Pagination meta={pagination} onPageChange={setPage} className="mt-6" itemLabel="remboursements" />

      <ConfirmationDialog
        open={Boolean(confirm)}
        onClose={() => setConfirm(null)}
        onConfirm={() => process(confirm.target)}
        title="Confirmer le remboursement ?"
        message={
          <>
            Tu déclares avoir effectué le règlement de{' '}
            <strong>{formatFcfa(confirm?.target?.amount)}</strong> à {confirm?.target?.user?.name} en dehors de
            la plateforme. Cette action est définitive.
          </>
        }
        confirmLabel="J’ai effectué le règlement"
        tone="primary"
        loading={busyId !== null}
      />
    </div>
  );
}

function StatBox({ label, value, tone = 'default' }) {
  const tones = { default: 'text-ink-900', warning: 'text-warning-700', danger: 'text-danger-600' };
  return (
    <div className="rounded-2xl border border-line-strong bg-white p-5 shadow-sm">
      <p className="text-xs font-semibold uppercase tracking-wide text-ink-500">{label}</p>
      <p className={`amount mt-2.5 text-2xl ${tones[tone] || tones.default}`}>{value}</p>
    </div>
  );
}

export default AdminRefunds;