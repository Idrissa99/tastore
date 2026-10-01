import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { api } from '../api/client';
import { useAuth } from '../hooks/useAuth';

/**
 * Compteurs persistants de l'espace administrateur.
 *
 * Le nombre de paiements en attente de vérification est affiché en pastille
 * sur la navigation. S'il est chargé par la page qui le consomme, il devient
 * faux dès que l'administrateur accepte un paiement : la pastille afficherait
 * un montant qui n'a plus cours tant que la page n'est pas rechargée.
 *
 * On le déporte donc dans un contexte de l'espace connecté : les pages
 * d'administration le rafraîchissent après avoir agi, et la navigation comme
 * le tableau de bord lisent la même valeur, toujours à jour.
 */
const AdminCountersContext = createContext(null);

export function AdminCountersProvider({ children }) {
  const { user } = useAuth();
  const [pendingPayments, setPendingPayments] = useState(0);
  const [isRefreshing, setIsRefreshing] = useState(false);

  const refreshPendingPayments = useCallback(async () => {
    setIsRefreshing(true);
    try {
      const response = await api.get('/admin/payments', { status: 'pending' });
      const list = Array.isArray(response) ? response : response?.data || [];
      setPendingPayments(list.length);
    } catch {
      // Un compteur de navigation ne doit jamais faire échouer l'action métier
      // qui vient de réussir : on conserve la valeur précédente.
    } finally {
      setIsRefreshing(false);
    }
  }, []);

  // Chargement initial : la pastille doit être juste à l'arrivée sur l'espace
  // admin, pas seulement après la première action de vérification.
  useEffect(() => {
    if (user?.role !== 'admin') {
      setPendingPayments(0);
      return;
    }
    refreshPendingPayments();
  }, [user?.role, refreshPendingPayments]);

  const value = useMemo(
    () => ({ pendingPayments, isRefreshing, refreshPendingPayments }),
    [pendingPayments, isRefreshing, refreshPendingPayments],
  );

  return <AdminCountersContext.Provider value={value}>{children}</AdminCountersContext.Provider>;
}

export function useAdminCounters() {
  const context = useContext(AdminCountersContext);
  if (!context) throw new Error("useAdminCounters doit être utilisé à l'intérieur de <AdminCountersProvider>.");
  return context;
}
