import { useEffect, useMemo, useRef, useState } from 'react';
import { Banknote, CheckCircle2, CreditCard, Landmark, Receipt, ShieldCheck, Smartphone, Wallet } from 'lucide-react';
import { Modal } from '../ui/Modal';
import { Button } from '../ui/Button';
import { Field, Input, RadioCard } from '../ui/Input';
import { Stepper } from '../ui/Tabs';
import { formatFcfa, normalizeTransferCode } from '../../utils/format';
import { isManualChannel } from '../../utils/status';
import { ManualChannelNotice } from './VerificationBanner';

/* Icônes par moyen de paiement, avec repli générique. */
const CHANNEL_ICONS = {
  orange_money: Smartphone,
  airtel_money: Smartphone,
  moov_money: Smartphone,
  mynita: ShieldCheck,
  amana: ShieldCheck,
  card: CreditCard,
  deposit_code: Landmark,
  bank: Landmark,
  cash: Banknote,
  default: Wallet,
};

const CHANNEL_HINTS = {
  orange_money: 'Orange Money',
  airtel_money: 'Airtel Money',
  moov_money: 'Moov Money',
  mynita: 'MyNita',
  amana: 'Amana',
  card: 'Carte bancaire',
  deposit_code: 'Code de dépôt en agence',
  bank: 'Virement bancaire',
};

const STEPS = [
  { id: 'montant', label: 'Montant' },
  { id: 'moyen', label: 'Moyen' },
  { id: 'confirmation', label: 'Confirmation' },
];

/**
 * Parcours de versement en 3 étapes, réutilisé par les cotisations
 * (POST /contributions/{id}/pay | /contributions/{id}/soumettre-code) et par
 * les tranches d'achat (POST /tranches/{id}/pay | /tranches/{id}/soumettre-code).
 *
 * L'API impose deux voies distinctes :
 *   - canaux à vérification manuelle (MyNita, Amana) : `soumettre-code`
 *     avec le code de transfert ;
 *   - autres canaux : `pay` avec une référence libre.
 * `channels` provient de GET /config (aucune donnée inventée).
 */
