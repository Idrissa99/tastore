import 'package:flutter_test/flutter_test.dart';
import 'package:tontine_achat_store/core/auth/auth_controller.dart';
import 'package:tontine_achat_store/routing/app_router.dart';

/// La redirection est la SEULE règle qui décide de l'écran affiché.
///
/// Elle est testée comme fonction pure, sans widget ni pumping : une erreur
/// ici se voit en production sous la forme d'un utilisateur connecté qui voit
/// l'écran de connexion, ou l'inverse — un défaut qui ne plante jamais et ne
/// se remarque qu'à l'écran.
void main() {
  AuthState unknown() => const AuthState(AuthStatus.unknown);
  AuthState guest() => const AuthState(AuthStatus.unauthenticated);

  AuthState member({String role = 'merchant'}) => AuthState(
        AuthStatus.authenticated,
        user: {'id': 1, 'name': 'Awa', 'role': role},
      );

  group('session inconnue', () {
    test('reste sur le splash, même si on demande un écran hors session', () {
      expect(redirectFor(unknown(), AppRoutes.login), AppRoutes.splash);
      expect(redirectFor(unknown(), AppRoutes.register), AppRoutes.splash);
      expect(redirectFor(unknown(), AppRoutes.home), AppRoutes.splash);
    });

    test('laisse le splash tranquille', () {
      expect(redirectFor(unknown(), AppRoutes.splash), isNull);
    });
  });

  group('session fermée', () {
    test('le splash renvoie vers la connexion', () {
      expect(redirectFor(guest(), AppRoutes.splash), AppRoutes.login);
    });

    test('sur un formulaire, on laisse l\'utilisateur où il est', () {
      // Déjà sur /login ou /register : aucune redirection, sinon go_router
      // bouclerait.
      expect(redirectFor(guest(), AppRoutes.login), isNull);
      expect(redirectFor(guest(), AppRoutes.register), isNull);
      expect(redirectFor(guest(), AppRoutes.forgotPassword), isNull);
    });

    test('l\'accueil ramène vers la connexion', () {
      // Régression : l'accueil n'était pas dans l'inventaire des sections
      // protégées, donc la déconnexion — qui se déclenche justement depuis
      // l'accueil — ne redirigeait pas. L'utilisateur restait sur une page
      // affichant ses raccourcis vers des écrans dont tous les appels
      // échouaient : la déconnexion semblait ne rien faire.
      expect(redirectFor(guest(), AppRoutes.home), AppRoutes.login);
    });
  });

  group('session ouverte', () {
    test('le splash et les formulaires renvoient vers l\'accueil', () {
      expect(redirectFor(member(), AppRoutes.splash), AppRoutes.home);
      expect(redirectFor(member(), AppRoutes.login), AppRoutes.home);
      expect(redirectFor(member(), AppRoutes.register), AppRoutes.home);
    });

    test('tous les rôles arrivent à l\'accueil, client compris', () {
      // Régression : un compte `client` était renvoyé vers un écran
      // d'onboarding où le rôle ne pouvait plus être changé — le backend
      // refuse de le modifier après l'inscription, cet écran ne pouvait donc
      // que bloquer. Un client n'a rien à faire avant de pouvoir rejoindre une
      // tontine, ce qui est l'accueil.
      expect(redirectFor(member(role: 'merchant'), AppRoutes.home), isNull);
      expect(redirectFor(member(role: 'client'), AppRoutes.home), isNull);
      expect(redirectFor(member(role: 'admin'), AppRoutes.home), isNull);
    });

    test('un utilisateur absent est traité comme valide', () {
      // /login peut répondre sans le bloc `user` si le contrat change : le
      // garder hors de l'accueil l'enfermerait sur le splash de la session
      // inconnue, sans aucun moyen d'agir.
      const state = AuthState(AuthStatus.authenticated);
      expect(redirectFor(state, AppRoutes.splash), AppRoutes.home);
      expect(redirectFor(state, AppRoutes.home), isNull);
    });
  });

  group('catalogue et fiche', () {
    // `GET /tontines` et `GET /tontines/{id}` sont publics : les consulter ne
    // demande aucun jeton. Les rediriger hors session interdirait de découvrir
    // une offre avant de créer un compte — l'inverse de ce que fait le SPA web,
    // où le catalogue est visible par tous.
    test('le catalogue reste atteignable dans les deux sens', () {
      expect(redirectFor(guest(), AppRoutes.catalog), isNull);
      expect(redirectFor(member(), AppRoutes.catalog), isNull);
    });

    test('la fiche reste atteignable elle aussi', () {
      const detail = '/tontines/12';
      expect(redirectFor(guest(), detail), isNull);
      expect(redirectFor(member(), detail), isNull);
    });

    test('mais le splash et les formulaires renvoient toujours vers l\'accueil', () {
      expect(redirectFor(guest(), AppRoutes.splash), AppRoutes.login);
      expect(redirectFor(member(), AppRoutes.splash), AppRoutes.home);
    });
  });

  group('sections de l\'espace connecté', () {
    // Ces écrans exigent une session : contrairement au catalogue et à la
    // fiche, qui restent lisibles hors session. La règle doit les laisser en
    // place une fois connecté, et ne jamais y laisser un visiteur sans jeton.
    const connected = [
      AppRoutes.home,
      AppRoutes.contributions,
      AppRoutes.notifications,
      AppRoutes.profile,
      AppRoutes.myTontines,
      AppRoutes.purchases,
    ];

    test('connecté, chaque section reste en place', () {
      for (final route in connected) {
        expect(redirectFor(member(), route), isNull, reason: route);
      }
    });

    test('hors session, chaque section renvoie vers la connexion', () {
      // Régression : ces routes exigeaient une session, mais la redirection les
      // laissait en place. Après une déconnexion faite depuis un onglet,
      // l'utilisateur restait coincé sur une page dont tous les appels
      // échouaient — la déconnexion semblait ne rien faire.
      for (final route in connected) {
        expect(redirectFor(guest(), route), AppRoutes.login, reason: route);
      }
    });

    test('le parcours « mot de passe oublié » est hors session', () {
      expect(redirectFor(guest(), AppRoutes.forgotPassword), isNull);
      expect(redirectFor(member(), AppRoutes.forgotPassword), AppRoutes.home);
    });
  });

  group('espace commerçant', () {
    /// Les cinq écrans du vendeur. Ils exigent tous une session ET le rôle
    /// `merchant` : le backend les place derrière le middleware `merchant`,
    /// qui exige en plus un profil approuvé.
    ///
    /// Ils sont listés explicitement plutôt que dérivés : c'est la liste même
    /// qui doit être vérifiée, et une liste générée depuis le routeur ne
    /// prouverait que le routeur est cohérent avec lui-même.
    const merchantRoutes = [
      AppRoutes.merchant,
      AppRoutes.merchantProducts,
      AppRoutes.merchantProductCreate,
      '/commercant/produits/11/modifier',
      AppRoutes.merchantDeliveries,
    ];

    test('connecté, chaque écran reste en place', () {
      for (final route in merchantRoutes) {
        expect(redirectFor(member(), route), isNull, reason: route);
      }
    });

    test('hors session, chaque écran renvoie vers la connexion', () {
      // Sans ce cas, un visiteur déconnecté resterait sur « Mon espace » à voir
      // ses produits — tous les appels échouant, la page restant vide ou en
      // erreur, avec l'air d'un bug plutôt que d'une session close.
      for (final route in merchantRoutes) {
        expect(redirectFor(guest(), route), AppRoutes.login, reason: route);
      }
    });

    test('aucun écran commerçant n\'est public', () {
      // Le contraire de l'inventaire des sections publiques : `/commercant`
      // commence par « c », pas par `/produits`, donc le test de racine ne peut
      // pas le classer par accident. On l'affirme explicitement, car c'est ce
      // qui protège le catalogue des clients.
      for (final route in merchantRoutes) {
        expect(AppRoutes.isPublic(route), isFalse, reason: route);
      }
    });
  });
}
