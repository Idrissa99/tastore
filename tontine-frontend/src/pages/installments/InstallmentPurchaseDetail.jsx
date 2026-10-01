import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowRight, CheckCircle2, Receipt } from 'lucide-react';
import { api } from '../../api/client';
import { useApi } from '../../hooks/useApi';
import { useToast } from '../../context/ToastContext';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { PageHeader } from '../../components/ui/PageHeader';
import { Card } from '../../components/ui/Card';
import { Button, ButtonLink } from '../../components/ui/Button';
import { StatusBadge } from '../../components/ui/Badge';
import { ProgressBar } from '../../components/ui/Progress';
import { ProductImage, productImage } from '../../components/ui/ProductImage';
import { Skeleton } from '../../components/ui/Skeleton';
import { ErrorState } from '../../components/ui/EmptyState';
import PaymentFlow from '../../components/payments/PaymentFlow';
import VerificationBanner from '../../components/payments/VerificationBanner';
import { formatDate, formatDateTime, formatFcfa, formatNumber, truncate } from '../../utils/format';

/**
 * Détail d'un achat par tranches + timeline de livraison.
 *
 * Endpoints : GET /mes-achats/{id}, GET /config,
 * POST /tranches/{id}/pay, POST /tranches/{id}/soumettre-code.
 */
