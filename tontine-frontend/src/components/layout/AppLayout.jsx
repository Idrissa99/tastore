import { useEffect, useMemo, useState } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import TopBar, { AppBar } from './TopBar';
import Sidebar from './Sidebar';
import MobileNav from './MobileNav';
import Footer from './Footer';
import { Drawer } from '../ui/Drawer';
import ErrorBoundary from '../ErrorBoundary';
import { ADMIN_NAV, CLIENT_NAV, MERCHANT_NAV, isAppPath, workspaceFor } from '../../navigation';
import { useAuth } from '../../hooks/useAuth';
import { useAdminCounters, AdminCountersProvider } from '../../context/AdminCountersContext';

const NAV_BY_WORKSPACE = { client: CLIENT_NAV, merchant: MERCHANT_NAV, admin: ADMIN_NAV };

/**
 * Coquille applicative.
 *
 * Deux mises en page cohabitent :
 *  - « publique »  : barre haute complète + contenu large + pied de page
 *    (accueil, catalogue, tontines, authentification) ;
 *  - « espace »    : barre compacte + sidebar sur desktop + bottom nav mobile,
 *    sans pied de page, pour préserver de la hauteur d'écran sur les tâches.
 */
export default function AppLayout() {
  return (
    <AdminCountersProvider>
      <Shell />
    </AdminCountersProvider>
  );
}

/** Corps de l'espace connecté — accède au contexte des compteurs admin. */
function Shell() {
  const location = useLocation();
  const appArea = isAppPath(location.pathname);

  useEffect(() => {
    window.scrollTo(0, 0);
  }, [location.pathname]);

  if (!appArea) {
    return (
      <div className="flex min-h-screen flex-col bg-canvas">
        <header className="sticky top-0 z-40 border-b border-line-strong bg-white/90 backdrop-blur-md">
          <div className="mx-auto w-full max-w-7xl px-5 sm:px-8">
            <TopBar />
          </div>
        </header>

        <main id="contenu" className="flex-1">
          <Outlet />
        </main>

        <Footer />
      </div>
    );
  }

  return <WorkspaceShell />;
}

function WorkspaceShell() {
  const { user } = useAuth();
  // Compteur partagé : l'acceptation d'un paiement le met à jour partout.
  const { pendingPayments } = useAdminCounters();
  const [menuOpen, setMenuOpen] = useState(false);
  const location = useLocation();
  const workspace = workspaceFor(location.pathname, user);
  const nav = NAV_BY_WORKSPACE[workspace] || CLIENT_NAV;

  const title = useMemo(() => {
    const match = nav
      .filter((item) => !item.group || workspace !== 'admin')
      .find((item) => (item.end ? location.pathname === item.to : location.pathname.startsWith(item.to)));
    return match?.label || 'Mon espace';
  }, [nav, location.pathname, workspace]);

  return (
    <div className="min-h-screen bg-canvas">
      <aside className="fixed inset-y-0 left-0 z-40 hidden w-64 border-r border-line-strong bg-white lg:block">
        <Sidebar workspace={workspace} pendingPayments={pendingPayments} />
      </aside>

      <Drawer
        open={menuOpen}
        onClose={() => setMenuOpen(false)}
        side="left"
        width="max-w-[17rem]"
        title="Menu"
        bodyClassName="overflow-hidden p-0"
      >
        <div className="h-full">
          <Sidebar workspace={workspace} onNavigate={() => setMenuOpen(false)} pendingPayments={pendingPayments} />
        </div>
      </Drawer>

      <div className="lg:pl-64">
        <header className="sticky top-0 z-30 border-b border-line-strong bg-white/90 backdrop-blur-md lg:hidden">
          <div className="px-4 sm:px-6">
            <AppBar title={title} subtitle={workspaceTitle(workspace, user)} onOpenMenu={() => setMenuOpen(true)} />
          </div>
        </header>

        <main id="contenu" className="mx-auto w-full max-w-6xl px-4 pb-28 pt-6 sm:px-6 lg:max-w-none lg:px-10 lg:pb-16 lg:pt-8">
          {/* La barrière englobe la page ET la zone principale, mais pas
              la sidebar : si un écran casse, la navigation reste
              fonctionnelle et l'utilisateur peut changer de section. */}
          <ErrorBoundary title="Cet écran a rencontré un problème">
            <Outlet />
          </ErrorBoundary>
        </main>
      </div>

      <MobileNav workspace={workspace} />
    </div>
  );
}

function workspaceTitle(workspace, user) {
  if (workspace === 'admin') return 'Administration';
  if (workspace === 'merchant') return user?.merchant?.business_name || 'Commerçant';
  return 'Mon espace';
}
