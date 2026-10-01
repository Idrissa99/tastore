import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { api } from '../../api/client';
import { useApi } from '../../hooks/useApi';
import { useToast } from '../../context/ToastContext';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { PageHeader } from '../../components/ui/PageHeader';
import TontineForm from '../../components/tontine/TontineForm';

/**
 * Création d'une tontine — commerçant approuvé ou administrateur.
 * POST /tontines
 *
 * Le formulaire est partagé avec la modification (components/tontine/
 * TontineForm) : les champs, la formule d'aperçu et les messages ne peuvent
 * pas diverger entre les deux parcours.
 */
export default function TontineCreate() {
  useDocumentTitle('Créer une tontine');
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const toast = useToast();

  const { data: config } = useApi(() => api.get('/config'), []);
  const commissionRate = config?.commission_rate ?? 0;

  const [initialValues] = useState(() => ({
    type: 'product',
    product_id: searchParams.get('product_id') || '',
    total_amount: '',
    name: '',
    frequency: 'monthly',
    max_members: '',
    start_date: '',
  }));

  const handleSubmit = async (payload) => {
    const response = await api.post('/tontines', {
      ...payload,
      start_date: payload.start_date || undefined,
    });
    toast.success('Tontine créée. Tu peux maintenant la partager.');
    navigate(`/tontines/${response.data.id}`);
  };

  return (
    <div className="mx-auto max-w-6xl">
      <PageHeader
        title="Créer une tontine"
        subtitle="Définis l’objectif, le nombre de participants et la fréquence : le montant par versement est calculé automatiquement."
        breadcrumb={[{ label: 'Espace commerçant' }, { label: 'Créer une tontine' }]}
      />

      <TontineForm
        mode="create"
        initialValues={initialValues}
        commissionRate={commissionRate}
        submitLabel="Créer la tontine"
        onSubmit={handleSubmit}
        onCancel={() => navigate(-1)}
      />
    </div>
  );
}
