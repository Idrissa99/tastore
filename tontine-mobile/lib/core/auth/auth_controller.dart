import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:tontine_achat_store/core/di/providers.dart';
import 'package:tontine_achat_store/core/network/api_exception.dart';
import 'package:tontine_achat_store/features/payments/reminder_service.dart';

/// Où en est la session au démarrage.
enum AuthStatus {
  /// Le jeton chiffré est en cours de lecture : l'app ne doit encore rien
  /// décider, sinon elle affiche brièvement la connexion à un utilisateur déjà
  /// connecté.
  unknown,

  /// Un jeton est présent dans le keystore et l'API l'a confirmé.
  authenticated,

  /// Aucun jeton, ou un jeton que l'API a rejeté.
  unauthenticated,
}

/// État de la session, seule source de vérité pour le routeur.
class AuthState {
  const AuthState(this.status, {this.user});

  final AuthStatus status;

  /// Utilisateur renvoyé par `GET /me` ou `POST /login`, tel que le backend
  /// le sérialise (voir UserResource). Conservé tel quel plutôt que converti
  /// en modèle : tant qu'aucun écran ne consomme un champ, un modèle
  ///Intermediate serait du code à maintenir dans le vide.
  final Map<String, dynamic>? user;

  bool get isAuthenticated => status == AuthStatus.authenticated;

  /// L'adresse e-mail a-t-elle été validée par un administrateur ?
  ///
  /// Ce n'est pas un détail : les routes de versement sont derrière le
  /// middleware `verified`. Un compte non vérifié reçoit donc 403 sur TOUT
  /// paiement, et l'application doit pouvoir le dire AVANT que l'utilisateur ne
  /// tente — et lui indiquer comment résoudre le blocage.
  ///
  /// Une session sans champ `is_verified` est traitée comme vérifiée : c'est le
  /// cas par défaut d'un administrateur et d'une réponse sans le bloc `user`.
  /// L'inverse ferait afficher un avertissement à quelqu'un qui n'en a pas.
  bool get isEmailVerified => user?['is_verified'] != false;
}

class AuthController extends StateNotifier<AuthState> {
  AuthController(this._ref) : super(const AuthState(AuthStatus.unknown));

  final Ref _ref;

  /// Vérifie la session persistée au démarrage.
  ///
  /// Un jeton présent ne prouve PAS que la session est encore valide : il a
  /// pu être révoqué (déconnexion sur un autre appareil, blocage du compte).
  /// On le vérifie donc auprès de l'API avant d'ouvrir l'application, et on ne
  /// se fie qu'à la réponse du serveur.
  Future<void> bootstrap() async {
    final token = await _ref.read(tokenStoreProvider).read();

    if (token == null || token.isEmpty) {
      state = const AuthState(AuthStatus.unauthenticated);
      return;
    }

    try {
      final response = await _ref.read(apiClientProvider).get('/me');
      state = AuthState(
        AuthStatus.authenticated,
        user: response is Map<String, dynamic> ? response : null,
      );
    } on ApiException catch (error) {
      // 401 et 403 sont traités PAR LE CLIENT HTTP lui-même (effacement du
      // jeton). Une erreur réseau, en revanche, ne doit PAS être confondue avec
      // une déconnexion : on garde le jeton et on laisse l'utilisateur
      // réessayer plutôt que de lui faire croire que sa session a pris fin.
      if (error.isNetwork) {
        state = const AuthState(AuthStatus.unknown);
        return;
      }

      state = const AuthState(AuthStatus.unauthenticated);
    }
  }

  /// `POST /login`, puis stockage du jeton.
  Future<void> signIn({required String email, required String password}) async {
    final response = await _ref.read(apiClientProvider).post(
      '/login',
      body: {'email': email.trim(), 'password': password},
    );

    await _openSession(response);
  }

  /// `POST /register`, puis stockage du jeton.
  ///
  /// Le rôle est ici un paramètre, et non une étape ultérieure : c'est
  /// `RegisterRequest` qui l'exige (`in:client,merchant`) et `ProfileController`
  /// refuse ensuite de le modifier. Un écran « choisis ton rôle » après la
  /// connexion n'aurait donc rien à écrire sur le serveur — il faut le demander
  /// au moment où l'API accepte encore de le décider.
  Future<void> register({
    required String name,
    required String email,
    required String phone,
    required String password,
    required String passwordConfirmation,
    required String role,
  }) async {
    final response = await _ref.read(apiClientProvider).post(
      '/register',
      body: {
        'name': name.trim(),
        'email': email.trim(),
        'phone': phone.trim(),
        'password': password,
        'password_confirmation': passwordConfirmation,
        'role': role,
      },
    );

    await _openSession(response);
  }

