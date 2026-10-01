import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiError } from '../api/client';

/**
 * Chargement de données simplifié et homogène :
 *   - état loading / error / data
 *   - AbortController : les réponses d'une requête annulée sont ignorées
 *   - `reload()` pour rafraîchir sans remounter le composant
 *
 * @param {() => Promise<any>} loader  fonction asynchrone (stabile via useCallback côté appelant)
 * @param {any[]} deps               dépendances qui déclenchent un rechargement
 */
export function useApi(loader, deps = []) {
  const [data, setData] = useState(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);

  const loaderRef = useRef(loader);
  loaderRef.current = loader;
  const controllerRef = useRef(null);
  const mountedRef = useRef(true);

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      controllerRef.current?.abort();
    };
  }, []);

  const execute = useCallback(async () => {
    controllerRef.current?.abort();
    const controller = new AbortController();
    controllerRef.current = controller;

    setIsLoading(true);
    setError(null);
    try {
      const result = await loaderRef.current(controller.signal);
      if (!controller.signal.aborted && mountedRef.current) setData(result);
      return result;
    } catch (err) {
      if (err?.name === 'AbortError' || controller.signal.aborted) return null;
      if (mountedRef.current) setError(err instanceof ApiError ? err : new ApiError('Une erreur inattendue est survenue.', 0));
      return null;
    } finally {
      if (!controller.signal.aborted && mountedRef.current) setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    execute();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);

  return { data, setData, isLoading, error, reload: execute, setError };
}
