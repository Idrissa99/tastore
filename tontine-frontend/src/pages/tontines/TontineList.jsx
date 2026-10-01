import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Filter, SlidersHorizontal, Wallet, X } from 'lucide-react';
import { api } from '../../api/client';
import { useAuth } from '../../hooks/useAuth';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { PageHeader } from '../../components/ui/PageHeader';
import PageContainer from '../../components/layout/PageContainer';
import { SearchInput, Select } from '../../components/ui/Input';
import { Button } from '../../components/ui/Button';
import { Drawer } from '../../components/ui/Drawer';
import { Pagination } from '../../components/ui/Pagination';
import { Checkbox } from '../../components/ui/Input';
import { EmptyState, ErrorState } from '../../components/ui/EmptyState';
import { TontineCard, TontineCardSkeleton } from '../../components/tontine/TontineCard';
import { formatFcfa, toNumber } from '../../utils/format';
import { FREQUENCIES, tontineTypeLabel } from '../../utils/status';

/** Pagination serveur : GET /tontines renvoie 12 tontines ouvertes par page. */
const PER_PAGE = 12;
/** Garde-fou : au-delà, on ne télécharge pas toute la base d'un coup. */
const MAX_PAGES = 6;

/**
 * Marketplace des tontines.
 *
 * L'API n'expose aucun paramètre de recherche sur GET /tontines (ni `q`, ni
 * filtre de type, de montant ou de fréquence : elle renvoie simplement les
 * tontines ouvertes, paginées par 12).
 * Pour proposer de vrais filtres sans modifier le backend, on charge les
 * premières pages (plafonnées) puis on filtre côté client. La pagination
 * affichée reste celle du serveur.
 */
