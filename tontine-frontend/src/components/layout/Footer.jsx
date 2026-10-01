import { Link } from 'react-router-dom';
import { ShieldCheck, Sparkles, Truck } from 'lucide-react';
import Logo from './Logo';
import { PUBLIC_NAV } from '../../navigation';

const PILLARS = [
  { icon: ShieldCheck, title: 'Paiements vérifiés', text: 'Chaque transfert est contrôlé avant d’être comptabilisé.' },
  { icon: Truck, title: 'Livraisons suivies', text: 'Du versement à la remise du produit, chaque étape est visible.' },
  { icon: Sparkles, title: 'Commission claire', text: 'Un taux unique affiché avant chaque paiement, sans surprise.' },
];

export default function Footer() {
  const year = new Date().getFullYear();

  return (
    <footer className="mt-auto border-t border-line-strong bg-white">
      <div className="mx-auto w-full max-w-7xl px-5 py-12 sm:px-8">
        <div className="grid gap-10 lg:grid-cols-[1.4fr_1fr_1fr]">
          <div>
            <Logo size="md" />
            <p className="mt-4 max-w-sm text-sm leading-relaxed text-ink-500">
              Tontine Achat Store permet d’épargner collectivement et d’acheter plus facilement, en toute
              transparence, au Niger et partout ailleurs.
            </p>
          </div>

          <nav aria-label="Liens de navigation">
            <p className="text-[11px] font-bold uppercase tracking-wider text-ink-400">Navigation</p>
            <ul className="mt-4 space-y-2.5">
              {PUBLIC_NAV.map((item) => (
                <li key={item.to}>
                  <Link to={item.to} className="text-sm text-ink-600 transition-colors hover:text-primary-700">
                    {item.label}
                  </Link>
                </li>
              ))}
            </ul>
          </nav>

          <div>
            <p className="text-[11px] font-bold uppercase tracking-wider text-ink-400">Nos engagements</p>
            <ul className="mt-4 space-y-4">
              {PILLARS.map((pillar) => (
                <li key={pillar.title} className="flex gap-3">
                  <pillar.icon className="mt-0.5 size-4 shrink-0 text-primary-600" aria-hidden="true" />
                  <div>
                    <p className="text-sm font-semibold text-ink-800">{pillar.title}</p>
                    <p className="mt-0.5 text-xs leading-relaxed text-ink-500">{pillar.text}</p>
                  </div>
                </li>
              ))}
            </ul>
          </div>
        </div>

        <div className="mt-10 flex flex-col items-center justify-between gap-3 border-t border-line-soft pt-6 sm:flex-row">
          <p className="text-xs text-ink-400">© {year} Tontine Achat Store. Tous droits réservés.</p>
          <p className="text-xs text-ink-400">Montants en FCFA (XOF)</p>
        </div>
      </div>
    </footer>
  );
}
