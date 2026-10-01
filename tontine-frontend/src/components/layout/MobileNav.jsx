import { NavLink, useLocation } from 'react-router-dom';
import { Bell, Boxes, LayoutDashboard, Truck, User, Wallet } from 'lucide-react';
import { useAuth } from '../../hooks/useAuth';
import { useNotifications } from '../../context/NotificationsContext';

/**
 * Bottom navigation mobile — 5 entrées, zone de pouce, hauteur 64 px +
 * safe-area iOS.
 *
 * Les entrées dépendent de l'espace actif, pas seulement du rôle : un
 * commerçant doit garder ses raccourcis produits/livraisons sous la main,
 * et un administrateur ses raccourcis de supervision.
 */
const NAV_BY_WORKSPACE = {
  client: (unread) => [
    { to: '/tableau-de-bord', label: 'Accueil', icon: HomeIcon },
    { to: '/tontines', label: 'Tontines', icon: Wallet },
    { to: '/mes-tontines', label: 'Mes tontines', icon: LayersIcon },
    { to: '/notifications', label: 'Notifs', icon: Bell, badge: unread },
    { to: '/profil', label: 'Profil', icon: User },
  ],
  merchant: (unread) => [
    { to: '/commercant', label: 'Dashboard', icon: LayoutDashboard, end: true },
    { to: '/commercant/produits', label: 'Produits', icon: Boxes },
    { to: '/commercant/livraisons', label: 'Livraisons', icon: Truck },
    { to: '/notifications', label: 'Notifs', icon: Bell, badge: unread },
    { to: '/profil', label: 'Profil', icon: User },
  ],
  admin: (unread) => [
    { to: '/admin', label: 'Dashboard', icon: LayoutDashboard, end: true },
    { to: '/admin/paiements', label: 'Paiements', icon: BadgeIcon },
    { to: '/admin/tontines', label: 'Tontines', icon: Wallet },
    { to: '/notifications', label: 'Notifs', icon: Bell, badge: unread },
    { to: '/profil', label: 'Profil', icon: User },
  ],
};

export default function MobileNav({ workspace }) {
  const { user } = useAuth();
  const { unread, markAllAsRead } = useNotifications();
  const location = useLocation();

  const builder = NAV_BY_WORKSPACE[workspace] ?? NAV_BY_WORKSPACE.client;
  const items = builder(unread).slice(0, 5);

  return (
    <nav
      aria-label="Navigation principale"
      className="fixed inset-x-0 bottom-0 z-40 border-t border-line-strong bg-white/95 backdrop-blur-md lg:hidden"
      style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}
    >
      <ul className="grid grid-cols-5">
        {items.map((item) => {
          const isActive = item.end
            ? location.pathname === item.to
            : location.pathname === item.to || location.pathname.startsWith(`${item.to}/`);

          return (
            <li key={item.to}>
              <NavLink
                to={item.to}
                end={item.end}
                onClick={() => {
                  // Ouvrir la liste vaut « je les ai vues » : le badge rouge
                  // doit partir au clic, pas au rechargement suivant.
                  if (item.to === '/notifications') markAllAsRead();
                }}
                className={`relative flex flex-col items-center gap-1 px-1 pb-2 pt-2.5 text-[10px] font-semibold transition-colors ${
                  isActive ? 'text-primary-700' : 'text-ink-400 active:text-ink-600'
                }`}
              >
                <span className="relative">
                  <item.icon className={`size-[22px] ${isActive ? 'scale-105' : ''} transition-transform`} aria-hidden="true" />
                  {item.badge ? (
                    <span className="absolute -right-1.5 -top-1 inline-flex min-w-[16px] items-center justify-center rounded-full bg-danger-500 px-1 text-[9px] font-bold text-white ring-2 ring-white">
                      {item.badge > 9 ? '9+' : item.badge}
                    </span>
                  ) : null}
                </span>
                <span className="max-w-full truncate leading-none">{item.label}</span>
                {isActive && <span className="absolute inset-x-5 top-0 h-0.5 rounded-full bg-primary-600" aria-hidden="true" />}
              </NavLink>
            </li>
          );
        })}
      </ul>
      <span className="sr-only">{user?.role}</span>
    </nav>
  );
}

function HomeIcon(props) {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" {...props}>
      <path d="M3 10.5 12 4l9 6.5" />
      <path d="M5.5 12v6a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2v-6" />
    </svg>
  );
}

function LayersIcon(props) {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" {...props}>
      <path d="m12 3 8.5 4.5L12 12 3.5 7.5 12 3Z" />
      <path d="m3.5 12.5 8.5 4.5 8.5-4.5" />
    </svg>
  );
}

/** Icône « carte de paiement » pour la file de vérification admin. */
function BadgeIcon(props) {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" {...props}>
      <rect x="2.5" y="5" width="19" height="14" rx="2.5" />
      <path d="M2.5 10h19" />
      <path d="M6.5 15h3" />
    </svg>
  );
}
