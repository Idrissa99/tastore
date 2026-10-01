import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/core/network/media.dart';

/// Modèles de l'espace commerçant.
///
/// **Ce ne sont PAS les modèles du catalogue client.** Un produit de vendeur
/// porte des champs que le catalogue n'expose pas — son `status` de
/// publication, son stock qu'on édite en place — et inversement le catalogue
/// est paginé par le serveur alors que `/merchant/products` ne l'est pas.
/// Réutiliser [Product] donnerait un écran qui affiche un produit publié aux
/// clients comme un brouillon, et une pagination qui n'a rien piloter.

/// Cycle de vie d'un produit chez le commerçant.
///
/// Les trois valeurs sont celles qu'attend `StoreProductRequest`
/// (`in:draft,published,archived`). Un produit `draft` ou `archived` reste
/// hors de tout catalogue : le vendeur le prépare encore, ou l'a retiré de la
/// vente sans le supprimer — ce qui compte, puisque le serveur refuse de
/// supprimer un produit lié à une tontine (409).
enum ProductStatus {
  draft('draft', 'Brouillon'),
  published('published', 'Publié'),
  archived('archived', 'Archivé');

  const ProductStatus(this.wire, this.label);

  /// Valeur attendue par l'API.
  final String wire;

  /// Libellé affiché, écrit à la main plutôt que capitalisé : « Brouillon »
  /// ne se déduit pas de `draft`.
  final String label;

  static ProductStatus fromWire(Object? value) {
    final text = value?.toString() ?? '';
    return ProductStatus.values.firstWhere(
      (status) => status.wire == text,
      orElse: () => ProductStatus.draft,
    );
  }
}

/// Un média attaché à un produit (photo ou vidéo).
///
/// L'`id` est indispensable : la suppression passe par
/// `DELETE /merchant/products/media/{id}`, pas par l'URL — on ne peut donc pas
/// se contenter de la chaîne affichée à l'écran.
class ProductMedia {
  const ProductMedia({required this.id, required this.url, required this.type});

  factory ProductMedia.fromJson(Map<String, dynamic> json, {String? type}) {
    return ProductMedia(
      id: Fmt.toInt(json['id']),
      url: mediaUrl(json['url']) ?? '',
      type: type ?? json['type']?.toString() ?? 'image',
    );
  }

  final int id;

  /// déjà résolue par [mediaUrl] : c'est elle qui part au chargeur d'images.
  final String url;

  /// `image` ou `video`. Lu de la réponse quand elle le fournit, sinon déduit
  /// du champ qui l'a portée.
  final String type;

  bool get isVideo => type == 'video';
}

/// Un produit vu depuis l'espace commerçant.
class MerchantProduct {
  const MerchantProduct({
    required this.id,
    required this.name,
    required this.description,
    required this.category,
    required this.price,
    required this.stock,
    required this.status,
    required this.images,
    required this.video,
    required this.createdAt,
    required this.tontinesCount,
  });

  factory MerchantProduct.fromJson(Map<String, dynamic> json) {
    final images = <ProductMedia>[];
    final rawImages = json['images'];

    if (rawImages is List) {
      for (final entry in rawImages) {
        if (entry is Map) {
          images.add(ProductMedia.fromJson(Map<String, dynamic>.from(entry), type: 'image'));
        }
      }
    }

    // `tontines_count` n'est présent que sur le tableau de bord, qui charge
    // la relation avec `withCount`. Ailleurs, le produit n'a simplement pas
    // été compté : zéro est donc le neutre, pas une information fausse.
    final rawVideo = json['video'];

    return MerchantProduct(
      id: Fmt.toInt(json['id']),
      name: json['name']?.toString() ?? '',
      description: json['description']?.toString() ?? '',
      category: json['category']?.toString() ?? '',
      price: Fmt.toDouble(json['price']),
      stock: Fmt.toInt(json['stock']),
      status: ProductStatus.fromWire(json['status']),
      images: images,
      video: rawVideo is Map
          ? ProductMedia.fromJson(Map<String, dynamic>.from(rawVideo), type: 'video')
          : null,
      createdAt: Fmt.parseDate(json['created_at']),
      tontinesCount: Fmt.toInt(json['tontines_count']),
    );
  }

  final int id;
  final String name;
  final String description;
  final String category;
  final double price;
  final int stock;
  final ProductStatus status;
  final List<ProductMedia> images;

  /// La vidéo est unique : `ProductResource` expose `firstWhere(type=video)`.
  final ProductMedia? video;

  final DateTime? createdAt;

  /// Nombre de tontines vendues sur ce produit, si le serveur l'a compté.
  final int tontinesCount;

  bool get isOutOfStock => stock <= 0;

  bool get isLive => status == ProductStatus.published;

  String? get coverUrl => images.isEmpty ? null : images.first.url;

  /// Photo principale, pour les listes.
  String get coverLabel => isOutOfStock
      ? 'Rupture de stock'
      : (category.isEmpty ? 'Produit' : Fmt.capitalize(category));
}

/// Chiffre d'affaires du commerçant.
///
/// La clé est `total` et non `revenue` : le backend renvoie
/// `{contributions, installments, total}`, et chaque poste est un cumul des
/// seuls versements `completed`.
class MerchantRevenue {
  const MerchantRevenue({required this.contributions, required this.installments, required this.total});

