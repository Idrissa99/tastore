import { useEffect, useMemo, useState } from 'react';
import { Banknote, Info, ShoppingBag, Sparkles } from 'lucide-react';
import { api } from '../../api/client';
import { useAuth } from '../../hooks/useAuth';
import { useApi } from '../../hooks/useApi';
import { Card } from '../ui/Card';
import { Button } from '../ui/Button';
import { Field, Input, RadioCard, Select } from '../ui/Input';
import { Alert } from '../ui/Alert';
import { ProductImage, productImage } from '../ui/ProductImage';
import { ErrorState } from '../ui/EmptyState';
import { formatFcfa, formatPercent } from '../../utils/format';
import { FREQUENCIES } from '../../utils/status';

/**
 * Formulaire d'une tontine, partagé par la création et la modification.
 *
 * Un seul formulaire pour les deux parcours : la liste des champs, la formule
 * d'aperçu et les messages d'erreur ne peuvent pas diverger entre « créer »
 * et « modifier ». Toute divergence se traduirait par un aperçu qui promet un
 * montant différent de celui que le serveur enregistrera ensuite.
 *
 * Le montant d'un versement N'EST JAMAIS SAISI : il est calculé par le
 * serveur. L'aperçu reproduit cette formule pour que le commerçant voie le
 * résultat avant de valider.
 */
