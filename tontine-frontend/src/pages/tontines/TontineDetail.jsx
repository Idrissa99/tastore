import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import {
  ArrowRight,
  Banknote,
  CalendarDays,
  CheckCircle2,
  Clock,
  Coins,
  Flag,
  Info,
  Lock,
  Package,
  Pencil,
  ShoppingBag,
  Star,
  UserPlus,
  Users,
} from 'lucide-react';
import { api } from '../../api/client';
import { useAuth } from '../../hooks/useAuth';
import { useApi } from '../../hooks/useApi';
import { useToast } from '../../context/ToastContext';
import { useDocumentTitle } from '../../hooks/useDocumentTitle';
import { Breadcrumb } from '../../components/ui/PageHeader';
import PageContainer from '../../components/layout/PageContainer';
import { Card } from '../../components/ui/Card';
import { Badge, StatusBadge } from '../../components/ui/Badge';
import { Button, ButtonLink } from '../../components/ui/Button';
import { Alert } from '../../components/ui/Alert';
import { ProgressCircle, ProgressSummary } from '../../components/ui/Progress';
import TontineCover from '../../components/tontine/TontineCover';
import { Modal } from '../../components/ui/Modal';
import { Tabs } from '../../components/ui/Tabs';
import { Skeleton } from '../../components/ui/Skeleton';
import { ErrorState } from '../../components/ui/EmptyState';
import TontineWheel from '../../components/tontine/TontineWheel';
import { formatCountdown, formatDate, formatFcfa, initials, toNumber } from '../../utils/format';
import { frequencyLabel, tontineTypeLabel } from '../../utils/status';
import { estimatedDueDate } from '../../utils/schedule';

/**
 * Fiche d'une tontine — page la plus consultée de l'application.
 *
 * Distinction volontairement maintenue entre deux états qui se confondent
 * souvent : la PARTICIPATION d'un membre (active / beneficiary / completed /
 * withdrawn) et la LIVRAISON du produit (awaiting_payment / pending /
 * delivered). Un tour terminé ne signifie pas « produit remis ».
 */
