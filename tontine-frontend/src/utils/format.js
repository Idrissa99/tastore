/**
 * Formatage centralisé des montants, dates et textes.
 * Les montants coming de l'API sont des chaînes décimales ("412.00").
 * Tout est rendu en FCFA avec un espace comme séparateur de milliers.
 */

const NBSP = '\u00A0';
const NNBSP = '\u202F';

/** Normalise les espaces fines insécables produites par Intl en un espace insécable stable. */
function normalizeSpaces(value) {
  return value.replace(new RegExp(`[${NNBSP}\u2009}]`, 'g'), NBSP).replace(new RegExp(NBSP, 'g'), NBSP);
}

/** Convertit une valeur API (nombre ou chaîne décimale) en nombre. */
export function toNumber(value) {
  if (value === null || value === undefined || value === '') return 0;
  const parsed = typeof value === 'number' ? value : Number.parseFloat(String(value).replace(',', '.'));
  return Number.isFinite(parsed) ? parsed : 0;
}

/**
 * Formate un montant en FCFA.
 * @example formatFcfa(5000) // "5 000 FCFA"
 * @example formatFcfa(1250000) // "1 250 000 FCFA"
 * @example formatFcfa(null) // "0 FCFA"
 */
export function formatFcfa(value, { withCurrency = true, decimals = false } = {}) {
  const amount = toNumber(value);
  const formatted = normalizeSpaces(
    amount.toLocaleString('fr-FR', {
      minimumFractionDigits: decimals ? 2 : 0,
      maximumFractionDigits: decimals ? 2 : 0,
    }),
  );
  return withCurrency ? `${formatted}${NBSP}FCFA` : formatted;
}

/** Montant compact pour les tuiles de statistiques (1,2 M / 850 k). */
export function formatFcfaCompact(value) {
  const amount = toNumber(value);
  if (Math.abs(amount) >= 1_000_000) {
    return `${normalizeSpaces((amount / 1_000_000).toLocaleString('fr-FR', { maximumFractionDigits: 1 }))}${NBSP}M`;
  }
  if (Math.abs(amount) >= 10_000) {
    return `${normalizeSpaces((amount / 1_000).toLocaleString('fr-FR', { maximumFractionDigits: 0 }))}${NBSP}k`;
  }
  return normalizeSpaces(amount.toLocaleString('fr-FR', { maximumFractionDigits: 0 }));
}

export function formatNumber(value) {
  return normalizeSpaces(toNumber(value).toLocaleString('fr-FR', { maximumFractionDigits: 0 }));
}

export function formatPercent(value, decimals = 0) {
  const percent = toPercentValue(value);
  return `${normalizeSpaces(percent.toLocaleString('fr-FR', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }))}${NBSP}%`;
}

/**
 * Même chose, mais sans zéros inutiles : « 3 % », « 3,5 % », « 3,33 % ».
 *
 * Utilisé quand le taux s'affiche hors d'un formulaire (page d'accueil,
 * tuile de statistique) : « 3,00 % » y ferait plus administratif que
 * nécessaire, alors qu'un arrondi brutal masquerait un taux de 3,5 %.
 */
export function formatPercentAuto(value) {
  const percent = toPercentValue(value);
  if (!Number.isFinite(percent)) return '—';
  return `${normalizeSpaces(percent.toLocaleString('fr-FR', { maximumFractionDigits: 2 }))}${NBSP}%`;
}

/** Normalise un taux exprimé en fraction (0.03) ou en pourcentage (3). */
function toPercentValue(value) {
  const parsed = toNumber(value);
  return parsed <= 1 ? parsed * 100 : parsed;
}

/* ------------------------------------------------------------------ */
/* Dates                                                               */
/* ------------------------------------------------------------------ */

function parseDate(value) {
  if (!value) return null;
  const date = value instanceof Date ? value : new Date(value);
  return Number.isNaN(date.getTime()) ? null : date;
}

