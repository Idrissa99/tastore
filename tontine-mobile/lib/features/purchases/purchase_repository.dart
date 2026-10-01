import 'package:tontine_achat_store/core/network/api_client.dart';
import 'package:tontine_achat_store/features/tontines/tontine.dart';
import 'package:tontine_achat_store/features/purchases/installment_purchase.dart';

/// Catalogue produits et achats en tranches.
///
/// **Les enveloppes diffèrent d'un bout à l'autre de cette ressource**, et les
/// confondre a déjà coûté une fiche vide :
///  - `GET /produits` et `GET /mes-achats` : ressource COLLECTION, donc
///    `{"data": [...]}`, sans paginateur pour les achats ;
///  - `GET /produits/{id}`, `POST /produits/{id}/tranches`,
///    `POST /tranches/{id}/…` : ressource unique, donc `{"data": {...}}` ;
///  - `POST /produits/{id}/tranches/apercu` : `response()->json()` nu, sans
///    aucune enveloppe.
class PurchaseRepository {
  const PurchaseRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  /// `GET /produits` — paginé par 12 côté serveur, avec recherche `q` et
  /// filtre `category`, que le serveur sait appliquer (contrairement aux
  /// tontines).
  Future<PagedResult<Product>> products({
    int page = 1,
    String? query,
    String? category,
  }) async {
    final response = await _api.get(
      '/produits',
      query: {'page': page, if (query != null) 'q': query, if (category != null) 'category': category},
    );

    return PagedResult.fromLaravel(response, Product.fromJson);
  }

  Future<Product> product(int id) async {
    final response = await _api.get('/produits/$id');

    return Product.fromJson(_unwrap(response));
  }

  /// `POST /produits/{id}/tranches/apercu` — le calcul avant engagement.
  ///
  /// Appelé AVANT toute création : c'est ce qui permet d'afficher le montant
  /// réel d'une tranche, commission comprise, plutôt qu'une division du prix
  /// affiché qui serait fausse.
  Future<InstallmentPreview> preview(int productId, {required int installmentsCount}) async {
    final response = await _api.post(
      '/produits/$productId/tranches/apercu',
      body: {'installments_count': installmentsCount},
    );

    if (response is! Map) {
      throw const FormatException('Réponse inattendue du serveur.');
    }

    return InstallmentPreview.fromJson(Map<String, dynamic>.from(response));
  }

  /// `POST /produits/{id}/tranches` — crée l'achat et ses tranches.
  Future<InstallmentPurchase> createPurchase(
    int productId, {
    required int installmentsCount,
  }) async {
    final response = await _api.post(
      '/produits/$productId/tranches',
      body: {'installments_count': installmentsCount},
    );

    return InstallmentPurchase.fromJson(_unwrap(response));
  }

  /// `GET /mes-achats` — non paginé côté serveur.
  Future<List<InstallmentPurchase>> purchases() async {
    final response = await _api.get('/mes-achats');
    final data = response is Map ? response['data'] : null;
    if (data is! List) return const [];

    return data
        .whereType<Map>()
        .map((item) => InstallmentPurchase.fromJson(Map<String, dynamic>.from(item)))
        .toList(growable: false);
  }

  Future<InstallmentPurchase> purchase(int id) async {
    final response = await _api.get('/mes-achats/$id');

    return InstallmentPurchase.fromJson(_unwrap(response));
  }

  /// `POST /tranches/{id}/pay` — canal à validation directe.
  ///
  /// Le serveur renvoie l'ACHAT entier, pas la tranche : c'est cette réponse
  /// qui remplace l'affichage, sans second aller-retour.
  Future<InstallmentPurchase> payInstallment(
    int installmentId, {
    required String channel,
    String? reference,
  }) async {
    final response = await _api.post(
      '/tranches/$installmentId/pay',
      body: {
        'payment_method': channel,
        if (reference != null && reference.isNotEmpty) 'reference': reference,
      },
    );

    return InstallmentPurchase.fromJson(_unwrap(response));
  }

  /// `POST /tranches/{id}/soumettre-code` — MyNita, Amana.
  Future<InstallmentPurchase> submitInstallmentCode(
    int installmentId, {
    required String channel,
    required String transferCode,
  }) async {
    final response = await _api.post(
      '/tranches/$installmentId/soumettre-code',
      body: {'payment_method': channel, 'transfer_code': transferCode},
    );

    return InstallmentPurchase.fromJson(_unwrap(response));
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
