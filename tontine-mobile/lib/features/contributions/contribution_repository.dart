import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/core/network/api_client.dart';
import 'package:tontine_achat_store/features/contributions/contribution.dart';

/// Accès aux cotisations de l'utilisateur connecté.
///
/// **Le contrat de cette ressource est différent de tous les autres.** Les
/// contrôleurs rendent `ContributionResource::…->resolve($request)`, ce qui
/// produit le JSON DÉBALlé : `GET /contributions` répond un tableau nu et
/// `pay` / `soumettre-code` un objet nu. Ailleurs, l'API enveloppe sous
/// `{"data": …}`.
///
/// C'est exactement le piège qui a fait afficher une fiche de tontine vide :
/// appliquer uniformément la même décompression ici renverrait un objet sans
/// aucun champ, et la liste paraîtrait vide sans erreur. Les deux formes sont
/// donc acceptées, l'enveloppe étant déballée quand elle est présente.
class ContributionRepository {
  const ContributionRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  /// `GET /contributions` — l'historique complet, pas paginé côté serveur.
  Future<List<Contribution>> index() async {
    final response = await _api.get('/contributions');

    // Le serveur renvoie `[...]`. Une enveloppe `{"data": [...]}` est également
    // acceptée : elle reste le format de toutes les autres ressources, et un
    // simple ajustement côté serveur ne doit pas vider l'écran.
    if (response is List) return _parseAll(response);

    if (response is Map) {
      final map = Map<String, dynamic>.from(response);
      final data = map['data'];
      if (data is List) return _parseAll(data);
    }

    return const [];
  }

  /// `POST /contributions/{id}/pay` — canal à validation directe.
  ///
  /// Réservé aux canaux NON manuels : le serveur répond 422 sur MyNita et
  /// Amana, qui exigent [submitTransferCode].
  Future<Contribution> pay(
    int contributionId, {
    required String channel,
    String? reference,
  }) async {
    final response = await _api.post(
      '/contributions/$contributionId/pay',
      body: {
        'payment_method': channel,
        if (reference != null && reference.isNotEmpty) 'reference': reference,
      },
    );

    return Contribution.fromJson(_unwrap(response));
  }

  /// `POST /contributions/{id}/soumettre-code` — MyNita, Amana.
  Future<Contribution> submitTransferCode(
    int contributionId, {
    required String channel,
    required String transferCode,
  }) async {
    final response = await _api.post(
      '/contributions/$contributionId/soumettre-code',
      body: {'payment_method': channel, 'transfer_code': transferCode},
    );

    return Contribution.fromJson(_unwrap(response));
  }

  List<Contribution> _parseAll(List<dynamic> raw) {
    return raw
        .whereType<Map>()
        .map((item) => Contribution.fromJson(Map<String, dynamic>.from(item)))
        .toList(growable: false);
  }
}

/// Déballe une réponse de contribution, enveloppe présente ou non.
Map<String, dynamic> _unwrap(Object? response) {
  if (response is! Map) {
    throw const FormatException('Réponse inattendue du serveur.');
  }

  final map = Map<String, dynamic>.from(response);
  final data = map['data'];

  return data is Map ? Map<String, dynamic>.from(data) : map;
}

/// Reference normalisée : `Fmt.normalizeTransferCode` retire tout sauf lettres
/// et chiffres, et met en majuscules — un code d agencies comme d'un code
/// d'un agent doivent passer tous deux.
String normalizeTransferCode(String? value) => Fmt.normalizeTransferCode(value);
