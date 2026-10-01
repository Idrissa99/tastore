import 'dart:io';

/// Erreur renvoyée par l'API, déjà normalisée.
///
/// Le client HTTP ne laisse jamais remonter de `SocketException` ou de
/// `FormatException` brute : chaque échec sort par cette classe, pour que
/// l'interface puisse afficher un message compréhensible en français.
class ApiException implements Exception {
  const ApiException({
    required this.message,
    this.statusCode = 0,
    this.validationErrors = const {},
  });

  /// Message déjà rédigé pour l'utilisateur.
  final String message;

  /// Code HTTP. 0 signifie « le serveur n'a pas répondu du tout »
  /// (DNS, Wi-Fi coupé, serveur arrêté, délai dépassé).
  final int statusCode;

  /// Erreurs de validation Laravel (422), indexées par champ.
  ///
  /// L'API renvoie `{ "message": "...", "errors": { "email": ["..."] } }`.
  final Map<String, List<String>> validationErrors;

  /// 401 : session absente, expirée ou révoquée → renvoyer vers la connexion.
  bool get isUnauthorized => statusCode == 401;

  /// 403 : authentifié mais action interdite. Déconnecter serait une erreur.
  bool get isForbidden => statusCode == 403;

  /// 404 : ressource inexistante.
  bool get isNotFound => statusCode == 404;

  /// 409 : conflit métier (versement déjà payé, tontine complète…).
  bool get isConflict => statusCode == 409;

  /// 422 : données refusées par la validation.
  bool get isValidation => statusCode == 422;

  /// Le serveur est injoignable (réseau coupé, serveur arrêté, timeout).
  bool get isNetwork => statusCode == 0;

  /// Erreur associée à un champ précis, première occurrence.
  String? fieldError(String field) {
    final errors = validationErrors[field];
    if (errors == null || errors.isEmpty) return null;
    return errors.first;
  }

  @override
  String toString() => 'ApiException($statusCode): $message';
}

/// Traduit une exception réseau brute en message utilisateur.
ApiException toApiException(Object error) {
  if (error is ApiException) return error;

  if (error is SocketException) {
    return const ApiException(
      message: 'Impossible de joindre le serveur. Vérifie ta connexion internet.',
    );
  }

  if (error is HttpException) {
    return const ApiException(
      message: 'Le serveur a renvoyé une réponse invalide.',
    );
  }

  if (error is FormatException) {
    return const ApiException(
      message: 'Réponse inattendue du serveur.',
    );
  }

  return const ApiException(message: 'Une erreur inattendue est survenue.');
}
