import { Link } from 'react-router-dom';
import { Banknote, CalendarClock, ShoppingBag, Users } from 'lucide-react';
import { Badge, StatusBadge } from '../ui/Badge';
import { ProgressBar } from '../ui/Progress';
import TontineCover from './TontineCover';
import { formatFcfa, formatNumber } from '../../utils/format';
import { frequencyLabel, tontineTypeLabel } from '../../utils/status';

/**
 * Carte de marketplace d'une tontine.
 * Deux variantes : produit (photo + prix) et argent (montant cible).
 * `footprint` = next contribution due by the current member (sert sur le
 * tableau de bord) — null quand l'utilisateur n'est pas membre.
 */
export function TontineCard({ tontine, footprint = null, className = '' }) {
  const isCash = tontine.type === 'cash';
  const total = Number(tontine.total_amount || 0);
  const perRound = Number(tontine.contribution_amount || 0);
  const collected = Number(tontine.round?.paid_members || 0) * perRound;
  const percent = total > 0 ? Math.min(100, Math.round((collected / total) * 100)) : 0;

  return (
    <Link
      to={`/tontines/${tontine.id}`}
      className={`group flex h-full flex-col overflow-hidden rounded-2xl border border-line-strong bg-white shadow-sm transition-all duration-300 hover:-translate-y-1 hover:border-primary-300 hover:shadow-lg ${className}`}
    >
      {/* Média */}
      <div className="relative">
        <TontineCover tontine={tontine} variant="hero" />

        <div className="absolute inset-x-0 top-0 flex items-start justify-between gap-2 p-3">
          <Badge
            tone={isCash ? 'brand' : 'accent'}
            size="sm"
            icon={isCash ? Banknote : ShoppingBag}
            className="backdrop-blur-sm"
          >
            {tontineTypeLabel(tontine.type).replace('Tontine ', '')}
          </Badge>
          {tontine.status && <StatusBadge status={tontine.status} size="sm" className="backdrop-blur-sm" />}
        </div>

        {footprint && (
          <div className="absolute inset-x-3 bottom-3">
            <span className="inline-flex items-center gap-1.5 rounded-lg bg-ink-950/75 px-2.5 py-1.5 text-[11px] font-semibold text-white backdrop-blur-sm">
              <CalendarClock className="size-3.5" aria-hidden="true" />
              {footprint}
            </span>
          </div>
        )}
      </div>

      {/* Contenu */}
      <div className="flex flex-1 flex-col p-4 sm:p-5">
        <h3 className="font-display text-base font-bold leading-snug text-ink-900 transition-colors group-hover:text-primary-800">
          {tontine.name}
        </h3>

        {!isCash && tontine.product?.name && (
          <p className="mt-0.5 truncate text-xs text-ink-500">
            Produit : <span className="font-medium text-ink-600">{tontine.product.name}</span>
          </p>
        )}

        <div className="mt-3 flex items-baseline justify-between gap-2">
          <p className="amount text-lg text-primary-800">
            {formatFcfa(perRound)}
            <span className="ml-1 text-[11px] font-medium text-ink-500">{frequencyLabel(tontine.frequency)}</span>
          </p>
          <span className="inline-flex items-center gap-1 text-xs text-ink-500">
            <Users className="size-3.5" aria-hidden="true" />
            {tontine.current_members}/{tontine.max_members}
          </span>
        </div>

        <div className="mt-3.5">
          <div className="mb-1.5 flex items-center justify-between text-[11px]">
            <span className="font-medium text-ink-500">
              {isCash ? 'Collecté' : 'Financé'}
            </span>
            <span className="font-bold text-ink-700">{percent} %</span>
          </div>
          <ProgressBar value={collected} total={total} size="sm" />
          <p className="mt-1.5 text-[11px] text-ink-400">
            {formatFcfa(collected)} / {formatFcfa(total)}
          </p>
        </div>

        {footprint && (
          <p className="mt-3 flex items-center justify-between border-t border-line-soft pt-3 text-[11px] text-ink-500">
            <span>Round {tontine.round?.number ?? tontine.current_round}</span>
            <span>
              {formatNumber(tontine.round?.paid_members || 0)}/{formatNumber(tontine.round?.expected_members || 0)} cotisés
            </span>
          </p>
        )}

        <div className="mt-auto pt-4">
          <span className="inline-flex items-center gap-1.5 text-sm font-bold text-primary-700 transition-transform duration-200 group-hover:translate-x-0.5">
            Voir la tontine
            <svg viewBox="0 0 24 24" className="size-4" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
              <path d="M5 12h14M13 6l6 6-6 6" />
            </svg>
          </span>
        </div>
      </div>
    </Link>
  );
}

