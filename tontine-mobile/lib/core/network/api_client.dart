import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../config/app_config.dart';
import 'api_exception.dart';

/// Signature appelée quand le serveur répond 401 hors écran de connexion.
///
/// Le client HTTP ne navigue pas lui-même : il notifie, et c'est le routeur
/// de l'application qui décide de rediriger. Ce découplage évite que la
/// couche réseau connaisse l'interface.
typedef UnauthorizedCallback = void Function();

/// Un fichier à joindre en multipart.
///
/// `http.MultipartFile.fromPath` est asynchrone ; on garde ici seulement
/// la description du fichier et on le lit au moment de l'envoi.
class FilePart {
  const FilePart({
    required this.path,
    this.field = 'images[]',
  });

  final String path;
  final String field;

  Future<http.MultipartFile> toMultipartFile() async {
    final file = await http.MultipartFile.fromPath(field, path);
    return file;
  }
}

/// Client HTTP typé au-dessus de l'API Laravel.
///
/// Comportement porté à l'identique du frontend web, parce qu'il est adapté
/// aux conventions du backend :
///
///  - jeton Bearer injecté automatiquement ;
///  - 401 → jeton effacé + notification, puis exception ;
///  - 403 → **ne** déconnecte jamais (un simple manque de droit n'est pas
///    une session invalide) ;
///  - aucune relance automatique : une relance sur erreur réseau créerait
///    une boucle infinie ;
///  - multipart via `MultipartRequest` (et non `Request` + `Content-Type`
///    forcé), la frontière et son `boundary` restant gérés par la
///    bibliothèque.
class ApiClient {
  ApiClient({
    http.Client? httpClient,
    String? baseUrl,
    required this.readToken,
    required this.writeToken,
    required this.clearToken,
  })  : _http = httpClient ?? http.Client(),
        _baseUrl = baseUrl ?? _resolveBaseUrl();

  /// Résout l'URL de l'API en vérifiant le schéma AVANT tout appel réseau.
  ///
  /// Élevé en factory pour que l'erreur soit remontée au premier écran plutôt
  /// qu'à la première requête : sans cela, une URL `http://` dans un APK
  /// compilé avec `ALLOW_INSECURE=false` se manifeste par une
  /// SocketException muette, indiscernable d'un téléphone hors ligne.
  static String _resolveBaseUrl() {
    final url = AppConfig.apiBaseUrl;
    AppConfig.assertTransportAllowed(url);
    return url;
  }

  final http.Client _http;
  final String _baseUrl;

  /// Lit le jeton persistant (voir `TokenStore`).
  final Future<String?> Function() readToken;

  /// Écrit un nouveau jeton (connexion, inscription).
  final Future<void> Function(String token) writeToken;

  /// Efface le jeton (déconnexion, 401, compte bloqué).
  final Future<void> Function() clearToken;

  UnauthorizedCallback? onUnauthorized;

  /// Chemins où un 401 est attendu et ne doit pas fermer la session,
  /// sinon l'écran de connexion se boucle tout seul.
  static const Set<String> _authPaths = {
    '/login',
    '/register',
    '/forgot-password',
    '/reset-password',
  };

  String get baseUrl => _baseUrl;

  void dispose() => _http.close();

  /* ------------------------------------------------------------------ */
  /* Verbes                                                              */
  /* ------------------------------------------------------------------ */

  Future<dynamic> get(String path, {Map<String, dynamic>? query}) =>
      _send('GET', path, query: query);

  Future<dynamic> post(String path, {Object? body, Map<String, dynamic>? query}) =>
      _send('POST', path, body: body, query: query);

  Future<dynamic> put(String path, {Object? body}) => _send('PUT', path, body: body);

  Future<dynamic> patch(String path, {Object? body}) => _send('PATCH', path, body: body);

  Future<dynamic> delete(String path) => _send('DELETE', path);

  Future<dynamic> postMultipart(String path, Map<String, dynamic> fields) =>
      _send('POST', path, body: fields, isMultipart: true);

  /// `PUT` via multipart, avec un `_method=PUT` de couverture.
  ///
  /// Réservé aux requêtes qui portent un fichier. Laravel décode le PUT
  /// multipart sans en avoir besoin, mais tous les clients HTTP et tous les
  /// proxys ne le transmettent pas fidèlement : on envoie donc la requête en
  /// POST, avec `_method` — le mécanisme « spoofi » de Symfony, que Laravel
  /// réinterprète avant le routage.
  Future<dynamic> putMultipart(String path, Map<String, dynamic> fields) {
    return _send('POST', path, body: {...fields, '_method': 'PUT'}, isMultipart: true);
  }

  /* ------------------------------------------------------------------ */
  /* Cœur                                                                */
  /* ------------------------------------------------------------------ */