export default function InstallmentPurchaseDetail() {
  const { id } = useParams();
  const toast = useToast();

  const { data, isLoading, error, reload } = useApi(() => api.get(`/mes-achats/${id}`), [id]);
  const { data: config } = useApi(() => api.get('/config'), []);
  const channels = config?.payment_channels || {};

  const [activeInstallment, setActiveInstallment] = useState(null);
  const purchase = data?.data;

  useDocumentTitle(purchase?.product?.name || 'Commande');

  const handlePay = async (installment, payload) => {
    await api.post(`/tranches/${installment.id}/pay`, payload);
    toast.success('Paiement enregistré.');
    await reload();
  };

  const handleSubmitCode = async (installment, payload) => {
    await api.post(`/tranches/${installment.id}/soumettre-code`, payload);
    toast.success('Code de transfert envoyé pour vérification.');
    await reload();
  };

  if (isLoading) return <DetailSkeleton />;

  if (error) {
    return (
      <div className="mx-auto max-w-2xl py-10">
        <ErrorState title="Commande introuvable" message={error.message} onRetry={reload} />
        <div className="mt-5 text-center">
          <Link to="/mes-achats" className="text-sm font-semibold text-primary-700 hover:underline">
            ← Retour à mes commandes
          </Link>
        </div>
      </div>
    );
  }

  if (!purchase) return null;

  const paid = purchase.paid_installments_count || 0;
  const total = purchase.installments_count || 1;
  const remaining = (total - paid) * Number(purchase.installment_amount || 0);

  return (
    <div>
      <PageHeader
        breadcrumb={[
          { label: 'Mon espace', to: '/tableau-de-bord' },
          { label: 'Mes commandes', to: '/mes-achats' },
          { label: purchase.product?.name || 'Commande' },
        ]}
        title={purchase.product?.name || 'Commande'}
        subtitle={`Commande du ${formatDate(purchase.created_at)} · ${formatNumber(paid)} tranches sur ${formatNumber(total)} réglées`}
      />

      <div className="grid gap-6 lg:grid-cols-[1fr_340px] lg:items-start">
        <div className="min-w-0 space-y-6">
          {/* Progression */}
          <Card>
            <div className="flex flex-col gap-5 sm:flex-row sm:items-center">
              <ProductImage
                src={productImage(purchase.product)}
                alt={purchase.product?.name}
                ratio="square"
                className="size-24 shrink-0"
              />
              <div className="min-w-0 flex-1">
                <div className="mb-2 flex items-baseline justify-between gap-3">
                  <span className="text-sm font-semibold text-ink-700">Avancement du paiement</span>
                  <span className="amount text-lg text-primary-700">
                    {Math.round((paid / total) * 100)} %
                  </span>
                </div>
                <ProgressBar value={paid} total={total} size="lg" />
                <p className="mt-2 text-xs text-ink-500">
                  {formatFcfa(paid * Number(purchase.installment_amount || 0))} réglés sur{' '}
                  {formatFcfa(Number(purchase.product_price || 0))} + commission
                </p>
              </div>
            </div>
          </Card>

          {/* Tranches */}
          <section>
            <h2 className="mb-3 font-display text-base font-bold text-ink-900">Détail des tranches</h2>
            <ul className="space-y-3">
              {purchase.installments?.map((installment) => (
                <li key={installment.id}>
                  <InstallmentCard
                    installment={installment}
                    onPay={() => setActiveInstallment(installment)}
                  />
                </li>
              ))}
            </ul>
          </section>
        </div>

        {/* Colonne latérale : timeline */}
        <aside className="lg:sticky lg:top-24">
          <Card>
            <h2 className="font-display text-base font-bold text-ink-900">Suivi de la commande</h2>
            <p className="mt-1 text-xs text-ink-500">
              La livraison est confirmée par le commerçant, pas par la plateforme.
            </p>

            <ol className="mt-5">
              <TimelineStep done title="Commande créée" meta={formatDate(purchase.created_at)} />
              <TimelineStep
                done={purchase.installments_count > 0 && purchase.installments.some((i) => i.status === 'completed')}
                title="Paiement confirmé"
                meta={
                  purchase.paid_installments_count > 0
                    ? `${formatNumber(paid)} tranche(s) validée(s)`
                    : 'En attente du premier versement'
                }
              />
              <TimelineStep
                done={paid >= total}
                title="Paiement complet"
                meta={paid >= total ? 'Toutes les tranches sont réglées' : `Reste ${formatFcfa(remaining)}`}
              />
              <TimelineStep
                done={purchase.delivery_status === 'delivered'}
                title="Livraison"
                meta={
                  purchase.delivery_status === 'delivered'
                    ? `Livré le ${formatDate(purchase.delivered_at)}`
                    : 'En attente de confirmation du commerçant'
                }
                last
              />
            </ol>

            <ButtonLink to="/mes-achats" variant="secondary" className="mt-6 w-full" icon={ArrowRight}>
              Toutes mes commandes
            </ButtonLink>
          </Card>

          <Card className="mt-4">
            <h3 className="flex items-center gap-2 text-sm font-bold text-ink-900">
              <Receipt className="size-4 text-primary-600" aria-hidden="true" />
              Récapitulatif financier
            </h3>
            <dl className="mt-3 space-y-2 text-sm">
              <Line label="Prix du produit" value={formatFcfa(purchase.product_price)} />
              <Line label="Nombre de tranches" value={formatNumber(total)} />
              <Line label="Montant par tranche" value={formatFcfa(purchase.installment_amount)} />
              <Line label="Déjà réglé" value={formatFcfa(paid * Number(purchase.installment_amount || 0))} />
              <Line label="Reste à payer" value={formatFcfa(remaining)} strong />
            </dl>
          </Card>
        </aside>
      </div>

      <PaymentFlow
        open={Boolean(activeInstallment)}
        onClose={() => setActiveInstallment(null)}
        title={`Tranche ${activeInstallment?.installment_number}`}
        subtitle={purchase.product?.name}
        amount={activeInstallment?.amount}
        channels={channels}
        onPay={(payload) => handlePay(activeInstallment, payload)}
        onSubmitCode={(payload) => handleSubmitCode(activeInstallment, payload)}
      />
    </div>
  );
}

/* ------------------------------------------------------------------ */

