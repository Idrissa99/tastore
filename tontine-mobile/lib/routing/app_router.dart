import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:tontine_achat_store/core/auth/auth_controller.dart';
import 'package:tontine_achat_store/core/widgets/state_views.dart';
import 'package:tontine_achat_store/features/auth/forgot_password_page.dart';
import 'package:tontine_achat_store/features/merchant/merchant_deliveries_page.dart';
import 'package:tontine_achat_store/features/merchant/merchant_dashboard_page.dart';
import 'package:tontine_achat_store/features/merchant/merchant_products_page.dart';
import 'package:tontine_achat_store/features/auth/login_page.dart';
import 'package:tontine_achat_store/features/auth/register_page.dart';
import 'package:tontine_achat_store/features/contributions/contributions_page.dart';
import 'package:tontine_achat_store/features/home/home_page.dart';
import 'package:tontine_achat_store/features/notifications/notifications_page.dart';
import 'package:tontine_achat_store/features/profile/profile_page.dart';
import 'package:tontine_achat_store/features/products/product_detail_page.dart';
import 'package:tontine_achat_store/features/products/products_page.dart';
import 'package:tontine_achat_store/features/purchases/purchase_detail_page.dart';
import 'package:tontine_achat_store/features/purchases/purchases_page.dart';
import 'package:tontine_achat_store/features/home/home_shell.dart';
import 'package:tontine_achat_store/features/splash/splash_page.dart';
import 'package:tontine_achat_store/features/tontines/catalog_page.dart';
import 'package:tontine_achat_store/features/tontines/detail_page.dart';
import 'package:tontine_achat_store/features/tontines/my_tontines_page.dart';

/// Chemins de l'application. Centralisés pour que le routeur et les tests ne
/// dépendent pas de chaînes en dur.
class AppRoutes {
  const AppRoutes._();

  static const splash = '/splash';
  static const login = '/login';
  static const register = '/register';
  static const forgotPassword = '/mot-de-passe-oublie';
  static const home = '/';

  static const catalog = '/tontines';
  static const contributions = '/cotisations';
  static const notifications = '/notifications';
  static const profile = '/profil';
  static const myTontines = '/mes-tontines';
  static const purchases = '/mes-achats';
  static const products = '/produits';

  /// Espace commerçant. Préfixe `/commercant`, comme le SPA web, pour que les
  /// deux clients restent lisibles l'un par rapport à l'autre.
  static const merchant = '/commercant';
  static const merchantProducts = '/commercant/produits';
  static const merchantDeliveries = '/commercant/livraisons';

  /// Création : pas d'identifiant à fournir.
  static const merchantProductCreate = '/commercant/produits/nouveau';

  static const merchantProductEditPattern = '/commercant/produits/:id/modifier';

  static String merchantProductEdit(int id) => '/commercant/produits/$id/modifier';

  /// Segment dynamique des fiches, montées hors de la coque à onglets.
  static const productDetailPattern = '/produits/:id';
  static const detailPattern = '/tontines/:id';
  static const purchasePattern = '/mes-achats/:id';

  /// Chemin de la fiche d'une tontine.
  ///
  /// Construit par une méthode et non par une interpolation en dur dans les
  /// écrans : c'est le seul endroit où le segment dynamique est assemblé, donc
  /// le seul à garder cohérent avec [detailPattern].
  static String detail(int id) => '$catalog/$id';

  static String purchase(int id) => '$purchases/$id';

  static String product(int id) => '$products/$id';