export function PaymentFlow({
  open,
  onClose,
  title,
  subtitle,
  amount,
  meta = [],
  channels = {},
  onPay,
  onSubmitCode,
}) {
  const [step, setStep] = useState(0);
  const [channel, setChannel] = useState('');
  const [reference, setReference] = useState('');
  const [transferCode, setTransferCode] = useState('');
  const [error, setError] = useState(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  // Panneau de confirmation affiché après un envoi réussi. On ne se base pas
  // sur le statut renvoyé par le parent : l'objet contribution reste celui
  // d'avant l'envoi tant que la page n'a pas rechargé ses données.
  const [submitted, setSubmitted] = useState(false);
  const codeRef = useRef(null);

  const channelEntries = useMemo(() => Object.entries(channels), [channels]);
  const manual = isManualChannel(channel);

  useEffect(() => {
    if (!open) return;
    setStep(0);
    setError(null);
    setReference('');
    setTransferCode('');
    setSubmitted(false);
    setIsSubmitting(false);
    setChannel((current) => current || channelEntries[0]?.[0] || '');
  }, [open, channelEntries]);

  const submit = async () => {
    setError(null);
    setIsSubmitting(true);
    try {
      if (manual) {
        await onSubmitCode({ payment_method: channel, transfer_code: transferCode.trim() });
      } else {
        await onPay({ payment_method: channel, reference: reference.trim() || undefined });
      }
      setSubmitted(true);
      setStep(2);
    } catch (err) {
      setError(err?.message || "L'opération a échoué.");
    } finally {
      setIsSubmitting(false);
    }
  };

  const canSubmit = manual ? transferCode.trim().length >= 3 : true;

  return (
    <Modal
      open={open}
      onClose={isSubmitting ? undefined : onClose}
      size="md"
      title={title}
      description={subtitle}
    >
      <Stepper steps={STEPS} current={STEPS[step].id} className="mb-6" />

      {/* Étape 1 — montant */}
      {step === 0 && (
        <div className="animate-[rise_0.3s_cubic-bezier(0.22,1,0.36,1)] space-y-5">
          <div className="rounded-2xl border border-primary-100 bg-gradient-to-br from-primary-50 to-white p-5 text-center">
            <p className="text-xs font-semibold uppercase tracking-wide text-primary-700">Montant du versement</p>
            <p className="amount mt-2 text-3xl text-primary-800 sm:text-4xl">{formatFcfa(amount)}</p>
          </div>

          {meta.length > 0 && (
            <dl className="divide-y divide-line-soft rounded-xl border border-line">
              {meta.map((item) => (
                <div key={item.label} className="flex items-center justify-between gap-4 px-4 py-3">
                  <dt className="text-sm text-ink-500">{item.label}</dt>
                  <dd className="text-sm font-semibold text-ink-900">{item.value}</dd>
                </div>
              ))}
            </dl>
          )}

          <Button size="lg" className="w-full" onClick={() => setStep(1)}>
            Continuer
          </Button>
        </div>
      )}

      {/* Étape 2 — moyen de paiement */}
      {step === 1 && (
        <div className="animate-[rise_0.3s_cubic-bezier(0.22,1,0.36,1)] space-y-4">
          {channelEntries.length === 0 ? (
            <p className="rounded-xl bg-ink-50 px-4 py-3 text-sm text-ink-600">
              Aucun moyen de paiement n’est configuré sur la plateforme. Contacte le support.
            </p>
          ) : (
            <div className="grid gap-2.5 sm:grid-cols-2">
              {channelEntries.map(([key, label]) => {
                const Icon = CHANNEL_ICONS[key] || CHANNEL_ICONS.default;
                return (
                  <RadioCard
                    key={key}
                    name="payment-channel"
                    value={key}
                    checked={channel === key}
                    onChange={() => setChannel(key)}
                    icon={Icon}
                    title={label || CHANNEL_HINTS[key] || key}
                    description={
                      isManualChannel(key)
                        ? 'Vérification manuelle par un administrateur'
                        : 'Validation immédiate par la plateforme'
                    }
                  />
                );
              })}
            </div>
          )}

          {manual && <ManualChannelNotice />}

          {error && <ErrorText message={error} />}

          <div className="flex gap-2.5 pt-1">
            <Button variant="ghost" onClick={() => setStep(0)}>
              Retour
            </Button>
            <Button
              className="flex-1"
              disabled={!channel || channelEntries.length === 0}
              onClick={() => {
                setError(null);
                setStep(2);
              }}
            >
              Continuer
            </Button>
          </div>
        </div>
      )}

      {/* Étape 3 — confirmation */}
      {step === 2 && (
        <div className="animate-[rise_0.3s_cubic-bezier(0.22,1,0.36,1)] space-y-4">
          {submitted ? (
            <div className="space-y-5">
              <div className="flex flex-col items-center rounded-2xl border border-success-200 bg-success-50 px-5 py-6 text-center">
                <span className="flex size-12 items-center justify-center rounded-full bg-success-500 text-white">
                  <CheckCircle2 className="size-6" aria-hidden="true" />
                </span>
                <h3 className="mt-3 text-base font-bold text-success-700">
                  {manual ? 'Paiement soumis' : 'Paiement confirmé'}
                </h3>
                <p className="mt-1 max-w-xs text-sm leading-relaxed text-ink-600">
                  {manual ? (
                    <>
                      Ton versement de <span className="font-bold">{formatFcfa(amount)}</span> attend maintenant la
                      vérification de notre équipe.
                    </>
                  ) : (
                    <>
                      Ton versement de <span className="font-bold">{formatFcfa(amount)}</span> a bien été enregistré.
                    </>
                  )}
                </p>
                <span
                  className={`mt-3 inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1 text-xs font-bold ring-1 ring-inset ${
                    manual ? 'text-warning-700 ring-warning-200' : 'text-success-700 ring-success-200'
                  }`}
                >
                  <span className={`size-1.5 rounded-full ${manual ? 'bg-warning-500' : 'bg-success-500'}`} aria-hidden="true" />
                  {manual ? 'En attente de vérification' : 'Validé'}
                </span>
              </div>

              <p className="text-center text-xs leading-relaxed text-ink-500">
                Tu peux suivre l’avancement depuis « Mes contributions ». Une notification t’avertira dès que le
                paiement sera accepté.
              </p>

              <Button variant="secondary" className="w-full" onClick={onClose}>
                Fermer
              </Button>
            </div>
          ) : manual ? (
            <div className="space-y-4">
              <Field
                label={`Code de transfert ${channels[channel] || ''}`}
                hint="Saisis le code reçu après ton transfert (ex. ABC123456789). Il sera comparé à l’opération mobile money."
                required
              >
                {(fieldProps) => (
                  <div>
                    <Input
                      {...fieldProps}
                      ref={codeRef}
                      value={transferCode}
                      onChange={(event) => setTransferCode(event.target.value)}
                      placeholder="ABC123456789"
                      autoComplete="off"
                      spellCheck="false"
                      maxLength={100}
                      className="font-mono tracking-wide"
                      inputSize="lg"
                    />
                    <p className="mt-2 flex items-center gap-1.5 text-[11px] text-ink-400">
                      <Receipt className="size-3" aria-hidden="true" />
                      {normalizeTransferCode(transferCode).length}/100 caractères
                    </p>
                  </div>
                )}
              </Field>

              {error && <ErrorText message={error} />}

              <div className="flex gap-2.5 pt-1">
                <Button variant="ghost" onClick={() => setStep(1)} disabled={isSubmitting}>
                  Retour
                </Button>
                <Button className="flex-1" onClick={submit} loading={isSubmitting} disabled={!canSubmit}>
                  {isSubmitting ? 'Envoi en cours…' : 'Envoyer le paiement'}
                </Button>
              </div>
            </div>
          ) : (
            <div className="space-y-4">
              <Field
                label="Référence de la transaction"
                hint="Facultatif : indique la référence reçue par SMS pour accélérer le rapprochement."
              >
                {(fieldProps) => (
                  <Input
                    {...fieldProps}
                    value={reference}
                    onChange={(event) => setReference(event.target.value)}
                    placeholder="Ex. OM-2026-884512"
                    maxLength={100}
                  />
                )}
              </Field>

              <div className="rounded-xl bg-ink-50 px-4 py-3.5">
                <p className="text-xs font-semibold uppercase tracking-wide text-ink-500">Récapitulatif</p>
                <div className="mt-2 space-y-1.5 text-sm">
                  <div className="flex justify-between">
                    <span className="text-ink-600">Moyen</span>
                    <span className="font-semibold text-ink-900">{channels[channel] || channel}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-ink-600">Montant</span>
                    <span className="amount">{formatFcfa(amount)}</span>
                  </div>
                </div>
              </div>

              {error && <ErrorText message={error} />}

              <div className="flex gap-2.5 pt-1">
                <Button variant="ghost" onClick={() => setStep(1)} disabled={isSubmitting}>
                  Retour
                </Button>
                <Button className="flex-1" variant="success" icon={CheckCircle2} onClick={submit} loading={isSubmitting}>
                  {isSubmitting ? 'Traitement…' : 'Confirmer le paiement'}
                </Button>
              </div>
            </div>
          )}
        </div>
      )}

    </Modal>
  );
}

function ErrorText({ message }) {
  return (
    <p role="alert" className="rounded-xl bg-danger-50 px-3.5 py-3 text-sm font-medium text-danger-700">
      {message}
    </p>
  );
}

export default PaymentFlow;
