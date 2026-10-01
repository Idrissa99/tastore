import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:tontine_achat_store/core/auth/auth_controller.dart';
import 'package:tontine_achat_store/core/di/providers.dart';
import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/core/network/api_client.dart';

/// Accès au profil (`GET /profil`, `PUT /profil`).
///
/// Les deux renvoient un `UserResource` **nu** : `resolve()` est utilisé côté
/// serveur, sans enveloppe `data`. La forme est celle de `UserResource`, donc
/// celle que porte déjà la session — d'où [ProfileRepository.read] qui rend le
/// même objet que `/me`, sans un second modèle à maintenir.
class ProfileRepository {
  const ProfileRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<Map<String, dynamic>> read() async {
    final response = await _api.get('/profil');

    return _unwrap(response);
  }

  /// `PUT /profil` — nom, téléphone, et éventuellement le mot de passe.
  ///
  /// Le verbe est `PUT` parce que c'est le SEUL que la route déclare
  /// (`Route::put('/profil')`) : un `PATCH` reçoit 405 et l'enregistrement du
  /// profil échoue, quel que soit le corps envoyé.
  ///
  /// Envoyé en JSON : le seul champ fichier du formulaire est l'avatar, qu'on
  /// ne propose pas encore. Le multipart ne servirait qu'à transporter une image
  /// absente, et `avatar` reste optionnel côté validation.
  ///
  /// L'e-mail, le rôle et l'état de blocage ne sont pas modifiables depuis
  /// l'API : ce formulaire ne les propose donc pas, plutôt que de proposer des
  /// champs qui seraient silencieusement ignorés.
  Future<Map<String, dynamic>> update({
    required String name,
    required String phone,
    String? currentPassword,
    String? newPassword,
  }) async {
    final response = await _api.put(
      '/profil',
      body: {
        'name': name.trim(),
        'phone': phone.trim(),
        if (newPassword != null && newPassword.isNotEmpty) ...{
          'current_password': currentPassword,
          'new_password': newPassword,
          'new_password_confirmation': newPassword,
        },
      },
    );

    return _unwrap(response);
  }
}

Map<String, dynamic> _unwrap(Object? response) {
  if (response is! Map) {
    throw const FormatException('Réponse inattendue du serveur.');
  }

  final map = Map<String, dynamic>.from(response);
  final data = map['data'];

  return data is Map ? Map<String, dynamic>.from(data) : map;
}

final profileRepositoryProvider = Provider<ProfileRepository>(
  (ref) => ProfileRepository(api: ref.watch(apiClientProvider)),
);

/// Le profil affiché, mis à jour après chaque enregistrement.
final profileProvider = FutureProvider<Map<String, dynamic>>(
  (ref) => ref.watch(profileRepositoryProvider).read(),
);

/// Vue de l'utilisateur, déduite de la réponse de profil.
///
/// L'application n'a pas de modèle `User` : `AuthState.user` et `/profil`
/// transportent le même `UserResource`. Cette classe ne fait que **lire** des
/// champs, ce qui évite d'introduire une troisième représentation de la même
/// donnée.
class ProfileView {
  const ProfileView(this.raw);

  factory ProfileView.of(Map<String, dynamic> raw) => ProfileView(raw);

  final Map<String, dynamic> raw;

  String get name => raw['name']?.toString() ?? '';

  String get email => raw['email']?.toString() ?? '';

  String get phone => raw['phone']?.toString() ?? '';

  String get role => raw['role']?.toString() ?? '';

  bool get isVerified => raw['is_verified'] != false;

  DateTime? get createdAt => Fmt.parseDate(raw['created_at']);

  /// Nom de commerce, pour un compte commerçant.
  String get businessName {
    final merchant = raw['merchant'];
    if (merchant is! Map) return '';

    return merchant['business_name']?.toString() ?? '';
  }

  String get merchantStatus {
    final merchant = raw['merchant'];
    if (merchant is! Map) return '';

    return merchant['status']?.toString() ?? '';
  }
}

/// Applique une réponse de profil à la session, pour que l'accueil et le
/// profil ne divergent pas.
void applyProfileToSession(WidgetRef ref, Map<String, dynamic> user) {
  ref.read(authControllerProvider.notifier).applyUser(user);
}
