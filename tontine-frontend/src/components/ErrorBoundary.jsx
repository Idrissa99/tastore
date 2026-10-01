import { Component } from 'react';
import { AlertTriangle, RefreshCw, Home } from 'lucide-react';

/**
 * Barrière d'erreur.
 *
 * Sans elle, une seule propriété manquante sur une réponse d'API suffit à
 * faire échouer le rendu : React démonte alors TOUTE l'application et
 * l'utilisateur voit un écran vide, sans comprendre pourquoi.
 *
 * Cette barrière limite le dégât à l'écran concerné et propose une action
 * claire plutôt qu'un mur blanc.
 */
export class ErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { error: null };
  }

  static getDerivedStateFromError(error) {
    return { error };
  }

  componentDidCatch(error, info) {
    // Conservé volontairement : en développement React affiche déjà la
    // pile dans la console. En production, brancher ici le service de
    // suivi d'erreurs (Sentry, Crashlytics…) sans toucher aux appelants.
    console.error('Erreur de rendu interceptée :', error, info?.componentStack);
  }

  handleReset = () => {
    this.setState({ error: null });
  };

  handleReload = () => {
    window.location.href = '/';
  };

  render() {
    const { error } = this.state;
    if (!error) return this.props.children;

    const { title, compact = false } = this.props;

    return (
      <div className={`flex w-full items-center justify-center px-5 ${compact ? 'py-10' : 'py-20'}`}>
        <div className="w-full max-w-lg rounded-2xl border border-danger-200 bg-danger-50/70 p-6 text-center sm:p-8">
          <span className="mx-auto flex size-14 items-center justify-center rounded-2xl bg-white text-danger-600 shadow-sm">
            <AlertTriangle className="size-7" aria-hidden="true" />
          </span>

          <h1 className="mt-5 font-display text-lg font-bold text-ink-900 sm:text-xl">
            {title || 'Cette page n’a pas pu s’afficher'}
          </h1>
          <p className="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-ink-600">
            Une donnée inattendue a interrompu l’affichage. Tes données ne sont pas perdues : réessaie, ou
            reviens à l’accueil.
          </p>

          {import.meta.env.DEV && (
            <pre className="mt-4 max-h-32 overflow-auto rounded-lg bg-white p-3 text-left font-mono text-[11px] leading-relaxed text-ink-600 ring-1 ring-danger-200">
              {String(error?.message || error)}
            </pre>
          )}

          <div className="mt-6 flex flex-col justify-center gap-2.5 sm:flex-row">
            <button
              type="button"
              onClick={this.handleReset}
              className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-primary-700 px-5 text-sm font-semibold text-white shadow-brand transition-colors hover:bg-primary-800"
            >
              <RefreshCw className="size-4" aria-hidden="true" />
              Réessayer
            </button>
            <button
              type="button"
              onClick={this.handleReload}
              className="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-line-strong bg-white px-5 text-sm font-semibold text-ink-800 shadow-sm transition-colors hover:bg-ink-50"
            >
              <Home className="size-4" aria-hidden="true" />
              Retour à l’accueil
            </button>
          </div>
        </div>
      </div>
    );
  }
}

export default ErrorBoundary;