  /// Ouvre la session à partir d'une réponse `{ "token": …, "user": … }`.
  ///
  /// `/login` et `/register` rendent la même chose, et leur traitement doit
  /// être rigoureusement le même : c'est factorisé ici plutôt que dupliqué, car
  /// c'est dans ces quelques lignes que se joue la persistance du jeton.
  ///
  /// Le client HTTP NE persiste rien : `ApiClient` reçoit bien une fonction
  /// `writeToken`, mais il ne l'appelle nulle part — la seule réponse qui porte
  /// un jeton est celle du login ou de l'inscription, c'est donc à cet endroit,
  /// et à cet endroit seulement, qu'on l'écrit.
  ///
  /// L'ordre compte : le jeton est écrit AVANT de passer l'état à
  /// `authenticated`. Si l'écriture échoue (keystore indisponible sur un
  /// appareil compromis, TokenStore avale l'erreur et retombe en mémoire), on
  /// déclare quand même la session ouverte — l'utilisateur reste connecté
  /// jusqu'à la fermeture de l'app, ce qui vaut mieux qu'un échec silencieux
  /// qui le renverrait aussitôt vers la connexion.
  Future<void> _openSession(dynamic response) async {
    final map = response is Map<String, dynamic> ? response : const <String, dynamic>{};
    final token = map['token'];
    final user = map['user'];

    if (token is! String || token.isEmpty) {
      // Le backend a renvoyé 2xx sans jeton : contrat rompu. Mieux vaut un
      // échec explicite qu'une session ouverte sans moyen de s'authentifier.
      throw const ApiException(
        message: 'Le serveur a accepté la demande mais n\'a renvoyé aucun jeton.',
        statusCode: 500,
      );
    }

    await _ref.read(tokenStoreProvider).write(token);

    state = AuthState(
      AuthStatus.authenticated,
      user: user is Map<String, dynamic> ? user : null,
    );
  }

  Future<void> signOut() async {
    // On efface le jeton même si l'appel réseau échoue : une déconnexion
    // demandée par l'utilisateur ne doit pas dépendre de la disponibilité du
    // serveur, sinon il resterait connecté sur son appareil malgré son clic.
    try {
      await _ref.read(apiClientProvider).post('/logout');
    } on ApiException {
      // Ignoré volontairement : voir ci-dessus. Le jeton local part dans tous
      // les cas, l'API finira par le purger de son côté.
    }

    await _ref.read(tokenStoreProvider).clear();

    // Les rappels sont rattachés à la session : sans cette annulation,
    // l'application continuerait de relancer un utilisateur déconnecté, dont
    // les cotisations ont pu être réglées depuis un autre appareil.
    await _ref.read(paymentReminderServiceProvider).clear();

    state = const AuthState(AuthStatus.unauthenticated);
  }

  /// Remplace l'utilisateur de la session après une mise à jour de profil.
  ///
  /// La ressource de profil renvoie l'utilisateur à jour : le réinjecter ici
  /// évite de dupliquer l'état entre le contrôleur d'authentification et l'écran
  /// de profil, qui afficherait le nouveau nom pendant que l'accueil montrerait
  /// encore l'ancien.
  void applyUser(Map<String, dynamic> user) {
    if (state.status != AuthStatus.authenticated) return;

    state = AuthState(AuthStatus.authenticated, user: user);
  }

  /// `POST /email/verification-notification` — renvoie le lien de validation.
  ///
  /// L'API ne renvoie pas d'e-mail : elle en déclenche l'envoi et répond par un
  /// message. On le relaie tel quel plutôt que d'inventer une confirmation.
  Future<void> resendVerificationEmail() async {
    await _ref.read(apiClientProvider).post('/email/verification-notification');
  }

  /// Appelé quand le client HTTP a reçu un 401 sur une route métier : la
  /// session a expiré, il faut renvoyer vers la connexion.
  void onSessionExpired() {
    if (state.status == AuthStatus.authenticated) {
      state = const AuthState(AuthStatus.unauthenticated);
    }
  }
}

final authControllerProvider = StateNotifierProvider<AuthController, AuthState>(
  AuthController.new,
);
