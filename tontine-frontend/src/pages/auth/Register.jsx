import { useState } from 'react';
import { Store, User as UserIcon, UserPlus } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../../hooks/useAuth';
import { homeForRole } from '../../navigation';
import { ApiError } from '../../api/client';
import { Alert } from '../../components/ui/Alert';
import { Button } from '../../components/ui/Button';
import { Field, Input, RadioCard } from '../../components/ui/Input';
import AuthLayout, { AuthFooter } from '../../components/layout/AuthLayout';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';

const initialForm = {
  name: '',
  email: '',
  phone: '',
  password: '',
  password_confirmation: '',
  role: 'client',
};

export default function Register() {
  useDocumentTitle('Créer un compte');
  const { register, user } = useAuth();
  const navigate = useNavigate();

  const [form, setForm] = useState(initialForm);
  const [errors, setErrors] = useState({});
  const [globalError, setGlobalError] = useState(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const update = (field) => (event) => setForm((current) => ({ ...current, [field]: event.target.value }));
  const fieldError = (name) => errors[name]?.[0];

  const handleSubmit = async (event) => {
    event.preventDefault();
    setErrors({});
    setGlobalError(null);

    if (form.password !== form.password_confirmation) {
      setErrors({ password_confirmation: ['La confirmation ne correspond pas au mot de passe.'] });
      return;
    }

    setIsSubmitting(true);
    try {
      const account = await register({
        ...form,
        email: form.email.trim(),
        name: form.name.trim(),
        phone: form.phone.trim(),
      });
      navigate(homeForRole(account || user), { replace: true });
    } catch (err) {
      if (err instanceof ApiError && err.errors) setErrors(err.errors);
      else setGlobalError(err instanceof ApiError ? err.message : 'Une erreur est survenue.');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <AuthLayout
      title="Créer mon compte"
      subtitle="Rejoins une tontine en une minute. Ton inscription est gratuite."
      footer={<AuthFooter text="Déjà un compte ?" linkTo="/connexion" linkLabel="Se connecter" />}
    >
      {globalError && (
        <Alert type="error" className="mb-5">
          {globalError}
        </Alert>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <Field label="Nom complet" error={fieldError('name')} required>
          <Input value={form.name} onChange={update('name')} required autoComplete="name" placeholder="Amadou Sanou" />
        </Field>

        <Field label="Adresse e-mail" error={fieldError('email')} required>
          <Input
            type="email"
            value={form.email}
            onChange={update('email')}
            required
            autoComplete="email"
            placeholder="nom@exemple.ne"
          />
        </Field>

        <Field label="Téléphone" error={fieldError('phone')} required hint="Sert à te contacter en cas de besoin.">
          <Input
            type="tel"
            value={form.phone}
            onChange={update('phone')}
            required
            autoComplete="tel"
            placeholder="+227 90 00 00 00"
          />
        </Field>

        <Field label="Mot de passe" error={fieldError('password')} required hint="8 caractères minimum.">
          <Input
            type="password"
            value={form.password}
            onChange={update('password')}
            required
            autoComplete="new-password"
            minLength={8}
          />
        </Field>

        <Field label="Confirmer le mot de passe" error={fieldError('password_confirmation')} required>
          <Input
            type="password"
            value={form.password_confirmation}
            onChange={update('password_confirmation')}
            required
            autoComplete="new-password"
            minLength={8}
          />
        </Field>

        <div>
          <p className="mb-2 text-sm font-semibold text-ink-700">Je m’inscris en tant que</p>
          <div className="grid gap-2.5 sm:grid-cols-2">
            <RadioCard
              name="role"
              value="client"
              checked={form.role === 'client'}
              onChange={update('role')}
              icon={UserIcon}
              title="Client"
              description="Je veux rejoindre des tontines"
            />
            <RadioCard
              name="role"
              value="merchant"
              checked={form.role === 'merchant'}
              onChange={update('role')}
              icon={Store}
              title="Commerçant"
              description="Je veux vendre mes produits"
            />
          </div>
          <p className="mt-2 text-[11px] leading-relaxed text-ink-400">
            Un compte commerçant est validé par un administrateur avant l’accès à l’espace vendeur.
          </p>
        </div>

        <Button type="submit" size="lg" className="w-full" icon={UserPlus} loading={isSubmitting} disabled={isSubmitting}>
          {isSubmitting ? 'Création du compte…' : 'Créer mon compte'}
        </Button>
      </form>
    </AuthLayout>
  );
}
