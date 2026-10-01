import { Link } from 'react-router-dom';
import {
  ArrowRight,
  Banknote,
  CheckCircle2,
  Gift,
  Handshake,
  ShieldCheck,
  ShoppingBag,
  Sparkles,
  TrendingUp,
  Users,
} from 'lucide-react';
import { ButtonLink } from '../components/ui/Button';
import { Badge } from '../components/ui/Badge';
import { Card } from '../components/ui/Card';
import { TontineCardSkeleton } from '../components/tontine/TontineCard';
import { useApi } from '../hooks/useApi';
import { useAuth } from '../hooks/useAuth';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { api } from '../api/client';
import { formatFcfa, formatPercentAuto } from '../utils/format';

const STEPS = [
  {
    icon: Users,
    title: 'Rejoignez',
    text: 'Choisissez une tontine ouverte qui correspond à votre budget et à votre objectif.',
  },
  {
    icon: Handshake,
    title: 'Cotisez',
    text: 'Versez votre part à chaque tour, par mobile money, avec vérification de chaque transfert.',
  },
  {
    icon: Gift,
    title: 'Recevez',
    text: 'Votre tour arrive : le commerçant vous remet le produit, ou la tontine argent est clôturée.',
  },
];

const TRUST = [
  { icon: ShieldCheck, title: 'Transferts vérifiés', text: 'Chaque code de transfert est contrôlé par un administrateur avant d’être comptabilisé.' },
  { icon: TrendingUp, title: 'Progression visible', text: 'Montant épargné, round en cours, prochaine échéance : tout est affiché en permanence.' },
  { icon: Sparkles, title: 'Aucun frais caché', text: 'Un taux unique, appliqué partout et affiché avant chaque paiement — jamais de surprise à l’écran de règlement.' },
];

