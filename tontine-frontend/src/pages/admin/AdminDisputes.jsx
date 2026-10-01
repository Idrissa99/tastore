import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { CheckCircle2, Scale, Send, User } from 'lucide-react';
import { api } from '../../api/client';
import { useApi } from '../../hooks/useApi';
import { useToast } from '../../context/ToastContext';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { PageHeader } from '../../components/ui/PageHeader';
import { Card } from '../../components/ui/Card';
import { Button } from '../../components/ui/Button';
import { Field, Select, Textarea } from '../../components/ui/Input';
import { StatusBadge } from '../../components/ui/Badge';
import { EmptyState, ErrorState } from '../../components/ui/EmptyState';
import { SkeletonRows } from '../../components/ui/Skeleton';
import { formatDateTime, formatNumber } from '../../utils/format';

/* ------------------------------------------------------------------ */
/* Liste des litiges                                                   */
/* ------------------------------------------------------------------ */

export function AdminDisputeList() {
  useDocumentTitle('Litiges');
  const [status, setStatus] = useState('');
  const { data, isLoading, error, reload } = useApi(
    () => api.get('/admin/disputes', status ? { status } : {}),
    [status],
  );

  const disputes = data?.data || [];

  return (
    <div>
      <PageHeader
        title="Litiges"
        subtitle="Dossiers signalés par les membres sur leurs tontines, à traiter et documenter."
        breadcrumb={[{ label: 'Administration', to: '/admin' }, { label: 'Litiges' }]}
        actions={
          <Select value={status} onChange={(event) => setStatus(event.target.value)} className="w-44" aria-label="Filtrer par statut">
            <option value="">Tous</option>
            <option value="open">Ouverts</option>
            <option value="resolved">Résolus</option>
            <option value="rejected">Rejetés</option>
          </Select>
        }
      />

      {error ? (
        <ErrorState message={error.message} onRetry={reload} />
      ) : isLoading ? (
        <Card>
          <SkeletonRows rows={4} />
        </Card>
      ) : disputes.length === 0 ? (
        <EmptyState
          icon={CheckCircle2}
          title={status === 'open' ? 'Aucun litige ouvert 🎉' : 'Aucun litige'}
          description="Aucun dossier ne correspond à ce filtre."
        />
      ) : (
        <ul className="space-y-3">
          {disputes.map((dispute) => (
            <li key={dispute.id}>
              <Card padding="sm" hover>
                <Link to={`/admin/litiges/${dispute.id}`} className="flex flex-wrap items-center gap-4">
                  <span
                    className={`flex size-11 shrink-0 items-center justify-center rounded-xl ${
                      dispute.status === 'open' ? 'bg-warning-50 text-warning-600' : 'bg-ink-100 text-ink-500'
                    }`}
                  >
                    <Scale className="size-5" aria-hidden="true" />
                  </span>

                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <p className="truncate font-display text-sm font-bold text-ink-900">{dispute.subject}</p>
                      <StatusBadge status={dispute.status} size="xs" dot />
                    </div>
                    <p className="mt-1 truncate text-xs text-ink-500">
                      Tontine : {dispute.tontine?.name || '—'} · signalée par {dispute.raised_by?.name || '—'}
                    </p>
                    <p className="mt-1 line-clamp-2 text-xs leading-relaxed text-ink-500">{dispute.description}</p>
                  </div>

                  <div className="shrink-0 text-right">
                    <p className="text-[11px] text-ink-400">{formatDateTime(dispute.created_at)}</p>
                  </div>
                </Link>
              </Card>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* Détail d'un litige                                                  */
/* ------------------------------------------------------------------ */

export function AdminDisputeDetail() {
  const { id } = useParams();
  const toast = useToast();
  const { data, isLoading, error, reload } = useApi(() => api.get(`/admin/disputes/${id}`), [id]);

  const [status, setStatus] = useState('resolved');
  const [note, setNote] = useState('');
  const [errors, setErrors] = useState({});
  const [isSubmitting, setIsSubmitting] = useState(false);

  const dispute = data?.data;
  useDocumentTitle(dispute ? dispute.subject : 'Litige');

  const handleResolve = async (event) => {
    event.preventDefault();
    setErrors({});
    setIsSubmitting(true);
    try {
      const response = await api.post(`/admin/disputes/${id}/resolve`, { status, resolution_note: note });
      toast.success(response?.message || 'Litige traité.');
      await reload();
    } catch (err) {
      if (err?.errors) setErrors(err.errors);
      toast.error(err.message);
    } finally {
      setIsSubmitting(false);
    }
  };

  if (isLoading) {
    return (
      <div className="max-w-3xl space-y-4" aria-busy="true">
        <Card className="h-40" />
        <Card className="h-56" />
      </div>
    );
  }

  if (error) {
    return (
      <div className="mx-auto max-w-2xl py-10">
        <ErrorState message={error.message} onRetry={reload} />
      </div>
    );
  }

  if (!dispute) return null;

  const isOpen = dispute.status === 'open';

  return (
    <div className="max-w-3xl">
      <PageHeader
        breadcrumb={[
          { label: 'Administration', to: '/admin' },
          { label: 'Litiges', to: '/admin/litiges' },
          { label: dispute.subject },
        ]}
        title={dispute.subject}
        subtitle={`Signalé le ${formatDateTime(dispute.created_at)}`}
        actions={<StatusBadge status={dispute.status} />}
      />

      <div className="grid gap-4 sm:grid-cols-3">
        <MetaTile icon={Scale} label="Tontine concernée" value={dispute.tontine?.name || '—'} sub={`${formatNumber(dispute.tontine?.members_count ?? 0)} membres`} />
        <MetaTile icon={User} label="Signalé par" value={dispute.raised_by?.name || '—'} sub={dispute.tontine?.type === 'cash' ? 'Tontine argent' : 'Tontine produit'} />
        <MetaTile
          icon={CheckCircle2}
          label="Traité par"
          value={dispute.resolved_by?.name || '—'}
          sub={dispute.resolved_at ? formatDateTime(dispute.resolved_at) : 'En attente de décision'}
        />
      </div>

      <Card className="mt-6">
        <h2 className="font-display text-base font-bold text-ink-900">Description du problème</h2>
        <p className="mt-3 whitespace-pre-line text-sm leading-relaxed text-ink-700">{dispute.description}</p>
      </Card>

      {isOpen ? (
        <Card className="mt-6">
          <h2 className="font-display text-base font-bold text-ink-900">Traiter ce litige</h2>
          <p className="mt-1 text-xs text-ink-500">
            La note de résolution est notifiée au membre : rédige-la de façon claire et factuelle.
          </p>

          <form onSubmit={handleResolve} className="mt-5 space-y-4">
            <Field label="Décision" htmlFor="dispute-status" error={errors.status?.[0]} required>
              <Select id="dispute-status" value={status} onChange={(event) => setStatus(event.target.value)}>
                <option value="resolved">Résolu — la demande est légitime</option>
                <option value="rejected">Rejeté — la demande est hors périmètre</option>
              </Select>
            </Field>

            <Field
              label="Note de résolution"
              htmlFor="dispute-note"
              error={errors.resolution_note?.[0]}
              required
              hint="Explicative : elle sera lue telle quelle par le membre."
            >
              <Textarea
                id="dispute-note"
                value={note}
                onChange={(event) => setNote(event.target.value)}
                required
                maxLength={2000}
                placeholder="Décris la décision prise et les suites à donner…"
              />
            </Field>

            <Button type="submit" icon={Send} loading={isSubmitting} disabled={isSubmitting || !note.trim()}>
              Enregistrer la décision
            </Button>
          </form>
        </Card>
      ) : (
        <Card className="mt-6">
          <h2 className="font-display text-base font-bold text-ink-900">Décision rendue</h2>
          <p className="mt-3 whitespace-pre-line text-sm leading-relaxed text-ink-700">{dispute.resolution_note}</p>
        </Card>
      )}
    </div>
  );
}

function MetaTile({ icon: Icon, label, value, sub }) {
  return (
    <Card padding="sm">
      <div className="flex items-center gap-2">
        <Icon className="size-3.5 shrink-0 text-primary-600" aria-hidden="true" />
        <p className="text-[11px] font-medium text-ink-500">{label}</p>
      </div>
      <p className="mt-1.5 truncate text-sm font-semibold text-ink-900">{value}</p>
      {sub && <p className="truncate text-[11px] text-ink-400">{sub}</p>}
    </Card>
  );
}
