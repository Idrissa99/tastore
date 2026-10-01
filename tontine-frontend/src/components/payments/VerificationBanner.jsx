import { AlertTriangle, CheckCircle2, Clock, ShieldCheck, XCircle } from 'lucide-react';

/**
 * Bandeau d'état d'un versement.
 * L'API expose deux axes distincts qu'il ne faut jamais confondre :
 *   - `status`               : état du versement lui-même (pending/completed/failed)
 *   - `verification_status`  : état de la vérification manuelle du code de transfert
 */
const STATES = {
  pending_verification: {
    tone: 'warning',
    ring: 'ring-warning-200',
    bg: 'bg-warning-50',
    text: 'text-warning-700',
    Icon: Clock,
    title: 'Paiement soumis — en attente de vérification',
    body: 'Ton versement a bien été enregistré. Un administrateur compare le code de transfert à l’opération mobile money avant de le valider.',
  },
  accepted: {
    tone: 'success',
    ring: 'ring-success-200',
    bg: 'bg-success-50',
    text: 'text-success-700',
    Icon: CheckCircle2,
    title: 'Paiement confirmé',
    body: 'Ton versement a été vérifié et accepté. Le montant est comptabilisé dans ta tontine.',
  },
  rejected: {
    tone: 'danger',
    ring: 'ring-danger-200',
    bg: 'bg-danger-50',
    text: 'text-danger-700',
    Icon: XCircle,
    title: 'Paiement refusé',
    body: 'Le code de transfert fourni n’a pas pu être rapproché. Tu peux en soumettre un nouveau ci-dessous.',
  },
  completed: {
    tone: 'success',
    ring: 'ring-success-200',
    bg: 'bg-success-50',
    text: 'text-success-700',
    Icon: CheckCircle2,
    title: 'Versement validé',
    body: 'Ce versement est comptabilisé dans le round en cours.',
  },
  failed: {
    tone: 'danger',
    ring: 'ring-danger-200',
    bg: 'bg-danger-50',
    text: 'text-danger-700',
    Icon: AlertTriangle,
    title: 'Versement échoué',
    body: 'L’opération n’a pas abouti. Réessaie avec un autre moyen de paiement.',
  },
  due: {
    tone: 'brand',
    ring: 'ring-primary-200',
    bg: 'bg-primary-50',
    text: 'text-primary-800',
    Icon: Clock,
    title: 'Versement attendu',
    body: 'Ce versement n’a pas encore été réglé.',
  },
};

export function resolvePaymentState({ status, verificationStatus }) {
  if (verificationStatus === 'pending') return STATES.pending_verification;
  if (verificationStatus === 'accepted') return STATES.accepted;
  if (verificationStatus === 'rejected') return STATES.rejected;
  if (status === 'completed') return STATES.completed;
  if (status === 'failed') return STATES.failed;
  if (status === 'cancelled') return null;
  return STATES.due;
}

export default function VerificationBanner({ status, verificationStatus, reason, className = '' }) {
  const state = resolvePaymentState({ status, verificationStatus });
  if (!state) return null;

  const { Icon, ring, bg, text, title, body } = state;

  return (
    <div className={`flex items-start gap-3 rounded-xl px-4 py-3.5 ring-1 ring-inset ${bg} ${ring} ${className}`}>
      <Icon className={`mt-0.5 size-4.5 shrink-0 ${text}`} aria-hidden="true" />
      <div className="min-w-0 flex-1">
        <p className={`text-sm font-bold ${text}`}>{title}</p>
        <p className="mt-0.5 text-xs leading-relaxed text-ink-600">{body}</p>
        {reason && <p className="mt-1 text-xs font-medium text-ink-600">Motif : {reason}</p>}
      </div>
    </div>
  );
}

/** Rappel discret des règles de vérification manuelle. */
export function ManualChannelNotice({ className = '' }) {
  return (
    <div className={`flex items-start gap-2.5 rounded-xl bg-ink-50 px-3.5 py-3 text-xs leading-relaxed text-ink-600 ${className}`}>
      <ShieldCheck className="mt-0.5 size-4 shrink-0 text-primary-600" aria-hidden="true" />
      <p>
        Effectue le transfert depuis ton téléphone, puis saisis le code reçu pour que notre équipe le vérifie.
        Le versement n’est comptabilisé qu’après validation.
      </p>
    </div>
  );
}

export { STATES };
