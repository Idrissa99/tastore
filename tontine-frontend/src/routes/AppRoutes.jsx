import { Suspense, lazy, useEffect } from 'react';
import { Route, Routes, useLocation } from 'react-router-dom';
import AppLayout from '../components/layout/AppLayout';
import ErrorBoundary from '../components/ErrorBoundary';
import { SkeletonGrid } from '../components/ui/Skeleton';
import PrivateRoute from './PrivateRoute';
import RoleRoute from './RoleRoute';

/*
 * Table de routage unique.
 * Les chemins existants sont conservés à l'identique : la refonte ne doit
 * casser aucun lien déjà partagé (notifications backend, signets, etc.).
 * Seules des routes additives sont créées (/mes-tontines).
 *
 * Les pages sont chargées paresseusement pour ne pas tout embarquer dans le
 * bundle initial, en particulier pour les espaces admin et commerçant.
 */
const Home = lazy(() => import('../pages/Home'));
const NotFound = lazy(() => import('../pages/NotFound'));

const Login = lazy(() => import('../pages/auth/Login'));
const Register = lazy(() => import('../pages/auth/Register'));
const ForgotPassword = lazy(() => import('../pages/auth/ForgotPassword'));
const ResetPassword = lazy(() => import('../pages/auth/ResetPassword'));
const VerifyEmailNotice = lazy(() => import('../pages/auth/VerifyEmailNotice'));

const Catalog = lazy(() => import('../pages/catalog/Catalog'));
const ProductDetail = lazy(() => import('../pages/catalog/ProductDetail'));
const MerchantProfile = lazy(() => import('../pages/catalog/MerchantProfile'));

const TontineList = lazy(() => import('../pages/tontines/TontineList'));
const TontineDetail = lazy(() => import('../pages/tontines/TontineDetail'));
const TontineCreate = lazy(() => import('../pages/tontines/TontineCreate'));
const TontineEdit = lazy(() => import('../pages/tontines/TontineEdit'));
const DisputeCreate = lazy(() => import('../pages/tontines/DisputeCreate'));
const MerchantReviewCreate = lazy(() => import('../pages/tontines/MerchantReviewCreate'));

const Dashboard = lazy(() => import('../pages/dashboard/Dashboard'));
const MyTontines = lazy(() => import('../pages/dashboard/MyTontines'));
const Contributions = lazy(() => import('../pages/dashboard/Contributions'));
const Notifications = lazy(() => import('../pages/dashboard/Notifications'));

const Profile = lazy(() => import('../pages/profile/Profile'));

const InstallmentPurchaseCreate = lazy(() => import('../pages/installments/InstallmentPurchaseCreate'));
const InstallmentPurchaseList = lazy(() => import('../pages/installments/InstallmentPurchaseList'));
const InstallmentPurchaseDetail = lazy(() => import('../pages/installments/InstallmentPurchaseDetail'));

const MerchantDashboard = lazy(() => import('../pages/merchant/MerchantDashboard'));
const MerchantProducts = lazy(() => import('../pages/merchant/MerchantProducts'));
const MerchantProductEdit = lazy(() => import('../pages/merchant/MerchantProductEdit'));
const MerchantDeliveries = lazy(() => import('../pages/merchant/MerchantDeliveries'));

const AdminDashboard = lazy(() => import('../pages/admin/AdminDashboard'));
const AdminMerchants = lazy(() => import('../pages/admin/AdminMerchants'));
const AdminTontines = lazy(() => import('../pages/admin/AdminTontines'));
const AdminPaymentVerifications = lazy(() => import('../pages/admin/AdminPaymentVerifications'));
const AdminUsers = lazy(() => import('../pages/admin/AdminUsers'));
const AdminProducts = lazy(() => import('../pages/admin/AdminProducts'));
const AdminRefunds = lazy(() => import('../pages/admin/AdminRefunds'));
const AdminReports = lazy(() => import('../pages/admin/AdminReports'));
const AdminCommissions = lazy(() => import('../pages/admin/AdminCommissions'));
const AdminDisputeList = lazy(() => import('../pages/admin/AdminDisputes').then((m) => ({ default: m.AdminDisputeList })));
const AdminDisputeDetail = lazy(() => import('../pages/admin/AdminDisputes').then((m) => ({ default: m.AdminDisputeDetail })));