export default function TontineList() {
  useDocumentTitle('Tontines');
  const { isAuthenticated } = useAuth();
  const [searchParams, setSearchParams] = useSearchParams();

  const [query, setQuery] = useState('');
  const [type, setType] = useState(searchParams.get('type') || '');
  const [frequency, setFrequency] = useState('');
  const [maxAmount, setMaxAmount] = useState('');
  const [slotsOnly, setSlotsOnly] = useState(false);
  const [availableOnly, setAvailableOnly] = useState(false);
  const [page, setPage] = useState(1);
  const [filtersOpen, setFiltersOpen] = useState(false);

  const [pool, setPool] = useState([]);
  const [meta, setMeta] = useState(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isLoadingMore, setIsLoadingMore] = useState(false);
  const [error, setError] = useState(null);
  const [hasMore, setHasMore] = useState(false);

  useEffect(() => {
    let cancelled = false;
    setIsLoading(true);
    setError(null);

    api
      .get('/tontines', { page: 1 })
      .then((data) => {
        if (cancelled) return;
        setPool(data?.data || []);
        setMeta(data);
        setHasMore((data?.meta?.last_page || 1) > 1);
      })
      .catch((err) => !cancelled && setError(err))
      .finally(() => !cancelled && setIsLoading(false));

    return () => {
      cancelled = true;
    };
  }, []);

  /** Charge les pages suivantes pour élargir le filtre (plafonné). */
  const loadMore = async () => {
    if (isLoadingMore || !hasMore) return;
    setIsLoadingMore(true);
    try {
      const lastPage = Number(meta?.meta?.last_page || 1);
      const target = Math.min(lastPage, MAX_PAGES);
      const results = await Promise.all(
        Array.from({ length: target - 1 }, (_, index) => api.get('/tontines', { page: index + 2 })),
      );
      setPool((current) => {
        const seen = new Set(current.map((item) => item.id));
        const extra = results.flatMap((result) => result?.data || []).filter((item) => !seen.has(item.id));
        return [...current, ...extra];
      });
      setHasMore(lastPage > target);
    } catch {
      setHasMore(false);
    } finally {
      setIsLoadingMore(false);
    }
  };

  useEffect(() => {
    setPage(1);
  }, [query, type, frequency, maxAmount, slotsOnly, availableOnly]);

  /* ---------------------------------------------------------------- */
  /* Filtrage côté client                                             */
  /* ---------------------------------------------------------------- */
  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase();

    return pool.filter((tontine) => {
      // Seule une tontine « open » accueille de nouveaux membres ; les
      // autres restent visibles mais ne sont pas des offres.
      if (availableOnly && tontine.status !== 'open') return false;
      if (type && tontine.type !== type) return false;
      if (frequency && tontine.frequency !== frequency) return false;
      if (slotsOnly && Number(tontine.current_members) >= Number(tontine.max_members)) return false;

      if (maxAmount) {
        const ceiling = Number(maxAmount);
        // Une tontine argent est comparée sur le montant cible, une tontine
        // produit sur son prix : dans les deux cas c'est "combien pour rejoindre".
        const reference = tontine.type === 'cash' ? toNumber(tontine.total_amount) : toNumber(tontine.contribution_amount);
        if (reference > ceiling) return false;
      }

      if (!needle) return true;
      const haystack = [tontine.name, tontine.product?.name, tontine.type].filter(Boolean).join(' ').toLowerCase();
      return haystack.includes(needle);
    });
  }, [pool, query, type, frequency, maxAmount, slotsOnly, availableOnly]);

  // Combien sont réellement ouvertes à l'adhésion : c'est ce chiffre qui
  // doit porter le mot « disponible ».
  const joinable = useMemo(() => filtered.filter((t) => t.status === 'open'), [filtered]);
  const availableCount = joinable.length;

  const pageCount = Math.max(1, Math.ceil(filtered.length / PER_PAGE));
  const visible = filtered.slice((page - 1) * PER_PAGE, page * PER_PAGE);

  const activeFilters = [type, frequency, maxAmount, slotsOnly, availableOnly].filter(Boolean).length;

  const clearFilters = () => {
    setType('');
    setFrequency('');
    setMaxAmount('');
    setSlotsOnly(false);
    setAvailableOnly(false);
    setQuery('');
    setSearchParams({});
  };

  /* ---------------------------------------------------------------- */

  const filterPanel = (
    <div className="space-y-5">
      <Select value={type} onChange={(event) => setType(event.target.value)} aria-label="Type de tontine">
        <option value="">Tous les types</option>
        <option value="product">{tontineTypeLabel('product')}</option>
        <option value="cash">{tontineTypeLabel('cash')}</option>
      </Select>

      <Select value={frequency} onChange={(event) => setFrequency(event.target.value)} aria-label="Fréquence">
        <option value="">Toutes les fréquences</option>
        {Object.entries(FREQUENCIES).map(([key, item]) => (
          <option key={key} value={key}>
            Versement {item.label}
          </option>
        ))}
      </Select>

      <Select value={maxAmount} onChange={(event) => setMaxAmount(event.target.value)} aria-label="Montant maximum">
        <option value="">Tous les montants</option>
        <option value="10000">Jusqu’à 10 000 FCFA</option>
        <option value="25000">Jusqu’à 25 000 FCFA</option>
        <option value="50000">Jusqu’à 50 000 FCFA</option>
        <option value="100000">Jusqu’à 100 000 FCFA</option>
      </Select>

      <Checkbox
        label="Rejoignables uniquement"
        description="Masque les tontines qui ont démarré, sont terminées ou annulées"
        checked={availableOnly}
        onChange={(event) => setAvailableOnly(event.target.checked)}
      />

      <Checkbox
        label="Places disponibles uniquement"
        description="Masque les tontines déjà complètes"
        checked={slotsOnly}
        onChange={(event) => setSlotsOnly(event.target.checked)}
      />

      {activeFilters > 0 && (
        <Button variant="ghost" size="sm" icon={X} onClick={clearFilters} className="w-full">
          Réinitialiser les filtres
        </Button>
      )}
    </div>
  );

  return (
    <PageContainer>
      <PageHeader
        title="Découvrez une tontine qui correspond à vos objectifs"
        subtitle="Rejoignez un produit que vous souhaitez, ou constituez une épargne collective. Chaque tontine affiche son montant, sa fréquence et sa progression réelle."
        breadcrumb={[{ label: 'Tontines' }]}
      />

      {/* Barre de recherche */}
      <div className="mb-6 flex flex-col gap-3 sm:flex-row">
        <SearchInput
          value={query}
          onChange={setQuery}
          onClear={() => setQuery('')}
          placeholder="Rechercher un produit ou une tontine…"
          className="flex-1"
          aria-label="Rechercher une tontine"
        />
        <Button
          variant="secondary"
          icon={SlidersHorizontal}
          onClick={() => setFiltersOpen(true)}
          className="lg:hidden"
        >
          Filtres
          {activeFilters > 0 && (
            <span className="ml-0.5 inline-flex size-5 items-center justify-center rounded-full bg-primary-700 text-[10px] font-bold text-white">
              {activeFilters}
            </span>
          )}
        </Button>
      </div>

      <div className="lg:grid lg:grid-cols-[260px_1fr] lg:gap-8">
        {/* Filtres desktop */}
        <aside className="hidden lg:block">
          <div className="sticky top-24 rounded-2xl border border-line-strong bg-white p-5 shadow-sm">
            <h2 className="mb-4 flex items-center gap-2 font-display text-sm font-bold text-ink-900">
              <Filter className="size-4 text-primary-600" aria-hidden="true" />
              Filtres
            </h2>
            {filterPanel}
          </div>
        </aside>

        {/* Résultats */}
        <div className="min-w-0">
          <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p className="text-sm text-ink-500" role="status" aria-live="polite">
              {isLoading
                ? 'Chargement des tontines…'
                : availableCount === filtered.length
                  ? `${filtered.length} tontine${filtered.length > 1 ? 's' : ''} disponible${filtered.length > 1 ? 's' : ''}`
                  : `${filtered.length} tontine${filtered.length > 1 ? 's' : ''} — dont ${availableCount} rejoignable${availableCount > 1 ? 's' : ''}`}
            </p>
            {joinable.length > 0 && (
              <p className="text-xs text-ink-400">
                Versement minimum :{' '}
                <span className="amount text-ink-600">
                  {formatFcfa(Math.min(...joinable.map((t) => Number(t.contribution_amount || 0))))}
                </span>
              </p>
            )}
          </div>

          {error ? (
            <ErrorState message={error.message} onRetry={() => window.location.reload()} />
          ) : isLoading ? (
            <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
              {Array.from({ length: 6 }).map((_, index) => (
                <TontineCardSkeleton key={index} />
              ))}
            </div>
          ) : filtered.length === 0 ? (
            <EmptyState
              icon={Wallet}
              title="Aucune tontine ne correspond"
              description={
                activeFilters > 0 || query
                  ? 'Élargis tes filtres ou cherche un autre terme pour découvrir plus de tontines.'
                  : 'Aucune tontine n’est disponible pour le moment. Reviens bientôt.'
              }
              action={
                activeFilters > 0 || query ? (
                  <Button variant="secondary" icon={X} onClick={clearFilters}>
                    Réinitialiser les filtres
                  </Button>
                ) : null
              }
            />
          ) : (
            <>
              <ul className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                {visible.map((tontine) => (
                  <li key={tontine.id}>
                    <TontineCard tontine={tontine} />
                  </li>
                ))}
              </ul>

              <div className="mt-8 flex flex-col items-center gap-4">
                {hasMore && (
                  <Button variant="secondary" icon={SlidersHorizontal} loading={isLoadingMore} onClick={loadMore}>
                    Charger plus de tontines
                  </Button>
                )}
                <Pagination
                  meta={{ current_page: page, last_page: pageCount, total: filtered.length }}
                  onPageChange={setPage}
                  itemLabel="tontines"
                />
              </div>
            </>
          )}

          {!isAuthenticated && filtered.length > 0 && (
            <p className="mt-8 rounded-xl bg-primary-50 px-4 py-3 text-center text-sm text-primary-800">
              <a href="/connexion" className="font-bold underline underline-offset-2">
                Connecte-toi
              </a>{' '}
              pour rejoindre une tontine et commencer à cotiser.
            </p>
          )}
        </div>
      </div>

      {/* Filtres mobile */}
      <Drawer open={filtersOpen} onClose={() => setFiltersOpen(false)} title="Filtres" side="left" width="max-w-xs">
        {filterPanel}
        <Button className="mt-6 w-full" onClick={() => setFiltersOpen(false)}>
          Voir les résultats
        </Button>
      </Drawer>
    </PageContainer>
  );
}
