import { useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { KeyRound } from 'lucide-react';
import { api, ApiError } from '../../api/client';
import { Alert } from '../../components/ui/Alert';
import { Button } from '../../components/ui/Button';
import { Field, Input } from '../../components/ui/Input';
import AuthLayout, { AuthFooter } from '../../components/layout/AuthLayout';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';

export default function ResetPassword() {
  useDocumentTitle('Nouveau mot de passe');
  const { token } = useParams();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();

  const [email, setEmail] = useState(searchParams.get('email') || '');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [error, setError] = useState(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const handleSubmit = async (event) => {
    event.preventDefault();
    setError(null);

    if (password !== passwordConfirmation) {
      setError('La confirmation ne correspond pas au nouveau mot de passe.');
      return;
    }

    setIsSubmitting(true);
    try {
      await api.post('/reset-password', {
        token,
        email: email.trim(),
        password,
        password_confirmation: passwordConfirmation,
      });
      navigate('/connexion', { replace: true, state: { resetSuccess: true } });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Une erreur est survenue.');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <AuthLayout
      title="Choisir un nouveau mot de passe"
      subtitle="Pour des raisons de sécurité, ce lien est à usage unique et expire rapidement."
      footer={<AuthFooter text="Le lien n’arrive plus ?" linkTo="/mot-de-passe-oublie" linkLabel="Redemander un lien" />}
    >
      {error && (
        <Alert type="error" className="mb-5">
          {error}
        </Alert>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <Field label="Adresse e-mail" htmlFor="reset-email" required>
          <Input
            id="reset-email"
            type="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            required
            autoComplete="email"
          />
        </Field>

        <Field label="Nouveau mot de passe" htmlFor="reset-password" required hint="8 caractères minimum.">
          <Input
            id="reset-password"
            type="password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            required
            autoComplete="new-password"
            minLength={8}
            autoFocus
          />
        </Field>

        <Field label="Confirmer le mot de passe" htmlFor="reset-password-confirm" required>
          <Input
            id="reset-password-confirm"
            type="password"
            value={passwordConfirmation}
            onChange={(event) => setPasswordConfirmation(event.target.value)}
            required
            autoComplete="new-password"
            minLength={8}
          />
        </Field>

        <Button type="submit" size="lg" className="w-full" icon={KeyRound} loading={isSubmitting} disabled={isSubmitting}>
          {isSubmitting ? 'Réinitialisation…' : 'Réinitialiser le mot de passe'}
        </Button>
      </form>
    </AuthLayout>
  );
}