function PageFallback() {
  return (
    <div className="mx-auto w-full max-w-7xl px-4 py-10 sm:px-6 lg:px-10">
      <div className="skeleton mb-6 h-8 w-64 rounded-lg" aria-hidden="true" />
      <SkeletonGrid count={6} />
      <span className="sr-only" role="status">
        Chargement de la page…
      </span>
    </div>
  );
}

/** Remet la vue en haut à chaque changement de page (routeur SPA). */
function ScrollToTop() {
  const { pathname } = useLocation();
  useEffect(() => {
    window.scrollTo(0, 0);
  }, [pathname]);
  return null;
}

export default function AppRoutes() {
  return (
    <ErrorBoundary>
      <Suspense fallback={<PageFallback />}>
        <ScrollToTop />
        <Routes>
        <Route element={<AppLayout />}>
          {/* Public */}
          <Route index element={<Home />} />
          <Route path="produits" element={<Catalog />} />
          <Route path="produits/:id" element={<ProductDetail />} />
          <Route path="commercants/:id" element={<MerchantProfile />} />
          <Route path="tontines" element={<TontineList />} />
          <Route path="tontines/:id" element={<TontineDetail />} />
          <Route path="connexion" element={<Login />} />
          <Route path="inscription" element={<Register />} />
          <Route path="mot-de-passe-oublie" element={<ForgotPassword />} />
          <Route path="reinitialiser-mot-de-passe/:token" element={<ResetPassword />} />

          {/* Authentifié */}
          <Route element={<PrivateRoute />}>
            <Route path="tableau-de-bord" element={<Dashboard />} />
            <Route path="mes-tontines" element={<MyTontines />} />
            <Route path="mes-cotisations" element={<Contributions />} />
            <Route path="notifications" element={<Notifications />} />
            <Route path="profil" element={<Profile />} />
            <Route path="verifier-email" element={<VerifyEmailNotice />} />
            <Route path="produits/:productId/tranches" element={<InstallmentPurchaseCreate />} />
            <Route path="mes-achats" element={<InstallmentPurchaseList />} />
            <Route path="mes-achats/:id" element={<InstallmentPurchaseDetail />} />
            <Route path="tontines/:id/litige" element={<DisputeCreate />} />
            <Route path="tontines/:id/avis" element={<MerchantReviewCreate />} />
          </Route>

          {/* Création de tontine : commerçant approuvé ou admin (revérifié côté API) */}
          <Route element={<RoleRoute allow={['merchant', 'admin']} />}>
            <Route path="tontines/nouvelle" element={<TontineCreate />} />
            {/* Modification d'une tontine ouverte : créateur ou admin uniquement
                (revérifié côté API, qui refuse dès que la tontine a démarré). */}
            <Route path="tontines/:id/modifier" element={<TontineEdit />} />
          </Route>

          {/* Espace commerçant */}
          <Route element={<RoleRoute allow={['merchant']} />}>
            <Route path="commercant" element={<MerchantDashboard />} />
            <Route path="commercant/produits" element={<MerchantProducts />} />
            <Route path="commercant/produits/:id" element={<MerchantProductEdit />} />
            <Route path="commercant/livraisons" element={<MerchantDeliveries />} />
          </Route>

          {/* Espace administrateur */}
          <Route element={<RoleRoute allow={['admin']} />}>
            <Route path="admin" element={<AdminDashboard />} />
            <Route path="admin/commercants" element={<AdminMerchants />} />
            <Route path="admin/tontines" element={<AdminTontines />} />
            <Route path="admin/litiges" element={<AdminDisputeList />} />
            <Route path="admin/litiges/:id" element={<AdminDisputeDetail />} />
            <Route path="admin/remboursements" element={<AdminRefunds />} />
            <Route path="admin/produits" element={<AdminProducts />} />
            <Route path="admin/utilisateurs" element={<AdminUsers />} />
            <Route path="admin/rapports" element={<AdminReports />} />
            <Route path="admin/commissions" element={<AdminCommissions />} />
            <Route path="admin/paiements" element={<AdminPaymentVerifications />} />
          </Route>

          <Route path="*" element={<NotFound />} />
        </Route>
        </Routes>
      </Suspense>
    </ErrorBoundary>
  );
}