  factory MerchantRevenue.fromJson(Map<String, dynamic> json) {
    return MerchantRevenue(
      contributions: Fmt.toDouble(json['contributions']),
      installments: Fmt.toDouble(json['installments']),
      total: Fmt.toDouble(json['total']),
    );
  }

  final double contributions;
  final double installments;
  final double total;
}

/// Une livraison de tontine en attente chez le commerçant.
///
/// La forme vient de `DeliveryService::queueForMerchant`, partagée par
/// `/merchant/orders` et le tableau de bord.
class DeliveryItem {
  const DeliveryItem({
    required this.id,
    required this.userName,
    required this.tontineName,
    required this.productName,
    required this.beneficiaryRound,
    required this.currentRound,
    required this.deliveryStatus,
    required this.isEligible,
    required this.blockers,
    required this.deliveredAt,
  });

  factory DeliveryItem.fromJson(Map<String, dynamic> json) {
    final tontine = json['tontine'];
    final tontineMap = tontine is Map ? Map<String, dynamic>.from(tontine) : const <String, dynamic>{};
    final product = tontineMap['product'];
    final productMap = product is Map ? Map<String, dynamic>.from(product) : const <String, dynamic>{};

    final rawBlockers = json['blockers'];

    return DeliveryItem(
      id: Fmt.toInt(json['id']),
      userName: json['user_name']?.toString() ?? '',
      tontineName: tontineMap['name']?.toString() ?? '',
      productName: productMap['name']?.toString() ?? '',
      beneficiaryRound: Fmt.toInt(json['beneficiary_round']),
      currentRound: Fmt.toInt(tontineMap['current_round']),
      deliveryStatus: json['delivery_status']?.toString() ?? '',
      // Absent vaut `false` : mieux vaut ne proposer la confirmation que le
      // serveur confirme, que l'inverse.
      isEligible: json['is_eligible'] == true,
      blockers: rawBlockers is List
          ? rawBlockers.map((blocker) => blocker.toString()).toList(growable: false)
          : const [],
      deliveredAt: Fmt.parseDate(json['delivered_at']),
    );
  }

  final int id;
  final String userName;
  final String tontineName;
  final String productName;
  final int beneficiaryRound;
  final int currentRound;
  final String deliveryStatus;

  /// Le serveur a-t-il calculé qu'aucun obstacle ne s'oppose à la remise ?
  final bool isEligible;

  /// Codes d'obstacle, à traduire par [deliveryBlockerLabel].
  final List<String> blockers;

  final DateTime? deliveredAt;

  bool get isDelivered => deliveryStatus == 'delivered';

  bool get isAwaitingPayment => deliveryStatus == 'awaiting_payment';
}

/// Explication d'un code d'obstacle.
///
/// Les codes viennent du backend ; leur traduction n'a de sens qu'ici, et une
/// clé inconnue est rendue telle quelle plutôt que masquée : elle signale un
/// décalage entre les deux applications au lieu de le dissimuler.
String deliveryBlockerLabel(String code) => switch (code) {
      'cash_tontine' => 'Tontine en espèces : pas de livraison à valider.',
      'tontine_cancelled' => 'La tontine a été annulée.',
      'not_beneficiary' => "Le membre n'est pas bénéficiaire de ce tour.",
      'turn_not_active' => "Le tour de ce membre n'est pas encore ouvert.",
      'round_not_paid' => 'La collecte du tour n\'est pas complète.',
      'already_delivered' => 'La livraison a déjà été confirmée.',
      'not_awaiting_delivery' => "Le membre n'attend pas de livraison.",
      'product_unavailable' => 'Le produit du membre est indisponible.',
      _ => code,
    };

/// Une livraison d'achat en tranches.
///
/// Le backend sérialise le modèle `InstallmentPurchase` **nu**, sans ressource :
/// le produit et l'utilisateur sont donc lus à plat, et `user` peut être absent
/// si la relation n'a pas été chargée.
class InstallmentDelivery {
  const InstallmentDelivery({
    required this.id,
    required this.productId,
    required this.userName,
    required this.productName,
    required this.installmentsCount,
    required this.deliveryStatus,
    required this.deliveredAt,
  });

  factory InstallmentDelivery.fromJson(Map<String, dynamic> json) {
    final user = json['user'];
    final userMap = user is Map ? Map<String, dynamic>.from(user) : const <String, dynamic>{};
    final product = json['product'];
    final productMap = product is Map ? Map<String, dynamic>.from(product) : const <String, dynamic>{};

    return InstallmentDelivery(
      id: Fmt.toInt(json['id']),
      productId: Fmt.toInt(json['product_id']),
      userName: userMap['name']?.toString() ?? '',
      productName: productMap['name']?.toString() ?? '',
      installmentsCount: Fmt.toInt(json['installments_count']),
      deliveryStatus: json['delivery_status']?.toString() ?? '',
      deliveredAt: Fmt.parseDate(json['delivered_at']),
    );
  }

  final int id;
  final int productId;
  final String userName;
  final String productName;
  final int installmentsCount;
  final String deliveryStatus;
  final DateTime? deliveredAt;

  bool get isPending => deliveryStatus == 'pending';

  bool get isDelivered => deliveryStatus == 'delivered';
}