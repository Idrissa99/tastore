import { Link, Navigate, Outlet, useLocation } from 'react-router-dom';
import { ArrowLeft, ShieldAlert } from 'lucide-react';
import { useAuth } from '../hooks/useAuth';
import { Card } from '../components/ui/Card';
import { Alert } from '../components/ui/Alert';
import { StatusBadge } from '../components/ui/Badge';
import { ButtonLink } from '../components/ui/Button';
import { RouteFallback } from './PrivateRoute';

/**
 * Garde de rôle côté client.
 *
 * ATTENTION : ceci n'est qu'un confort d'affichage. La vraie autorisation est
 * appliquée par le backend (middleware `admin`, `merchant`, `verified`,
 * `can-create-tontine` et les Policies). Masquer une page ou rediriger ne
 * protège jamais rien.
 *
 * allow : tableau de rôles autorisés, ex: ['admin'] ou ['merchant', 'admin'].
 * Pour « merchant », on exige aussi un compte commerçant approuvé, exactement
 * comme le middleware `merchant` côté API.
 */
export default function RoleRoute({ allow = [] }) {
  const { user, isLoading } = useAuth();
  const location = useLocation();

  if (isLoading) return <RouteFallback label="Vérification de tes accès…" />;

  if (!user) {
    return <Navigate to="/connexion" replace state={{ from: location.pathname }} />;
  }

  const isAllowed = allow.some((role) => {
    if (role === 'admin') return user.role === 'admin';
    if (role === 'merchant') return user.role === 'merchant' && user.merchant?.status === 'approved';
    return false;
  });

  if (!isAllowed) {
    return <AccessDenied user={user} />;
  }

  return <Outlet />;
}

function AccessDenied({ user }) {
  const isPendingMerchant = user.role === 'merchant' && user.merchant?.status !== 'approved';

  return (
    <div className="mx-auto w-full max-w-xl px-4 py-14 sm:px-6">
      <Card className="text-center">
        <span className="mx-auto flex size-14 items-center justify-center rounded-2xl bg-danger-50 text-danger-600">
          <ShieldAlert className="size-7" aria-hidden="true" />
        </span>

        <h1 className="mt-5 font-display text-xl font-bold text-ink-900">Accès refusé</h1>

        <p className="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-ink-600">
          Ton compte n’a pas les droits nécessaires pour accéder à cette section.
        </p>

        <div className="mt-4 flex justify-center">
          <StatusBadge status={user.role} />
        </div>

        {isPendingMerchant && (
          <Alert type="warning" className="mt-5 text-left">
            Ton inscription commerçant est en attente de validation par un administrateur. L’accès à l’espace
            vendeur sera ouvert dès qu’elle sera approuvée.
          </Alert>
        )}

        <div className="mt-6 flex flex-col justify-center gap-2.5 sm:flex-row">
          <ButtonLink to="/tableau-de-bord" variant="secondary" icon={ArrowLeft}>
            Retour à mon espace
          </ButtonLink>
          <Link
            to="/profil"
            className="inline-flex h-11 items-center justify-center rounded-xl bg-primary-700 px-5 text-sm font-semibold text-white shadow-brand transition-colors hover:bg-primary-800"
          >
            Voir mon profil
          </Link>
        </div>

        <p className="mt-5 text-[11px] text-ink-400">
          Si tu penses qu’il s’agit d’une erreur, contacte le support.
        </p>
      </Card>
    </div>
  );
}
