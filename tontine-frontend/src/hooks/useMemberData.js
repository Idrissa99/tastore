import { useCallback, useMemo } from 'react';
import { api } from '../api/client';
import { useApi } from './useApi';

/**
 * Vue membre de l'application, dérivée des API existantes.
 *
 * Sources (aucune donnée inventée) :
 *  - GET /contributions          → toutes les cotisations du membre
 *                                   (tableau JSON brut, non paginé)
 *  - GET /tontines/{id}          → fiche complète d'une tontine, car la
 *                                   resource imbriquée dans une cotisation
 *                                   ne contient que {id, name, status, type}.
 *
 * Il n'existe pas d'endpoint « mes tontines » : la liste est donc déduite
 * des cotisations (une tontine = au moins une cotisation), puis enrichie.
 */
export function useMemberData({ withTontines = true, maxTontines = 12 } = {}) {
  const { data, isLoading, error, reload } = useApi(() => api.get('/contributions'), []);

  // L'API renvoie un tableau JSON brut ici (et non { data: [...] }).
  const contributions = useMemo(() => (Array.isArray(data) ? data : []), [data]);

  const tontineIds = useMemo(() => {
    const ids = new Set();
    contributions.forEach((item) => {
      if (item.tontine?.id) ids.add(item.tontine.id);
    });
    return [...ids].slice(0, maxTontines);
  }, [contributions, maxTontines]);

  const { data: tontinesPayload, isLoading: isLoadingTontines, error: tontinesError } = useApi(
    async () => {
      if (!withTontines || tontineIds.length === 0) return [];
      const results = await Promise.allSettled(tontineIds.map((id) => api.get(`/tontines/${id}`)));
      return results.filter((result) => result.status === 'fulfilled').map((result) => result.value?.data).filter(Boolean);
    },
    [tontineIds.join(','), withTontines],
  );

  const tontines = useMemo(() => tontinesPayload || [], [tontinesPayload]);

  /** Regroupe les cotisations par tontine pour un accès rapide. */
  const byTontine = useMemo(() => {
    const map = new Map();
    contributions.forEach((item) => {
      const id = item.tontine?.id;
      if (!id) return;
      if (!map.has(id)) map.set(id, []);
      map.get(id).push(item);
    });
    return map;
  }, [contributions]);

  const stats = useMemo(() => {
    const isSettled = (item) => item.status === 'completed' && item.verification_status !== 'rejected';
    const isAwaitingVerification = (item) => item.verification_status === 'pending';
    const isRejected = (item) => item.verification_status === 'rejected';
    const isActionable = (item) => !isSettled(item) && !isAwaitingVerification(item) && item.status !== 'cancelled';

    const settled = contributions.filter(isSettled);
    const pending = contributions.filter(isActionable);
    const awaiting = contributions.filter(isAwaitingVerification);
    const rejected = contributions.filter(isRejected);

    return {
      totalPaid: settled.reduce((sum, item) => sum + Number(item.amount || 0), 0),
      totalCommission: settled.reduce((sum, item) => sum + Number(item.commission_amount || 0), 0),
      paidCount: settled.length,
      pendingCount: pending.length,
      awaitingCount: awaiting.length,
      rejectedCount: rejected.length,
      pendingAmount: pending.reduce((sum, item) => sum + Number(item.amount || 0), 0),
      // Prochain versement : le plus ancien round encore actionnable.
      nextContribution:
        [...pending].sort((a, b) => Number(a.round) - Number(b.round))[0] || null,
      tontineCount: tontines.length,
      activeTontineCount: tontines.filter((tontine) => ['open', 'active'].includes(tontine.status)).length,
    };
  }, [contributions, tontines]);

  const reloadAll = useCallback(async () => {
    await reload();
  }, [reload]);

  return {
    contributions,
    tontines,
    byTontine,
    stats,
    isLoading: isLoading || isLoadingTontines,
    isLoadingContributions: isLoading,
    error: error || tontinesError,
    reload: reloadAll,
    reloadTontines: reloadAll,
  };
}

export default useMemberData;
