import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Film, ImageIcon, Trash2, X } from 'lucide-react';
import { api } from '../../api/client';
import { useToast } from '../../context/ToastContext';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { Breadcrumb } from '../../components/ui/PageHeader';
import { Card } from '../../components/ui/Card';
import { Button, ButtonLink } from '../../components/ui/Button';
import { Field, Input, Select, Textarea } from '../../components/ui/Input';
import { StatusBadge } from '../../components/ui/Badge';
import { ConfirmationDialog } from '../../components/ui/ConfirmationDialog';
import { Skeleton } from '../../components/ui/Skeleton';
import { ErrorState } from '../../components/ui/EmptyState';
import { mediaUrl } from '../../components/ui/ProductImage';
import { formatFcfa } from '../../utils/format';

/**
 * Édition d'un produit (espace commerçant).
 * PUT /merchant/products/{id} via POST + _method (multipart),
 * DELETE /merchant/products/media/{id}, DELETE /merchant/products/{id}.
 */
export default function MerchantProductEdit() {
  const { id } = useParams();
  const navigate = useNavigate();
  const toast = useToast();

  const [product, setProduct] = useState(null);
  const [form, setForm] = useState(null);
  const [newImages, setNewImages] = useState([]);
  const [newVideo, setNewVideo] = useState(null);
  const [errors, setErrors] = useState({});
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [busyMedia, setBusyMedia] = useState(null);

  useDocumentTitle(product ? `Modifier ${product.name}` : 'Produit');

  const load = async () => {
    try {
      // L'API n'expose pas GET /merchant/products/{id} : on récupère la liste
      // et on isole le produit visé (comportement historique conservé).
      const data = await api.get('/merchant/products');
      const found = (data.data || []).find((item) => String(item.id) === String(id));
      if (!found) {
        setError(new Error('Produit introuvable dans ton catalogue.'));
        return;
      }
      setProduct(found);
      setForm({
        name: found.name || '',
        description: found.description || '',
        category: found.category || '',
        price: found.price ?? '',
        stock: found.stock ?? 0,
        status: found.status || 'draft',
      });
    } catch (err) {
      setError(err);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id]);

  const update = (field) => (event) => setForm((current) => ({ ...current, [field]: event.target.value }));
  const fieldError = (name) => errors[name]?.[0];

  const handleSubmit = async (event) => {
    event.preventDefault();
    setErrors({});
    setIsSubmitting(true);

    const formData = new FormData();
    formData.append('_method', 'PUT');
    Object.entries(form).forEach(([key, value]) => formData.append(key, value));
    newImages.forEach((image) => formData.append('images[]', image));
    if (newVideo) formData.append('video', newVideo);

    try {
      await api.post(`/merchant/products/${id}`, formData, { isFormData: true });
      toast.success('Produit mis à jour.');
      navigate('/commercant/produits');
    } catch (err) {
      if (err?.errors) setErrors(err.errors);
      toast.error(err.message);
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleDeleteMedia = async (mediaId) => {
    setBusyMedia(mediaId);
    try {
      await api.delete(`/merchant/products/media/${mediaId}`);
      toast.success('Média supprimé.');
      await load();
    } catch (err) {
      toast.error(err.message);
    } finally {
      setBusyMedia(null);
    }
  };

  const handleDeleteProduct = async () => {
    setDeleting(true);
    try {
      await api.delete(`/merchant/products/${id}`);
      toast.success('Produit supprimé.');
      navigate('/commercant/produits');
    } catch (err) {
      toast.error(err.message);
      setConfirmDelete(false);
    } finally {
      setDeleting(false);
    }
  };

  if (isLoading) {
    return (
      <div className="max-w-3xl space-y-5" aria-busy="true">
        <Skeleton className="h-8 w-64" />
        <Card>
          <Skeleton className="h-40 w-full rounded-xl" />
        </Card>
        <Card className="space-y-4">
          {Array.from({ length: 5 }).map((_, index) => (
            <Skeleton key={index} className="h-12 w-full rounded-xl" />
          ))}
        </Card>
      </div>
    );
  }

  if (error) {
    return (
      <div className="mx-auto max-w-2xl py-10">
        <ErrorState message={error.message} onRetry={load} />
        <div className="mt-5 text-center">
          <Link to="/commercant/produits" className="text-sm font-semibold text-primary-700 hover:underline">
            ← Retour à mes produits
          </Link>
        </div>
      </div>
    );
  }

  const hasMedia = (product.images?.length || 0) > 0 || Boolean(product.video);

  return (
    <div className="max-w-3xl">
      <Breadcrumb
        items={[
          { label: 'Espace commerçant' },
          { label: 'Produits', to: '/commercant/produits' },
          { label: product.name },
        ]}
        className="mb-4"
      />

      <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0">
          <h1 className="font-display text-2xl font-extrabold tracking-tight text-ink-900">Modifier le produit</h1>
          <p className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-ink-500">
            <span className="font-medium text-ink-700">{product.name}</span>
            <StatusBadge status={product.status} size="xs" />
            <span className="amount">{formatFcfa(product.price)}</span>
          </p>
        </div>
        <ButtonLink to="/commercant/produits" variant="ghost" size="sm" icon={ArrowLeft}>
          Retour
        </ButtonLink>
      </div>

      {/* Médias actuels */}
      {hasMedia && (
        <Card className="mb-6">
          <h2 className="font-display text-base font-bold text-ink-900">Médias du produit</h2>
          <p className="mt-1 text-xs text-ink-500">
            Les médias sont supprimés immédiatement. La suppression est définitive.
          </p>

          <ul className="mt-4 flex flex-wrap gap-3">
            {product.images?.map((image) => (
              <li key={image.id} className="relative">
                <img
                  src={mediaUrl(image.url)}
                  alt=""
                  loading="lazy"
                  className="size-24 rounded-xl border border-line-strong object-cover"
                />
                <MediaDeleteButton busy={busyMedia === image.id} onClick={() => handleDeleteMedia(image.id)} />
              </li>
            ))}

            {product.video && (
              <li className="relative">
                <video src={mediaUrl(product.video.url)} className="size-24 rounded-xl border border-line-strong object-cover" muted />
                <span className="absolute bottom-1.5 left-1.5 inline-flex items-center gap-1 rounded-md bg-ink-950/70 px-1.5 py-0.5 text-[10px] font-semibold text-white">
                  <Film className="size-2.5" aria-hidden="true" />
                  Vidéo
                </span>
                <MediaDeleteButton busy={busyMedia === product.video.id} onClick={() => handleDeleteMedia(product.video.id)} />
              </li>
            )}
          </ul>
        </Card>
      )}

      <Card>
        <h2 className="font-display text-base font-bold text-ink-900">Informations</h2>

        <form onSubmit={handleSubmit} className="mt-4 space-y-4">
          <Field label="Nom du produit" error={fieldError('name')} required>
            <Input value={form.name} onChange={update('name')} required />
          </Field>

          <Field label="Description" error={fieldError('description')}>
            <Textarea value={form.description} onChange={update('description')} />
          </Field>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Catégorie" error={fieldError('category')}>
              <Input value={form.category} onChange={update('category')} />
            </Field>

            <Field label="Prix (FCFA)" error={fieldError('price')} required>
              <Input type="number" min="0" value={form.price} onChange={update('price')} required />
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

          <Field label="Ajouter des photos" error={fieldError('images')} hint="Jusqu’à 6 fichiers — jpg, png ou webp, 5 Mo max.">
            <label className="flex cursor-pointer items-center gap-2 rounded-xl border border-dashed border-line-strong bg-ink-50/60 px-3.5 py-3 text-sm font-medium text-ink-600 transition-colors hover:border-primary-300">
              <ImageIcon className="size-4" aria-hidden="true" />
              <span className="truncate">
                {newImages.length ? `${newImages.length} fichier(s) sélectionné(s)` : 'Choisir des images'}
              </span>
              <input
                type="file"
                multiple
                accept="image/jpeg,image/png,image/webp"
                className="sr-only"
                onChange={(event) => setNewImages([...event.target.files].slice(0, 6))}
              />
            </label>
          </Field>

          <Field label="Ajouter ou remplacer la vidéo" error={fieldError('video')} hint="mp4, mov ou webm — 50 Mo max.">
            <label className="flex cursor-pointer items-center gap-2 rounded-xl border border-line-strong bg-white px-3.5 py-2.5 text-sm text-ink-600 transition-colors hover:bg-ink-50">
              <Film className="size-4" aria-hidden="true" />
              <span className="truncate">{newVideo ? newVideo.name : 'Choisir une vidéo'}</span>
              <input
                type="file"
                accept="video/mp4,video/quicktime,video/webm"
                className="sr-only"
                onChange={(event) => setNewVideo(event.target.files?.[0] || null)}
              />
            </label>
          </Field>

          <div className="flex flex-wrap items-center gap-2.5 border-t border-line-soft pt-5">
            <Button type="submit" loading={isSubmitting} disabled={isSubmitting}>
              Enregistrer les modifications
            </Button>
            <Button type="button" variant="danger-outline" icon={Trash2} onClick={() => setConfirmDelete(true)}>
              Supprimer le produit
            </Button>
          </div>
        </form>
      </Card>

      <ConfirmationDialog
        open={confirmDelete}
        onClose={() => setConfirmDelete(false)}
        onConfirm={handleDeleteProduct}
        title="Supprimer ce produit ?"
        message={
          <>
            <strong>{product?.name}</strong> sera définitivement supprimé de ton catalogue. S’il est lié à une
            tontine ou à un achat déjà engagé, l’API refusera la suppression.
          </>
        }
        confirmLabel="Supprimer définitivement"
        loading={deleting}
      />
    </div>
  );
}

function MediaDeleteButton({ onClick, busy }) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={busy}
      aria-label="Supprimer ce média"
      className="absolute -right-2 -top-2 flex size-6 items-center justify-center rounded-full bg-white text-ink-600 shadow-md ring-1 ring-line transition-colors hover:bg-danger-50 hover:text-danger-600 disabled:opacity-50"
    >
      <X className="size-3" aria-hidden="true" />
    </button>
  );
}
