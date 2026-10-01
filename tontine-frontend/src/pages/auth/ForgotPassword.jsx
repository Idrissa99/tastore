import { useState } from 'react';
import { useLocation } from 'react-router-dom';
import { Mail, Send } from 'lucide-react';
import { api, ApiError } from '../../api/client';
import { Alert } from '../../components/ui/Alert';
import { Button } from '../../components/ui/Button';
import { Field, Input } from '../../components/ui/Input';
import AuthLayout, { AuthFooter } from '../../components/layout/AuthLayout';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';

export default function ForgotPassword() {
  useDocumentTitle('Mot de passe oublié');
  const location = useLocation();

  const [email, setEmail] = useState(location.state?.email || '');
  const [message, setMessage] = useState(null);
  const [error, setError] = useState(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const handleSubmit = async (event) => {
    event.preventDefault();
    setError(null);
    setMessage(null);
    setIsSubmitting(true);

    try {
      const data = await api.post('/forgot-password', { email: email.trim() });
      // L'API répond toujours 200, même si aucun compte n'existe : on
      // n'indique jamais si une adresse est enregistrée.
      setMessage(data.message);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Une erreur est survenue.');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <AuthLayout
      title="Mot de passe oublié"
      subtitle="Indique l’adresse e-mail de ton compte : tu recevras un lien pour choisir un nouveau mot de passe."
      footer={<AuthFooter text="Tu t’es souvenu du mot de passe ?" linkTo="/connexion" linkLabel="Retour à la connexion" />}
    >
      {message && (
        <Alert type="success" className="mb-5">
          {message}
        </Alert>
      )}

      {error && (
        <Alert type="error" className="mb-5">
          {error}
        </Alert>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <Field label="Adresse e-mail" htmlFor="forgot-email" required>
          <Input
            id="forgot-email"
            type="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            required
            autoComplete="email"
            autoFocus
            placeholder="nom@exemple.ne"
          />
        </Field>

        <Button type="submit" size="lg" className="w-full" icon={message ? Mail : Send} loading={isSubmitting} disabled={isSubmitting}>
          {isSubmitting ? 'Envoi en cours…' : 'Envoyer le lien'}
        </Button>
      </form>
    </AuthLayout>
  );
}
