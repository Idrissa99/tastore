/**
 * Règles de calcul du badge de notifications.
 *
 * Deux besoins distincts sont confondus dans un simple compteur de « non
 * lues », ce qui produit exactement le défaut signalé : un badge rouge qui
 * ne part pas, ou qui ment sur ce qui reste à faire.
 *
 *  1. « Je ne l'ai pas encore vu » → se règle au clic. Doit disparaître
 *     immédiatement, sans recharger la page.
 *  2. « L'action n'est pas terminée » → ne se règle PAS au clic. Un
 *     paiement reste en attente tant que personne ne l'a vérifié : l'_user
 *     ne peut pas résoudre ce blocage, seul l'administrateur le peut.
 *
 * On sépare donc les deux notions : `unread` (vue) et `actionRequired`
 * (en attente de vérification). Le badge affiché est leur union.
 */

/** Statuts de vérification qui signifient « quelqu'un doit encore agir ». */
const PENDING_VERIFICATION = 'pending';

/**
 * Identifie la ressource concernée par une notification.
 *
 * Combine le TYPE et l'identifiant de la ressource, ce qui est indispensable :
 * une tontine génère à la fois « tour arrivé » et « livraison confirmée », et
 * ces deux événements sont indépendants. Se contenter de l'identifiant de la
 * tontine ferait disparaître la livraison quand le tour arrive, ce qui serait
 * une perte d'information réelle.
 *
 * Le type en revanche doit regrouper : un même paiement notifié trois fois
 * (créé, en attente, accepté) ne doit compter qu'une fois, dans son état
 * le plus récent.
 */
export function notificationSubject(notification) {
  const data = notification?.data || {};
  const resource = [
    data.contribution_id,
    data.installment_id,
    data.installment_purchase_id,
    data.dispute_id,
    data.tontine_id,
  ].find((value) => value !== undefined && value !== null);

  return resource === undefined ? `notification:${notification?.id}` : `${notification?.type}:${resource}`;
}

/**
 * Vrai si la notification décrit une vérification encore à faire.
 *
 * Source de vérité : le `verification_status` embarqué par le backend dans le
 * payload. On ne le devine pas d'après le texte du message, qui est rédigé
 * en français et pourrait dire autre chose.
 */
export function isAwaitingVerification(notification) {
  return notification?.data?.verification_status === PENDING_VERIFICATION;
}

/**
 * Ne conserve que la notification la plus récente de chaque sujet.
 *
 * L'API renvoie déjà les plus récentes en premier, mais on ne s'y fie pas :
 * l'ordre n'est pas garanti par le contrat et une inversion ferait réapparaître
 * un état périmé (par exemple un paiement affiché « en attente » après avoir
 * été accepté).
 */
export function latestPerSubject(items = []) {
  const seen = new Set();
  const latest = [];

  for (const item of items) {
    const key = notificationSubject(item);
    if (seen.has(key)) continue;
    seen.add(key);
    latest.push(item);
  }

  return latest;
}

/**
 * Sépare ce qui est simplement non lu de ce qui bloque encore une action.
 */
export function partitionNotifications(items = [], dismissed = new Set()) {
  const visible = latestPerSubject(items);

  const actionable = visible.filter((item) => isAwaitingVerification(item));
  const seen = visible.filter(
    (item) => !item.read_at && !dismissed.has(item.id) && !isAwaitingVerification(item),
  );

  return { visible, unseen: seen, actionable };
}
