import { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { Boxes, ImagePlus, Pencil, Plus, Upload, X } from 'lucide-react';
import { api } from '../../api/client';
import { useApi } from '../../hooks/useApi';
import { useToast } from '../../context/ToastContext';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { PageHeader } from '../../components/ui/PageHeader';
import { Card } from '../../components/ui/Card';
import { Button } from '../../components/ui/Button';
import { Field, Input, Select, Textarea } from '../../components/ui/Input';
import { StatusBadge } from '../../components/ui/Badge';
import { ProductImage, productImage } from '../../components/ui/ProductImage';
import { Modal } from '../../components/ui/Modal';
import { EmptyState, ErrorState } from '../../components/ui/EmptyState';
import { SkeletonCard } from '../../components/ui/Skeleton';
import { formatFcfa } from '../../utils/format';

const EMPTY_FORM = { name: '', description: '', category: '', price: '', stock: 0, status: 'published' };

/**
 * Espace commerçant — produits.
 * GET/POST /merchant/products, PATCH /merchant/products/{id}/stock.
 */
export default function MerchantProducts() {
  useDocumentTitle('Mes produits');
  const toast = useToast();

  const { data, isLoading, error, reload } = useApi(() => api.get('/merchant/products'), []);
  const [formOpen, setFormOpen] = useState(false);
  const [busyId, setBusyId] = useState(null);

  const products = data?.data || [];

  const updateStock = async (product, value) => {
    const stock = Number(value);
    if (!Number.isFinite(stock) || stock < 0 || stock === Number(product.stock)) return;
    setBusyId(product.id);
    try {
      await api.patch(`/merchant/products/${product.id}/stock`, { stock });
      toast.success(`Stock de « ${product.name} » mis à jour.`);
      await reload();
    } catch (err) {
      toast.error(err.message);
      await reload();
    } finally {
      setBusyId(null);
    }
  };

  return (
    <div>
      <PageHeader
        title="Mes produits"
        subtitle="Publie tes produits, ajuste ton stock et suis leur visibilité sur le catalogue."
        breadcrumb={[{ label: 'Espace commerçant' }, { label: 'Produits' }]}
        actions={
          <Button icon={Plus} onClick={() => setFormOpen(true)}>
            Ajouter un produit
          </Button>
        }
      />

      {error ? (
        <ErrorState message={error.message} onRetry={reload} />
      ) : isLoading ? (
        <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
          {Array.from({ length: 3 }).map((_, index) => (
            <SkeletonCard key={index} />
          ))}
        </div>
      ) : products.length === 0 ? (
        <EmptyState
          icon={Boxes}
          title="Votre catalogue est vide"
          description="Ajoutez un produit pour qu’il puisse être financé par une tontine ou acheté en plusieurs fois."
          action={
            <Button icon={Plus} onClick={() => setFormOpen(true)}>
              Ajouter mon premier produit
            </Button>
          }
        />
      ) : (
        <ul className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
          {products.map((product) => (
            <li key={product.id}>
              <Card padding="none" hover className="flex h-full flex-col overflow-hidden">
                <ProductImage src={productImage(product)} alt={product.name} ratio="4/3" rounded="rounded-none" iconSize="size-7" />

                <div className="flex flex-1 flex-col p-4">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <h2 className="truncate font-display text-sm font-bold text-ink-900">{product.name}</h2>
                      {product.category && <p className="mt-0.5 truncate text-xs text-ink-500">{product.category}</p>}
                    </div>
                    <StatusBadge status={product.status} size="xs" />
                  </div>

                  <p className="amount mt-3 text-lg text-primary-800">{formatFcfa(product.price)}</p>

                  <div className="mt-4 flex items-end gap-2 border-t border-line-soft pt-4">
                    <Field label="Stock" htmlFor={`stock-${product.id}`} className="w-28">
                      <Input
                        id={`stock-${product.id}`}
                        type="number"
                        min="0"
                        inputSize="sm"
                        defaultValue={product.stock}
                        disabled={busyId === product.id}
                        onBlur={(event) => updateStock(product, event.target.value)}
                      />
                    </Field>
                    {Number(product.stock) === 0 && (
                      <span className="pb-2.5 text-[11px] font-semibold text-danger-600">Rupture de stock</span>
                    )}
                  </div>

                  <Link
                    to={`/commercant/produits/${product.id}`}
                    className="mt-4 inline-flex items-center gap-1.5 text-sm font-bold text-primary-700 transition-colors hover:text-primary-900"
                  >
                    <Pencil className="size-3.5" aria-hidden="true" />
                    Modifier
                  </Link>
                </div>
              </Card>
            </li>
          ))}
        </ul>
      )}

      <Modal
        open={formOpen}
        onClose={() => setFormOpen(false)}
        title="Ajouter un produit"
        description="Le produit sera visible dans le catalogue une fois publié."
        size="lg"
      >
        <ProductForm
          onCreated={async () => {
            setFormOpen(false);
            await reload();
          }}
          onCancel={() => setFormOpen(false)}
        />
      </Modal>
    </div>
  );
}

/* ------------------------------------------------------------------ */

function ProductForm({ initial = EMPTY_FORM, productId, onCreated, onCancel }) {
  const toast = useToast();
  const [form, setForm] = useState(initial);
  const [images, setImages] = useState([]);
  const [video, setVideo] = useState(null);
  const [errors, setErrors] = useState({});
  const [isSubmitting, setIsSubmitting] = useState(false);
  const imageInput = useRef(null);

  const update = (field) => (event) => setForm((current) => ({ ...current, [field]: event.target.value }));
  const fieldError = (name) => errors[name]?.[0];

  const handleSubmit = async (event) => {
    event.preventDefault();
    setErrors({});
    setIsSubmitting(true);

    const formData = new FormData();
    Object.entries(form).forEach(([key, value]) => formData.append(key, value));
    images.forEach((image) => formData.append('images[]', image));
    if (video) formData.append('video', video);

    try {
      if (productId) {
        formData.append('_method', 'PUT');
        await api.post(`/merchant/products/${productId}`, formData, { isFormData: true });
        toast.success('Produit mis à jour.');
      } else {
        await api.post('/merchant/products', formData, { isFormData: true });
        toast.success('Produit créé.');
      }
      onCreated?.();
    } catch (err) {
      if (err?.errors) setErrors(err.errors);
      toast.error(err.message);
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <Field label="Nom du produit" error={fieldError('name')} required>
        <Input value={form.name} onChange={update('name')} required placeholder="Ex. Téléphone Android 128 Go" />
      </Field>

      <Field label="Description" error={fieldError('description')} hint="Décris le produit : matière, garantie, état…">
        <Textarea value={form.description} onChange={update('description')} placeholder="Décris ton produit en quelques lignes" />
      </Field>

      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Catégorie" error={fieldError('category')}>
          <Input value={form.category} onChange={update('category')} placeholder="Ex. Électronique" />
        </Field>

        <Field label="Prix (FCFA)" error={fieldError('price')} required>
          <Input type="number" min="0" step="1" value={form.price} onChange={update('price')} required placeholder="250000" />
        </Field>

        <Field label="Stock" error={fieldError('stock')} required>
          <Input type="number" min="0" value={form.stock} onChange={update('stock')} required />
        </Field>

        <Field label="Statut" error={fieldError('status')} required>
          <Select value={form.status} onChange={update('status')}>
            <option value="draft">Brouillon (invisible)</option>
            <option value="published">Publié (visible au catalogue)</option>
            <option value="archived">Archivé</option>
          </Select>
        </Field>
      </div>

      <Field label="Photos (jusqu’à 6)" error={fieldError('images')} hint="jpg, jpeg, png ou webp — 5 Mo max par fichier.">
        <div>
          <label className="flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed border-line-strong bg-ink-50/60 px-4 py-6 text-sm font-medium text-ink-600 transition-colors hover:border-primary-300 hover:bg-primary-50/50">
            <ImagePlus className="size-4.5" aria-hidden="true" />
            Choisir des images
            <input
              ref={imageInput}
              type="file"
              multiple
              accept="image/jpeg,image/png,image/webp"
              className="sr-only"
              onChange={(event) => setImages([...event.target.files].slice(0, 6))}
            />
          </label>

          {images.length > 0 && (
            <ul className="mt-3 flex flex-wrap gap-2">
              {images.map((image, index) => (
                <li key={image.name}>
                  <div className="relative">
                    <img src={URL.createObjectURL(image)} alt="" className="size-16 rounded-lg object-cover" />
                    <button
                      type="button"
                      onClick={() => setImages((current) => current.filter((_, i) => i !== index))}
                      aria-label={`Retirer ${image.name}`}
                      className="absolute -right-1.5 -top-1.5 rounded-full bg-white p-1 shadow-md ring-1 ring-line"
                    >
                      <X className="size-3 text-ink-600" aria-hidden="true" />
                    </button>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
      </Field>

      <Field label="Vidéo (optionnel)" error={fieldError('video')} hint="mp4, mov ou webm — 50 Mo max.">
        <label className="flex cursor-pointer items-center gap-2 rounded-xl border border-line-strong bg-white px-3.5 py-2.5 text-sm text-ink-600 transition-colors hover:bg-ink-50">
          <Upload className="size-4" aria-hidden="true" />
          <span className="truncate">{video ? video.name : 'Choisir une vidéo'}</span>
          <input type="file" accept="video/mp4,video/quicktime,video/webm" className="sr-only" onChange={(event) => setVideo(event.target.files?.[0] || null)} />
        </label>
      </Field>

      <div className="flex flex-wrap gap-2.5 pt-2">
        <Button type="submit" loading={isSubmitting} disabled={isSubmitting}>
          {productId ? 'Enregistrer les modifications' : 'Créer le produit'}
        </Button>
        {onCancel && (
          <Button type="button" variant="ghost" onClick={onCancel}>
            Annuler
          </Button>
        )}
      </div>
    </form>
  );
}

export { ProductForm };
