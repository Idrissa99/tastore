import { useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Info, Layers, ShoppingCart } from 'lucide-react';
import { api } from '../../api/client';
import { useApi } from '../../hooks/useApi';
import { useToast } from '../../context/ToastContext';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { Breadcrumb } from '../../components/ui/PageHeader';
import { Card } from '../../components/ui/Card';
import { Button } from '../../components/ui/Button';
import { Field, Input } from '../../components/ui/Input';
import { Alert } from '../../components/ui/Alert';
import { ProductImage, productImage } from '../../components/ui/ProductImage';
import { Skeleton } from '../../components/ui/Skeleton';
import { ErrorState } from '../../components/ui/EmptyState';
import { formatFcfa, formatPercent } from '../../utils/format';

const MIN_TRANCHES = 2;
const MAX_TRANCHES = 24;

/**
 * Choix du nombre de tranches — POST /produits/{id}/tranches
 * (l'API accepte `installments_count` entre 2 et 24).
 *
 * Le montant par tranche est calculé côté serveur ; l'aperçu ci-dessous
 * reproduit la même formule pour affichage, avec le taux de commission lu
 * sur GET /config.
 */
export default function InstallmentPurchaseCreate() {
  const { productId } = useParams();
  const navigate = useNavigate();
  const toast = useToast();
  useDocumentTitle('Acheter par tranches');

  const { data, isLoading, error, reload } = useApi(() => api.get(`/produits/${productId}`), [productId]);
  const product = data?.data;
  const { data: config } = useApi(() => api.get('/config'), []);

  const commissionRate = config?.commission_rate ?? 0;

  const [count, setCount] = useState(3);
  const [formError, setFormError] = useState(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const preview = useMemo(() => {
    const price = Number(product?.price || 0);
    if (!price || count < MIN_TRANCHES) return null;
    const base = price / count;
    const amount = Math.round(base * (1 + commissionRate) * 100) / 100;
    return {
      base: Math.round(base),
      amount,
      commission: Math.round(base * commissionRate),
      total: Math.round(amount * count),
    };
  }, [product, count, commissionRate]);

  const handleSubmit = async (event) => {
    event.preventDefault();
    setFormError(null);
    setIsSubmitting(true);
    try {
      const response = await api.post(`/produits/${productId}/tranches`, { installments_count: count });
      toast.success('Achat par tranches créé. Tu peux régler la première tranche.');
      navigate(`/mes-achats/${response.data.id}`);
    } catch (err) {
      setFormError(err.message);
    } finally {
      setIsSubmitting(false);
    }
  };

  if (isLoading) {
    return (
      <div className="mx-auto max-w-5xl px-4 py-8 sm:px-6" aria-busy="true">
        <Skeleton className="h-8 w-72" />
        <div className="mt-6 grid gap-6 lg:grid-cols-[1fr_320px]">
          <Skeleton className="h-80 rounded-2xl" />
          <Skeleton className="h-80 rounded-2xl" />
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="mx-auto max-w-2xl px-4 py-10">
        <ErrorState
          title={error.status === 404 ? 'Produit introuvable' : 'Impossible de charger ce produit'}
          message={error.message}
          onRetry={reload}
        />
      </div>
    );
  }

  if (!product) return null;

  const isOutOfStock = Number(product.stock) === 0;

  return (
    <div className="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
      <Breadcrumb
        items={[
          { label: 'Produits', to: '/produits' },
          { label: product.name, to: `/produits/${product.id}` },
          { label: 'Acheter par tranches' },
        ]}
        className="mb-5"
      />

      <h1 className="font-display text-2xl font-extrabold tracking-tight text-ink-900 sm:text-3xl">
        Acheter {product.name} en plusieurs fois
      </h1>
      <p className="mt-2 text-sm text-ink-500">
        Choisis combien de versements tu veux effectuer. Aucun intérêt : tu paies uniquement le prix du produit
        et la commission de la plateforme.
      </p>

      <div className="mt-6 grid gap-6 lg:grid-cols-[1fr_320px] lg:items-start">
        <Card>
          {/* Produit */}
          <div className="flex items-center gap-4 border-b border-line-soft pb-5">
            <ProductImage
              src={productImage(product)}
              alt={product.name}
              rounded="xl"
              iconSize="size-5"
              className="size-20 shrink-0"
            />
            <div className="min-w-0">
              <p className="truncate font-display text-sm font-bold text-ink-900">{product.name}</p>
              {product.category && <p className="truncate text-xs text-ink-500">{product.category}</p>}
              <p className="amount mt-1 text-lg text-primary-800">{formatFcfa(product.price)}</p>
            </div>
          </div>

          <form onSubmit={handleSubmit} className="mt-5 space-y-5">
            {formError && <Alert type="error">{formError}</Alert>}

            {isOutOfStock && (
              <Alert type="warning" title="Stock épuisé">
                Le stock de ce produit est à zéro : l’API refusera de créer la commande. Passe par une tontine
                si un financement collectif est possible.
              </Alert>
            )}

            <Field
              label="Nombre de tranches"
              htmlFor="tranches-count"
              required
              hint={`Entre ${MIN_TRANCHES} et ${MAX_TRANCHES} versements.`}
            >
              <Input
                id="tranches-count"
                type="number"
                min={MIN_TRANCHES}
                max={MAX_TRANCHES}
                value={count}
                onChange={(event) => setCount(Math.min(MAX_TRANCHES, Math.max(MIN_TRANCHES, Number(event.target.value) || MIN_TRANCHES)))}
                required
                inputSize="lg"
              />
            </Field>

            {/* Raccourcis */}
            <div className="flex flex-wrap gap-2">
              {[3, 6, 12, 24].map((value) => (
                <button
                  key={value}
                  type="button"
                  onClick={() => setCount(value)}
                  className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors ${
                    count === value ? 'bg-primary-700 text-white' : 'bg-ink-100 text-ink-600 hover:bg-ink-200'
                  }`}
                >
                  {value} tranches
                </button>
              ))}
            </div>

            <div className="flex flex-wrap gap-2.5 border-t border-line-soft pt-5">
              <Button type="submit" size="lg" icon={ShoppingCart} loading={isSubmitting} disabled={isSubmitting || isOutOfStock}>
                Confirmer l’achat
              </Button>
              <Button type="button" variant="ghost" size="lg" onClick={() => navigate(-1)} disabled={isSubmitting}>
                Annuler
              </Button>
            </div>
          </form>
        </Card>

        {/* Aperçu */}
        <aside className="lg:sticky lg:top-24">
          <Card>
            <h2 className="flex items-center gap-2 font-display text-base font-bold text-ink-900">
              <Layers className="size-4.5 text-primary-600" aria-hidden="true" />
              Aperçu
            </h2>

            <div className="mt-4 rounded-xl bg-primary-50/80 px-4 py-3">
              <p className="text-[11px] font-semibold uppercase tracking-wide text-primary-700">
                Commission plateforme
              </p>
              <p className="amount mt-0.5 text-lg text-primary-800">{formatPercent(commissionRate, 2)}</p>
              <p className="mt-1 text-[11px] text-ink-500">Par tranche, sur le montant du produit.</p>
            </div>

            {preview ? (
              <dl className="mt-5 space-y-3">
                <Row label="Prix du produit" value={formatFcfa(product.price)} />
                <Row label="Nombre de tranches" value={String(count)} />
                <div className="rounded-xl bg-ink-950 px-4 py-3.5">
                  <p className="text-[11px] font-semibold uppercase tracking-wide text-primary-300">Par tranche</p>
                  <p className="amount mt-1 text-2xl text-white">{formatFcfa(preview.amount)}</p>
                  <p className="mt-1 text-[11px] text-ink-300">dont {formatFcfa(preview.commission)} de commission</p>
                </div>
                <Row label="Total sur toutes les tranches" value={formatFcfa(preview.total)} strong />
              </dl>
            ) : (
              <p className="mt-5 text-xs text-ink-400">Choisis un nombre de tranches pour voir le détail.</p>
            )}

            <p className="mt-5 flex items-start gap-2 border-t border-line-soft pt-4 text-[11px] leading-relaxed text-ink-400">
              <Info className="mt-px size-3.5 shrink-0" aria-hidden="true" />
              Tu peux régler chaque tranche indépendamment, avec ou sans code de transfert selon le moyen de
              paiement choisi.
            </p>
          </Card>
        </aside>
      </div>
    </div>
  );
}

function Row({ label, value, strong = false }) {
  return (
    <div className={`flex items-center justify-between gap-4 ${strong ? 'border-t border-line-soft pt-2.5' : 'border-b border-line-soft pb-2.5'}`}>
      <dt className="text-xs text-ink-500">{label}</dt>
      <dd className={strong ? 'amount text-base text-primary-700' : 'amount text-sm'}>{value}</dd>
    </div>
  );
}
