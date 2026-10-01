import { useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { Eye, EyeOff, LogIn } from 'lucide-react';
import { useAuth } from '../../hooks/useAuth';
import { homeForRole } from '../../navigation';
import { ApiError } from '../../api/client';
import { Alert } from '../../components/ui/Alert';
import { Button } from '../../components/ui/Button';
import { Field, Input } from '../../components/ui/Input';
import AuthLayout, { AuthFooter } from '../../components/layout/AuthLayout';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';

export default function Login() {
  useDocumentTitle('Connexion');
  const { login, user } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const handleSubmit = async (event) => {
    event.preventDefault();
    setError(null);
    setIsSubmitting(true);

    try {
      const account = await login(email.trim(), password);
      // Retour à la page qui a demandé l'authentification ; à défaut, on
      // lands dans l'espace correspondant au rôle (un administrateur doit
      // arriver sur /admin, pas sur un tableau de bord client).
      const redirectTo = location.state?.from?.pathname || homeForRole(account || user);
      navigate(redirectTo, { replace: true });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Une erreur est survenue. Réessaie dans un instant.');
    } finally {
      setIsSubmitting(false);
    }
  };

  const sessionExpired = location.state?.reason === 'session_expiree';

  return (
    <AuthLayout
      title="Content de te revoir"
      subtitle="Connecte-toi pour suivre tes tontines, tes versements et tes commandes."
      footer={<AuthFooter text="Pas encore de compte ?" linkTo="/inscription" linkLabel="Créer un compte" />}
    >
      {sessionExpired && (
        <Alert type="warning" className="mb-5">
          Ta session a expiré ou a été déconnectée (changement de mot de passe, déconnexion à distance ou
          blocage du compte). Reconnecte-toi.
        </Alert>
      )}

      {error && (
        <Alert type="error" className="mb-5">
          {error}
        </Alert>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <Field label="Adresse e-mail" htmlFor="login-email" required>
          <Input
            id="login-email"
            type="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            required
            autoComplete="email"
            autoFocus
            placeholder="nom@exemple.ne"
          />
        </Field>

        <Field
          label="Mot de passe"
          htmlFor="login-password"
          required
          hint={
            <span className="inline-flex items-center gap-2">
              <Link to="/mot-de-passe-oublie" state={{ email }} className="font-semibold text-primary-700 hover:underline">
                Mot de passe oublié ?
              </Link>
            </span>
          }
        >
          <div className="relative">
            <Input
              id="login-password"
              type={showPassword ? 'text' : 'password'}
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              required
              autoComplete="current-password"
              className="pr-11"
            />
            <button
              type="button"
              onClick={() => setShowPassword((value) => !value)}
              aria-label={showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'}
              className="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg p-2 text-ink-400 transition-colors hover:bg-ink-100 hover:text-ink-700"
            >
              {showPassword ? <EyeOff className="size-4" aria-hidden="true" /> : <Eye className="size-4" aria-hidden="true" />}
            </button>
          </div>
        </Field>

        <Button type="submit" size="lg" className="w-full" icon={LogIn} loading={isSubmitting} disabled={isSubmitting}>
          {isSubmitting ? 'Connexion en cours…' : 'Se connecter'}
        </Button>
      </form>
    </AuthLayout>
  );
}
