import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { api } from '../api/client';
import { useAuth } from '../hooks/useAuth';
import { partitionNotifications } from '../utils/notifications';

/**
 * État partagé des notifications.
 *
 * L'endpoint GET /notifications renvoie un paginator brut (pagination au
 * premier niveau, et non imbriquée sous `meta` comme les ressources Laravel).
 * On le lit de façon défensive pour rester compatible avec les deux formats.
 *
 * Ce contexte évite que l'en-tête, la bottom nav et la page « Notifications »
 * déclenchent trois appels identiques.
 *
 * Le badge n'est PAS un simple compteur de « non lues ». Il additionne deux
 * notions volontairement distinctes (voir utils/notifications.js) :
 *  - ce que l'utilisateur n'a pas encore vu  → se règle au clic ;
 *  - ce qui attend encore une vérification     → ne se règle PAS au clic.
 * Un administrateur doit ainsi toujours voir le compteur de paiements à
 * contrôler, même après que l'utilisateur a tout lu.
 */
const NotificationsContext = createContext(null);

export function NotificationsProvider({ children }) {
  const { isAuthenticated } = useAuth();
  const [items, setItems] = useState([]);
  // Notifications déjà consultées pendant cette session. Permet de faire
  // disparaître le badge instantanément, avant même la confirmation serveur.
  const [dismissed, setDismissed] = useState(() => new Set());
  const [isLoading, setIsLoading] = useState(false);

  // Miroir de `items` pour que les callbacks de lecture restent stables et
  // puissent restaurer l'état exact en cas d'échec réseau.
  const itemsRef = useRef(items);
  useEffect(() => {
    itemsRef.current = items;
  }, [items]);

  const applyPayload = useCallback((payload) => {
    const list = Array.isArray(payload) ? payload : payload?.data || [];
    setItems(list);
  }, []);

  const load = useCallback(async () => {
    if (!isAuthenticated) {
      setItems([]);
      setDismissed(new Set());
      return;
    }
    setIsLoading(true);
    try {
      applyPayload(await api.get('/notifications'));
    } catch {
      // Un échec de lecture des notifications ne doit pas bloquer la page :
      // on garde le tableau précédent.
    } finally {
      setIsLoading(false);
    }
  }, [isAuthenticated, applyPayload]);

  useEffect(() => {
    load();
  }, [load]);

  const { visible, unseen, actionable } = useMemo(
    () => partitionNotifications(items, dismissed),
    [items, dismissed],
  );

  const markAsRead = useCallback(async (id) => {
    const previous = itemsRef.current.find((item) => item.id === id)?.read_at ?? null;
    if (previous) return;

    const at = new Date().toISOString();
    setItems((current) => current.map((item) => (item.id === id ? { ...item, read_at: at } : item)));
    setDismissed((current) => new Set(current).add(id));

    try {
      await api.post(`/notifications/${id}/read`);
    } catch {
      // L'enregistrement a échoué : on ne masque pas la panne. Le badge
      // réapparaît plutôt que d'afficher une fausse information.
      setItems((current) => current.map((item) => (item.id === id ? { ...item, read_at: previous } : item)));
      setDismissed((current) => {
        const next = new Set(current);
        next.delete(id);
        return next;
      });
    }
  }, []);

  const markAllAsRead = useCallback(async () => {
    const snapshot = itemsRef.current;
    const targets = snapshot.filter((item) => !item.read_at);
    if (targets.length === 0) return;

    const at = new Date().toISOString();
    setItems((current) => current.map((item) => (item.read_at ? item : { ...item, read_at: at })));
    setDismissed((current) => {
      const next = new Set(current);
      targets.forEach((item) => next.add(item.id));
      return next;
    });

    try {
      await api.post('/notifications/mark-all-read');
    } catch {
      setItems(snapshot);
      setDismissed((current) => {
        const next = new Set(current);
        targets.forEach((item) => next.delete(item.id));
        return next;
      });
    }
  }, []);

  const value = useMemo(
    () => ({
      items: visible,
      // Le badge : ce qu'il reste à voir + ce qui reste à vérifier.
      unread: unseen.length + actionable.length,
      // Combien reste-t-il réellement à VOIR (donc effaçable d'un clic) ?
      unreadItems: unseen.length + actionable.filter((item) => !dismissed.has(item.id)).length,
      // Vrai tant qu'un paiement attend une vérification : ces éléments
      // ne doivent jamais disparaître au clic.
      hasActionRequired: actionable.length > 0,
      isLoading,
      reload: load,
      markAsRead,
      markAllAsRead,
    }),
    [visible, unseen, actionable, dismissed, isLoading, load, markAsRead, markAllAsRead],
  );

  return <NotificationsContext.Provider value={value}>{children}</NotificationsContext.Provider>;
}

export function useNotifications() {
  const context = useContext(NotificationsContext);
  if (!context) throw new Error("useNotifications doit être utilisé à l'intérieur de <NotificationsProvider>.");
  return context;
}
