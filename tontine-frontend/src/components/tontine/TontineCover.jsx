/**
 * Visuel d'une tontine.
 *
 * Deux cas, dans cet ordre :
 *
 *  1. une photo RÉELLE existe pour le produit (tontine produit dont le
 *     commerçant a téléversé des visuels) — on l'affiche, une photo vaut mieux
 *     qu'un avatar ;
 *  2. sinon — tontine argent, ou tontine produit sans photo — on affiche
 *     l'AVATAR DU PROJET : initiales sur dégradé déterministe, en cercle. C'est
 *     exactement le rendu de la photo de profil par défaut, parce que c'est le
 *     MÊME composant, pas une copie à maintenir en parallèle.
 *
 * Le badge TA / TP rappelle la nature de la tontine, positionné comme un badge
 * de rôle sur le cercle : il ne déforme pas l'avatar.
 */
import { Avatar } from '../ui/Avatar';
import ProductImage, { productImage } from '../ui/ProductImage';

/**
 * Nom dont on tire les initiales et le dégradé.
 *
 * Tontine argent : pas de produit, on prend le nom de la tontine. Tontine
 * produit : les initiales sont celles du PRODUIT — deux tontines vendues sur
 * le même produit se ressemblent, ce qui est vrai.
 */
function labelFor(tontine) {
  return tontine.type === 'cash' ? tontine.name : tontine.product?.name || tontine.name;
}

function Badge({ type }) {
  const isCash = type === 'cash';

  return (
    <span
      className={`absolute -bottom-0.5 -right-0.5 flex items-center justify-center rounded-full font-bold leading-none text-white ring-2 ring-white ${
        isCash ? 'h-5 w-9 bg-ink-900 text-[9px]' : 'h-5 w-8 bg-primary-600 text-[10px]'
      }`}
      title={isCash ? 'Tontine argent' : 'Tontine produit'}
    >
      {isCash ? 'TA' : 'TP'}
    </span>
  );
}

/** L'avatar seul, badge superposé. Ne gère pas la boîte qui l'entoure. */
function TontineAvatar({ tontine, size = 'lg' }) {
  return (
    <span className="relative inline-flex">
      <Avatar name={labelFor(tontine)} size={size} />
      <Badge type={tontine.type} />
    </span>
  );
}

/**
 * Tailles de vignette et avatar associé.
 *
 * L'avatar doit laisser de la marge : le badge déborde volontairement de 2 px,
 * sinon il serait rogné par la boîte. `size-12` (48 px) accueille l'avatar
 * 'md' (44 px), `size-10` (40 px) l'avatar 'sm' (36 px) — pas l'inverse, qui
 * déborderait.
 */
const THUMB_SIZES = {
  'size-10': 'sm',
  'size-12': 'md',
  'size-14': 'lg',
};

/**
 * @param {object} tontine
 * @param {'hero'|'thumb'} variant  hero = grand bloc (carte, fiche de détail),
 *                                   thumb = vignette carrée (listes).
 * @param {'size-10'|'size-12'|'size-14'} thumbSize
 */
export default function TontineCover({
  tontine,
  variant = 'hero',
  thumbSize = 'size-12',
  rounded = 'rounded-xl',
  className = '',
}) {
  if (!tontine) return null;

  const isCash = tontine.type === 'cash';
  const photo = isCash ? null : productImage(tontine.product);
  const alt = tontine.product?.name || tontine.name;

  if (variant === 'thumb') {
    const avatarSize = THUMB_SIZES[thumbSize] || 'md';

    return photo ? (
      <ProductImage
        src={photo}
        alt={alt}
        ratio="square"
        rounded={rounded}
        iconSize="size-5"
        className={`${thumbSize} shrink-0 ${className}`}
      />
    ) : (
      <span
        className={`flex ${thumbSize} shrink-0 items-center justify-center ${rounded} bg-ink-50 ${className}`}
      >
        <TontineAvatar tontine={tontine} size={avatarSize} />
      </span>
    );
  }

  return photo ? (
    <ProductImage
      src={photo}
      alt={alt}
      ratio="16/9"
      rounded="rounded-none"
      iconSize="size-8"
      className={className}
    />
  ) : (
    <div className={`flex aspect-video items-center justify-center bg-ink-50 ${className}`}>
      <TontineAvatar tontine={tontine} size="xl" />
    </div>
  );
}
