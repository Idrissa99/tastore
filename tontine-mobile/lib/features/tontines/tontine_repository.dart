import 'package:tontine_achat_store/core/network/api_client.dart';
import 'package:tontine_achat_store/features/tontines/tontine.dart';

/// Accès aux tontines.
///
/// Les trois appels dont l'application a besoin pour le parcours d'adhésion,
/// et rien d'autre : la création d'une tontine et son édition appartiennent à
/// l'espace commerçant, qui n'existe pas encore côté mobile.
///
/// Le [currentUserId] est un PARAMÈTRE et non une lecture d'état. C'est
/// volontairement le cas que l'API laisse au client : `TontineResource`
/// calcule bien `is_member` à partir de l'utilisateur authentifié, mais la
/// liste des membres ne dit pas lequel est « moi ». Sans cet identifiant, la
/// ligne personnelle de l'utilisateur serait indiscernable sur la fiche.
class TontineRepository {
  const TontineRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  /// Le catalogue public, paginé par le serveur.
  ///
  /// [availableOnly] est le seul filtre que l'API accepte : il ne renvoie que
  /// les tontines « open ». Tous les autres (type, fréquence, montant,
  /// recherche) sont appliqués ensuite côté client, sur les pages déjà
  /// téléchargées — l'API n'expose aucun paramètre pour eux.
  Future<PagedResult<Tontine>> catalog({
    int page = 1,
    bool availableOnly = false,
    int? currentUserId,
  }) async {
    final response = await _api.get(
      '/tontines',
      query: {'page': page, if (availableOnly) 'available': 1},
    );

    return PagedResult.fromLaravel(
      response,
      (json) => Tontine.fromJson(json, currentUserId: currentUserId),
    );
  }

  /// La fiche d'une tontine. Accessible sans session : c'est ce qui permet de
  /// consulter une offre avant de se connecter.
  Future<Tontine> detail(int id, {int? currentUserId}) async {
    final response = await _api.get('/tontines/$id');

    return Tontine.fromJson(
      _asResource(response),
      currentUserId: currentUserId,
    );
  }

  /// `POST /tontines/{id}/join` — l'adhésion.
  ///
  /// Le serveur renvoie la tontine mise à jour, pas un simple « 204 » : la
  /// réponse est donc réutilisée telle quelle pour rafraîchir la fiche, et l'on
  /// évite un aller-retour supplémentaire qui pourrait rendre le nombre de
  /// membres faux à l'écran.
  Future<Tontine> join(int id, {int? currentUserId}) async {
    final response = await _api.post('/tontines/$id/join');

    return Tontine.fromJson(
      _asResource(response),
      currentUserId: currentUserId,
    );
  }
}

/// Déballe la réponse d'une ressource unique.
///
/// Laravel sérialise un `JsonResource` sous `{"data": {...}}` : c'est le cas de
/// `GET /tontines/{id}` comme de `POST /tontines/{id}/join`. En revanche, le
/// paginateur de `GET /tontines` utilise le même nom `data` pour sa PROPRE
/// liste, et ses éléments ne sont pas enveloppés individuellement — les deux
/// cas relèvent de leurs appelants.
///
/// La décompression se fait AVANT toute lecture de champ : conserver
/// l'enveloppe ferait trouver chaque champ absent, et la fiche s'afficherait
/// vide — sans exception, sans erreur, donc sans le moindre signe permettant
/// de comprendre la panne.
Map<String, dynamic> _asResource(Object? response) {
  if (response is! Map) {
    throw const FormatException('Réponse inattendue du serveur.');
  }

  final map = Map<String, dynamic>.from(response);
  final data = map['data'];

  return data is Map ? Map<String, dynamic>.from(data) : map;
}
