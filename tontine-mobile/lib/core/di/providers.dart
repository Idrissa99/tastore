import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:tontine_achat_store/core/network/api_client.dart';
import 'package:tontine_achat_store/core/storage/token_store.dart';

/// Racine d'injection de dépendances.
///
/// Le client HTTP et le stockage du jeton étaient écrits et documentés depuis le
/// début mais n'étaient reliés à AUCUN appelant : ils ne servaient à rien tant
/// qu'un point de composition unique ne les instancie pas. C'est ici.
///
/// Un seul `ApiClient` vit pendant toute la session applicative : le recreer
/// à chaque écran viderait le `http.Client` sous ses pieds et perdrait le
/// keep-alive des connexions.
///
/// Le [TokenStore] n'a pas de `dispose()` : `flutter_secure_storage` ne tient
/// aucun ressources à libérer.
final tokenStoreProvider = Provider<TokenStore>((ref) => TokenStore());

/// [ApiClient] reçoit ses trois accès au jeton depuis le [TokenStore] : il ne
/// connaît rien du stockage, il sait seulement lire, écrire et effacer.
final apiClientProvider = Provider<ApiClient>((ref) {
  final store = ref.watch(tokenStoreProvider);

  final client = ApiClient(
    readToken: store.read,
    writeToken: store.write,
    clearToken: store.clear,
  );

  ref.onDispose(client.dispose);
  return client;
});