  /// Les racines atteignables SANS session, et toutes leurs fiches.
  ///
  /// Le catalogue et le catalogue produits sont publics : `GET /tontines`,
  /// `GET /tontines/{id}`, `GET /produits` et `GET /produits/{id}` ne demandent
  /// aucun jeton, et un visiteur doit pouvoir découvrir une offre avant de créer
  /// un compte. Tout le reste exige une session — c'est donc cette liste qui
  /// décide si l'on peut rester sur l'écran courant ou s'il faut renvoyer vers
  /// la connexion.
  ///
  /// Ce sont des RACINES, pas des écrans : `/tontines/12` est la fiche du
  /// catalogue, donc publique elle aussi. `matchedLocation` rend le chemin avec
  /// les paramètres résolus, jamais le motif — comparer à `/tontines/:id` ne
  /// serait donc jamais vrai.
  ///
  /// L'inventaire des sections *protégées* était l'autre façon de dire la même
  /// chose, mais il devait être mis à jour à chaque écran ajouté. Une omission
  /// ne bloque rien : elle laisse un utilisateur **déconnecté** sur un écran
  /// connecté. C'est exactement le défaut qui rendait la déconnexion invisible —
  /// on revenait à l'accueil, l'application y affichait encore ses raccourcis,
  /// et le jeton effacé ne se voyait qu'au prochain appel réseau.
  static const publicSections = <String>[
    catalog,
    products,
  ];

  /// L'écran reste-t-il consultable sans jeton ?
  static bool isPublic(String location) => publicSections.any(
        (root) => location == root || location.startsWith('$root/'),
      );
}

/// Notifie le routeur quand la session change, pour que la redirection soit
/// réévaluée sans reconstruire le `GoRouter` — et donc sans perdre l'état de
/// navigation.
class _AuthRefreshNotifier extends ChangeNotifier {
  _AuthRefreshNotifier(Ref ref) {
    ref.listen<AuthState>(authControllerProvider, (previous, next) {
      if (previous?.status != next.status) notifyListeners();
    });
  }
}

/// Redirection centrale : une seule règle décide de l'écran, quel que soit le
/// point d'entrée.
///
/// Volontairement une fonction PURE, sans widget ni Riverpod : c'est une règle
/// de domaine, elle se teste donc directement. Une erreur ici ne plante
/// jamais — elle se manifeste sous la forme d'un utilisateur connecté qui voit
/// l'écran de connexion, ou l'inverse.
///
/// Tant que la session est `unknown` on reste sur le splash, même si
/// l'utilisateur demande `/login` : décider avant la lecture du keystore
/// ferait clignoter l'écran de connexion avant d'ouvrir l'app.
///
/// Il n'existe volontairement AUCUN écran de « choix du rôle » après la
/// connexion. Le rôle est fixé à l'inscription (`POST /register`, `role`
/// limité à `client,merchant`) et plus jamais modifié :
/// `ProfileController::update` ne le touche pas. Un onboarding aurait donc été
/// un cul-de-sac — et surtout, un client n'a rien à faire avant de pouvoir
/// rejoindre une tontine, ce qui est précisément le catalogue.
String? redirectFor(AuthState auth, String? location) {
  final onSplash = location == AppRoutes.splash;
  final onAuthForm = location == AppRoutes.login ||
      location == AppRoutes.register ||
      location == AppRoutes.forgotPassword;

  if (auth.status == AuthStatus.unknown) {
    return onSplash ? null : AppRoutes.splash;
  }

  if (auth.status == AuthStatus.unauthenticated) {
    // Depuis le splash, on va vers la connexion. Sur les trois formulaires
    // « hors session », on ne redirige PAS : go_router bouclerait.
    if (onSplash) return AppRoutes.login;
    if (onAuthForm) return null;

    // Une déconnexion demandée depuis un onglet connecté laisse l'utilisateur
    // sur l'écran courant : l'application y dessine encore ses raccourcis, alors
    // que le jeton est effacé et que chaque appel va échouer. Tout écran qui
    // n'est pas public doit donc ramener vers la connexion — c'est ce qui rend
    // le bouton « Se déconnecter » visible dans son effet.
    return location != null && AppRoutes.isPublic(location) ? null : AppRoutes.login;
  }

  // Connecté : plus rien à faire sur le splash ni sur les formulaires. Un
  // utilisateur déjà connecté qui ouvre `/register` est renvoyé à l'accueil :
  // il n'a pas de compte à créer. `/mot-de-passe-oublie` suit la même règle —
  // c'est un écran « hors session », et y laisser un utilisateur connecté
  // l'amènerait à réinitialiser le mot de passe du compte qu'il utilise déjà.
  if (onSplash || onAuthForm) return AppRoutes.home;

  return null;
}

