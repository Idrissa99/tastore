import { FREQUENCIES } from './status';

/**
 * Échéances.
 *
 * L'API n'expose pas de date d'échéance sur les cotisations : elle ne
 * connaît que `tontine.start_date`, `tontine.frequency` et le numéro du
 * round (`contribution.round`).
 *
 * On en déduit donc une date ESTIMÉE, jamais une donnée inventée :
 *   round 1 → jour du start_date, round N → start_date + (N-1) × fréquence.
 * L'interface la présente explicitement comme « échéance estimée » et
 * n'affiche rien si `start_date` est absente.
 */
export function estimatedDueDate(tontine, round) {
  const start = tontine?.start_date ? new Date(tontine.start_date) : null;
  if (!start || Number.isNaN(start.getTime())) return null;

  const frequency = FREQUENCIES[tontine.frequency] || FREQUENCIES.weekly;
  const number = Math.max(1, Number(round) || 1);

  const date = new Date(start);
  date.setDate(date.getDate() + (number - 1) * frequency.days);
  return date;
}

/**
 * Montant dû par un membre pour un round.
 * L'API n'expose pas de planning nominatif : le montant vient de la
 * cotisation réellement générée pour ce round.
 */
export function amountDue(contributions, tontineId, round) {
  const match = contributions.find(
    (item) => item.tontine?.id === tontineId && Number(item.round) === Number(round),
  );
  return match ? Number(match.amount || 0) : 0;
}

/**
 * Prochain versement en attente pour une tontine : le round le plus bas
 * dont la cotisation n'est ni validée ni en cours de vérification.
 */
export function nextPendingRound(contributions, tontineId) {
  const rounds = contributions
    .filter((item) => item.tontine?.id === tontineId)
    .filter((item) => item.status !== 'completed' && item.status !== 'cancelled')
    .filter((item) => item.verification_status !== 'accepted' && item.verification_status !== 'pending')
    .sort((a, b) => Number(a.round) - Number(b.round));

  return rounds[0] || null;
}

/** Nombre de mois estimé entre le début et la fin d'une tontine. */
export function estimatedDurationMonths(tontine) {
  if (!tontine?.start_date) return null;
  const start = new Date(tontine.start_date);
  if (Number.isNaN(start.getTime())) return null;

  const frequency = FREQUENCIES[tontine.frequency] || FREQUENCIES.weekly;
  const rounds = Math.max(1, Number(tontine.max_members || 1));
  const totalDays = frequency.days * rounds;
  return Math.max(1, Math.round(totalDays / 30));
}