export default function TontineDetail() {
  const { id } = useParams();
  const { isAuthenticated } = useAuth();
  const toast = useToast();
  const navigate = useNavigate();

  const { data, isLoading, error, reload } = useApi(() => api.get(`/tontines/${id}`), [id]);
  const tontine = data?.data;

  const [isJoining, setIsJoining] = useState(false);
  const [confirmJoin, setConfirmJoin] = useState(false);
  const [tab, setTab] = useState('members');

  useDocumentTitle(tontine?.name || 'Tontine');

  const handleJoin = async () => {
    setIsJoining(true);
    try {
      await api.post(`/tontines/${id}/join`);
      toast.success('Tu as rejoint la tontine. Tes versements apparaissent dans « Mes cotisations ».');
      setConfirmJoin(false);
      await reload();
    } catch (err) {
      toast.error(err.message);
    } finally {
      setIsJoining(false);
    }
  };

  if (isLoading) return <PageContainer><TontineDetailSkeleton /></PageContainer>;

  if (error) {
    return (
      <PageContainer size="narrow">
        <ErrorState
          title={error.status === 404 ? 'Tontine introuvable' : 'Impossible de charger cette tontine'}
          message={error.message}
          onRetry={reload}
        />
        <div className="mt-5 text-center">
          <Link to="/tontines" className="text-sm font-semibold text-primary-700 hover:underline">
            ← Retour aux tontines
          </Link>
        </div>
      </PageContainer>
    );
  }

  if (!tontine) return null;

  const isCash = tontine.type === 'cash';
  const total = toNumber(tontine.total_amount);
  const perRound = toNumber(tontine.contribution_amount);
  const collected = toNumber(tontine.round?.paid_members) * perRound;
  const isFull = toNumber(tontine.current_members) >= toNumber(tontine.max_members);
  const isJoinable = tontine.status === 'open' && !isFull;
  const canJoin = isAuthenticated && !tontine.is_member && isJoinable;

  // Motif affiché quand la tontine n'accueille plus personne : on ne se
  // contente jamais d'afficher un statut brut.
  const notJoinableReason = {
    active: 'Cette tontine a démarré : elle compte déjà tous ses membres et ses versements sont en cours.',
    completed: 'Cette tontine est terminée. Son cycle de versement est arrivé à son terme.',
    cancelled: 'Cette tontine a été annulée. Les sommes déjà versées ont fait l’objet d’un remboursement.',
  }[tontine.status]
    || (isFull
      ? 'Cette tontine a atteint son nombre maximal de membres.'
      : 'Cette tontine n’accepte plus de nouveaux membres.');
  const nextRoundDate = estimatedDueDate(tontine, tontine.round?.number ?? tontine.current_round);

  return (
    <PageContainer>
      <Breadcrumb items={[{ label: 'Tontines', to: '/tontines' }, { label: tontine.name }]} className="mb-5" />

      <div className="grid gap-8 lg:grid-cols-[1fr_360px] lg:items-start">
        {/* ------------------------------------------------ Colonne principale */}
        <div className="min-w-0">
          {/* Visuel + titre */}
          <Card padding="none" className="mb-6 overflow-hidden">
            <div className="relative">
              <TontineCover tontine={tontine} variant="hero" />
              <div className="absolute left-4 top-4 flex flex-wrap gap-2">
                <Badge tone={isCash ? 'brand' : 'accent'} icon={isCash ? Banknote : ShoppingBag} className="backdrop-blur-sm">
                  {tontineTypeLabel(tontine.type)}
                </Badge>
                <StatusBadge status={tontine.status} className="backdrop-blur-sm" />
              </div>
            </div>

            <div className="p-5 sm:p-7">
              <h1 className="font-display text-2xl font-extrabold tracking-tight text-ink-900 sm:text-3xl">
                {tontine.name}
              </h1>

              <div className="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-ink-500">
                <span className="inline-flex items-center gap-1.5">
                  <Users className="size-4 text-ink-400" aria-hidden="true" />
                  <span className="font-semibold text-ink-800">{tontine.current_members}</span> membres
                  <span className="text-ink-400">sur {tontine.max_members}</span>
                </span>

                {!isCash && tontine.product?.name && (
                  <span className="inline-flex items-center gap-1.5">
                    <Package className="size-4 text-ink-400" aria-hidden="true" />
                    Produit :{' '}
                    <Link to={`/produits/${tontine.product.id}`} className="font-semibold text-primary-700 hover:underline">
                      {tontine.product.name}
                    </Link>
                  </span>
                )}

                {tontine.is_member && <Badge tone="success" size="sm" icon={CheckCircle2}>Tu es membre</Badge>}

                {/* Modification : possible uniquement tant que la tontine
                    n'a pas démarré. La décision ET son motif viennent du
                    serveur — cette page n'invente aucune règle. */}
                {tontine.can_edit ? (
                  <ButtonLink
                    to={`/tontines/${id}/modifier`}
                    variant="secondary"
                    size="sm"
                    icon={Pencil}
                    className="ml-auto"
                  >
                    Modifier
                  </ButtonLink>
                ) : tontine.is_creator && tontine.edit_locked_reason ? (
                  <span className="ml-auto inline-flex items-center gap-1.5 text-xs text-ink-500">
                    <Lock className="size-3.5 shrink-0" aria-hidden="true" />
                    {tontine.edit_locked_reason}
                  </span>
                ) : null}
              </div>

              {isCash && (
                <p className="mt-4 rounded-xl bg-ink-50 px-4 py-3 text-xs leading-relaxed text-ink-600">
                  Tontine argent : chaque membre cotise {formatFcfa(perRound)} {frequencyLabel(tontine.frequency)} et
                  bénéficie à son tour. Le versement au bénéficiaire n’est pas effectué par la plateforme.
                </p>
              )}
            </div>
          </Card>

          {/* Progression */}
          <Card className="mb-6">
            <h2 className="font-display text-base font-bold text-ink-900">Progression du round {tontine.round?.number}</h2>
            <p className="mt-1 text-sm text-ink-500">
              {tontine.round?.paid_members} membres sur {tontine.round?.expected_members} ont cotisé —{' '}
              {tontine.round?.is_funded ? 'round financé' : 'round en cours de financement'}
            </p>

            <div className="mt-5 flex flex-col items-center gap-6 sm:flex-row sm:items-center sm:gap-8">
              <ProgressCircle
                value={collected}
                total={total}
                size={132}
                label={`${tontine.round?.paid_members}/${tontine.round?.expected_members} membres`}
              />
              <div className="w-full flex-1">
                <ProgressSummary collected={collected} target={total} />
                <dl className="mt-5 grid grid-cols-2 gap-4 border-t border-line-soft pt-4 text-sm">
                  <div>
                    <dt className="text-[11px] text-ink-400">Versement par tour</dt>
                    <dd className="amount text-base">{formatFcfa(perRound)}</dd>
                  </div>
                  <div>
                    <dt className="text-[11px] text-ink-400">Places restantes</dt>
                    <dd className="amount text-base">
                      {Math.max(0, toNumber(tontine.max_members) - toNumber(tontine.current_members))}
                    </dd>
                  </div>
                </dl>
              </div>
            </div>
          </Card>

          {/* Informations clés */}
          <section className="mb-6">
            <h2 className="mb-3 font-display text-base font-bold text-ink-900">Informations de la tontine</h2>
            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3">
              <InfoTile icon={Coins} label="Montant par versement" value={formatFcfa(perRound)} />
              <InfoTile icon={Clock} label="Fréquence" value={frequencyLabel(tontine.frequency).replace('par ', '/')} />
              <InfoTile icon={Users} label="Nombre de membres" value={`${tontine.current_members} / ${tontine.max_members}`} />
              <InfoTile
                icon={CalendarDays}
                label="Date de début"
                value={tontine.start_date ? formatDate(tontine.start_date) : 'Non planifiée'}
              />
              <InfoTile
                icon={CalendarDays}
                label="Prochaine échéance"
                value={nextRoundDate ? `${formatDate(nextRoundDate)}` : 'Non planifiée'}
                hint={nextRoundDate ? `Estimée · ${formatCountdown(nextRoundDate)}` : null}
              />
              <InfoTile
                icon={isCash ? Banknote : Package}
                label={isCash ? 'Montant cible' : 'Prix du produit'}
                value={formatFcfa(total)}
              />
            </dl>
          </section>

          {/* Membres / activité */}
          <Card padding="none">
            <div className="border-b border-line-soft p-5">
              <Tabs
                variant="underline"
                ariaLabel="Détail de la tontine"
                value={tab}
                onChange={setTab}
                tabs={[
                  { value: 'members', label: 'Membres', count: tontine.members?.length || 0 },
                  { value: 'wheel', label: 'Tour de rôle' },
                ]}
              />
            </div>

            {tab === 'wheel' ? (
              <div className="p-5">
                <TontineWheel
                  members={tontine.members}
                  currentRound={tontine.current_round}
                  revealed={tontine.rotation_revealed !== false}
                  className="my-4"
                />
                <Legend />
              </div>
            ) : (
              <ul className="divide-y divide-line-soft">
                {tontine.members?.map((member) => (
                  <li key={member.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 p-4 sm:px-5">
                    <span
                      className={`flex size-9 shrink-0 items-center justify-center rounded-full border-2 text-xs font-bold ${
                        member.status === 'beneficiary'
                          ? 'border-accent-500 bg-accent-500 text-ink-900'
                          : member.status === 'withdrawn'
                            ? 'border-ink-200 bg-ink-100 text-ink-400'
                            : member.status === 'completed' && member.delivery_status === 'delivered'
                              ? 'border-primary-600 bg-primary-600 text-white'
                              : 'border-line-strong bg-white text-ink-600'
                      }`}
                    >
                      {/* Le numéro d'ordre n'existe qu'après le tirage. Avant,
                          l'API renvoie `position: null` : on affiche l'initiale
                          pour ne pas laisser croire à un ordre de passage. */}
                      {tontine.rotation_revealed !== false && member.position != null ? member.position : initials(member.user_name)}
                    </span>

                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-semibold text-ink-900">{member.user_name}</p>
                      {member.beneficiary_round && (
                        <p className="text-[11px] text-ink-500">Bénéficiaire au tour {member.beneficiary_round}</p>
                      )}
                    </div>

                    <div className="flex shrink-0 items-center gap-1.5">
                      <StatusBadge status={member.status} size="sm" />
                      {!isCash && <StatusBadge status={member.delivery_status} size="sm" />}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Alert type="info" className="mt-6" title="Participation et livraison">
            Ce sont deux états distincts : « tour terminé » signifie que la période du membre est bouclée, pas
            que le produit lui a été remis. La livraison est confirmée séparément par le commerçant.
          </Alert>
        </div>

        {/* ------------------------------------------------ Colonne latérale */}
        <aside className="lg:sticky lg:top-24">
          <Card>
            <h2 className="font-display text-base font-bold text-ink-900">
              {tontine.is_member ? 'Tu es membre' : isJoinable ? 'Rejoindre cette tontine' : 'Tontine fermée'}
            </h2>

            {tontine.is_member ? (
              <>
                <div className="mt-4 space-y-3 rounded-xl bg-primary-50/70 p-4">
                  <Row label="Ta participation" status={tontine.my_status} />
                  {!isCash && <Row label="Ta livraison" status={tontine.my_delivery_status} />}
                </div>
                <p className="mt-4 text-sm leading-relaxed text-ink-600">
                  Verse {formatFcfa(perRound)} {frequencyLabel(tontine.frequency)} depuis « Mes contributions ».
                </p>
                <div className="mt-4 flex flex-col gap-2.5">
                  <ButtonLink to="/mes-cotisations" className="w-full" icon={ArrowRight}>
                    Payer mon versement
                  </ButtonLink>
                  <Button variant="secondary" className="w-full" icon={Flag} onClick={() => navigate(`/tontines/${id}/litige`)}>
                    Signaler un problème
                  </Button>
                  {tontine.status === 'completed' && !isCash && (
                    <Button variant="secondary" className="w-full" icon={Star} onClick={() => navigate(`/tontines/${id}/avis`)}>
                      Évaluer le commerçant
                    </Button>
                  )}
                </div>
              </>
            ) : !isJoinable ? (
              /* Tontine visible mais fermée à l'adhésion. Inviter à rejoindre
                 ici une tontine annulée serait un mensonge : l'API le refuse
                 et, pour une annulation, l'argent a été remboursé. */
              <div className="mt-4">
                <p className="text-sm leading-relaxed text-ink-600">{notJoinableReason}</p>
                <Alert type="info" className="mt-4">
                  Tu peux consulter cette tontine, mais plus la rejoindre. Les Versements et l’historique restent
                  consultables par les membres qui y ont participé.
                </Alert>
              </div>
            ) : (
              <>
                <ul className="mt-4 space-y-2.5">
                  <Bullet text={`${formatFcfa(perRound)} ${frequencyLabel(tontine.frequency)}`} />
                  <Bullet text={`${tontine.round?.paid_members}/${tontine.round?.expected_members} membres ont cotisé ce round`} />
                  <Bullet text={`${Math.max(0, toNumber(tontine.max_members) - toNumber(tontine.current_members))} places restantes`} />
                </ul>

                {/* On n'atteint cette branche que si la tontine est
                    rejoignable : le cas « fermée » est traité plus haut. */}
                {canJoin ? (
                  <Button className="mt-5 w-full" size="lg" icon={UserPlus} onClick={() => setConfirmJoin(true)}>
                    Rejoindre cette tontine
                  </Button>
                ) : (
                  <>
                    <ButtonLink to="/connexion" state={{ from: { pathname: `/tontines/${id}` } }} className="mt-5 w-full" size="lg">
                      Se connecter pour rejoindre
                    </ButtonLink>
                    <ButtonLink to="/inscription" variant="secondary" className="mt-2.5 w-full">
                      Créer un compte
                    </ButtonLink>
                  </>
                )}

                <p className="mt-4 text-[11px] leading-relaxed text-ink-400">
                  En rejoignant, tu acceptes de verser {formatFcfa(perRound)} {frequencyLabel(tontine.frequency)}{' '}
                  pendant toute la durée de la tontine.
                </p>
              </>
            )}
          </Card>

          <Card className="mt-4">
            <h3 className="flex items-center gap-2 font-display text-sm font-bold text-ink-900">
              <Info className="size-4 text-primary-600" aria-hidden="true" />
              Comment ça se passe
            </h3>
            <ol className="mt-3 space-y-3">
              {[
                'Tu rejoins avec ton compte.',
                `Tu verses ${formatFcfa(perRound)} à chaque tour.`,
                'Chaque transfert est vérifié avant comptabilisation.',
                isCash ? 'Le bénéficiaire du tour reçoit sa part hors plateforme.' : 'Le commerçant te remet le produit à ton tour.',
              ].map((step, index) => (
                <li key={step} className="flex gap-3 text-xs leading-relaxed text-ink-600">
                  <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary-50 text-[10px] font-bold text-primary-700">
                    {index + 1}
                  </span>
                  {step}
                </li>
              ))}
            </ol>
          </Card>
        </aside>
      </div>

      {/* Confirmation d'adhésion */}
      <Modal
        open={confirmJoin}
        onClose={() => setConfirmJoin(false)}
        title="Rejoindre cette tontine ?"
        size="sm"
        footer={
          <>
            <Button variant="ghost" onClick={() => setConfirmJoin(false)} disabled={isJoining}>
              Annuler
            </Button>
            <Button loading={isJoining} onClick={handleJoin} data-autofocus>
              Confirmer
            </Button>
          </>
        }
      >
        <div className="space-y-3 text-sm leading-relaxed text-ink-600">
          <p>
            Tu vas rejoindre <strong className="text-ink-900">{tontine.name}</strong> et devoir verser{' '}
            <strong className="text-ink-900">{formatFcfa(perRound)}</strong>{' '}
            {frequencyLabel(tontine.frequency)}.
          </p>
          <p>
            Tes cotisations apparaîtront immédiatement dans « Mes cotisations », où tu pourras les régler à
            chaque tour.
          </p>
        </div>
      </Modal>
    </PageContainer>
  );
}

/* ------------------------------------------------------------------ */

function InfoTile({ icon: Icon, label, value, hint }) {
  return (
    <div className="rounded-xl border border-line-strong bg-white p-4">
      <div className="flex items-center gap-2">
        <Icon className="size-3.5 shrink-0 text-primary-600" aria-hidden="true" />
        <dt className="truncate text-[11px] font-medium text-ink-500">{label}</dt>
      </div>
      <dd className="amount mt-1.5 text-sm">{value}</dd>
      {hint && <p className="mt-0.5 text-[10px] text-ink-400">{hint}</p>}
    </div>
  );
}

function Row({ label, status }) {
  return (
    <div className="flex items-center justify-between gap-3">
      <span className="text-xs font-medium text-primary-900">{label}</span>
      <StatusBadge status={status} size="sm" />
    </div>
  );
}

function Bullet({ text }) {
  return (
    <li className="flex items-start gap-2 text-sm text-ink-600">
      <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-primary-600" aria-hidden="true" />
      {text}
    </li>
  );
}

function Legend() {
  const items = [
    { className: 'bg-accent-500 border-accent-500', label: 'Bénéficiaire du round en cours' },
    { className: 'bg-primary-600 border-primary-600', label: 'Tour terminé et produit remis' },
    { className: 'bg-white border-dashed border-primary-600', label: 'Tour terminé, livraison en attente' },
    { className: 'bg-white border-primary-600', label: 'Membre en attente de son tour' },
    { className: 'bg-ink-100 border-ink-200', label: 'Sorti de la tontine' },
  ];
  return (
    <ul className="mx-auto mt-4 grid max-w-md gap-2">
      {items.map((item) => (
        <li key={item.label} className="flex items-center gap-2.5 text-xs text-ink-600">
          <span className={`size-4 shrink-0 rounded-full border-2 ${item.className}`} aria-hidden="true" />
          {item.label}
        </li>
      ))}
    </ul>
  );
}

function TontineDetailSkeleton() {
  return (
    <div className="grid gap-8 lg:grid-cols-[1fr_360px]" aria-busy="true">
      <div className="space-y-6">
        <Card padding="none" className="overflow-hidden">
          <Skeleton className="aspect-video w-full rounded-none" />
          <div className="space-y-3 p-7">
            <Skeleton className="h-8 w-2/3" />
            <Skeleton className="h-4 w-1/3" />
          </div>
        </Card>
        <Card>
          <Skeleton className="h-5 w-48" />
          <div className="mt-6 flex gap-8">
            <Skeleton className="size-32 shrink-0 rounded-full" />
            <div className="flex-1 space-y-3">
              <Skeleton className="h-8 w-40" />
              <Skeleton className="h-2.5 w-full rounded-full" />
            </div>
          </div>
        </Card>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
          {Array.from({ length: 6 }).map((_, index) => (
            <Skeleton key={index} className="h-24 rounded-xl" />
          ))}
        </div>
      </div>
      <Card className="space-y-3">
        <Skeleton className="h-5 w-40" />
        <Skeleton className="h-24 w-full rounded-xl" />
        <Skeleton className="h-11 w-full rounded-xl" />
      </Card>
    </div>
  );
}
