import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Ban, Trash2, Users, Wallet } from 'lucide-react';
import { api } from '../../api/client';
import { useApi } from '../../hooks/useApi';
import { useToast } from '../../context/ToastContext';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { PageHeader } from '../../components/ui/PageHeader';
import { Card } from '../../components/ui/Card';
import { Button } from '../../components/ui/Button';
import { Select } from '../../components/ui/Input';
import { StatusBadge } from '../../components/ui/Badge';
import { DataTable } from '../../components/ui/Table';
import { ConfirmationDialog } from '../../components/ui/ConfirmationDialog';
import { EmptyState, ErrorState } from '../../components/ui/EmptyState';
import { Pagination, normalizePagination } from '../../components/ui/Pagination';
import { formatDate, formatFcfa, formatNumber } from '../../utils/format';
import { tontineTypeLabel } from '../../utils/status';

/**
 * Administration — toutes les tontines.
 * GET /admin/tontines?status=  (paginé)
 * POST   /admin/tontines/{id}/cancel   annulation (reversible, déclenche les remboursements)
 * DELETE /admin/tontines/{id}          suppression DÉFINITIVE, refusée si de l'argent a été encaissé
 */
export default function AdminTontines() {
  useDocumentTitle('Tontines');
  const toast = useToast();

  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [busyId, setBusyId] = useState(null);
  const [confirm, setConfirm] = useState(null);

  const { data, isLoading, error, reload } = useApi(
    () => api.get('/admin/tontines', { page, status: status || undefined }),
    [page, status],
  );

  const tontines = data?.data || [];
  const pagination = normalizePagination(data);

  const totals = {
    shown: tontines.length,
    cash: tontines.filter((row) => row.type === 'cash').length,
    product: tontines.filter((row) => row.type !== 'cash').length,
  };

  const destroy = async (target) => {
    setBusyId(target.id);
    try {
      const response = await api.delete(`/admin/tontines/${target.id}`);
      toast.success(response?.message || `« ${target.name} » a été supprimée.`);
      await reload();
    } catch (err) {
      // Le 409 remonte le motif exact du refus (cotisations encaissées,
      // litiges…) : on l'affiche tel quel, l'admin sait quoi faire ensuite.
      toast.error(err.message);
    } finally {
      setBusyId(null);
      setConfirm(null);
    }
  };

  const cancel = async (target) => {
    setBusyId(target.id);
    try {
      const response = await api.post(`/admin/tontines/${target.id}/cancel`);
      toast.success(response?.message || `« ${target.name} » a été annulée.`);
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
      key: 'name',
      header: 'Tontine',
      render: (row) => (
        <div className="min-w-0">
          <Link to={`/tontines/${row.id}`} className="truncate font-semibold text-ink-900 hover:text-primary-700 hover:underline">
            {row.name}
          </Link>
          <p className="truncate text-xs text-ink-500">
            {tontineTypeLabel(row.type)}
            {row.product?.name ? ` · ${row.product.name}` : ''}
          </p>
        </div>
      ),
      mobileClassName: 'text-left',
    },
    {
      key: 'members',
      header: 'Membres',
      hideOnMobile: true,
      render: (row) => (
        <span className="inline-flex items-center gap-1.5 text-ink-600">
          <Users className="size-3.5 text-ink-400" aria-hidden="true" />
          {row.current_members} / {row.max_members}
        </span>
      ),
    },
    { key: 'round', header: 'Round', align: 'right', hideOnMobile: true, render: (row) => <span className="amount text-sm">{row.current_round}</span> },
    {
      key: 'amount',
      header: 'Montant cible',
      align: 'right',
      render: (row) => <span className="amount text-sm">{formatFcfa(row.total_amount)}</span>,
    },
    { key: 'created', header: 'Créée le', hideOnMobile: true, render: (row) => <span className="text-ink-600">{formatDate(row.created_at)}</span> },
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
      render: (row) => (
        <div className="flex items-center justify-end gap-2">
          {/* Une tontine terminée a nécessairement encaissé de l'argent : le
              serveur refuserait toujours la suppression (409), on ne propose
              donc pas un bouton qui échouerait à tous les coups. */}
          {row.status !== 'completed' && (
            <Button
              variant="ghost"
              size="xs"
              icon={Trash2}
              loading={busyId === row.id}
              onClick={() => setConfirm({ mode: 'delete', target: row })}
            >
              Supprimer
            </Button>
          )}

          {['completed', 'cancelled'].includes(row.status) ? (
            <span className="text-xs text-ink-400">—</span>
          ) : (
            <Button
              variant="danger-outline"
              size="xs"
              icon={Ban}
              loading={busyId === row.id}
              onClick={() => setConfirm({ mode: 'cancel', target: row })}
            >
              Annuler
            </Button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <PageHeader
        title="Tontines"
        subtitle="Supervise toutes les tontines : annule celles qui posent problème, supprime celles qui n'ont jamais démarré."
        breadcrumb={[{ label: 'Administration', to: '/admin' }, { label: 'Tontines' }]}
        actions={
          <Select
            value={status}
            onChange={(event) => { setStatus(event.target.value); setPage(1); }}
            className="w-48"
            aria-label="Filtrer par statut"
          >
            <option value="">Tous les statuts</option>
            <option value="open">Ouvertes</option>
            <option value="active">En cours</option>
            <option value="completed">Terminées</option>
            <option value="cancelled">Annulées</option>
          </Select>
        }
      />

      {!isLoading && tontines.length > 0 && (
        <div className="mb-6 grid gap-4 sm:grid-cols-3">
          <Tile label="Sur cette page" value={formatNumber(totals.shown)} icon={Wallet} />
          <Tile label="Tontines argent" value={formatNumber(totals.cash)} />
          <Tile label="Tontines produit" value={formatNumber(totals.product)} />
        </div>
      )}

      {error ? (
        <ErrorState message={error.message} onRetry={reload} />
      ) : (
        <DataTable
          columns={columns}
          rows={tontines}
          loading={isLoading}
          empty={<EmptyState icon={Wallet} title="Aucune tontine" description="Aucune tontine ne correspond à ce filtre." compact />}
        />
      )}

      <Pagination meta={pagination} onPageChange={setPage} className="mt-6" itemLabel="tontines" />

      <ConfirmationDialog
        open={Boolean(confirm)}
        onClose={() => setConfirm(null)}
        onConfirm={() => (confirm?.mode === 'delete' ? destroy(confirm.target) : cancel(confirm.target))}
        title={confirm?.mode === 'delete' ? 'Supprimer définitivement ?' : 'Annuler cette tontine ?'}
        message={
          confirm?.mode === 'delete' ? (
            <>
              <strong>{confirm?.target?.name}</strong> et tout son historique (membres, cotisations, litiges)
              seront supprimés <strong>définitivement</strong>. Action irréversible : si la tontine a encaissé
              de l&apos;argent, le serveur refusera — annule-la plutôt.
            </>
          ) : (
            <>
              <strong>{confirm?.target?.name}</strong> sera annulée et ne pourra plus recevoir de membres ni de
              cotisations. Les membres devront être remboursés manuellement.
            </>
          )
        }
        confirmLabel={confirm?.mode === 'delete' ? 'Supprimer définitivement' : 'Annuler la tontine'}
        loading={busyId !== null}
      />
    </div>
  );
}

function Tile({ label, value, icon: Icon }) {
  return (
    <Card padding="sm">
      <div className="flex items-center gap-3">
        {Icon && (
          <span className="flex size-10 items-center justify-center rounded-xl bg-primary-50 text-primary-700">
            <Icon className="size-4.5" aria-hidden="true" />
          </span>
        )}
        <div className="min-w-0">
          <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-500">{label}</p>
          <p className="amount mt-0.5 text-xl">{value}</p>
        </div>
      </div>
    </Card>
  );
}
