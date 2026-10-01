import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Camera,
  CheckCircle2,
  KeyRound,
  LogOut,
  Mail,
  MailCheck,
  ShieldCheck,
  Smartphone,
  User as UserIcon,
} from 'lucide-react';
import { api } from '../../api/client';
import { useAuth } from '../../hooks/useAuth';
import { useApi } from '../../hooks/useApi';
import { useToast } from '../../context/ToastContext';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { PageHeader } from '../../components/ui/PageHeader';
import { Card } from '../../components/ui/Card';
import { Button } from '../../components/ui/Button';
import { Field, Input } from '../../components/ui/Input';
import { StatusBadge } from '../../components/ui/Badge';
import { Alert } from '../../components/ui/Alert';
import { Skeleton } from '../../components/ui/Skeleton';
import { formatDate, formatDateTime } from '../../utils/format';

/**
 * Profil utilisateur.
 * GET/PUT /profil (multipart via POST + _method, car l'avatar est un fichier)
 * GET /sessions → sessions Sanctum actives de l'utilisateur.
 *
 * L'API ne permet pas de modifier l'email ni le rôle : ces champs sont
 * affichés en lecture seule avec une explication.
 */
export default function Profile() {
  useDocumentTitle('Profil');
  const { refreshUser, logout } = useAuth();
  const toast = useToast();
  const navigate = useNavigate();
  const fileInputRef = useRef(null);

  const [profile, setProfile] = useState(null);
  const [form, setForm] = useState({ name: '', phone: '', current_password: '', new_password: '', new_password_confirmation: '' });
  const [avatarFile, setAvatarFile] = useState(null);
  const [avatarPreview, setAvatarPreview] = useState(null);
  const [errors, setErrors] = useState({});
  const [isSubmitting, setIsSubmitting] = useState(false);

  const { data: sessionsData, error: sessionsError } = useApi(() => api.get('/sessions'), []);
  // L'API renvoie un tableau JSON brut pour /sessions.
  const sessions = Array.isArray(sessionsData) ? sessionsData : [];

  useEffect(() => {
    api
      .get('/profil')
      .then((data) => {
        setProfile(data);
        setForm((current) => ({ ...current, name: data.name || '', phone: data.phone || '' }));
      })
      .catch((err) => toast.error(err.message));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const update = (field) => (event) => setForm((current) => ({ ...current, [field]: event.target.value }));
  const fieldError = (name) => errors[name]?.[0];

  const handleAvatarChange = (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    setAvatarFile(file);
    setAvatarPreview(URL.createObjectURL(file));
  };

  const handleSubmit = async (event) => {
    event.preventDefault();
    setErrors({});
    setIsSubmitting(true);

    const formData = new FormData();
    formData.append('_method', 'PUT');
    Object.entries(form).forEach(([key, value]) => formData.append(key, value));
    if (avatarFile) formData.append('avatar', avatarFile);

    try {
      const updated = await api.post('/profil', formData, { isFormData: true });
      setProfile(updated);
      await refreshUser();
      setAvatarFile(null);
      setAvatarPreview(null);
      setForm((current) => ({ ...current, current_password: '', new_password: '', new_password_confirmation: '' }));
      toast.success('Ton profil a été mis à jour.');
    } catch (err) {
      if (err?.errors) setErrors(err.errors);
      toast.error(err.message);
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleLogout = async () => {
    await logout();
    navigate('/', { replace: true });
  };

  if (!profile) {
    return (
      <div className="max-w-5xl space-y-5" aria-busy="true">
        <Skeleton className="h-9 w-48" />
        <Card>
          <div className="flex flex-col items-center gap-4 sm:flex-row sm:items-start">
            <Skeleton className="size-24 rounded-full" />
            <div className="w-full space-y-3">
              <Skeleton className="h-5 w-48" />
              <Skeleton className="h-10 w-full rounded-xl" />
              <Skeleton className="h-10 w-full rounded-xl" />
            </div>
          </div>
        </Card>
      </div>
    );
  }

  const avatarSrc = avatarPreview || profile.avatar_url;

  return (
    <div className="mx-auto max-w-5xl">
      <PageHeader
        title="Mon profil"
        subtitle="Tes informations personnelles, ta sécurité et tes sessions actives."
        breadcrumb={[{ label: 'Mon espace', to: '/tableau-de-bord' }, { label: 'Profil' }]}
      />

      <div className="grid gap-6 lg:grid-cols-[1fr_320px] lg:items-start">
        {/* Informations personnelles */}
        <Card>
          <h2 className="font-display text-base font-bold text-ink-900">Informations personnelles</h2>

          {/* Photo */}
          <div className="mt-5 flex flex-col items-center gap-4 rounded-2xl bg-ink-50/70 p-5 sm:flex-row sm:items-center">
            <button
              type="button"
              onClick={() => fileInputRef.current?.click()}
              className="group relative shrink-0"
              aria-label="Changer la photo de profil"
            >
              {avatarSrc ? (
                <img src={avatarSrc} alt="" className="size-24 rounded-full object-cover ring-4 ring-white" />
              ) : (
                <span className="flex size-24 items-center justify-center rounded-full bg-gradient-to-br from-primary-600 to-primary-800 text-2xl font-bold text-white ring-4 ring-white">
                  {profile.name?.charAt(0).toUpperCase()}
                </span>
              )}
              <span className="absolute inset-0 flex items-center justify-center rounded-full bg-ink-950/50 text-white opacity-0 transition-opacity group-hover:opacity-100">
                <Camera className="size-5" aria-hidden="true" />
              </span>
            </button>

            <input ref={fileInputRef} type="file" accept="image/*" className="sr-only" onChange={handleAvatarChange} />

            <div className="min-w-0 flex-1 text-center sm:text-left">
              <p className="truncate font-display text-lg font-bold text-ink-900">{profile.name}</p>
              <p className="truncate text-sm text-ink-500">{profile.email}</p>
              <div className="mt-2 flex flex-wrap items-center justify-center gap-2 sm:justify-start">
                <StatusBadge status={profile.role} size="sm" />
                {profile.email_verified_at ? (
                  <span className="inline-flex items-center gap-1 text-[11px] font-semibold text-success-700">
                    <MailCheck className="size-3.5" aria-hidden="true" />
                    Email vérifié
                  </span>
                ) : (
                  <span className="inline-flex items-center gap-1 text-[11px] font-semibold text-warning-700">
                    <Mail className="size-3.5" aria-hidden="true" />
                    Email non vérifié
                  </span>
                )}
              </div>
              <Button variant="ghost" size="xs" className="mt-2" onClick={() => fileInputRef.current?.click()}>
                Changer la photo
              </Button>
              {fieldError('avatar') && <p className="mt-1 text-xs font-medium text-danger-600">{fieldError('avatar')}</p>}
            </div>
          </div>

          <form onSubmit={handleSubmit} className="mt-6 space-y-4">
            <Field label="Nom complet" error={fieldError('name')} required>
              <Input value={form.name} onChange={update('name')} required autoComplete="name" />
            </Field>

            <Field label="Téléphone" error={fieldError('phone')} required hint="Utilisé pour te joindre en cas de besoin.">
              <Input value={form.phone} onChange={update('phone')} required type="tel" autoComplete="tel" placeholder="+227 90 00 00 00" />
            </Field>

            <Field label="Adresse e-mail" hint="L'adresse e-mail ne peut pas être modifiée depuis l'application.">
              <Input value={profile.email} disabled readOnly />
            </Field>

            <div className="border-t border-line-soft pt-5">
              <div className="flex items-center gap-2">
                <KeyRound className="size-4 text-primary-600" aria-hidden="true" />
                <h3 className="font-display text-sm font-bold text-ink-900">Sécurité — changer de mot de passe</h3>
              </div>
              <p className="mt-1 text-xs text-ink-500">
                Laisse ces champs vides si tu ne veux pas modifier ton mot de passe. 8 caractères minimum.
              </p>

              <div className="mt-4 grid gap-4 sm:grid-cols-3">
                <Field label="Mot de passe actuel" error={fieldError('current_password')}>
                  <Input type="password" value={form.current_password} onChange={update('current_password')} autoComplete="current-password" />
                </Field>
                <Field label="Nouveau mot de passe" error={fieldError('new_password')}>
                  <Input type="password" value={form.new_password} onChange={update('new_password')} autoComplete="new-password" />
                </Field>
                <Field label="Confirmer" error={fieldError('new_password_confirmation')}>
                  <Input type="password" value={form.new_password_confirmation} onChange={update('new_password_confirmation')} autoComplete="new-password" />
                </Field>
              </div>
            </div>

            <div className="flex flex-wrap items-center gap-3 border-t border-line-soft pt-5">
              <Button type="submit" loading={isSubmitting} disabled={isSubmitting}>
                Enregistrer les modifications
              </Button>
              {fieldError('email') && <p className="text-xs font-medium text-danger-600">{fieldError('email')}</p>}
            </div>
          </form>
        </Card>

        {/* Colonne latérale */}
        <aside className="space-y-4">
          <Card>
            <h3 className="flex items-center gap-2 font-display text-sm font-bold text-ink-900">
              <ShieldCheck className="size-4 text-primary-600" aria-hidden="true" />
              Sécurité
            </h3>
            <p className="mt-1 text-xs leading-relaxed text-ink-500">
              Compte protégé par jeton d’authentification. En cas de doute, change ton mot de passe puis
              déconnecte-toi.
            </p>
            <Button variant="secondary" icon={LogOut} className="mt-4 w-full" onClick={handleLogout}>
              Se déconnecter
            </Button>
          </Card>

          <Card>
            <h3 className="flex items-center gap-2 font-display text-sm font-bold text-ink-900">
              <Smartphone className="size-4 text-primary-600" aria-hidden="true" />
              Sessions actives
            </h3>
            <p className="mt-1 text-xs text-ink-500">Appareils actuellement connectés à ton compte.</p>

            {sessionsError ? (
              <Alert type="error" className="mt-3">
                Sessions indisponibles.
              </Alert>
            ) : sessions.length === 0 ? (
              <p className="mt-3 text-xs text-ink-400">Aucune session active.</p>
            ) : (
              <ul className="mt-4 space-y-2.5">
                {sessions.map((session) => (
                  <li key={session.id} className="flex items-start gap-2.5 rounded-xl bg-ink-50 px-3 py-2.5">
                    <span className="mt-0.5">
                      {session.is_current ? (
                        <CheckCircle2 className="size-4 text-success-600" aria-hidden="true" />
                      ) : (
                        <Smartphone className="size-4 text-ink-400" aria-hidden="true" />
                      )}
                    </span>
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-xs font-semibold text-ink-800">
                        {session.is_current ? 'Session actuelle' : session.name || 'Session'}
                      </p>
                      <p className="truncate text-[11px] text-ink-500">
                        {session.last_used_at ? `Dernière activité ${formatDate(session.last_used_at)}` : `Créée le ${formatDate(session.created_at)}`}
                      </p>
                    </div>
                  </li>
                ))}
              </ul>
            )}

            <p className="mt-4 border-t border-line-soft pt-3 text-[11px] leading-relaxed text-ink-400">
              Pour révoquer une session précise, contacte le support : l’API d’administration n’expose pas
              d’action de révocation unitaire depuis ce profil.
            </p>
          </Card>

          <Card>
            <h3 className="flex items-center gap-2 font-display text-sm font-bold text-ink-900">
              <UserIcon className="size-4 text-primary-600" aria-hidden="true" />
              Infos du compte
            </h3>
            <dl className="mt-3 space-y-2 text-xs">
              <InfoRow label="Rôle" value={profile.role} />
              <InfoRow label="Inscrit le" value={formatDate(profile.created_at)} />
              <InfoRow label="Email vérifié le" value={profile.email_verified_at ? formatDateTime(profile.email_verified_at) : 'Jamais'} />
              {profile.merchant && (
                <InfoRow
                  label="Boutique"
                  value={`${profile.merchant.business_name} (${profile.merchant.status})`}
                />
              )}
            </dl>
          </Card>
        </aside>
      </div>
    </div>
  );
}

function InfoRow({ label, value }) {
  return (
    <div className="flex items-start justify-between gap-3 border-b border-line-soft pb-2 last:border-0 last:pb-0">
      <dt className="shrink-0 text-ink-500">{label}</dt>
      <dd className="truncate text-right font-semibold capitalize text-ink-800">{value}</dd>
    </div>
  );
}
