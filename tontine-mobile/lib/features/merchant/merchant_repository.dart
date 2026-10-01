import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/core/network/api_client.dart';
import 'package:tontine_achat_store/features/merchant/merchant_models.dart';

/// Accès à l'espace commerçant.
///
/// **Les enveloppes diffèrent d'un endpoint à l'autre**, et les uniformiser
/// serait faux :
///  - `/merchant/products`, `/merchant/dashboard` : le premier est une
///    ressource COLLECTION (`{"data": [...]}`), le second un `response()->json`
///    écrit à la main dont les tableaux ne sont PAS enveloppés ;
///  - `/merchant/orders` et `/merchant/installment-orders` : des tableaux
///    JSON **nus**, sans aucune enveloppe ni paginateur.
///
/// Chaque lecture déballe donc exactement ce que l'endpoint renvoie, et une
/// forme inattendue donne une liste vide plutôt qu'une exception : un écran
/// merchant qui plante sur un détail de sérialisation est un commerçant qui ne
/// peut plus livrer.
class MerchantRepository {
  const MerchantRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  /* ------------------------------------------------------------------ */
  /* Tableau de bord                                                     */
  /* ------------------------------------------------------------------ */

  /// `GET /merchant/dashboard` — chiffre d'affaires, produits, files de
  /// livraison.
  Future<MerchantDashboard> dashboard() async {
    final response = await _api.get('/merchant/dashboard');

    if (response is! Map) {
      throw const FormatException('Réponse inattendue du serveur.');
    }

    return MerchantDashboard.fromJson(Map<String, dynamic>.from(response));
  }

  /* ------------------------------------------------------------------ */
  /* Produits                                                            */
  /* ------------------------------------------------------------------ */

  /// `GET /merchant/products` — ressource collection, donc `{"data": [...]}`.
  ///
  /// Non paginé : le vendeur voit tout son catalogue, sans curseur.
  Future<List<MerchantProduct>> products() async {
    final response = await _api.get('/merchant/products');

    return _list(response).map(MerchantProduct.fromJson).toList(growable: false);
  }

  /// `POST /merchant/products` — création, en multipart quand il y a des
  /// fichiers.
  Future<MerchantProduct> createProduct(ProductDraft draft) async {
    final response = draft.hasFiles
        ? await _api.postMultipart('/merchant/products', draft.toMultipart())
        : await _api.post('/merchant/products', body: draft.toFields());

    return MerchantProduct.fromJson(_one(response));
  }

  /// `PUT /merchant/products/{id}` — modification.
  ///
  /// Le même `StoreProductRequest` sert la création et la mise à jour, donc
  /// **tous les champs sont obligatoires dans les deux cas** : un envoi
  /// partiel se ferait refuser en 422 sur le premier champ manquant. [ProductDraft]
  /// est donc toujours construit depuis le produit existant, jamais à vide.
  Future<MerchantProduct> updateProduct(int id, ProductDraft draft) async {
    final response = draft.hasFiles
        ? await _api.putMultipart('/merchant/products/$id', draft.toMultipart())
        : await _api.put('/merchant/products/$id', body: draft.toFields());

    return MerchantProduct.fromJson(_one(response));
  }

  /// `PATCH /merchant/products/{id}/stock` — le seul champ envoyé.
  Future<MerchantProduct> updateStock(int id, int stock) async {
    final response = await _api.patch('/merchant/products/$id/stock', body: {'stock': stock});

    return MerchantProduct.fromJson(_one(response));
  }

  /// `DELETE /merchant/products/{id}`.
  ///
  /// Le serveur répond 409 si le produit est lié à une tontine : le message
  /// qu'il renvoie est remonté tel quel, car il dit quoi faire à la place
  /// (archiver).
  Future<void> deleteProduct(int id) async {
    await _api.delete('/merchant/products/$id');
  }

  /// `DELETE /merchant/products/media/{id}` — une photo ou la vidéo.
  Future<void> deleteMedia(int mediaId) async {
    await _api.delete('/merchant/products/media/$mediaId');
  }

