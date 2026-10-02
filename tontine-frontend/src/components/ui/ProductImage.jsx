import { ImageOff } from 'lucide-react';

/**
 * Image de produit avec repli élégant.
 * L'API renvoie soit `image` (legacy), soit `images[0].url` (ProductMedia).
 * Les URLs sont relatives au domaine du backend : on les rend absolues.
 */

const BACKEND_ORIGIN = (() => {
  const base = import.meta.env.VITE_API_BASE_URL || 'http://127.0.0.1:8000/api';
  try {
    return new URL(base).origin;
  } catch {
    return '';
  }
})();

/** Transforme une URL de média backend en URL absolue exploitable par <img>. */
export function mediaUrl(url) {
  if (!url) return null;
  const value = String(url);
  if (/^https?:\/\//i.test(value) || value.startsWith('data:')) return value;
  return `${BACKEND_ORIGIN}${value.startsWith('/') ? '' : '/'}${value}`;
}

/** Première image disponible d'un produit (tontine ou catalogue). */
export function productImage(product) {
  if (!product) return null;
  if (Array.isArray(product.images) && product.images.length > 0) {
    return mediaUrl(product.images[0]?.url || product.images[0]);
  }
  return mediaUrl(product.image) || null;
}

const RATIOS = {
  square: 'aspect-square',
  '4/3': 'aspect-[4/3]',
  '16/9': 'aspect-video',
  '3/4': 'aspect-[3/4]',
};

export function ProductImage({
  src,
  alt = '',
  ratio = 'square',
  className = '',
  imgClassName = '',
  rounded = 'rounded-xl',
  iconSize = 'size-7',
}) {
  return (
    <div
      className={`relative flex items-center justify-center overflow-hidden bg-gradient-to-br from-ink-50 to-ink-100 ${RATIOS[ratio] || RATIOS.square} ${rounded} ${className}`}
    >
      {src ? (
        <img
          src={src}
          alt={alt}
          loading="lazy"
          decoding="async"
          className={`size-full object-cover transition-transform duration-500 ${imgClassName}`}
        />
      ) : (
        <span className="flex flex-col items-center gap-1.5 text-ink-300">
          <ImageOff className={iconSize} aria-hidden="true" />
          <span className="text-[11px] font-medium">Pas de photo</span>
        </span>
      )}
    </div>
  );
}

export default ProductImage;