export default function Home() {
  useDocumentTitle('Accueil');
  const { isAuthenticated, user } = useAuth();

  // Les tontines ouvertes servent de vitrine sur la page d'accueil.
  const { data, isLoading } = useApi(() => api.get('/tontines'), []);
  const featured = (data?.data || []).slice(0, 3);

  // Le taux de commission affiché ici est celui RÉELLEMENT appliqué :
  // l'administrateur le modifie depuis Espace admin › Commissions, et
  // `Setting::set()` invalide le cache — `GET /config` renvoie donc la
  // nouvelle valeur dès le rechargement suivant.
  //
  // `/config` est public : la page d'accueil reste lisible sans compte.
  const { data: config } = useApi(() => api.get('/config'), []);
  const commissionRate = config?.commission_rate;
  const hasCommission = typeof commissionRate === 'number' && Number.isFinite(commissionRate);

  return (
    <>
      {/* ---------------------------------------------------------------- */}
      {/* Hero                                                              */}
      {/* ---------------------------------------------------------------- */}
      <section className="hero-glow relative overflow-hidden border-b border-line">
        <div className="surface-grid absolute inset-0 opacity-60" aria-hidden="true" />

        <div className="relative mx-auto grid w-full max-w-7xl items-center gap-12 px-5 py-16 sm:px-8 sm:py-20 lg:grid-cols-[1.05fr_0.95fr] lg:py-28">
          <div className="animate-[rise_0.5s_cubic-bezier(0.22,1,0.36,1)_both]">
            <Badge tone="brand" size="sm" icon={Sparkles} className="mb-5">
              Tontine d’achat · Niger & Sahel
            </Badge>

            <h1 className="font-display text-[2.1rem] font-extrabold leading-[1.08] tracking-tight text-ink-900 sm:text-5xl lg:text-[3.4rem]">
              Épargnez ensemble.
              <span className="block text-primary-700">Achetez plus facilement.</span>
            </h1>

            <p className="mt-5 max-w-xl text-base leading-relaxed text-ink-600 sm:text-lg">
              Rejoignez une tontine, cotisez à votre rythme et obtenez le produit de votre choix grâce à
              l’effort collectif. Versements vérifiés, progression transparente, livraison suivie.
            </p>

            <div className="mt-8 flex flex-col gap-3 sm:flex-row">
              <ButtonLink to={isAuthenticated ? '/tontines' : '/inscription'} size="xl" icon={Users}>
                Rejoindre une tontine
              </ButtonLink>
              <ButtonLink to="/tontines" size="xl" variant="secondary" icon={Gift}>
                Créer une tontine
              </ButtonLink>
            </div>

            <dl className="mt-10 grid max-w-lg grid-cols-3 gap-6 border-t border-line pt-6">
              {[
                {
                  label: 'Commission plateforme',
                  // Jamais de valeur en dur : si l'appel échoue on affiche « — »
                  // plutôt qu'un taux faux, qui serait un engagement commercial faux.
                  value: hasCommission ? formatPercentAuto(commissionRate) : '—',
                  title: hasCommission ? 'Taux appliqué à chaque versement, par membre' : undefined,
                },
                { label: 'Moyens de paiement', value: 'Mobile money' },
                { label: 'Vérification', value: 'Tous les versements' },
              ].map((item) => (
                <div key={item.label}>
                  <dt className="text-[11px] font-semibold uppercase tracking-wide text-ink-400">{item.label}</dt>
                  <dd
                    className="mt-1 font-display text-base font-bold text-ink-900"
                    title={item.title}
                    aria-busy={item.label.startsWith('Commission') ? !hasCommission : undefined}
                  >
                    {item.value}
                  </dd>
                </div>
              ))}
            </dl>
          </div>

          {/* Illustration */}
          <div className="relative animate-[rise_0.6s_0.1s_cubic-bezier(0.22,1,0.36,1)_both]" aria-hidden="true">
            <HeroArt />
          </div>
        </div>
      </section>

      {/* ---------------------------------------------------------------- */}
      {/* Les deux types de tontine                                        */}
      {/* ---------------------------------------------------------------- */}
      <section className="mx-auto w-full max-w-7xl px-5 py-16 sm:px-8 sm:py-20">
        <div className="mx-auto max-w-2xl text-center">
          <p className="text-xs font-bold uppercase tracking-[0.14em] text-primary-700">Deux façons d’épargner</p>
          <h2 className="mt-3 font-display text-3xl font-extrabold tracking-tight text-ink-900 sm:text-4xl">
            Le produit que tu veux, ou l’épargne qui grandit
          </h2>
          <p className="mt-4 text-base leading-relaxed text-ink-600">
            Deux mécaniques simples, une même rigueur : chaque versement est tracé et vérifié.
          </p>
        </div>

        <div className="mt-12 grid gap-6 lg:grid-cols-2">
          <TypeCard
            icon={Banknote}
            emoji="💰"
            badge="Tontine Argent"
            title="Épargne collective"
            text="Épargnez collectivement et bénéficiez d’une organisation simple et transparente de vos contributions."
            bullets={[
              'Un montant cible à réunir collectivement',
              'Un bénéficiaire par tour, dans l’ordre d’arrivée',
              'Versement au bénéficiaire géré hors plateforme',
            ]}
            ctaLabel="Découvrir les tontines argent"
            to="/tontines?type=cash"
            tone="brand"
          />

          <TypeCard
            icon={ShoppingBag}
            emoji="🛍️"
            badge="Tontine Produit"
            title="Le produit de vos rêves"
            text="Achetez les produits que vous souhaitez grâce à un système de cotisation progressive."
            bullets={[
              'Un produit précis, vendu par un commerçant vérifié',
              'Le prix réparti sur tous les tours de la tontine',
              'Livraison confirmée par le commerçant',
            ]}
            ctaLabel="Découvrir les tontines produit"
            to="/tontines?type=product"
            tone="accent"
          />
        </div>
      </section>

      {/* ---------------------------------------------------------------- */}
      {/* Tontines ouvertes                                                */}
      {/* ---------------------------------------------------------------- */}
      <section className="border-y border-line bg-white">
        <div className="mx-auto w-full max-w-7xl px-5 py-16 sm:px-8 sm:py-20">
          <div className="flex flex-wrap items-end justify-between gap-4">
            <div>
              <p className="text-xs font-bold uppercase tracking-[0.14em] text-primary-700">Disponible maintenant</p>
              <h2 className="mt-3 font-display text-3xl font-extrabold tracking-tight text-ink-900">Tontines ouvertes</h2>
              <p className="mt-3 max-w-xl text-base leading-relaxed text-ink-600">
                Rejoignez une tontine en cours de remplissage : chaque round se rapproche un peu plus du produit.
              </p>
            </div>
            <ButtonLink to="/tontines" variant="secondary" iconRight={ArrowRight} className="shrink-0">
              Voir toutes les tontines
            </ButtonLink>
          </div>

          <div className="mt-10">
            {isLoading ? (
              <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                {Array.from({ length: 3 }).map((_, index) => (
                  <TontineCardSkeleton key={index} />
                ))}
              </div>
            ) : featured.length === 0 ? (
              <Card variant="muted" className="text-center">
                <p className="text-sm text-ink-600">
                  Aucune tontine ouverte pour le moment. Reviens bientôt — de nouvelles tontines sont publiées
                  chaque semaine.
                </p>
              </Card>
            ) : (
              <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                {featured.map((tontine) => (
                  <li key={tontine.id}>
                    <FeaturedTontine tontine={tontine} />
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      </section>

      {/* ---------------------------------------------------------------- */}
      {/* Comment ça marche                                                 */}
      {/* ---------------------------------------------------------------- */}
      <section className="mx-auto w-full max-w-7xl px-5 py-16 sm:px-8 sm:py-20">
        <div className="mx-auto max-w-2xl text-center">
          <h2 className="font-display text-3xl font-extrabold tracking-tight text-ink-900 sm:text-4xl">
            Trois étapes, aucune surprise
          </h2>
        </div>

        <ol className="mt-12 grid gap-6 md:grid-cols-3">
          {STEPS.map((step, index) => (
            <li key={step.title}>
              <Card hover className="h-full">
                <span className="flex size-11 items-center justify-center rounded-xl bg-primary-50 text-primary-700">
                  <step.icon className="size-5" aria-hidden="true" />
                </span>
                <p className="mt-5 text-xs font-bold uppercase tracking-wider text-ink-400">Étape {index + 1}</p>
                <h3 className="mt-1.5 font-display text-lg font-bold text-ink-900">{step.title}</h3>
                <p className="mt-2 text-sm leading-relaxed text-ink-600">{step.text}</p>
              </Card>
            </li>
          ))}
        </ol>
      </section>

      {/* ---------------------------------------------------------------- */}
      {/* Confiance                                                         */}
      {/* ---------------------------------------------------------------- */}
      <section className="border-t border-line bg-ink-950">
        <div className="mx-auto w-full max-w-7xl px-5 py-16 sm:px-8">
          <div className="grid gap-8 md:grid-cols-3">
            {TRUST.map((item) => (
              <div key={item.title} className="flex gap-4">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-white/10 text-primary-300">
                  <item.icon className="size-5" aria-hidden="true" />
                </span>
                <div>
                  <h3 className="font-display text-base font-bold text-white">{item.title}</h3>
                  <p className="mt-1.5 text-sm leading-relaxed text-ink-300">{item.text}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ---------------------------------------------------------------- */}
      {/* Appel à action final                                              */}
      {/* ---------------------------------------------------------------- */}
      {!isAuthenticated && (
        <section className="mx-auto w-full max-w-7xl px-5 py-16 sm:px-8 sm:py-20">
          <Card padding="lg" className="overflow-hidden bg-gradient-to-br from-primary-800 to-primary-950 text-center">
            <h2 className="font-display text-2xl font-extrabold tracking-tight text-white sm:text-3xl">
              Prêt à faire grandir ton projet ?
            </h2>
            <p className="mx-auto mt-3 max-w-lg text-sm leading-relaxed text-primary-100 sm:text-base">
              Crée ton compte en une minute, choisis ta tontine et commence à cotiser dès aujourd’hui.
            </p>
            <div className="mt-7 flex flex-col justify-center gap-3 sm:flex-row">
              <ButtonLink to="/inscription" size="lg" variant="accent" icon={ArrowRight}>
                Créer mon compte
              </ButtonLink>
              <Link
                to="/connexion"
                className="inline-flex h-12 items-center justify-center rounded-xl border border-white/25 px-6 text-base font-semibold text-white transition-colors hover:bg-white/10"
              >
                J’ai déjà un compte
              </Link>
            </div>
          </Card>
        </section>
      )}

      {isAuthenticated && user && (
        <section className="mx-auto w-full max-w-7xl px-5 py-14 sm:px-8">
          <Card padding="lg" className="flex flex-wrap items-center justify-between gap-6">
            <div>
              <h2 className="font-display text-xl font-bold text-ink-900">Bonjour {user.name?.split(' ')[0]} 👋</h2>
              <p className="mt-1.5 text-sm text-ink-600">Retrouve l’état de tes tontines et tes versements à payer.</p>
            </div>
            <div className="flex flex-wrap gap-2.5">
              <ButtonLink to="/tableau-de-bord" size="lg" icon={ArrowRight}>
                Mon tableau de bord
              </ButtonLink>
              <ButtonLink to="/mes-cotisations" size="lg" variant="secondary">
                Mes contributions
              </ButtonLink>
            </div>
          </Card>
        </section>
      )}
    </>
  );
}

/* ------------------------------------------------------------------ */
/* Sous-composants                                                     */
/* ------------------------------------------------------------------ */

function TypeCard({ icon: Icon, emoji, badge, title, text, bullets, ctaLabel, to, tone }) {
  const accents = {
    brand: { ring: 'hover:border-primary-300', chip: 'bg-primary-50 text-primary-700', badge: 'brand' },
    accent: { ring: 'hover:border-accent-300', chip: 'bg-accent-50 text-accent-700', badge: 'accent' },
  }[tone];

  return (
    <Card hover className={`flex h-full flex-col p-6 sm:p-8 ${accents.ring}`}>
      <div className="flex items-start justify-between gap-4">
        <span className={`flex size-12 items-center justify-center rounded-2xl text-2xl ${accents.chip}`}>
          <span aria-hidden="true">{emoji}</span>
        </span>
        <Badge tone={accents.badge} icon={Icon}>
          {badge}
        </Badge>
      </div>

      <h3 className="mt-6 font-display text-2xl font-bold tracking-tight text-ink-900">{title}</h3>
      <p className="mt-3 text-sm leading-relaxed text-ink-600 sm:text-base">{text}</p>

      <ul className="mt-6 flex-1 space-y-2.5">
        {bullets.map((bullet) => (
          <li key={bullet} className="flex items-start gap-2.5 text-sm text-ink-600">
            <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-primary-600" aria-hidden="true" />
            <span>{bullet}</span>
          </li>
        ))}
      </ul>

      <ButtonLink to={to} variant="secondary" className="mt-7 w-full" iconRight={ArrowRight}>
        {ctaLabel}
      </ButtonLink>
    </Card>
  );
}

function FeaturedTontine({ tontine }) {
  const isCash = tontine.type === 'cash';
  const total = Number(tontine.total_amount || 0);
  const collected = Number(tontine.round?.paid_members || 0) * Number(tontine.contribution_amount || 0);
  const percent = total > 0 ? Math.min(100, Math.round((collected / total) * 100)) : 0;

  return (
    <Link
      to={`/tontines/${tontine.id}`}
      className="group flex h-full flex-col rounded-2xl border border-line-strong bg-canvas p-5 transition-all duration-300 hover:-translate-y-1 hover:border-primary-300 hover:bg-white hover:shadow-lg"
    >
      <div className="flex items-start justify-between gap-3">
        <span className="text-2xl" aria-hidden="true">
          {isCash ? '💰' : '🛍️'}
        </span>
        <Badge tone={isCash ? 'brand' : 'accent'} size="sm">
          {isCash ? 'Argent' : 'Produit'}
        </Badge>
      </div>

      <h3 className="mt-4 font-display text-base font-bold text-ink-900 group-hover:text-primary-800">{tontine.name}</h3>
      <p className="mt-1 text-xs text-ink-500">
        {tontine.current_members}/{tontine.max_members} membres
      </p>

      <p className="amount mt-4 text-lg text-primary-800">{formatFcfa(tontine.contribution_amount)}</p>

      <div className="mt-4">
        <div className="mb-1.5 flex justify-between text-[11px]">
          <span className="text-ink-500">Financé</span>
          <span className="font-bold text-ink-700">{percent} %</span>
        </div>
        <div className="h-1.5 w-full overflow-hidden rounded-full bg-primary-100">
          <div className="h-full rounded-full bg-primary-600 transition-[width] duration-700" style={{ width: `${percent}%` }} />
        </div>
      </div>
    </Link>
  );
}

/** Illustration abstraite : anneau de tour + progression. */
function HeroArt() {
  const nodes = [
    { top: '6%', left: '50%', state: 'done' },
    { top: '20%', left: '78%', state: 'done' },
    { top: '48%', left: '84%', state: 'current' },
    { top: '74%', left: '68%', state: 'todo' },
    { top: '84%', left: '40%', state: 'todo' },
    { top: '70%', left: '16%', state: 'todo' },
    { top: '40%', left: '14%', state: 'todo' },
  ];

  const fills = {
    done: 'bg-primary-600 border-primary-600 text-white',
    current: 'bg-accent-500 border-accent-500 text-ink-900',
    todo: 'bg-white border-line-strong text-ink-300',
  };

  return (
    <div className="relative mx-auto aspect-square w-full max-w-md">
      <div className="absolute inset-0 rounded-full bg-gradient-to-br from-primary-600 to-primary-900 opacity-[0.07]" aria-hidden="true" />

      <div className="absolute inset-[8%] rounded-full border-2 border-dashed border-primary-200" aria-hidden="true" />

      {nodes.map((node, index) => (
        <span
          key={index}
          className={`absolute flex size-12 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 text-sm font-bold shadow-sm ${fills[node.state]}`}
          style={{ top: node.top, left: node.left }}
        >
          {index + 1}
        </span>
      ))}

      <div className="absolute inset-0 flex flex-col items-center justify-center">
        <p className="font-display text-4xl font-extrabold tracking-tight text-primary-800">68 %</p>
        <p className="mt-1 text-xs font-medium text-ink-500">du produit financé</p>
      </div>
    </div>
  );
}
