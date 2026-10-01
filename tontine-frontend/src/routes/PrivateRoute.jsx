import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import { Skeleton } from '../components/ui/Skeleton';

/**
 * Garde d'authentification.
 * Conserve la page d'origine dans `location.state.from` pour y revenir
 * après connexion.
 */
export default function PrivateRoute() {
  const { isAuthenticated, isLoading } = useAuth();
  const location = useLocation();

  if (isLoading) return <RouteFallback />;

  if (!isAuthenticated) {
    return <Navigate to="/connexion" state={{ from: location }} replace />;
  }

  return <Outlet />;
}

/** Squelette de chargement pendant la résolution de la session. */
export function RouteFallback({ label = 'Chargement de ton espace…' }) {
  return (
    <div className="mx-auto w-full max-w-7xl px-4 py-10 sm:px-6 lg:px-10" role="status">
      <Skeleton className="mb-6 h-9 w-64" />
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {Array.from({ length: 4 }).map((_, index) => (
          <Skeleton key={index} className="h-32 rounded-2xl" />
        ))}
      </div>
      <span className="sr-only">{label}</span>
    </div>
  );
}