final routerProvider = Provider<GoRouter>((ref) {
  final notifier = _AuthRefreshNotifier(ref);

  ref.onDispose(notifier.dispose);

  return GoRouter(
    initialLocation: AppRoutes.splash,
    refreshListenable: notifier,
    redirect: (context, state) {
      return redirectFor(ref.read(authControllerProvider), state.matchedLocation);
    },
    routes: [
      GoRoute(
        path: AppRoutes.splash,
        builder: (context, state) => const SplashPage(),
      ),
      GoRoute(
        path: AppRoutes.login,
        builder: (context, state) => const LoginPage(),
      ),
      GoRoute(
        path: AppRoutes.register,
        builder: (context, state) => const RegisterPage(),
      ),
      GoRoute(
        path: AppRoutes.forgotPassword,
        builder: (context, state) => const ForgotPasswordPage(),
      ),
      // Coque à onglets : chaque branche garde sa propre pile de navigation.
      StatefulShellRoute.indexedStack(
        builder: (context, state, shell) => HomeShell(shell: shell),
        branches: [
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: AppRoutes.home,
                builder: (context, state) => const HomePage(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: AppRoutes.catalog,
                builder: (context, state) => const TontineCatalogPage(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: AppRoutes.contributions,
                builder: (context, state) => const ContributionsPage(),
              ),
            ],
          ),
        ],
      ),

      // Les fiches sont des routes PUBLIQUES et hors de la coque : `GET
      // /tontines/{id}` et `GET /mes-achats/{id}` ne demandent pas de jeton, et
      // on lit une fiche en plein écran. La redirection ci-dessus ne les
      // redirige donc pas hors session — c'est le rôle de la session, et non
      // du chemin, que de décider ce qu'on propose à un visiteur.
      GoRoute(
        path: AppRoutes.detailPattern,
        builder: (context, state) {
          final id = int.tryParse(state.pathParameters['id'] ?? '');

          // Un identifiant non numérique ne peut correspondre à rien : le
          // laisser passer produirait `GET /tontines/abc`, c'est-à-dire un 404
          // du serveur pour une URL qui n'a aucun sens.
          if (id == null) return const _UnknownTontinePage();

          return TontineDetailPage(tontineId: id);
        },
      ),

      // Achats : le catalogue et l'historique sont poussés depuis l'accueil,
      // la fiche est poussée depuis les deux.
      GoRoute(
        path: AppRoutes.products,
        builder: (context, state) => const ProductsPage(),
      ),
      GoRoute(
        path: AppRoutes.productDetailPattern,
        builder: (context, state) {
          final id = int.tryParse(state.pathParameters['id'] ?? '');
          if (id == null) return const _UnknownTontinePage();

          return ProductDetailPage(productId: id);
        },
      ),
      GoRoute(
        path: AppRoutes.purchases,
        builder: (context, state) => const PurchasesPage(),
      ),
      GoRoute(
        path: AppRoutes.purchasePattern,
        builder: (context, state) {
          final id = int.tryParse(state.pathParameters['id'] ?? '');
          if (id == null) return const _UnknownTontinePage();

          return PurchaseDetailPage(purchaseId: id);
        },
      ),

      GoRoute(
        path: AppRoutes.notifications,
        builder: (context, state) => const NotificationsPage(),
      ),
      GoRoute(
        path: AppRoutes.profile,
        builder: (context, state) => const ProfilePage(),
      ),
      GoRoute(
        path: AppRoutes.myTontines,
        builder: (context, state) => const MyTontinesPage(),
      ),

      // ── Espace commerçant ───────────────────────────────────────────────
      // Derrière `merchant` + `verified` côté API : un vendeur non approuvé
      // reçoit 403, et le garde ci-dessous évite de lui faire pousser un écran
      // qui ne pourra rien charger.
      GoRoute(
        path: AppRoutes.merchant,
        builder: (context, state) => const MerchantAreaGuard(child: MerchantDashboardPage()),
      ),
      GoRoute(
        path: AppRoutes.merchantProducts,
        builder: (context, state) => const MerchantAreaGuard(child: MerchantProductsPage()),
      ),
      GoRoute(
        path: AppRoutes.merchantProductCreate,
        builder: (context, state) =>
            const MerchantAreaGuard(child: MerchantProductEditPage()),
      ),
      GoRoute(
        path: AppRoutes.merchantProductEditPattern,
        builder: (context, state) {
          final id = int.tryParse(state.pathParameters['id'] ?? '');
          if (id == null) return const _UnknownProductPage();

          return MerchantAreaGuard(child: MerchantProductEditPage(productId: id));
        },
      ),
      GoRoute(
        path: AppRoutes.merchantDeliveries,
        builder: (context, state) =>
            const MerchantAreaGuard(child: MerchantDeliveriesPage()),
      ),
    ],
  );
});