  Future<dynamic> _send(
    String method,
    String path, {
    Object? body,
    Map<String, dynamic>? query,
    bool isMultipart = false,
  }) async {
    final uri = _buildUri(path, query);
    final token = await readToken();

    final headers = <String, String>{'Accept': 'application/json'};
    if (!isMultipart) headers['Content-Type'] = 'application/json';
    if (token != null && token.isNotEmpty) {
      headers['Authorization'] = 'Bearer $token';
    }

    http.Response response;
    try {
      final streamed = isMultipart
          ? await _sendMultipart(method, uri, headers, body as Map<String, dynamic>)
          : await _http.send(_buildRequest(method, uri, headers, body));
      response = await http.Response.fromStream(streamed).timeout(AppConfig.receiveTimeout);
    } on TimeoutException {
      throw const ApiException(
        message: 'Le serveur met trop de temps à répondre. Réessaie dans un instant.',
      );
    } catch (error) {
      throw toApiException(error);
    }

    // Pas d'await explicite : dans une fonction async, retourne une Future
    // l'attend implicitement, et une exception qu'elle porte se propage
    // quand même sur le future de _send.
    return _handleResponse(response, path);
  }

  http.Request _buildRequest(
    String method,
    Uri uri,
    Map<String, String> headers,
    Object? body,
  ) {
    final request = http.Request(method, uri)..headers.addAll(headers);
    if (body != null) request.body = jsonEncode(body);
    return request;
  }

  Future<http.StreamedResponse> _sendMultipart(
    String method,
    Uri uri,
    Map<String, String> headers,
    Map<String, dynamic> fields,
  ) async {
    final request = http.MultipartRequest(method, uri)
      ..headers.addAll(headers)
      ..followRedirects = false;

    for (final entry in fields.entries) {
      final value = entry.value;
      if (value == null) continue;

      if (value is FilePart) {
        request.files.add(await value.toMultipartFile());
      } else if (value is List) {
        for (final item in value) {
          if (item is FilePart) request.files.add(await item.toMultipartFile());
        }
      } else {
        request.fields[entry.key] = value.toString();
      }
    }

    return request.send().timeout(AppConfig.connectTimeout);
  }

  Uri _buildUri(String path, Map<String, dynamic>? query) {
    final normalized = path.startsWith('/') ? path : '/$path';
    final base = Uri.parse('$_baseUrl$normalized');

    if (query == null || query.isEmpty) return base;

    // On retire les valeurs nulles ou vides : sans cela, une recherche non
    // renseignée deviendrait « ?q= » et compterait comme un critère réel.
    final cleaned = <String, String>{};
    query.forEach((key, value) {
      if (value == null) return;
      final text = value.toString();
      if (text.isEmpty) return;
      cleaned[key] = text;
    });

    return base.replace(queryParameters: {...base.queryParameters, ...cleaned});
  }

  Future<dynamic> _handleResponse(http.Response response, String path) async {
    if (response.statusCode == 401) {
      if (!_authPaths.contains(path)) {
        // Le jeton est expiré, révoqué ou absent : on le supprime pour ne plus
        // renvoyer un jeton mort, puis on prévient l'application.
        //
        // Cette méthode est `async` et attend `clearToken` pour une raison
        // précise : `clearToken` renvoie une Future, et depuis une méthode
        // synchrone elle partait en future flottante. Elle pouvait n'être pas
        // terminée quand l'application affichait déjà l'écran de connexion,
        // laissant le jeton mort dans le keystore jusqu'au prochain démarrage.
        //
        // Sur `/login`, à l'inverse, un 401 signifie de simples identifiants
        // erronés : il ne doit PAS effacer une session valide.
        await clearToken();
        onUnauthorized?.call();
      }
      throw const ApiException(
        message: 'Session expirée ou invalide. Reconnecte-toi.',
        statusCode: 401,
      );
    }

    if (response.statusCode == 204 || response.body.isEmpty) return null;

    dynamic data;
    try {
      data = jsonDecode(response.body);
    } on FormatException {
      if (response.statusCode >= 200 && response.statusCode < 300) return null;
      throw ApiException(
        message: 'Réponse illisible du serveur.',
        statusCode: response.statusCode,
      );
    }

    if (response.statusCode >= 200 && response.statusCode < 300) return data;

    final map = data is Map<String, dynamic> ? data : const <String, dynamic>{};

    final message = switch (response.statusCode) {
      403 => map['message'] ?? "Tu n'as pas les droits nécessaires pour cette action.",
      _ => map['message'] ?? 'Une erreur est survenue.',
    };

    final rawErrors = map['errors'];
    final errors = <String, List<String>>{};
    if (rawErrors is Map) {
      rawErrors.forEach((key, value) {
        if (value is List) {
          errors[key.toString()] = value.map((item) => item.toString()).toList();
        }
      });
    }

    throw ApiException(
      message: message.toString(),
      statusCode: response.statusCode,
      validationErrors: errors,
    );
  }
}
