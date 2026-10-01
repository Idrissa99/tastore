import { useState } from 'react';
import { Archive, Package, Trash2 } from 'lucide-react';
import { api } from '../../api/client';
import { useToast } from '../../context/ToastContext';
import { useApi } from '../../hooks/useApi';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { PageHeader } from '../../components/ui/PageHeader';
import { Button } from '../../components/ui/Button';
import { SearchInput, Select } from '../../components/ui/Input';
import { StatusBadge } from '../../components/ui/Badge';
import { ProductImage, productImage } from '../../components/ui/ProductImage';
import { DataTable } from '../../components/ui/Table';
import { Pagination, normalizePagination } from '../../components/ui/Pagination';
import { ConfirmationDialog } from '../../components/ui/ConfirmationDialog';
import { EmptyState, ErrorState } from '../../components/ui/EmptyState';
import { formatFcfa } from '../../utils/format';

/**
 * Administration — catalogue produits toutes boutiques confondues.
 * GET /admin/products accepte status et q, et pagine (20 par page).
 */
function AdminProducts() {
  const toast = useToast();
  useDocumentTitle('Produits');

  const [query, setQuery] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [busyId, setBusyId] = useState(null);
  const [confirm, setConfirm] = useState(null);

  const { data, isLoading, error, reload } = useApi(
    () => api.get('/admin/products', { page, status: status || undefined, q: query || undefined }),
    [page, status, query],
  );

  const products = data?.data || [];
  const pagination = normalizePagination(data);

  const totals = {
    shown: products.length,
    published: products.filter((item) => item.status === 'published').length,
    archived: products.filter((item) => item.status === 'archived').length,
    value: products.reduce((sum, item) => sum + Number(item.price || 0), 0),
  };

  const archive = async (target) => {
    setBusyId(target.id);
    try {
      await api.post(`/admin/products/${target.id}/archive`);
      toast.success(`« ${target.name} » est archivé et invisible du catalogue.`);
      await reload();
    } catch (err) {
      toast.error(err.message);
    } finally {
      setBusyId(null);
    }
  };

  const destroy = async (target) => {
    setBusyId(target.id);
    try {
      await api.delete(`/admin/products/${target.id}`);
      toast.success('Produit supprimé.');
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
      key: 'product',
      header: 'Produit',
      render: (row) => (
        <div className="flex min-w-0 items-center gap-3">
          <ProductImage src={productImage(row)} alt={row.name} rounded="rounded-lg" iconSize="size-4" className="size-11 shrink-0" />
          <div className="min-w-0">
            <p className="truncate font-semibold text-ink-900">{row.name}</p>
            <p className="truncate text-xs text-ink-500">{row.category || 'Sans catégorie'}</p>
          </div>
        </div>
      ),
      mobileClassName: 'text-left',
    },
    {
      key: 'merchant',
      header: 'Commerçant',
      hideOnMobile: true,
      render: (row) => <span className="text-ink-600">{row.merchant?.business_name || '—'}</span>,
    },
    { key: 'price', header: 'Prix', align: 'right', render: (row) => <span className="amount">{formatFcfa(row.price)}</span> },
    { key: 'stock', header: 'Stock', align: 'right', hideOnMobile: true, render: (row) => <span className="amount">{row.stock}</span> },
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
        <div className="flex justify-end gap-1.5">
          {row.status !== 'archived' && (
            <Button variant="secondary" size="xs" icon={Archive} loading={busyId === row.id} onClick={() => archive(row)}>
              Archiver
            </Button>
          )}
          <Button variant="danger-outline" size="xs" icon={Trash2} onClick={() => setConfirm({ target: row })}>
            Supprimer
          </Button>
        </div>
      ),
    },
  ];

  return (
    <div>
      <PageHeader
        title="Produits"
        subtitle="Supervise le catalogue de toutes les boutiques et modère les publications."
        breadcrumb={[{ label: 'Administration', to: '/admin' }, { label: 'Produits' }]}
      />

      <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatBox label="Sur cette page" value={products.length} />
        <StatBox label="En ligne" value={totals.published} tone="success" />
        <StatBox label="Archivés" value={totals.archived} />
        <StatBox label="Valeur du catalogue" value={formatFcfa(totals.value)} />
      </div>

      <div className="mb-5 flex flex-col gap-3 sm:flex-row">
        <SearchInput
          value={query}
          onChange={(value) => { setQuery(value); setPage(1); }}
          onClear={() => { setQuery(''); setPage(1); }}
          placeholder="Rechercher un produit…"
          className="sm:max-w-sm"
        />
        <Select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }} className="sm:max-w-[200px]" aria-label="Filtrer par statut">
          <option value="">Tous les statuts</option>
          <option value="draft">Brouillon</option>
          <option value="published">En ligne</option>
          <option value="archived">Archivé</option>
        </Select>
      </div>

      {error ? (
        <ErrorState message={error.message} onRetry={reload} />
      ) : (
        <DataTable
          columns={columns}
          rows={products}
          loading={isLoading}
          empty={
            <EmptyState icon={Package} title="Aucun produit" description="Aucun produit ne correspond à ces critères." compact />
          }
        />
      )}

      <Pagination meta={pagination} onPageChange={setPage} className="mt-6" itemLabel="produits" />

      <ConfirmationDialog
        open={Boolean(confirm)}
        onClose={() => setConfirm(null)}
        onConfirm={() => destroy(confirm.target)}
        title="Supprimer ce produit ?"
        message={
          <>
            <strong>{confirm?.target?.name}</strong> sera définitivement supprimé. Si le produit est lié à une
            tontine ou à un achat par tranches, l’API refuse la suppression : archive-le plutôt.
          </>
        }
        confirmLabel="Supprimer définitivement"
        loading={busyId !== null}
      />
    </div>
  );
}

function StatBox({ label, value, tone = 'default' }) {
  const tones = { default: 'text-ink-900', success: 'text-success-700', danger: 'text-danger-600' };
  return (
    <div className="rounded-2xl border border-line-strong bg-white p-5 shadow-sm">
      <p className="text-xs font-semibold uppercase tracking-wide text-ink-500">{label}</p>
      <p className={`amount mt-2.5 text-2xl ${tones[tone] || tones.default}`}>{value}</p>
    </div>
  );
}

export default AdminProducts;