/** "5 oct. 2026" */
export function formatDate(value) {
  const date = parseDate(value);
  if (!date) return '—';
  return normalizeSpaces(date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' }));
}

/** "02 octobre 2026" */
export function formatDateLong(value) {
  const date = parseDate(value);
  if (!date) return '—';
  return normalizeSpaces(
    date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' }),
  );
}

/** "02/10/2026" */
export function formatDateShort(value) {
  const date = parseDate(value);
  if (!date) return '—';
  return normalizeSpaces(date.toLocaleDateString('fr-FR'));
}

/** "02 oct. 2026 à 14:35" */
export function formatDateTime(value) {
  const date = parseDate(value);
  if (!date) return '—';
  const day = normalizeSpaces(date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' }));
  const time = normalizeSpaces(date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }));
  return `${day} à ${time}`;
}

/** "il y a 3 jours", "dans 2 h" */
export function formatRelative(value) {
  const date = parseDate(value);
  if (!date) return '—';

  const diffMs = date.getTime() - Date.now();
  const abs = Math.abs(diffMs);
  const rtf = new Intl.RelativeTimeFormat('fr-FR', { numeric: 'auto', style: 'long' });

  const units = [
    { limit: 60_000, div: 1_000, unit: 'second' },
    { limit: 3_600_000, div: 60_000, unit: 'minute' },
    { limit: 86_400_000, div: 3_600_000, unit: 'hour' },
    { limit: 2_592_000_000, div: 86_400_000, unit: 'day' },
    { limit: 31_536_000_000, div: 604_800_000, unit: 'week' },
    { limit: Infinity, div: 2_592_000_000, unit: 'month' },
  ];

  const match = units.find((u) => abs < u.limit) || units[units.length - 1];
  return rtf.format(Math.round(diffMs / match.div), match.unit);
}

/** Nombre de jours calendaires entre aujourd'hui et une date (0 = aujourd'hui). */
export function daysUntil(value) {
  const date = parseDate(value);
  if (!date) return null;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const target = new Date(date);
  target.setHours(0, 0, 0, 0);
  return Math.round((target - today) / 86_400_000);
}

/** "dans 3 jours", "aujourd'hui", "il y a 2 jours" — orienté échéance. */
export function formatCountdown(value) {
  const days = daysUntil(value);
  if (days === null) return '—';
  if (days === 0) return "aujourd'hui";
  if (days === 1) return 'demain';
  if (days === -1) return 'hier';
  return days > 0 ? `dans ${days} jours` : `il y a ${Math.abs(days)} jours`;
}

/* ------------------------------------------------------------------ */
/* Textes & divers                                                     */
/* ------------------------------------------------------------------ */

/** Initiales pour les avatars (2 lettres max). */
export function initials(name) {
  if (!name) return '?';
  return String(name)
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('');
}

/** Première lettre en capitale. */
export function capitalize(value) {
  if (!value) return '';
  return String(value).charAt(0).toUpperCase() + String(value).slice(1);
}

/** Accord singulier / pluriel : pluralize(1, 'tontine') -> "1 tontine". */
export function pluralize(count, singular, plural) {
  const n = toNumber(count);
  return `${formatNumber(n)} ${n > 1 ? plural || `${singular}s` : singular}`;
}

/** Borne une progression entre 0 et 100. */
export function clampPercent(value, total) {
  const t = toNumber(total);
  if (t <= 0) return 0;
  const ratio = (toNumber(value) / t) * 100;
  return Math.min(100, Math.max(0, Math.round(ratio)));
}

/** Raccourcit un texte long en conservant la fin (utile pour les références). */
export function truncate(value, max = 40) {
  if (!value) return '';
  const str = String(value);
  return str.length <= max ? str : `${str.slice(0, max - 1)}…`;
}

/** Retire les caractères non alphanumériques d'une référence de paiement. */
export function normalizeTransferCode(value) {
  return String(value || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase();
}
