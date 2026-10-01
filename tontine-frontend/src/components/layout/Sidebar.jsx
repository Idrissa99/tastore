import { NavLink } from 'react-router-dom';
import { ChevronDown, X } from 'lucide-react';
import { useState } from 'react';
import Logo from './Logo';
import { useAuth } from '../../hooks/useAuth';
import { useNotifications } from '../../context/NotificationsContext';
import { ACCOUNT_NAV, ADMIN_NAV, CLIENT_NAV, MERCHANT_NAV, WORKSPACE_LABELS, availableWorkspaces } from '../../navigation';

const NAV_BY_WORKSPACE = { client: CLIENT_NAV, merchant: MERCHANT_NAV, admin: ADMIN_NAV };

/**
 * Navigation latérale de l'espace connecté.
 *
 * Deux garanties :
 *  - les liens de COMPTE (notifications, profil) sont toujours présents,
 *    quel que soit l'espace : sans eux un administrateur n'a aucun moyen
 *    d'y accéder depuis la sidebar ;
 *  - un sélecteur d'espace est affiché dès que l'utilisateur a droit à
 *    plus d'un espace (administration, vendeur, personnel).
 */
export default function Sidebar({ workspace, onNavigate, pendingPayments = 0 }) {
  const { user } = useAuth();
  const { unread, markAllAsRead } = useNotifications();
  const [switcherOpen, setSwitcherOpen] = useState(false);

  const nav = NAV_BY_WORKSPACE[workspace] || CLIENT_NAV;
  const spaces = availableWorkspaces(user);

  // Ouvrir la liste des notifications vaut « je les ai vues » : le badge
  // disparaît immédiatement, sans attendre un rechargement de page. Les
  // entrées en attente de vérification, elles, restent (voir le contexte).
  const handleNavigate = (item) => {
    onNavigate?.();
    if (item?.to === '/notifications') markAllAsRead();
  };

  const badgeFor = (item) => {
    if (item.badge === 'unread') return unread || undefined;
    if (item.badge === 'pendingPayments') return pendingPayments || undefined;
    return undefined;
  };

  // Regroupement (l'admin a beaucoup d'entrées, le client aucune).
  const groups = nav.reduce((accumulator, item) => {
    const key = item.group || '';
    const last = accumulator[accumulator.length - 1];
    if (last && last.key === key) last.items.push(item);
    else accumulator.push({ key, items: [item] });
    return accumulator;
  }, []);

  const currentSpace = spaces.find((space) => space.key === workspace) || spaces[spaces.length - 1];

  return (
    <div className="flex h-full flex-col">
      {/* Marque */}
      <div className="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-line-soft px-5">
        <Logo size="sm" />
        {onNavigate && (
          <button
            type="button"
            onClick={onNavigate}
            aria-label="Fermer le menu"
            className="rounded-lg p-2 text-ink-400 transition-colors hover:bg-ink-100 hover:text-ink-700"
          >
            <X className="size-4" aria-hidden="true" />
          </button>
        )}
      </div>

      <nav aria-label="Navigation de l'espace" className="min-h-0 flex-1 overflow-y-auto px-3 py-4">
        {/* Sélecteur d'espace */}
        {spaces.length > 1 && (
          <div className="relative mb-4">
            <button
              type="button"
              onClick={() => setSwitcherOpen((value) => !value)}
              aria-expanded={switcherOpen}
              className="flex w-full items-center gap-2.5 rounded-xl border border-line-strong bg-white px-3 py-2.5 text-left shadow-sm transition-all hover:border-primary-300"
            >
              <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary-700 text-white">
                <currentSpace.icon className="size-4" aria-hidden="true" />
              </span>
              <span className="min-w-0 flex-1">
                <span className="block text-[10px] font-bold uppercase tracking-wider text-ink-400">Espace</span>
                <span className="block truncate text-sm font-bold text-ink-900">{WORKSPACE_LABELS[workspace]}</span>
              </span>
              <ChevronDown className={`size-4 shrink-0 text-ink-400 transition-transform ${switcherOpen ? 'rotate-180' : ''}`} aria-hidden="true" />
            </button>

            {switcherOpen && (
              <ul className="absolute inset-x-0 top-full z-20 mt-1.5 animate-[scale-in_0.16s_cubic-bezier(0.22,1,0.36,1)] overflow-hidden rounded-xl border border-line-strong bg-white p-1 shadow-lg">
                {spaces.map((space) => (
                  <li key={space.key}>
                    <NavLink
                      to={space.to}
                      onClick={() => {
                        setSwitcherOpen(false);
                        onNavigate?.();
                      }}
                      className={`flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-semibold transition-colors ${
                        space.key === workspace ? 'bg-primary-50 text-primary-800' : 'text-ink-700 hover:bg-ink-50'
                      }`}
                    >
                      <space.icon className="size-4 shrink-0 text-ink-400" aria-hidden="true" />
                      <span className="min-w-0 flex-1 truncate">{space.label}</span>
                    </NavLink>
                  </li>
                ))}
              </ul>
            )}
          </div>
        )}

        {groups.map((group, groupIndex) => (
          <div key={group.key || `group-${groupIndex}`} className={groupIndex > 0 ? 'mt-6' : ''}>
            {group.key && (
              <p className="mb-1.5 px-3 text-[10px] font-bold uppercase tracking-[0.12em] text-ink-400">{group.key}</p>
            )}
            <ul className="space-y-0.5">
              {group.items.map((item) => (
                <li key={item.to}>
                  <NavItem item={item} badge={badgeFor(item)} onNavigate={() => handleNavigate(item)} />
                </li>
              ))}
            </ul>
          </div>
        ))}

        {/* Compte — toujours accessible. Les entrées déjà présentes dans la
            navigation de l'espace (client) sont filtrées pour éviter un
            doublon ; pour l'admin et le commerçant, ce bloc est leur unique
            moyen d'accéder à leur profil et à leurs notifications. */}
        {(() => {
          const present = new Set(nav.map((item) => item.to));
          const missing = ACCOUNT_NAV.filter((item) => !present.has(item.to));
          if (missing.length === 0) return null;

          return (
            <div className="mt-6">
              <p className="mb-1.5 px-3 text-[10px] font-bold uppercase tracking-[0.12em] text-ink-400">Compte</p>
              <ul className="space-y-0.5">
                {missing.map((item) => (
                  <li key={item.to}>
                    <NavItem item={item} badge={badgeFor(item)} onNavigate={() => handleNavigate(item)} />
                  </li>
                ))}
              </ul>
            </div>
          );
        })()}
      </nav>
    </div>
  );
}

function NavItem({ item, badge, onNavigate }) {
  return (
    <NavLink
      to={item.to}
      end={item.end}
      onClick={onNavigate}
      className={({ isActive }) =>
        `group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition-all duration-200 ${
          isActive ? 'bg-primary-700 text-white shadow-brand' : 'text-ink-600 hover:bg-ink-100 hover:text-ink-900'
        }`
      }
    >
      {({ isActive }) => (
        <>
          <item.icon
            className={`size-[18px] shrink-0 transition-colors ${isActive ? 'text-white' : 'text-ink-400 group-hover:text-primary-600'}`}
            aria-hidden="true"
          />
          <span className="min-w-0 flex-1 truncate">{item.label}</span>
          {badge ? (
            <span
              className={`inline-flex min-w-[20px] items-center justify-center rounded-full px-1.5 py-0.5 text-[10px] font-bold ${
                isActive ? 'bg-white/20 text-white' : 'bg-danger-500 text-white'
              }`}
            >
              {badge > 99 ? '99+' : badge}
            </span>
          ) : null}
        </>
      )}
    </NavLink>
  );
}
