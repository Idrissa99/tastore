import { useMemo } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Lock } from 'lucide-react';
import { api } from '../../api/client';
import { useApi } from '../../hooks/useApi';
import { useToast } from '../../context/ToastContext';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { PageHeader } from '../../components/ui/PageHeader';
import { Card } from '../../components/ui/Card';
import { Button } from '../../components/ui/Button';
import { Skeleton } from '../../components/ui/Skeleton';
import { ErrorState } from '../../components/ui/EmptyState';
import TontineForm from '../../components/tontine/TontineForm';

/**
 * Modification d'une tontine qui n'a pas encore démarré.
 * PUT /tontines/{id}
 *
 * Le serveur est l'unique juge : c'est lui qui décide si la modification est
 * encore permise (créateur/admin + statut "open") et c'est lui qui renvoie la
 * raison du blocage. Cette page ne repropose pas sa propre règle — elle ne
 * fait que la restituer.
 */
export default function TontineEdit() {
  useDocumentTitle('Modifier la tontine');
  const { id } = useParams();
  const navigate = useNavigate();
  const toast = useToast();

  const { data, isLoading, error, reload } = useApi(() => api.get(`/tontines/${id}`), [id]);
  const tontine = data?.data;

  const initialValues = useMemo(() => {
    if (!tontine) return null;
    return {
      type: tontine.type,
      product_id: tontine.product?.id ? String(tontine.product.id) : '',
      total_amount: tontine.type === 'cash' ? String(tontine.total_amount ?? '') : '',
      name: tontine.name || '',
      frequency: tontine.frequency || 'monthly',
      max_members: String(tontine.max_members ?? ''),
      start_date: tontine.start_date ? String(tontine.start_date).slice(0, 10) : '',
    };
  }, [tontine]);

  const handleSubmit = async (payload) => {
    await api.put(`/tontines/${id}`, payload);
    toast.success('Tontine mise à jour. Les membres inscrits ont été informés.');
    navigate(`/tontines/${id}`);
  };

  if (isLoading) {
    return (
      <div className="mx-auto max-w-6xl">
        <PageHeader title="Modifier la tontine" breadcrumb={[{ label: 'Mon espace', to: '/tableau-de-bord' }, { label: 'Modifier' }]} />
        <Card>
          <Skeleton className="h-10 w-full" />
          <Skeleton className="mt-4 h-40 w-full" />
        </Card>
      </div>
    );
  }

  if (error) {
    return (
      <div className="mx-auto max-w-3xl">
        <ErrorState
          title={error.status === 404 ? 'Tontine introuvable' : 'Impossible de charger cette tontine'}
          message={error.message}
          onRetry={reload}
        />
        <div className="mt-5 text-center">
          <Button variant="ghost" onClick={() => navigate('/tontines')}>
            ← Retour aux tontines
          </Button>
        </div>
      </div>
    );
  }

  if (!tontine) return null;

  if (!tontine.can_edit) {
    return (
      <div className="mx-auto max-w-3xl">
        <PageHeader
          title="Modifier la tontine"
          breadcrumb={[{ label: 'Tontines', to: '/tontines' }, { label: tontine.name }, { label: 'Modifier' }]}
        />
        <Card>
          <div className="flex items-start gap-3.5">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-warning-50 text-warning-600">
              <Lock className="size-4.5" aria-hidden="true" />
            </span>
            <div>
              <h2 className="font-display text-base font-bold text-ink-900">Modification impossible</h2>
              <p className="mt-1.5 text-sm leading-relaxed text-ink-600">
                {tontine.edit_locked_reason
                  || 'Seul le créateur de la tontine peut la modifier, et uniquement avant son démarrage.'}
              </p>
              <Button className="mt-5" variant="secondary" onClick={() => navigate(`/tontines/${id}`)}>
                Revenir à la tontine
              </Button>
            </div>
          </div>
        </Card>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-6xl">
      <PageHeader
        title="Modifier la tontine"
        subtitle="Tant que la tontine n’a pas démarré, tu peux corriger ses paramètres. Les membres déjà inscrits seront informés."
        breadcrumb={[
          { label: 'Tontines', to: '/tontines' },
          { label: tontine.name, to: `/tontines/${id}` },
          { label: 'Modifier' },
        ]}
      />

      <TontineForm
        mode="edit"
        initialValues={initialValues}
        // Le taux applicable est celui FIGÉ sur cette tontine, pas le taux
        // global actuel : sans cela l'aperçu annoncerait un montant différent
        // de celui que le serveur conservera.
        commissionRate={Number(tontine.commission_rate ?? 0)}
        currentProduct={tontine.product}
        submitLabel="Enregistrer les modifications"
        onSubmit={handleSubmit}
        onCancel={() => navigate(`/tontines/${id}`)}
      />
    </div>
  );
}
