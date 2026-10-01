import { Link } from 'react-router-dom';

/** Page 404 — garde-fou de routage. */
export default function NotFound() {
  return (
    <div className="mx-auto flex w-full max-w-2xl flex-col items-center justify-center px-5 py-24 text-center">
      <span className="font-display text-7xl font-extrabold tracking-tight text-primary-700 sm:text-8xl">404</span>
      <h1 className="mt-6 font-display text-2xl font-bold tracking-tight text-ink-900 sm:text-3xl">
        Cette page n’existe pas
      </h1>
      <p className="mt-3 max-w-md text-sm leading-relaxed text-ink-500 sm:text-base">
        Le lien que tu as suivi est peut-être obsolète, ou la page a été déplacée. Reviens à l’accueil ou
        explore les tontines ouvertes.
      </p>

      <div className="mt-8 flex flex-col gap-3 sm:flex-row">
        <Link
          to="/"
          className="inline-flex h-12 items-center justify-center rounded-xl bg-primary-700 px-6 text-sm font-semibold text-white shadow-brand transition-colors hover:bg-primary-800"
        >
          Retour à l’accueil
        </Link>
        <Link
          to="/tontines"
          className="inline-flex h-12 items-center justify-center rounded-xl border border-line-strong bg-white px-6 text-sm font-semibold text-ink-800 shadow-sm transition-colors hover:bg-ink-50"
        >
          Voir les tontines
        </Link>
      </div>
    </div>
  );
}
