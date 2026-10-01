import { useState } from 'react';
import { CheckCircle2, MailCheck, RefreshCw, Send } from 'lucide-react';
import { Link } from 'react-router-dom';
import { api } from '../../api/client';
import { useAuth } from '../../hooks/useAuth';
import { Alert } from '../../components/ui/Alert';
import { Button } from '../../components/ui/Button';
import { PageHeader } from '../../components/ui/PageHeader';
import { Card } from '../../components/ui/Card';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';

export default function VerifyEmailNotice() {
  useDocumentTitle('Vérifier mon email');
  const { user, refreshUser } = useAuth();

  const [message, setMessage] = useState(null);
  const [error, setError] = useState(null);
  const [isSending, setIsSending] = useState(false);
  const [isRefreshing, setIsRefreshing] = useState(false);

  const isVerified = Boolean(user?.email_verified_at);

  const handleResend = async () => {
    setError(null);
    setMessage(null);
    setIsSending(true);
    try {
      const data = await api.post('/email/verification-notification');
      setMessage(data.message);
    } catch (err) {
      setError(err.message);
    } finally {
      setIsSending(false);
    }
  };

  const handleRefresh = async () => {
    setIsRefreshing(true);
    try {
      await refreshUser();
    } finally {
      setIsRefreshing(false);
    }
  };

  return (
    <div className="mx-auto max-w-2xl">
      <PageHeader
        title="Vérifie ton adresse e-mail"
        subtitle="Cette étape est nécessaire pour rejoindre ou créer une tontine et régler tes versements."
        breadcrumb={[{ label: 'Mon espace', to: '/tableau-de-bord' }, { label: 'Vérifier mon email' }]}
      />

      <Card className="text-center">
        <span
          className={`mx-auto flex size-16 items-center justify-center rounded-2xl ${
            isVerified ? 'bg-success-50 text-success-600' : 'bg-primary-50 text-primary-700'
          }`}
        >
          {isVerified ? <CheckCircle2 className="size-8" aria-hidden="true" /> : <MailCheck className="size-8" aria-hidden="true" />}
        </span>

        {isVerified ? (
          <>
            <h2 className="mt-5 font-display text-xl font-bold text-ink-900">Ton e-mail est vérifié ✓</h2>
            <p className="mx-auto mt-2 max-w-md text-sm leading-relaxed text-ink-600">
              Toutes les fonctionnalités sont débloquées : tu peux rejoindre une tontine, en créer une et régler
              tes cotisations.
            </p>
            <Link
              to="/tableau-de-bord"
              className="mt-6 inline-flex h-11 items-center justify-center rounded-xl bg-primary-700 px-5 text-sm font-semibold text-white shadow-brand transition-colors hover:bg-primary-800"
            >
              Aller à mon tableau de bord
            </Link>
          </>
        ) : (
          <>
            <h2 className="mt-5 font-display text-xl font-bold text-ink-900">Un e-mail de vérification t’a été envoyé</h2>
            <p className="mx-auto mt-2 max-w-md text-sm leading-relaxed text-ink-600">
              Clique sur le lien reçu à <strong className="text-ink-800">{user?.email}</strong> pour confirmer ton
              adresse. Vérifie aussi tes courriers indésirables.
            </p>

            {message && (
              <Alert type="success" className="mt-5 text-left">
                {message}
              </Alert>
            )}

            {error && (
              <Alert type="error" className="mt-5 text-left">
                {error}
              </Alert>
            )}

            <div className="mt-6 flex flex-col justify-center gap-2.5 sm:flex-row">
              <Button icon={Send} loading={isSending} onClick={handleResend} disabled={isSending}>
                Renvoyer l’e-mail
              </Button>
              <Button variant="secondary" icon={RefreshCw} loading={isRefreshing} onClick={handleRefresh} disabled={isRefreshing}>
                J’ai vérifié, actualiser
              </Button>
            </div>

            <p className="mt-6 border-t border-line-soft pt-5 text-xs leading-relaxed text-ink-400">
              Sans vérification, l’API refuse toute action nécessitant le middleware `verified` : rejoindre une
              tontine, en créer une ou payer une cotisation.
            </p>
          </>
        )}
      </Card>
    </div>
  );
}