/**
 * Version compacte pour le tableau de bord : pas d'image plein cadre,
 * progression et prochaine échéance mises en avant.
 */
export function TontineMiniCard({ tontine, nextContribution, dueDate, className = '' }) {
  const total = Number(tontine.total_amount || 0);
  const perRound = Number(tontine.contribution_amount || 0);
  const collected = Number(tontine.round?.paid_members || 0) * perRound;

  return (
    <div className={`rounded-2xl border border-line-strong bg-white shadow-sm transition-all duration-300 hover:-translate-y-0.5 hover:border-primary-300 hover:shadow-md ${className}`}>
      <Link to={`/tontines/${tontine.id}`} className="block p-5">
        <div className="flex items-start justify-between gap-3">
          <div className="flex min-w-0 items-center gap-3">
            <TontineCover tontine={tontine} variant="thumb" />
            <div className="min-w-0">
              <p className="truncate font-display text-sm font-bold text-ink-900">{tontine.name}</p>
              <p className="mt-0.5 text-[11px] text-ink-500">
                {tontineTypeLabel(tontine.type)} · round {tontine.round?.number ?? tontine.current_round}
              </p>
            </div>
          </div>
          <StatusBadge status={tontine.status} size="sm" />
        </div>

        <div className="mt-4">
          <div className="mb-1.5 flex items-baseline justify-between text-[11px]">
            <span className="font-medium text-ink-500">Progression</span>
            <span className="amount text-xs">{formatFcfa(collected)} / {formatFcfa(total)}</span>
          </div>
          <ProgressBar value={collected} total={total} size="sm" />
        </div>

        {nextContribution ? (
          <div className="mt-4 flex items-center justify-between rounded-xl bg-primary-50/70 px-3 py-2.5">
            <span className="text-[11px] font-semibold text-primary-800">Prochain versement</span>
            <span className="amount text-sm text-primary-800">{formatFcfa(nextContribution)}</span>
          </div>
        ) : dueDate ? (
          <div className="mt-4 flex items-center gap-2 rounded-xl bg-ink-50 px-3 py-2.5 text-[11px] text-ink-600">
            <CalendarClock className="size-3.5 shrink-0" aria-hidden="true" />
            <span className="truncate">Prochaine échéance : {dueDate}</span>
          </div>
        ) : null}
      </Link>
    </div>
  );
}

/** Ligne compacte (listes latérales, pages de détail). */
export function TontineRow({ tontine, right, className = '' }) {
  return (
    <Link
      to={`/tontines/${tontine.id}`}
      className={`flex items-center gap-3 rounded-xl p-2.5 transition-colors hover:bg-ink-50 ${className}`}
    >
      <TontineCover tontine={tontine} variant="thumb" thumbSize="size-10" rounded="rounded-lg" />
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-semibold text-ink-900">{tontine.name}</p>
        <p className="truncate text-[11px] text-ink-500">
          {formatFcfa(tontine.contribution_amount)} · {frequencyLabel(tontine.frequency)}
        </p>
      </div>
      {right}
    </Link>
  );
}

export function TontineCardSkeleton() {
  return (
    <div className="overflow-hidden rounded-2xl border border-line-strong bg-white shadow-sm" aria-hidden="true">
      <div className="skeleton aspect-video w-full" />
      <div className="space-y-3 p-5">
        <div className="skeleton h-4 w-3/4 rounded" />
        <div className="skeleton h-3 w-1/2 rounded" />
        <div className="skeleton h-2 w-full rounded-full" />
        <div className="skeleton h-3 w-2/5 rounded" />
      </div>
    </div>
  );
}

export default TontineCard;
