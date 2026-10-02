/// Configuration applicative.
///
/// L'URL de l'API est fournie à la compilation via `--dart-define` :
///
/// ```
/// # émulateur Android (alias de la machine hôte)
/// flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api
///
/// # téléphone physique sur le même réseau Wi-Fi
/// flutter run --dart-define=API_BASE_URL=http://192.168.1.20:8000/api
///
/// # iOS simulator
/// flutter run --dart-define=API_BASE_URL=http://127.0.0.1:8000/api
/// ```
///
/// # APK de test déployé (API distante HTTPS)
/// flutter build apk --release \
///   --dart-define=API_BASE_URL=https://ton-api.onrender.com/api \
///   --dart-define=ALLOW_INSECURE=false
///
/// Sans `--dart-define`, on retombe sur la valeur la plus probable :
/// `10.0.2.2` sous Android (où `127.0.0.1` désignerait l'émulateur
/// lui-même), `127.0.0.1` ailleurs.
library;

import 'dart:io';

class AppConfig {
  const AppConfig._();

  static const String _defaultValue = '';

  /// URL de base de l'API Laravel, sans barre oblique finale.
  static String get apiBaseUrl {
    const fromEnv = String.fromEnvironment('API_BASE_URL', defaultValue: _defaultValue);
    if (fromEnv.isNotEmpty) return _stripTrailingSlash(fromEnv);
    return _stripTrailingSlash(
      Platform.isAndroid ? 'http://10.0.2.2:8000/api' : 'http://127.0.0.1:8000/api',
    );
  }

  /// Origine du backend, déduite de l'URL de l'API.
  ///
  /// Les médias produits (photos, vidéos) sont servis par Laravel sur
  /// `/storage/...` avec une URL RELATIVE. Il faut la préfixer par cette
  /// origine, sinon `<img>` chercherait le fichier sur le serveur de
  /// l'application mobile et rien ne s'afficherait.
  static String get backendOrigin {
    final base = Uri.parse(apiBaseUrl);
    return '${base.scheme}://${base.authority}';
  }

  static String _stripTrailingSlash(String value) =>
      value.endsWith('/') ? value.substring(0, value.length - 1) : value;

  /// Autorise le HTTP en clair. Nécessaire en développement local, où le
  /// backend tourne sur le poste en http://.
  ///
  /// Un APK de test déployé doit passer par HTTPS : on compile donc avec
  /// `--dart-define=ALLOW_INSECURE=false`, et le garde-fou ci-dessous lève
  /// une exception au PREMIER accès réseau plutôt que de laisser l'utilisateur
  /// watching un écran d'erreur « pas de connexion » caused by a http:// URL
  /// rejetée en silence par Android.
  static const bool allowInsecureTransport = bool.fromEnvironment(
    'ALLOW_INSECURE',
    defaultValue: true,
  );

  /// Refuse une URL API non-HTTPS quand le transport en clair est interdit.
  ///
  /// `android/app/src/main/res/xml/network_security_config.xml` bloque déjà
  /// le HTTP en clair pour tout hôte hors de la liste de développement, mais
  /// un échec de ce type se manifeste par une SocketException sans message
  /// exploitable. Cette vérification le transforme en cause claire.
  static void assertTransportAllowed(String url) {
    if (allowInsecureTransport) return;

    final uri = Uri.tryParse(url);
    if (uri == null) {
      throw StateError(
        'API_BASE_URL est illisible : « $url ». Attendu : '
        'https://ton-api.example.com/api',
      );
    }

    if (uri.scheme != 'https') {
      throw StateError(
        'ALLOW_INSECURE=false impose HTTPS, mais API_BASE_URL vaut « $url ». '
        'Reconstruis l\'APK avec une URL https://, ou passe '
        '--dart-define=ALLOW_INSECURE=true pour un test en local.',
      );
    }
  }

  static const Duration connectTimeout = Duration(seconds: 20);
  static const Duration receiveTimeout = Duration(seconds: 30);

  /// Taille de page par défaut, alignée sur le backend
  /// (`GET /tontines` et `GET /produits` paginent à 12).
  static const int pageSize = 12;

  /// Intervalle entre deux resynchronisations automatiques, application au
  /// premier plan.
  ///
  /// Une minute : c'est la valeur à laquelle on cesse de s'attendre à ce qu'un
  /// versement validé côté web apparaisse « tout seul », sans que l'utilisateur
  /// ait à secouer l'écran. En dessous, ce serait une requête par utilisateur
  /// et par minute pour une information qui change quelques fois par jour — le
  /// coût est réel en data, le bénéfice non.
  static const Duration syncInterval = Duration(minutes: 1);

  /// Garde-fou lors du chargement d'un ensemble : le filtrage detailed se
  /// fait ensuite côté client, sans télécharger toute la base.
  static const int maxPages = 6;

  static const String appName = 'Tontine Achat Store';

  /// Avertissement affiché au développeur si l'URL n'a pas été fournie
  /// et qu'on tourne sur un appareil physique.
  static bool get looksLikeEmulatorAlias =>
      Platform.isAndroid && !const String.fromEnvironment('API_BASE_URL').isNotEmpty;
}