export default function TontineForm({
  mode = 'create',
  initialValues,
  onSubmit,
  submitLabel,
  commissionRate,
  currentProduct,
  onCancel,
  cancelTo,
}) {
  const { isAdmin } = useAuth();
  const [products, setProducts] = useState([]);
  const [productsError, setProductsError] = useState(null);
  const [isLoadingProducts, setIsLoadingProducts] = useState(true);

  const [form, setForm] = useState(() => ({
    type: 'product',
    product_id: '',
    total_amount: '',
    name: '',
    frequency: 'monthly',
    max_members: '',
    start_date: '',
    ...initialValues,
  }));
  const [errors, setErrors] = useState({});
  const [globalError, setGlobalError] = useState(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  // Un admin choisit dans tout le catalogue, un commerçant dans le sien.
  useEffect(() => {
    const source = isAdmin ? '/produits' : '/merchant/products';
    setIsLoadingProducts(true);
    api
      .get(source)
      .then((data) => setProducts(data.data || []))
      .catch((err) => setProductsError(err))
      .finally(() => setIsLoadingProducts(false));
  }, [isAdmin]);

  // Le produit actuellement attaché à la tontine doit rester sélectionnable
  // même s'il ne figure plus dans la liste renvoyée (produit dépublié, ou
  // réponse paginée qui ne le contient pas). Sans cela, la modification
  // renverrait un product_id vide et changerait silencieusement l'objet.
  const selectableProducts = useMemo(() => {
    if (!currentProduct) return products;
    const isListed = products.some((product) => String(product.id) === String(currentProduct.id));
    return isListed ? products : [currentProduct, ...products];
  }, [products, currentProduct]);

  const update = (field) => (event) => setForm((current) => ({ ...current, [field]: event.target.value }));
  const fieldError = (name) => errors[name]?.[0];

  const selectedProduct = useMemo(
    () => selectableProducts.find((product) => String(product.id) === String(form.product_id)),
    [selectableProducts, form.product_id],
  );

  const preview = useMemo(() => {
    const target = form.type === 'product' ? Number(selectedProduct?.price || 0) : Number(form.total_amount || 0);
    const members = Number(form.max_members || 0);
    if (target <= 0 || members <= 0) return null;

    const base = target / members;
    const contribution = base * (1 + commissionRate);
    const commission = base * commissionRate;

    return {
      target,
      members,
      contribution: Math.round(contribution),
      commission: Math.round(commission),
      roundTotal: Math.round(contribution * members),
      rounds: members,
    };
  }, [form.type, form.total_amount, form.max_members, selectedProduct, commissionRate]);

  const handleSubmit = async (event) => {
    event.preventDefault();
    setErrors({});
    setGlobalError(null);
    setIsSubmitting(true);

    try {
      await onSubmit({
        type: form.type,
        name: form.name,
        frequency: form.frequency,
        max_members: form.max_members,
        start_date: form.start_date || null,
        ...(form.type === 'product' ? { product_id: form.product_id } : { total_amount: form.total_amount }),
      });
    } catch (err) {
      if (err?.errors) setErrors(err.errors);
      else setGlobalError(err.message);
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <>
      {productsError ? (
        <ErrorState
          title="Catalogue indisponible"
          message={productsError.message}
          onRetry={() => window.location.reload()}
          className="mb-6"
        />
      ) : null}

      <div className="grid gap-6 lg:grid-cols-[1fr_360px] lg:items-start">
        <Card>
          <form onSubmit={handleSubmit} className="space-y-5">
            {globalError && <Alert type="error">{globalError}</Alert>}

            <div>
              <p className="mb-2.5 text-sm font-semibold text-ink-700">
                Type de tontine <span className="text-danger-500">*</span>
              </p>
              <div className="grid gap-3 sm:grid-cols-2">
                <RadioCard
                  name="tontine-type"
                  value="product"
                  checked={form.type === 'product'}
                  onChange={() => setForm((current) => ({ ...current, type: 'product' }))}
                  icon={ShoppingBag}
                  tone="accent"
                  title="Tontine Produit"
                  description="Les membres cotisent pour financer un produit précis."
                />
                {isAdmin && (
                  <RadioCard
                    name="tontine-type"
                    value="cash"
                    checked={form.type === 'cash'}
                    onChange={() => setForm((current) => ({ ...current, type: 'cash' }))}
                    icon={Banknote}
                    title="Tontine Argent"
                    description="Épargne collective, sans produit physique."
                  />
                )}
              </div>
              {fieldError('type') && <p className="mt-1.5 text-xs font-medium text-danger-600">{fieldError('type')}</p>}
            </div>

            {form.type === 'product' ? (
              <Field label="Produit à financer" error={fieldError('product_id')} required>
                {(fieldProps) => (
                  <>
                    <Select {...fieldProps} value={form.product_id} onChange={update('product_id')} required>
                      <option value="">{isLoadingProducts ? 'Chargement…' : '— Choisir un produit —'}</option>
                      {selectableProducts.map((product) => (
                        <option key={product.id} value={product.id}>
                          {product.name} — {formatFcfa(product.price)}
                        </option>
                      ))}
                    </Select>

                    {selectedProduct && (
                      <div className="mt-3 flex items-center gap-3 rounded-xl border border-line-strong bg-ink-50/70 p-3">
                        <ProductImage
                          src={productImage(selectedProduct)}
                          alt={selectedProduct.name}
                          rounded="rounded-lg"
                          iconSize="size-4"
                          className="size-14 shrink-0"
                        />
                        <div className="min-w-0">
                          <p className="truncate text-sm font-semibold text-ink-900">{selectedProduct.name}</p>
                          <p className="text-xs text-ink-500">
                            {selectedProduct.category || 'Sans catégorie'}
                            {selectedProduct.stock !== undefined ? ` · stock ${selectedProduct.stock}` : ''}
                          </p>
                          <p className="amount mt-0.5 text-sm text-primary-800">{formatFcfa(selectedProduct.price)}</p>
                        </div>
                      </div>
                    )}
                  </>
                )}
              </Field>
            ) : (
              <Field label="Montant total à réunir (FCFA)" error={fieldError('total_amount')} required>
                <Input
                  type="number"
                  min="1"
                  value={form.total_amount}
                  onChange={update('total_amount')}
                  required
                  placeholder="500000"
                  inputSize="lg"
                />
              </Field>
            )}

            <Field
              label="Nom de la tontine"
              error={fieldError('name')}
              required
              hint="Un nom parlant aide les membres à rejoindre : « Tontine iPhone 15 »."
            >
              <Input value={form.name} onChange={update('name')} required placeholder="Ex. Tontine iPhone 15" />
            </Field>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Fréquence des versements" error={fieldError('frequency')} required>
                <Select value={form.frequency} onChange={update('frequency')} required>
                  {Object.entries(FREQUENCIES).map(([key, item]) => (
                    <option key={key} value={key}>
                      {item.short}
                    </option>
                  ))}
                </Select>
              </Field>

              <Field
                label="Nombre maximum de membres"
                error={fieldError('max_members')}
                required
                hint="Chaque membre reçoit son tour : 10 membres = 10 tours."
              >
                <Input type="number" min="2" value={form.max_members} onChange={update('max_members')} required placeholder="10" />
              </Field>
            </div>

            <Field
              label="Date de démarrage"
              error={fieldError('start_date')}
              hint="Facultatif. Elle sert à estimer les échéances affichées aux membres."
            >
              <Input type="date" value={form.start_date} onChange={update('start_date')} />
            </Field>

            <div className="flex flex-wrap gap-2.5 border-t border-line-soft pt-5">
              <Button type="submit" size="lg" icon={Sparkles} loading={isSubmitting} disabled={isSubmitting}>
                {submitLabel}
              </Button>
              <Button type="button" variant="ghost" size="lg" onClick={onCancel} disabled={isSubmitting}>
                Annuler
              </Button>
            </div>
          </form>
        </Card>

        {/* Aperçu */}
        <aside className="lg:sticky lg:top-24">
          <Card>
            <h2 className="font-display text-base font-bold text-ink-900">Aperçu de la tontine</h2>
            <p className="mt-1 text-xs leading-relaxed text-ink-500">
              Le montant du versement n’est pas saisi à la main : il est calculé à partir du montant visé et du
              nombre de membres.
            </p>

            <div className="mt-4 rounded-xl bg-primary-50/80 px-4 py-3">
              <p className="text-[11px] font-semibold uppercase tracking-wide text-primary-700">
                Commission plateforme
              </p>
              <p className="amount mt-0.5 text-lg text-primary-800">{formatPercent(commissionRate, 2)}</p>
              <p className="mt-1 text-[11px] leading-relaxed text-ink-500">
                {mode === 'edit'
                  ? 'Taux figé à la création de cette tontine. Il ne suit pas le paramétrage général.'
                  : 'Ajoutée par membre et par versement. Elle est figée sur chaque transaction.'}
              </p>
            </div>

            {preview ? (
              <dl className="mt-5 space-y-3">
                <PreviewRow label="Montant visé" value={formatFcfa(preview.target)} />
                <PreviewRow label="Nombre de membres" value={String(preview.members)} />
                <PreviewRow label="Nombre de tours" value={String(preview.rounds)} />
                <div className="rounded-xl bg-ink-950 px-4 py-3.5">
                  <p className="text-[11px] font-semibold uppercase tracking-wide text-primary-300">
                    Versement par membre
                  </p>
                  <p className="amount mt-1 text-2xl text-white">{formatFcfa(preview.contribution)}</p>
                  <p className="mt-1 text-[11px] text-ink-300">dont {formatFcfa(preview.commission)} de commission</p>
                </div>
                <PreviewRow label="Total collecté par tour" value={formatFcfa(preview.roundTotal)} />
              </dl>
            ) : (
              <div className="mt-5 rounded-xl border border-dashed border-line-strong px-4 py-8 text-center">
                <Info className="mx-auto size-5 text-ink-300" aria-hidden="true" />
                <p className="mt-2 text-xs text-ink-400">
                  Choisis un produit et le nombre de membres pour voir l’aperçu.
                </p>
              </div>
            )}

            {mode === 'edit' && (
              <p className="mt-4 text-[11px] leading-relaxed text-ink-500">
                Les membres déjà inscrits seront informés des changements que tu enregistres ici.
              </p>
            )}
          </Card>
        </aside>
      </div>
    </>
  );
}

function PreviewRow({ label, value }) {
  return (
    <div className="flex items-center justify-between gap-4 border-b border-line-soft pb-2.5 last:border-0 last:pb-0">
      <dt className="text-xs text-ink-500">{label}</dt>
      <dd className="amount text-sm">{value}</dd>
    </div>
  );
}
