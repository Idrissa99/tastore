import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Flag, Send, Wallet } from 'lucide-react';
import { api } from '../../api/client';
import { useToast } from '../../context/ToastContext';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { Breadcrumb } from '../../components/ui/PageHeader';
import { Card } from '../../components/ui/Card';
import { Button } from '../../components/ui/Button';
import { Field, Input, Textarea } from '../../components/ui/Input';
import { Alert } from '../../components/ui/Alert';

/**
 * Signalement de problème sur une tontine — POST /tontines/{id}/disputes
 * Réservé aux membres de la tontine (policy côté API).
 */
export default function DisputeCreate() {
  const { id } = useParams();
  const navigate = useNavigate();
  const toast = useToast();
  useDocumentTitle('Signaler un problème');

  const [subject, setSubject] = useState('');
  const [description, setDescription] = useState('');
  const [errors, setErrors] = useState({});
  const [error, setError] = useState(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const handleSubmit = async (event) => {
    event.preventDefault();
    setErrors({});
    setError(null);
    setIsSubmitting(true);

    try {
      await api.post(`/tontines/${id}/disputes`, { subject: subject.trim(), description: description.trim() });
      toast.success('Ton signalement a été transmis. Un administrateur va l’examiner.');
      navigate(`/tontines/${id}`);
    } catch (err) {
      if (err?.errors) setErrors(err.errors);
      else setError(err.message);
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="mx-auto max-w-2xl px-4 py-8 sm:px-6 lg:px-8">
      <Breadcrumb
        items={[
          { label: 'Tontines', to: '/tontines' },
          { label: `Tontine #${id}`, to: `/tontines/${id}` },
          { label: 'Signaler un problème' },
        ]}
        className="mb-5"
      />

      <h1 className="flex items-center gap-2.5 font-display text-2xl font-extrabold tracking-tight text-ink-900">
        <Flag className="size-6 text-danger-500" aria-hidden="true" />
        Signaler un problème
      </h1>
      <p className="mt-2 text-sm leading-relaxed text-ink-600">
        Décris précisément ce qui bloque de ton côté : versement non pris en compte, retard de livraison,
        désaccord entre membres… Un administrateur traitera ton dossier et te répondra par notification.
      </p>

      <Card className="mt-6">
        <form onSubmit={handleSubmit} className="space-y-5">
          {error && <Alert type="error">{error}</Alert>}

          <Field label="Objet du signalement" error={errors.subject?.[0]} required>
            <Input
              value={subject}
              onChange={(event) => setSubject(event.target.value)}
              required
              maxLength={255}
              placeholder="Ex. Mon versement n’a pas été pris en compte"
            />
          </Field>

          <Field
            label="Description détaillée"
            error={errors.description?.[0]}
            required
            hint="Sois le plus précis possible : dates, montants, numéros de round."
          >
            <Textarea
              value={description}
              onChange={(event) => setDescription(event.target.value)}
              required
              maxLength={5000}
              rows={7}
              placeholder="Décris ce qui s’est passé, ce que tu as déjà tenté…"
            />
          </Field>

          <Alert type="info">
            Ce formulaire est réservé aux membres de la tontine. Les versements ne sont pas débités par cette
            action : tu poursuis tes obligations de cotisation normalement.
          </Alert>

          <div className="flex flex-wrap gap-2.5 border-t border-line-soft pt-5">
            <Button type="submit" icon={Send} loading={isSubmitting} disabled={isSubmitting || !subject.trim() || !description.trim()}>
              Envoyer le signalement
            </Button>
            <Button type="button" variant="ghost" onClick={() => navigate(`/tontines/${id}`)} disabled={isSubmitting}>
              Annuler
            </Button>
          </div>
        </form>
      </Card>

      <p className="mt-5 text-center text-xs text-ink-400">
        <Link to="/notifications" className="font-semibold text-primary-700 hover:underline">
          Suivre mes signalements
        </Link>{' '}
        · <Wallet className="inline size-3.5" aria-hidden="true" /> tu seras notifié dès qu’une décision sera prise
      </p>
    </div>
  );
}
