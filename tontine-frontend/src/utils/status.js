/**
 * Libellés et teintes de statut centralisés.
 * Toute l'application lit ses statuts dans ce fichier : un seul endroit à
 * mettre à jour, et des badges visuellement cohérents sur toutes les pages.
 */

/** Teintes disponibles pour <StatusBadge tone="..."> */
export const TONES = {
  success: 'bg-success-50 text-success-700 ring-success-200',
  warning: 'bg-warning-50 text-warning-700 ring-warning-200',
  danger: 'bg-danger-50 text-danger-700 ring-danger-200',
  info: 'bg-info-50 text-info-700 ring-info-200',
  brand: 'bg-primary-50 text-primary-800 ring-primary-200',
  accent: 'bg-accent-50 text-accent-700 ring-accent-200',
  neutral: 'bg-ink-100 text-ink-600 ring-ink-200',
};

const STATUS = {
  /* Tontine */
  open: { label: 'Ouverte', tone: 'brand' },
  active: { label: 'En cours', tone: 'success' },
  completed: { label: 'Terminée', tone: 'info' },
  cancelled: { label: 'Annulée', tone: 'danger' },

  /* Cotisation / tranche */
  pending: { label: 'En attente', tone: 'warning' },
  failed: { label: 'Échoué', tone: 'danger' },

  /* Vérification manuelle du transfert */
  accepted: { label: 'Accepté', tone: 'success' },
  rejected: { label: 'Refusé', tone: 'danger' },
  not_applicable: { label: 'Sans objet', tone: 'neutral' },

  /* Membre de tontine */
  beneficiary: { label: 'Bénéficiaire', tone: 'accent' },
  withdrawn: { label: 'Sorti', tone: 'neutral' },
  'completed-good': { label: 'Payé', tone: 'success' },

  /* Livraison */
  awaiting_payment: { label: 'En attente du tour', tone: 'warning' },
  delivered: { label: 'Livré', tone: 'success' },

  /* Produit */
  draft: { label: 'Brouillon', tone: 'neutral' },
  published: { label: 'En ligne', tone: 'success' },
  archived: { label: 'Archivé', tone: 'neutral' },

  /* Commerçant */
  approved: { label: 'Approuvé', tone: 'success' },

  /* Litige */
  open_dispute: { label: 'Litige ouvert', tone: 'warning' },
  resolved: { label: 'Résolu', tone: 'success' },

  /* Remboursement */
  processed: { label: 'Traité', tone: 'success' },

  /* Rôle utilisateur */
  client: { label: 'Client', tone: 'neutral' },
  merchant: { label: 'Commerçant', tone: 'brand' },
  admin: { label: 'Administrateur', tone: 'accent' },
  blocked: { label: 'Bloqué', tone: 'danger' },
};

/**
 * Résout un statut backend vers { label, tone }.
 * Le statut "completed" est ambigu côté API (tontine terminée d'un côté,
 * cotisation payée de l'autre) : les appelants qui savent précisément de
 * quoi il s'agit transmettent "completed-good" pour une cotisation validée.
 */
export function resolveStatus(status, { completedLabel, completedTone } = {}) {
  if (!status) return { label: '—', tone: 'neutral' };
  if (status === 'completed' && (completedLabel || completedTone)) {
    return { label: completedLabel || 'Terminée', tone: completedTone || 'info' };
  }
  return STATUS[status] || { label: String(status).replace(/_/g, ' '), tone: 'neutral' };
}

export function statusTone(status, tone) {
  return tone || resolveStatus(status).tone;
}

/* ------------------------------------------------------------------ */
/* Métadonnées métier                                                   */
/* ------------------------------------------------------------------ */

export const FREQUENCIES = {
  daily: { label: 'par jour', short: 'Jour', days: 1 },
  weekly: { label: 'par semaine', short: 'Semaine', days: 7 },
  monthly: { label: 'par mois', short: 'Mois', days: 30 },
};

export function frequencyLabel(frequency) {
  return FREQUENCIES[frequency]?.label || frequency || '—';
}

export const TONTINE_TYPES = {
  cash: { label: 'Tontine Argent', short: 'Argent', tone: 'brand' },
  product: { label: 'Tontine Produit', short: 'Produit', tone: 'accent' },
};

export function tontineTypeLabel(type) {
  return TONTINE_TYPES[type]?.label || 'Tontine';
}

/** Moyens de paiement nécessitant un code de transfert vérifié par un admin. */
export const MANUAL_PAYMENT_CHANNELS = ['mynita', 'amana'];

export function isManualChannel(channel) {
  return MANUAL_PAYMENT_CHANNELS.includes(channel);
}

/** Raisons métier bloquant une livraison (API: DeliveryService). */
export const DELIVERY_BLOCKERS = {
  cash_tontine: "Tontine argent : aucune livraison physique n'est gérée.",
  tontine_cancelled: 'La tontine a été annulée.',
  not_beneficiary: "Le membre n'est pas bénéficiaire d'un tour.",
  turn_not_active: "Le tour du membre n'est pas encore arrivé.",
  round_not_paid: 'Tous les membres du round n’ont pas encore cotisé.',
  already_delivered: 'Le produit a déjà été remis.',
  not_awaiting_delivery: "Le membre n'est pas en attente de livraison.",
  product_unavailable: 'Le produit est indisponible ou archivé.',
};

/** Catégories de notification (API: app/Notifications). */
export const NOTIFICATION_ICONS = {
  BecameBeneficiaryNotification: 'gift',
  DeliveryConfirmedNotification: 'check',
  DisputeResolvedNotification: 'scale',
  ContributionVerificationUpdatedNotification: 'receipt',
  InstallmentVerificationUpdatedNotification: 'receipt',
  InstallmentPurchaseCompletedNotification: 'party',
  InstallmentDeliveryConfirmedNotification: 'package',
};
