import { useEffect } from 'react';

/** Met à jour le titre de l'onglet (bon pour l'orientation + partage). */
export function useDocumentTitle(title) {
  useEffect(() => {
    if (!title) return;
    const previous = document.title;
    document.title = `${title} · Tontine Achat Store`;
    return () => {
      document.title = previous;
    };
  }, [title]);
}
