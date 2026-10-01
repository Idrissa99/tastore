import { useState } from 'react';
import { Check, Store, X } from 'lucide-react';
import { api } from '../../api/client';
import { useApi } from '../../hooks/useApi';
import { useToast } from '../../context/ToastContext';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { PageHeader } from '../../components/ui/PageHeader';
import { Card } from '../../components/ui/Card';
import { StatCard } from '../../components/ui/StatCard';
import { Button } from '../../components/ui/Button';
import { Select } from '../../components/ui/Input';
import { StatusBadge } from '../../components/ui/Badge';
import { Avatar } from '../../components/ui/Avatar';
import { Modal } from '../../components/ui/Modal';
import { EmptyState, ErrorState } from '../../components/ui/EmptyState';
import { SkeletonRows } from '../../components/ui/Skeleton';
import { Pagination, normalizePagination } from '../../components/ui/Pagination';
import { formatDate, formatNumber } from '../../utils/format';

/**
 * Administration — validation des commerçants.
 * GET /admin/merchants?status= (paginé)
 * POST /admin/merchants/{id}/approve · POST /admin/merchants/{id}/reject
 *
 * Rappel : un compte commerçant `pending` n'accède pas à son espace tant
 * qu'il n'est pas approuvé (middleware `merchant` côté API).
 */