/// Garde l'espace commerçant.
///
/// **Ce n'est qu'un confort, pas une protection.** L'API exige déjà
/// `role = merchant` ET `merchant.status = approved` (middleware `merchant`),
/// et répond 403 sinon : un client qui pousse la route n'atteint aucune donnée.
/// Le garde sert à ne pas proposer au client un espace qui ne se chargera
/// jamais, et à expliquer le 403 quand il vient malgré tout du serveur.
///
/// Il est aussi le moment de dire POUR QUOI la vente est bloquée : un profil
/// `pending` n'est pas une erreur, c'est une demande en cours d'examen, et
/// l'accueil le dit déjà — le rendre ici évite qu'un vendeur se demande où sa
/// boutique s'est évaporée.
class MerchantAreaGuard extends ConsumerWidget {
  const MerchantAreaGuard({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(authControllerProvider).user;
    final role = user?['role']?.toString();
    final merchant = user?['merchant'];
    final status = merchant is Map ? merchant['status']?.toString() : null;

    if (role != 'merchant') {
      return const _MerchantBlockedScreen(
        icon: Icons.storefront_outlined,
        title: 'Espace réservé aux commerçants',
        message: "Ce compte n'a pas de boutique. L'espace commerçant est "
            'réservé aux comptes marchands approuvés.',
      );
    }

    if (status != 'approved') {
      return _MerchantBlockedScreen(
        icon: Icons.hourglass_top_outlined,
        title: 'Boutique en attente',
        message: status == 'rejected'
            ? "Ta demande a été refusée. Contacte l'administration pour en savoir plus."
            : 'Un administrateur doit approuver ta boutique avant que tu puisses '
                'y vendre. Ta demande est bien enregistrée.',
      );
    }

    return child;
  }
}

class _MerchantBlockedScreen extends StatelessWidget {
  const _MerchantBlockedScreen({
    required this.icon,
    required this.title,
    required this.message,
  });

  final IconData icon;
  final String title;
  final String message;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Mon espace')),
      body: EmptyView(
        icon: icon,
        title: title,
        message: message,
        actionLabel: 'Retour à l\'accueil',
        onAction: () => context.go(AppRoutes.home),
      ),
    );
  }
}

/// Fiche produit demandée sur un identifiant illisible.
///
/// Distincte de [_UnknownTontinePage] : le renvoi doit Ramener vers le
/// CATALOGUE DU VENDEUR, pas vers le catalogue public — c'est le seul endroit
/// où cette fiche peut être rectifiée.
class _UnknownProductPage extends StatelessWidget {
  const _UnknownProductPage();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Produit')),
      body: EmptyView(
        icon: Icons.search_off,
        title: 'Produit introuvable',
        message: "Cette fiche n'existe pas dans ton catalogue.",
        actionLabel: 'Voir mes produits',
        onAction: () => context.go(AppRoutes.merchantProducts),
      ),
    );
  }
}

/// Écran affiché quand l'identifiant de l'URL n'est pas un nombre.
class _UnknownTontinePage extends StatelessWidget {
  const _UnknownTontinePage();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Tontine')),
      body: EmptyView(
        icon: Icons.search_off,
        title: 'Tontine introuvable',
        message: 'Cette adresse ne correspond à aucune tontine.',
        actionLabel: 'Voir le catalogue',
        onAction: () => context.go(AppRoutes.catalog),
      ),
    );
  }
}