  /* ------------------------------------------------------------------ */
  /* Livraisons                                                          */
  /* ------------------------------------------------------------------ */

  /// `GET /merchant/orders` — file des livraisons de tontine. Tableau nu.
  Future<List<DeliveryItem>> deliveryQueue() async {
    final response = await _api.get('/merchant/orders');

    return _list(response).map(DeliveryItem.fromJson).toList(growable: false);
  }

  /// `POST /merchant/orders/{memberId}/deliver` — remise confirmée.
  Future<void> confirmTontineDelivery(int memberId) async {
    await _api.post('/merchant/orders/$memberId/deliver');
  }

  /// `GET /merchant/installment-orders` — livraisons d'achats en tranches.
  Future<List<InstallmentDelivery>> installmentDeliveryQueue() async {
    final response = await _api.get('/merchant/installment-orders');

    return _list(response).map(InstallmentDelivery.fromJson).toList(growable: false);
  }

  /// `POST /merchant/installment-orders/{purchaseId}/deliver`.
  Future<void> confirmInstallmentDelivery(int purchaseId) async {
    await _api.post('/merchant/installment-orders/$purchaseId/deliver');
  }
}

/// Contenu du tableau de bord.
///
/// `products` y est un tableau **nu** : le contrôleur y applique
/// `ProductResource::collection(...)->resolve()`, qui renvoie la liste
/// déballée. C'est le seul endroit où c'est le cas.
class MerchantDashboard {
  const MerchantDashboard({
    required this.businessName,
    required this.city,
    required this.status,
    required this.rating,
    required this.revenue,
    required this.products,
    required this.pendingTontineDeliveries,
    required this.awaitingPaymentTontineDeliveries,
    required this.pendingInstallmentDeliveries,
  });

  factory MerchantDashboard.fromJson(Map<String, dynamic> json) {
    final merchant = json['merchant'];
    final merchantMap =
        merchant is Map ? Map<String, dynamic>.from(merchant) : const <String, dynamic>{};
    final revenue = json['revenue'];

    return MerchantDashboard(
      businessName: merchantMap['business_name']?.toString() ?? '',
      city: merchantMap['city']?.toString() ?? '',
      status: merchantMap['status']?.toString() ?? '',
      rating: Fmt.toDouble(merchantMap['rating']),
      revenue: MerchantRevenue.fromJson(
        revenue is Map ? Map<String, dynamic>.from(revenue) : const <String, dynamic>{},
      ),
      products: _list(json['products']).map(MerchantProduct.fromJson).toList(growable: false),
      pendingTontineDeliveries: _list(json['pending_deliveries_tontine'])
          .map(DeliveryItem.fromJson)
          .toList(growable: false),
      awaitingPaymentTontineDeliveries: _list(json['waiting_for_payment_tontine'])
          .map(DeliveryItem.fromJson)
          .toList(growable: false),
      pendingInstallmentDeliveries: _list(json['pending_deliveries_installment'])
          .map(InstallmentDelivery.fromJson)
          .toList(growable: false),
    );
  }

  final String businessName;
  final String city;
  final String status;
  final double rating;
  final MerchantRevenue revenue;
  final List<MerchantProduct> products;
  final List<DeliveryItem> pendingTontineDeliveries;

  /// Membres dont la collecte est finie mais le versement pas encore validé :
  /// ils ne sont PAS livrables, et les proposer serait un mensonge du serveur.
  final List<DeliveryItem> awaitingPaymentTontineDeliveries;

  final List<InstallmentDelivery> pendingInstallmentDeliveries;

  int get publishedCount => products.where((product) => product.isLive).length;

  int get outOfStockCount => products.where((product) => product.isOutOfStock).length;

  /// Ce qui demande une action AU COMMERCANT : les livraisons éligibles, plus
  /// les tranches en attente.
  int get actionableDeliveries =>
      pendingTontineDeliveries.where((item) => item.isEligible).length +
      pendingInstallmentDeliveries.where((item) => item.isPending).length;
}

