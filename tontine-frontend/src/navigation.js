import {
  BadgePercent,
  Bell,
  Boxes,
  Building2,
  ChartNoAxesColumn,
  Compass,
  CreditCard,
  Gift,
  HandCoins,
  Home,
  LayoutDashboard,
  Package,
  RefreshCcw,
  Scale,
  ShoppingBag,
  Store,
  Truck,
  User,
  Users,
  Wallet,
} from 'lucide-react';

/**
 * Source de vérité de la navigation.
 *
 * Un seul endroit décrit QUI voit QUOI : barre haute, sidebar, navigation
 * mobile, et la page d'accueil de chaque rôle s'y réfèrent.
 *
 * Règle importante : un administrateur qui se connecte doit atterrir dans
 * SON espace (administration), pas sur un tableau de bord client. D'où
 * `homeForRole()` et le `workspaceFor()` sensible au rôle.
 */

/** Liens publics, affichés dans la barre haute pour tous. */
export const PUBLIC_NAV = [
  { to: '/', label: 'Accueil', icon: Home, end: true },
  { to: '/tontines', label: 'Tontines', icon: Wallet },
  { to: '/produits', label: 'Produits', icon: ShoppingBag },
];

/** Espace client — commun à tout utilisateur connecté. */
export const CLIENT_NAV = [
  { to: '/tableau-de-bord', label: 'Tableau de bord', icon: LayoutDashboard },
  { to: '/mes-tontines', label: 'Mes tontines', icon: Wallet },
  { to: '/mes-cotisations', label: 'Mes contributions', icon: HandCoins },
  { to: '/mes-achats', label: 'Mes commandes', icon: Package },
  { to: '/notifications', label: 'Notifications', icon: Bell, badge: 'unread' },
  { to: '/profil', label: 'Profil', icon: User },
];

/** Onglets de la bottom navigation mobile (5 maximum). */
export const CLIENT_MOBILE_NAV = [
  { to: '/tableau-de-bord', label: 'Accueil', icon: Home },
  { to: '/tontines', label: 'Tontines', icon: Compass },
  { to: '/mes-tontines', label: 'Mes tontines', icon: Wallet },
  { to: '/notifications', label: 'Notifs', icon: Bell, badge: 'unread' },
  { to: '/profil', label: 'Profil', icon: User },
];

/** Espace commerçant. */
export const MERCHANT_NAV = [
  { to: '/commercant', label: 'Dashboard', icon: LayoutDashboard, end: true, group: 'Ventes' },
  { to: '/commercant/produits', label: 'Produits', icon: Boxes, group: 'Ventes' },
  { to: '/commercant/livraisons', label: 'Commandes & livraisons', icon: Truck, group: 'Ventes' },
  { to: '/tontines/nouvelle', label: 'Créer une tontine', icon: Gift, group: 'Ventes' },
];

/**
 * Espace administrateur — couvre l'intégralité des endpoints
 * `routes/api.php` du groupe `admin`.
 */
export const ADMIN_NAV = [
  { to: '/admin', label: 'Dashboard', icon: LayoutDashboard, end: true, group: 'Pilotage' },
  { to: '/admin/utilisateurs', label: 'Utilisateurs', icon: Users, group: 'Pilotage' },
  { to: '/admin/commercants', label: 'Commerçants', icon: Building2, group: 'Pilotage' },

  { to: '/admin/paiements', label: 'Paiements', icon: CreditCard, badge: 'pendingPayments', group: 'Opérations' },
  { to: '/admin/tontines', label: 'Tontines', icon: Wallet, group: 'Opérations' },
  { to: '/admin/produits', label: 'Produits', icon: ShoppingBag, group: 'Opérations' },
  { to: '/admin/litiges', label: 'Litiges', icon: Scale, group: 'Opérations' },
  { to: '/admin/remboursements', label: 'Remboursements', icon: RefreshCcw, group: 'Opérations' },

  { to: '/admin/commissions', label: 'Commissions', icon: BadgePercent, group: 'Finance' },
  { to: '/admin/rapports', label: 'Rapports', icon: ChartNoAxesColumn, group: 'Finance' },
];

/**
 * Liens de compte, présents dans TOUS les espaces.
 *
 * Sans eux, un administrateur connecté en sidebar desktop n'a aucun moyen
 * d'atteindre ses notifications ni son profil : son menu ne contient que
 * des entrées d'administration.
 */
export const ACCOUNT_NAV = [
  { to: '/notifications', label: 'Notifications', icon: Bell, badge: 'unread' },
  { to: '/profil', label: 'Mon profil', icon: User },
];

/** Libellés des espaces, utilisés dans l'en-tête et la sidebar. */
export const WORKSPACE_LABELS = {
  client: 'Mon espace',
  merchant: 'Espace commerçant',
  admin: 'Administration',
};

/**
 * Préfixes desservis par la mise en page « application » (sidebar + bottom
 * nav) plutôt que par la mise en page publique (header + footer).
 */
export const APP_PREFIXES = [
  '/tableau-de-bord',
  '/mes-tontines',
  '/mes-cotisations',
  '/mes-achats',
  '/notifications',
  '/profil',
  '/verifier-email',
  '/commercant',
  '/admin',
];

export function isAppPath(pathname) {
  return APP_PREFIXES.some((prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`));
}

/** Un compte commerçant n'accède à son espace que s'il est approuvé. */
function isApprovedMerchant(user) {
  return user?.role === 'merchant' && user?.merchant?.status === 'approved';
}

/**
 * Espace de travail déduit de l'URL **et du rôle**.
 *
 * Les préfixes explicites l'emportent toujours : un administrateur qui
 * ouvre « Mes contributions » reste dans son espace admin, mais un
 * administrateur qui arrive sur `/tableau-de-bord` doit voir la
 * navigation d'administration, pas celle d'un client.
 */
export function workspaceFor(pathname, user) {
  if (pathname.startsWith('/admin')) return 'admin';
  if (pathname.startsWith('/commercant')) return 'merchant';
  if (user?.role === 'admin') return 'admin';
  if (isApprovedMerchant(user)) return 'merchant';
  return 'client';
}

/** Page d'accueil correspondant au rôle — utilisée après connexion. */
export function homeForRole(user) {
  if (user?.role === 'admin') return '/admin';
  if (isApprovedMerchant(user)) return '/commercant';
  return '/tableau-de-bord';
}

/** Tous les espaces auxquels l'utilisateur a droit, pour le sélecteur. */
export function availableWorkspaces(user) {
  const spaces = [];
  if (user?.role === 'admin') {
    spaces.push({ key: 'admin', label: 'Administration', to: '/admin', icon: LayoutDashboard });
  }
  if (isApprovedMerchant(user)) {
    spaces.push({ key: 'merchant', label: 'Espace commerçant', to: '/commercant', icon: Store });
  }
  spaces.push({ key: 'client', label: 'Mon espace', to: '/tableau-de-bord', icon: User });
  return spaces;
}