function InstallmentCard({ installment, onPay }) {
  const isSettled = installment.status === 'completed' && installment.verification_status !== 'rejected';
  const isAwaiting = installment.verification_status === 'pending';

  return (
    <Card padding="sm" className={isSettled ? 'opacity-90' : ''}>
      <div className="flex flex-wrap items-start gap-4">
        <span
          className={`flex size-11 shrink-0 flex-col items-center justify-center rounded-xl text-xs font-bold ${
            isSettled ? 'bg-success-50 text-success-700' : isAwaiting ? 'bg-warning-50 text-warning-700' : 'bg-ink-50 text-ink-500'
          }`}
        >
          <span className="text-[9px] uppercase tracking-wide opacity-70">Tranche</span>
          {installment.installment_number}
        </span>

        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <p className="text-sm font-semibold text-ink-900">Versement n°{installment.installment_number}</p>
            {isSettled && <StatusBadge status="accepted" label="Validée" size="xs" dot />}
            {isAwaiting && <StatusBadge status="pending" label="En vérification" size="xs" dot />}
            {installment.verification_status === 'rejected' && <StatusBadge status="rejected" size="xs" dot />}
            {!isSettled && !isAwaiting && installment.verification_status !== 'rejected' && (
              <StatusBadge status="pending" label="À payer" size="xs" dot />
            )}
          </div>

          {installment.paid_at && <p className="mt-1 text-xs text-ink-500">Réglée le {formatDate(installment.paid_at)}</p>}

          <VerificationBanner
            status={installment.status}
            verificationStatus={installment.verification_status}
            className="mt-3"
          />
        </div>

        <div className="flex shrink-0 flex-col items-end gap-2.5">
          <p className="amount text-lg">{formatFcfa(installment.amount)}</p>
          {!isSettled && !isAwaiting && (
            <Button size="sm" icon={ArrowRight} onClick={onPay}>
              {installment.verification_status === 'rejected' ? 'Nouveau code' : 'Payer'}
            </Button>
          )}
        </div>
      </div>

      {installment.transfer_code && (
        <p className="mt-3 flex items-center gap-2 border-t border-line-soft pt-3 text-xs">
          <span className="text-ink-400">Code de transfert</span>
          <span className="rounded-md bg-ink-100 px-2 py-0.5 font-mono text-[11px] font-semibold text-ink-800">
            {truncate(installment.transfer_code, 24)}
          </span>
          {installment.submitted_at && (
            <span className="ml-auto text-[11px] text-ink-400">Soumis le {formatDateTime(installment.submitted_at)}</span>
          )}
        </p>
      )}
    </Card>
  );
}

function TimelineStep({ done, title, meta, last = false }) {
  return (
    <li className="relative flex gap-3.5 pb-6 last:pb-0">
      {!last && (
        <span
          className={`absolute left-[11px] top-6 h-full w-0.5 ${done ? 'bg-success-200' : 'bg-line'}`}
          aria-hidden="true"
        />
      )}
      <span
        className={`relative z-10 flex size-6 shrink-0 items-center justify-center rounded-full border-2 transition-colors ${
          done ? 'border-success-500 bg-success-500 text-white' : 'border-line-strong bg-white text-ink-300'
        }`}
      >
        {done ? <CheckCircle2 className="size-3.5" aria-hidden="true" /> : <span className="size-1.5 rounded-full bg-current" />}
      </span>
      <div className="min-w-0 pt-0.5">
        <p className={`text-sm font-semibold ${done ? 'text-ink-900' : 'text-ink-500'}`}>{title}</p>
        {meta && <p className="mt-0.5 text-xs text-ink-500">{meta}</p>}
      </div>
    </li>
  );
}

function Line({ label, value, strong = false }) {
  return (
    <div className={`flex items-center justify-between gap-4 ${strong ? 'border-t border-line-soft pt-2' : ''}`}>
      <dt className="text-xs text-ink-500">{label}</dt>
      <dd className={strong ? 'amount text-base text-primary-700' : 'amount text-sm'}>{value}</dd>
    </div>
  );
}

function DetailSkeleton() {
  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_340px]" aria-busy="true">
      <div className="space-y-6">
        <Card>
          <div className="flex gap-5">
            <Skeleton className="size-24 rounded-xl" />
            <div className="flex-1 space-y-3">
              <Skeleton className="h-4 w-32" />
              <Skeleton className="h-2.5 w-full rounded-full" />
            </div>
          </div>
        </Card>
        {[0, 1, 2].map((index) => (
          <Card key={index}>
            <div className="flex gap-4">
              <Skeleton className="size-11 rounded-xl" />
              <div className="flex-1 space-y-3">
                <Skeleton className="h-4 w-40" />
                <Skeleton className="h-3 w-24" />
              </div>
              <Skeleton className="h-8 w-24 rounded-xl" />
            </div>
          </Card>
        ))}
      </div>
      <Card className="space-y-3">
        <Skeleton className="h-5 w-40" />
        {[0, 1, 2, 3].map((index) => (
          <Skeleton key={index} className="h-12 w-full rounded-xl" />
        ))}
      </Card>
    </div>
  );
}