/// Formulaire de produit, prêt à être envoyé.
///
/// Réunit les champs texte et les fichiers, et sait produire la forme JSON comme
/// la forme multipart : le client HTTP choisit laquelle selon [hasFiles].
///
/// Les fichiers sont des chemins, pas des octets : ils sont lus au moment de
/// l'envoi, ce qui évite de tenir une photo de 5 Mo en mémoire pendant que
/// l'utilisateur remplit le reste du formulaire.
class ProductDraft {
  const ProductDraft({
    required this.name,
    required this.description,
    required this.category,
    required this.price,
    required this.stock,
    required this.status,
    this.imagePaths = const [],
    this.videoPath,
  });

  /// Pré-remplit le formulaire depuis un produit existant, pour l'édition.
  factory ProductDraft.fromProduct(MerchantProduct product) {
    return ProductDraft(
      name: product.name,
      description: product.description,
      category: product.category,
      price: product.price,
      stock: product.stock,
      // Un produit déjà publié ne repasse pas en brouillon parce qu'on l'ouvre :
      // cela le retirerait du catalogue sans que personne l'ait demandé.
      status: product.status,
    );
  }

  final String name;
  final String description;
  final String category;
  final double price;
  final int stock;
  final ProductStatus status;

  /// Chemins des photos à AJOUTER. Elles s'ajoutent aux existantes : le serveur
  /// ne remplace rien, il incrémente une position.
  final List<String> imagePaths;

  final String? videoPath;

  bool get hasFiles => imagePaths.isNotEmpty || videoPath != null;

  /// Les six champs que le serveur exige, en création comme en mise à jour.
  Map<String, dynamic> toFields() => {
        'name': name.trim(),
        'description': description.trim(),
        'category': category.trim(),
        // Le backend valide `numeric|min:0` : le montant part en texte, comme
        // le renvoie le formulaire web, et `decimal` y est toléré.
        'price': price.toStringAsFixed(2),
        'stock': stock,
        'status': status.wire,
      };

  /// Corps multipart : les champs plus les fichiers.
  ///
  /// `FilePart` porte par défaut le nom de champ `images[]`, qui est
  /// exactement ce que `$request->file('images', [])` attend. La vidéo est un
  /// champ unique et porte donc son propre nom.
  Map<String, dynamic> toMultipart() => {
        ...toFields(),
        if (imagePaths.isNotEmpty)
          'images[]': imagePaths.map((path) => FilePart(path: path)).toList(growable: false),
        if (videoPath != null) 'video': FilePart(path: videoPath!, field: 'video'),
      };
}

/// Nombre maximal de photos PAR ENVOI.
///
/// `StoreProductRequest` valide `images` avec `max:6`, et cette limite
/// s'applique à la requête, pas au produit : envoyer trois photos maintenant
/// et trois plus tard laisse six photos au total, ce que le serveur accepte.
/// C'est pourquoi l'écran n'affiche pas « 3/6 » comme un quota restant.
const maxImagesPerUpload = 6;

/// Taille maximale d'une photo, en kilo-octets — `max:5120` côté backend.
const maxImageKilobytes = 5120;

/// Taille maximale d'une vidéo, en kilo-octets — `max:51200` côté backend.
const maxVideoKilobytes = 51200;

/// Déballe une liste d'objets.
///
/// Accepte aussi bien un tableau nu qu'un objet `{data: [...]}` : les deux
/// formes coexistent dans cet espace, et un catalogue qui s'affiche vide est
/// pire qu'une distinction de plus à lire.
List<Map<String, dynamic>> _list(Object? response) {
  final raw = response is Map ? response['data'] : response;
  if (raw is! List) return const [];

  return raw
      .whereType<Map>()
      .map((item) => Map<String, dynamic>.from(item))
      .toList(growable: false);
}

/// Déballe un objet unique, avec ou sans enveloppe `data`.
Map<String, dynamic> _one(Object? response) {
  if (response is! Map) {
    throw const FormatException('Réponse inattendue du serveur.');
  }

  final map = Map<String, dynamic>.from(response);
  final data = map['data'];

  return data is Map ? Map<String, dynamic>.from(data) : map;
}