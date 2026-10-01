import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Persistance du jeton Sanctum.
///
/// Le jeton est un secret d'authentification : il est stocké dans le
/// keystore Android (chiffré au repos par le hardware quand disponible)
/// ou le Keychain iOS, et **jamais** en `SharedPreferences`, qui est un
/// simple fichier lisible sur un appareil non rooté.
class TokenStore {
  TokenStore({FlutterSecureStorage? storage})
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
              iOptions: IOSOptions(
                accessibility: KeychainAccessibility.first_unlock_this_device,
              ),
            );

  static const _tokenKey = 'tontine_token';

  final FlutterSecureStorage _storage;

  Future<String?> read() async {
    try {
      return await _storage.read(key: _tokenKey);
    } catch (_) {
      // Stockage indisponible (appareil compromis, rooted) : on continue
      // sans persistance plutôt que de planter au démarrage.
      return null;
    }
  }

  Future<void> write(String token) async {
    try {
      await _storage.write(key: _tokenKey, value: token);
    } catch (_) {
      // Session en mémoire uniquement : l'utilisateur devra se reconnecter
      // au redémarrage, ce qui est préférable à un échec silencieux.
    }
  }

  Future<void> clear() async {
    try {
      await _storage.delete(key: _tokenKey);
    } catch (_) {
      // Rien à faire : le jeton sera de toute façon ignoré s'il est illisible.
    }
  }
}
