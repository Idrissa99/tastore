import { Link } from 'react-router-dom';
import { BadgeCheck, ShieldCheck, Sparkles } from 'lucide-react';
import Logo from './Logo';

/**
 * Gabarit commun aux pages d'authentification : narration à gauche,
 * formulaire à droite. Sur mobile, seul le formulaire est visible.
 */
export default function AuthLayout({ title, subtitle, children, footer, aside }) {
  return (
    <div className="mx-auto grid w-full max-w-6xl gap-12 px-5 py-10 sm:px-8 sm:py-16 lg:grid-cols-2 lg:items-center lg:py-24">
      {/* Narration */}
      <div className="hidden lg:block">
        <Logo size="lg" />
        <h2 className="mt-10 font-display text-3xl font-extrabold leading-tight tracking-tight text-ink-900">
          Épargnez ensemble.
          <span className="block text-primary-700">Achetez plus facilement.</span>
        </h2>
        <p className="mt-4 max-w-md text-base leading-relaxed text-ink-600">
          Rejoignez une tontine, cotisez à votre rythme et obtenez le produit de votre choix grâce à l’effort
          collectif.
        </p>

        <ul className="mt-10 space-y-5">
          {[
            { icon: BadgeCheck, title: 'Versements vérifiés', text: 'Chaque transfert est contrôlé avant d’être comptabilisé.' },
            { icon: Sparkles, title: 'Progression visible', text: 'Montant épargné, tour en cours, prochaine échéance.' },
            { icon: ShieldCheck, title: 'Aucun frais caché', text: 'Un taux unique, affiché avant chaque paiement.' },
          ].map((item) => (
            <li key={item.title} className="flex gap-4">
              <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-700">
                <item.icon className="size-5" aria-hidden="true" />
              </span>
              <div>
                <p className="font-display text-sm font-bold text-ink-900">{item.title}</p>
                <p className="mt-0.5 text-sm leading-relaxed text-ink-500">{item.text}</p>
              </div>
            </li>
          ))}
        </ul>
      </div>

      {/* Formulaire */}
      <div className="w-full max-w-md mx-auto lg:mx-0 lg:justify-self-end">
        <div className="lg:hidden">
          <Logo size="md" className="mb-8" />
        </div>

        <div className="rounded-2xl border border-line-strong bg-white p-6 shadow-md sm:p-8">
          <h1 className="font-display text-2xl font-extrabold tracking-tight text-ink-900">{title}</h1>
          {subtitle && <p className="mt-2 text-sm leading-relaxed text-ink-500">{subtitle}</p>}

          <div className="mt-6">{children}</div>

          {footer && <div className="mt-6 border-t border-line-soft pt-5">{footer}</div>}
        </div>

        {aside}
      </div>
    </div>
  );
}

/** Bloc « pas encore de compte ? » en bas de formulaire. */
export function AuthFooter({ text, linkTo, linkLabel }) {
  return (
    <p className="text-center text-sm text-ink-600">
      {text}{' '}
      <Link to={linkTo} className="font-semibold text-primary-700 underline-offset-4 hover:underline">
        {linkLabel}
      </Link>
    </p>
  );
}
