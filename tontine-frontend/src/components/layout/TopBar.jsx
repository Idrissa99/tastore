import { useState } from 'react';
import { Link, NavLink } from 'react-router-dom';
import { LogOut, Menu, ShieldCheck, Store } from 'lucide-react';
import { ButtonLink, IconButton } from '../ui/Button';
import { Avatar } from '../ui/Avatar';
import { Drawer } from '../ui/Drawer';
import { Dropdown, DropdownItem, DropdownLink, DropdownLabel, DropdownSeparator } from '../ui/Dropdown';
import Logo from './Logo';
import { useAuth } from '../../hooks/useAuth';
import { useNotifications } from '../../context/NotificationsContext';
import { PUBLIC_NAV, CLIENT_NAV, ADMIN_NAV, homeForRole } from '../../navigation';

/**
 * Barre haute de la navigation publique : liens de marque, compte, CTA.
 * Sur mobile, la navigation complète est dépliée dans un tiroir.
 * L'espace connecté (sidebar) possède sa propre barre, plus compacte.
 */
export default function TopBar() {
  const { user, isAuthenticated, isAdmin, isMerchant, logout } = useAuth();
  const { unread, markAllAsRead } = useNotifications();
  const [menuOpen, setMenuOpen] = useState(false);

  const canCreateTontine = isAdmin || (isMerchant && user?.merchant?.status === 'approved');

  const mobileLinks = isAuthenticated
    ? [
        ...PUBLIC_NAV.slice(1),
        { to: homeForRole(user), label: 'Mon espace', icon: ShieldCheck, end: true },
        // Un administrateur garde l'accès à ses sections même sur mobile.
        ...(user?.role === 'admin' ? ADMIN_NAV : CLIENT_NAV),
      ]
    : PUBLIC_NAV;

  return (
    <>
      <div className="flex h-16 items-center justify-between gap-4">
        <div className="flex items-center gap-2">
          <IconButton icon={Menu} label="Ouvrir le menu" onClick={() => setMenuOpen(true)} className="-ml-2 lg:hidden" />
          <Logo size="md" />
        </div>

        <nav aria-label="Navigation principale" className="hidden items-center gap-1 lg:flex">
          {PUBLIC_NAV.map((item) => (
            <NavItem key={item.to} {...item} />
          ))}
          {isAuthenticated && (
            <>
              <span className="mx-1.5 h-5 w-px bg-line" aria-hidden="true" />
              <NavItem to={homeForRole(user)} label="Mon espace" />
              <NavItem
                to="/mes-cotisations"
                label="Mes contributions"
                badge={unread || undefined}
                badgeTone="alert"
                onClick={markAllAsRead}
              />
            </>
          )}
        </nav>

        <div className="flex items-center gap-2">
          {isAuthenticated ? (
            <>
              {canCreateTontine && (
                <ButtonLink to="/tontines/nouvelle" size="sm" className="hidden sm:inline-flex">
                  Créer une tontine
                </ButtonLink>
              )}
              <AccountMenu user={user} onLogout={logout} />
            </>
          ) : (
            <>
              <ButtonLink to="/connexion" variant="ghost" size="sm" className="hidden sm:inline-flex">
                Connexion
              </ButtonLink>
              <ButtonLink to="/inscription" size="sm">
                Créer un compte
              </ButtonLink>
            </>
          )}
        </div>
      </div>

      {/* Navigation mobile */}
      <Drawer open={menuOpen} onClose={() => setMenuOpen(false)} side="left" width="max-w-[19rem]" title="Menu">
        <nav aria-label="Navigation mobile">
          <ul className="space-y-0.5">
            {mobileLinks.map((item) => (
              <li key={`${item.to}-${item.label}`}>
                <NavLink
                  to={item.to}
                  end={item.end}
                  onClick={() => {
                    setMenuOpen(false);
                    // Ouvrir la liste des notifications vaut « je les ai vues ».
                    if (item.to === '/notifications') markAllAsRead();
                  }}
                  className={({ isActive }) =>
                    `flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition-colors ${
                      isActive ? 'bg-primary-50 text-primary-800' : 'text-ink-700 hover:bg-ink-100'
                    }`
                  }
                >
                  {item.icon && <item.icon className="size-4.5 text-ink-400" aria-hidden="true" />}
                  <span className="min-w-0 flex-1 truncate">{item.label}</span>
                  {item.badge === 'unread' && unread ? (
                    <span className="inline-flex min-w-[20px] items-center justify-center rounded-full bg-danger-500 px-1.5 py-0.5 text-[10px] font-bold text-white">
                      {unread > 99 ? '99+' : unread}
                    </span>
                  ) : null}
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>

        {!isAuthenticated && (
          <div className="mt-5 grid gap-2.5 border-t border-line-soft pt-5">
            <ButtonLink to="/connexion" variant="secondary" className="w-full">
              Connexion
            </ButtonLink>
            <ButtonLink to="/inscription" className="w-full">
              Créer un compte
            </ButtonLink>
          </div>
        )}
      </Drawer>
    </>
  );
}

/** Barre compacte de l'espace connecté (mobile + desktop). */
export function AppBar({ title, subtitle, action, onOpenMenu }) {
  const { user } = useAuth();
  const { unread, markAllAsRead } = useNotifications();

  return (
    <div className="flex h-16 items-center gap-3">
      {onOpenMenu && <IconButton icon={Menu} label="Ouvrir le menu" onClick={onOpenMenu} className="-ml-2 lg:hidden" />}
      <div className="min-w-0 flex-1">
        <p className="truncate font-display text-sm font-bold text-ink-900 sm:text-base">{title}</p>
        {subtitle && <p className="truncate text-[11px] text-ink-500">{subtitle}</p>}
      </div>

      <Link
        to="/notifications"
        onClick={markAllAsRead}
        aria-label={`Notifications${unread ? ` — ${unread} à traiter` : ''}`}
        title="Ouvrir mes notifications"
        className="relative hidden size-10 items-center justify-center rounded-xl text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-900 sm:inline-flex"
      >
        <BellIcon />
        {unread > 0 && (
          <span className="absolute -right-0.5 -top-0.5 inline-flex min-w-[18px] items-center justify-center rounded-full bg-danger-500 px-1 py-0.5 text-[10px] font-bold text-white ring-2 ring-white">
            {unread > 99 ? '99+' : unread}
          </span>
        )}
      </Link>

      {action}
      <Avatar src={user?.avatar_url} name={user?.name} size="sm" />
    </div>
  );
}

function BellIcon() {
  return (
    <svg viewBox="0 0 24 24" className="size-[18px]" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
      <path d="M13.7 21a2 2 0 0 1-3.4 0" />
    </svg>
  );
}

function NavItem({ to, label, icon: Icon, badge, badgeTone, end, onClick }) {
  return (
    <NavLink
      to={to}
      end={end}
      onClick={onClick}
      className={({ isActive }) =>
        `relative inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold transition-colors ${
          isActive ? 'bg-primary-50 text-primary-800' : 'text-ink-600 hover:bg-ink-50 hover:text-ink-900'
        }`
      }
    >
      {Icon && <Icon className="size-4" aria-hidden="true" />}
      {label}
      {badge ? (
        <span
          className={`ml-0.5 inline-flex min-w-[18px] items-center justify-center rounded-full px-1 py-0.5 text-[10px] font-bold ${
            badgeTone === 'alert' ? 'bg-danger-500 text-white' : 'bg-primary-100 text-primary-800'
          }`}
        >
          {badge > 99 ? '99+' : badge}
        </span>
      ) : null}
    </NavLink>
  );
}

export function AccountMenu({ user, onLogout }) {
  const { markAllAsRead } = useNotifications();
  const firstName = user?.name?.split(' ')[0] || 'Compte';

  return (
    <Dropdown
      width="w-64"
      trigger={
        <button
          type="button"
          className="inline-flex items-center gap-2 rounded-xl border border-line-strong bg-white py-1 pl-1 pr-2.5 shadow-sm transition-all hover:border-ink-300 hover:shadow-sm"
          aria-label="Menu du compte"
        >
          <Avatar src={user?.avatar_url} name={user?.name} size="sm" />
          <span className="hidden max-w-[110px] truncate text-sm font-semibold text-ink-800 sm:block">{firstName}</span>
        </button>
      }
    >
      <div className="flex items-center gap-3 px-3 py-2.5">
        <Avatar src={user?.avatar_url} name={user?.name} size="md" />
        <div className="min-w-0">
          <p className="truncate text-sm font-bold text-ink-900">{user?.name}</p>
          <p className="truncate text-xs text-ink-500">{user?.email}</p>
        </div>
      </div>
      <DropdownSeparator />
      <DropdownLabel>Espaces</DropdownLabel>
      <DropdownLink to={homeForRole(user)} icon={ShieldCheck}>
        {user?.role === 'admin' ? 'Administration' : 'Mon tableau de bord'}
      </DropdownLink>
      {user?.role === 'merchant' && (
        <DropdownLink to="/commercant" icon={Store}>
          Espace commerçant
        </DropdownLink>
      )}
      <DropdownSeparator />
      <DropdownLabel>Mon compte</DropdownLabel>
      {CLIENT_NAV.filter((item) => item.to !== '/tableau-de-bord').map((item) => (
        <DropdownLink
          key={item.to}
          to={item.to}
          icon={item.icon}
          onClick={item.to === '/notifications' ? markAllAsRead : undefined}
        >
          {item.label}
        </DropdownLink>
      ))}
      <DropdownSeparator />
      <DropdownItem danger icon={LogOut} onClick={onLogout}>
        Se déconnecter
      </DropdownItem>
    </Dropdown>
  );
}