export default function AdminMerchants() {
  useDocumentTitle('Commerçants');
  const toast = useToast();

  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [busyId, setBusyId] = useState(null);
  const [detail, setDetail] = useState(null);

  const { data, isLoading, error, reload } = useApi(
    () => api.get('/admin/merchants', { page, status: status || undefined }),
    [page, status],
  );

  const merchants = data?.data || [];
  const pagination = normalizePagination(data);

  const totals = {
    shown: merchants.length,
    pending: merchants.filter((item) => item.status === 'pending').length,
    approved: merchants.filter((item) => item.status === 'approved').length,
    blocked: merchants.filter((item) => item.owner?.is_blocked).length,
  };

  const decide = async (merchant, action) => {
    setBusyId(merchant.id);
    try {
      const response = await api.post(`/admin/merchants/${merchant.id}/${action}`);
      toast.success(response?.message || (action === 'approve' ? 'Commerçant approuvé.' : 'Commerçant rejeté.'));
      setDetail(null);
      await reload();
    } catch (err) {
      toast.error(err.message);
    } finally {
      setBusyId(null);
    }
  };

  return (
    <div>
      <PageHeader
        title="Commerçants"
        subtitle="Valide les boutiques qui rejoignent la plateforme et suis leur activité."
        breadcrumb={[{ label: 'Administration', to: '/admin' }, { label: 'Commerçants' }]}
        actions={
          <Select
            value={status}
            onChange={(event) => { setStatus(event.target.value); setPage(1); }}
            className="w-48"
            aria-label="Filtrer par statut"
          >
            <option value="">Tous les statuts</option>
            <option value="pending">En attente</option>
            <option value="approved">Approuvés</option>
            <option value="rejected">Rejetés</option>
          </Select>
        }
      />

      {error ? (
        <ErrorState message={error.message} onRetry={reload} />
      ) : (
        <>
          {!isLoading && merchants.length > 0 && (
            <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
              <StatCard label="Sur cette page" value={formatNumber(totals.shown)} icon={Store} />
              <StatCard label="En attente de validation" value={formatNumber(totals.pending)} tone={totals.pending ? 'warning' : 'neutral'} />
              <StatCard label="Approuvés" value={formatNumber(totals.approved)} tone="success" />
              <StatCard label="Comptes suspendus" value={formatNumber(totals.blocked)} tone={totals.blocked ? 'danger' : 'neutral'} />
            </div>
          )}

          {isLoading ? (
            <Card>
              <SkeletonRows rows={4} />
            </Card>
          ) : merchants.length === 0 ? (
            <EmptyState
              icon={Store}
              title="Aucun commerçant"
              description="Aucun commerçant ne correspond à ce filtre."
            />
          ) : (
            <ul className="space-y-3">
              {merchants.map((merchant) => (
                <li key={merchant.id}>
                  <Card padding="sm" hover>
                    <div className="flex flex-wrap items-center gap-4">
                      <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-700">
                        <Store className="size-5" aria-hidden="true" />
                      </span>

                      <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                          <p className="truncate font-display text-sm font-bold text-ink-900">{merchant.business_name}</p>
                          <StatusBadge status={merchant.status} size="xs" dot />
                          {merchant.owner?.is_blocked && <StatusBadge status="blocked" size="xs" dot />}
                        </div>
                        <p className="mt-0.5 truncate text-xs text-ink-500">
                          {merchant.owner?.name} · {merchant.owner?.email}
                        </p>
                        <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-ink-500">
                          {merchant.city && <span>{merchant.city}</span>}
                          <span>{formatNumber(merchant.products_count)} produit(s)</span>
                          <span>{formatNumber(merchant.reviews_count)} avis</span>
                          <span>Inscrit le {formatDate(merchant.created_at)}</span>
                        </div>
                      </div>

                      <div className="flex shrink-0 gap-2">
                        <Button variant="secondary" size="sm" onClick={() => setDetail(merchant)}>
                          Détails
                        </Button>
                        {merchant.status === 'pending' && (
                          <>
                            <Button variant="success" size="sm" icon={Check} loading={busyId === merchant.id} onClick={() => decide(merchant, 'approve')}>
                              Approuver
                            </Button>
                            <Button variant="danger-outline" size="sm" icon={X} disabled={busyId === merchant.id} onClick={() => decide(merchant, 'reject')}>
                              Rejeter
                            </Button>
                          </>
                        )}
                      </div>
                    </div>
                  </Card>
                </li>
              ))}
            </ul>
          )}

          <Pagination meta={pagination} onPageChange={setPage} className="mt-6" itemLabel="commerçants" />
        </>
      )}

      {/* Fiche commerçant */}
      <Modal
        open={Boolean(detail)}
        onClose={() => setDetail(null)}
        title={detail?.business_name}
        description={detail?.city ? `Commerçant à ${detail.city}` : undefined}
      >
        {detail && (
          <div className="space-y-5">
            <div className="flex items-center gap-3 rounded-xl bg-ink-50 p-4">
              <Avatar name={detail.owner?.name} src={detail.owner?.avatar_url} size="lg" />
              <div className="min-w-0">
                <p className="truncate text-sm font-bold text-ink-900">{detail.owner?.name}</p>
                <p className="truncate text-xs text-ink-500">{detail.owner?.email}</p>
                <p className="truncate text-xs text-ink-500">{detail.owner?.phone}</p>
              </div>
            </div>

            {detail.description && (
              <div>
                <p className="text-xs font-semibold uppercase tracking-wide text-ink-500">Présentation</p>
                <p className="mt-1.5 whitespace-pre-line text-sm leading-relaxed text-ink-700">{detail.description}</p>
              </div>
            )}

            {detail.address && (
              <div>
                <p className="text-xs font-semibold uppercase tracking-wide text-ink-500">Adresse</p>
                <p className="mt-1.5 text-sm text-ink-700">{detail.address}</p>
              </div>
            )}

            <dl className="grid grid-cols-2 gap-3">
              <div className="rounded-xl border border-line-strong p-3">
                <dt className="text-[11px] text-ink-500">Produits</dt>
                <dd className="amount mt-1 text-lg">{formatNumber(detail.products_count)}</dd>
              </div>
              <div className="rounded-xl border border-line-strong p-3">
                <dt className="text-[11px] text-ink-500">Note moyenne</dt>
                <dd className="amount mt-1 text-lg">{detail.rating} / 5</dd>
              </div>
            </dl>

            {detail.status === 'pending' && (
              <div className="flex flex-wrap gap-2.5 border-t border-line-soft pt-4">
                <Button variant="success" icon={Check} loading={busyId === detail.id} onClick={() => decide(detail, 'approve')}>
                  Approuver ce commerçant
                </Button>
                <Button variant="danger-outline" icon={X} disabled={busyId === detail.id} onClick={() => decide(detail, 'reject')}>
                  Rejeter
                </Button>
              </div>
            )}
          </div>
        )}
      </Modal>
    </div>
  );
}
