import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Star } from 'lucide-react';
import { api } from '../../api/client';
import { useToast } from '../../context/ToastContext';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { Breadcrumb } from '../../components/ui/PageHeader';
import { Card } from '../../components/ui/Card';
import { Button } from '../../components/ui/Button';
import { Field, Textarea } from '../../components/ui/Input';
import { Alert } from '../../components/ui/Alert';

/**
 * Avis sur le commerçant d'une tontine terminée.
 * POST /tontines/{id}/review  (rating 1-5, comment optionnel)
 * L'API réserve l'avis aux membres d'une tontine `completed`.
 */
export default function MerchantReviewCreate() {
  const { id } = useParams();
  const navigate = useNavigate();
  const toast = useToast();
  useDocumentTitle('Évaluer le commerçant');

  const [rating, setRating] = useState(5);
  const [comment, setComment] = useState('');
  const [errors, setErrors] = useState({});
  const [error, setError] = useState(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const handleSubmit = async (event) => {
    event.preventDefault();
    setErrors({});
    setError(null);
    setIsSubmitting(true);

    try {
      await api.post(`/tontines/${id}/review`, { rating, comment: comment.trim() || undefined });
      toast.success('Merci ! Ton avis aide les prochains acheteurs.');
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
          { label: 'Évaluer le commerçant' },
        ]}
        className="mb-5"
      />

      <h1 className="font-display text-2xl font-extrabold tracking-tight text-ink-900">Évaluer le commerçant</h1>
      <p className="mt-2 text-sm leading-relaxed text-ink-600">
        Ta tontine est terminée : ton retour aide les membres à choisir en confiance et aide le commerçant à
        s’améliorer.
      </p>

      <Card className="mt-6">
        <form onSubmit={handleSubmit} className="space-y-6">
          {error && <Alert type="error">{error}</Alert>}

          {/* Note */}
          <div>
            <p className="mb-2 text-sm font-semibold text-ink-700">
              Note <span className="text-danger-500">*</span>
            </p>
            <div role="radiogroup" aria-label="Note sur 5" className="flex gap-2">
              {[1, 2, 3, 4, 5].map((value) => (
                <button
                  key={value}
                  type="button"
                  role="radio"
                  aria-checked={rating === value}
                  aria-label={`${value} étoile${value > 1 ? 's' : ''}`}
                  onClick={() => setRating(value)}
                  className={`flex size-12 items-center justify-center rounded-xl border-2 transition-all ${
                    value <= rating
                      ? 'border-accent-400 bg-accent-50 text-accent-500'
                      : 'border-line-strong bg-white text-ink-300 hover:border-accent-300'
                  }`}
                >
                  <Star className={`size-6 ${value <= rating ? 'fill-current' : ''}`} aria-hidden="true" />
                </button>
              ))}
            </div>
            <p className="mt-2 text-xs text-ink-500">
              {['', 'Très insatisfait', 'Insatisfait', 'Correct', 'Bien', 'Excellent'][rating]}
            </p>
            {errors.rating?.[0] && <p className="mt-1.5 text-xs font-medium text-danger-600">{errors.rating[0]}</p>}
          </div>

          <Field label="Ton commentaire" error={errors.comment?.[0]} hint="Facultatif, 1 000 caractères maximum.">
            <Textarea
              value={comment}
              onChange={(event) => setComment(event.target.value)}
              maxLength={1000}
              rows={5}
              placeholder="Décris ton expérience : qualité du produit, respect des délais, communication…"
            />
          </Field>

          <div className="flex flex-wrap gap-2.5 border-t border-line-soft pt-5">
            <Button type="submit" icon={Star} loading={isSubmitting} disabled={isSubmitting}>
              Publier mon avis
            </Button>
            <Button type="button" variant="ghost" onClick={() => navigate(`/tontines/${id}`)} disabled={isSubmitting}>
              Annuler
            </Button>
          </div>
        </form>
      </Card>
    </div>
  );
}
