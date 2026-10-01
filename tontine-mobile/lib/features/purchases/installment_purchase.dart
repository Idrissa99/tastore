import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/features/contributions/contribution.dart';
import 'package:tontine_achat_store/core/network/media.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';

/// Un produit du catalogue, vendu à terme ou en tranches.
///
/// `ProductResource` est renvoyé enveloppé sous `{"data": …}` par
/// `GET /produits/{id}`, mais le PAGINATEUR de `GET /produits` place la liste
/// dans `data` et ne ré-enveloppe pas ses éléments. Les deux formes sont donc
/// gérées par le même déballage que pour les tontines.
class Product {
  const Product({
    required this.id,
    required this.name,
    required this.description,
    required this.category,
    required this.price,
    required this.stock,
    required this.images,
    required this.merchantName,
    required this.merchantCity,
    required this.merchantRating,
    required this.raw,
  });

  factory Product.fromJson(Map<String, dynamic> json) {
    final merchant = json['merchant'];
    final media = merchant is Map ? Map<String, dynamic>.from(merchant) : const <String, dynamic>{};

    return Product(
      id: Fmt.toInt(json['id']),
      name: json['name']?.toString() ?? '',
      description: json['description']?.toString() ?? '',
      category: json['category']?.toString() ?? '',
      price: Fmt.toDouble(json['price']),
      stock: Fmt.toInt(json['stock']),
      images: _imageUrls(json),
      merchantName: media['business_name']?.toString() ?? '',
      merchantCity: media['city']?.toString() ?? '',
      merchantRating: Fmt.toDouble(media['rating']),
      raw: json,
    );
  }

  final int id;
  final String name;
  final String description;
  final String category;
  final double price;
  final int stock;

  /// Toutes les photos, pas seulement la première : la fiche en affiche
  /// plusieurs, et un catalogue qui n'en montre qu'une donne l'impression
  /// qu'il n'y en a qu'une.
  final List<String> images;

  final String merchantName;
  final String merchantCity;
  final double merchantRating;

  /// JSON d'origine, pour lire un champ que le modèle ne connaît pas encore
  /// plutôt que de reconstruire la réponse à la main.
  final Map<String, dynamic> raw;

  String? get coverUrl => images.isEmpty ? null : images.first;

  bool get hasRating => merchantRating > 0;

  /// Liste les deux champs dont la fiche a besoin pour un avis rapide.
  String get merchantLine {
    final parts = [merchantName, merchantCity].where((part) => part.isNotEmpty).toList();
    return parts.join(' · ');
  }

  /// Toutes les photos, lues dans les DEUX représentations que l'API accepte.
  ///
  /// `image` et `images[]` ne sont pas deux désignations de la même chose :
  /// `image` est la photo principale, `images[]` la galerie. Se fier à
  /// `productImage`, qui renvoie `images[0]` en priorité, faisait perdre
  /// `image` dès que la galerie était renseignée — la photo principale
  /// disparaissait alors qu'elle était la mieux choisie.
  static List<String> _imageUrls(Map<String, dynamic> json) {
    final urls = <String>[];

    void add(Object? value) {
      final url = mediaUrl(value);
      // La même photo est souvent présente dans les deux champs : la lister
      // deux fois la ferait apparaître en double dans la galerie.
      if (url != null && !urls.contains(url)) urls.add(url);
    }

    add(json['image']);

    final images = json['images'];
    if (images is List) {
      for (final entry in images) {
        add(entry is Map ? entry['url'] : entry);
      }
    }

    final video = json['video'];
    if (video is Map) add(video['url']);

    return urls;
  }
}

/// Aperçu d'un achat en tranches, renvoyé par
/// `POST /produits/{id}/tranches/apercu`.
///
/// **Réponse nue, sans enveloppe** : le contrôleur rend un `response()->json()`
/// et non une ressource. Une décompression appliquée par réflexe y verrait un
/// objet sans champs, et l'aperçu afficherait zéro.
class InstallmentPreview {
  const InstallmentPreview({
    required this.commissionRate,
    required this.installmentAmount,
    required this.total,
  });

  factory InstallmentPreview.fromJson(Map<String, dynamic> json) => InstallmentPreview(
        commissionRate: Fmt.toDouble(json['commission_rate']),
        installmentAmount: Fmt.toDouble(json['installment_amount']),
        total: Fmt.toDouble(json['total']),
      );

  final double commissionRate;
  final double installmentAmount;

  /// Somme des tranches, commission comprise : c'est ce que l'utilisateur
  /// paiera réellement au total, toujours supérieur au prix affiché.
  final double total;
}

/// État d'une tranche.
enum InstallmentStatus {
  pending('pending', 'À payer', AppTone.warning),
  completed('completed', 'Payée', AppTone.success),
  failed('failed', 'Échouée', AppTone.danger);

  const InstallmentStatus(this.wire, this.label, this.tone);

  final String wire;
  final String label;
  final AppTone tone;

  bool get isPaid => this == InstallmentStatus.completed;

  static InstallmentStatus parse(Object? value) => values.firstWhere(
        (status) => status.wire == value,
        orElse: () => InstallmentStatus.pending,
      );
}

/// État de la livraison du produit acheté en tranches.
enum PurchaseDeliveryStatus {
  pending('pending', 'En préparation', AppTone.warning),
  shipped('shipped', 'Expédié', AppTone.info),
  delivered('delivered', 'Livré', AppTone.success);

  const PurchaseDeliveryStatus(this.wire, this.label, this.tone);

  final String wire;
  final String label;
  final AppTone tone;

  static PurchaseDeliveryStatus parse(Object? value) => values.firstWhere(
        (status) => status.wire == value,
        orElse: () => PurchaseDeliveryStatus.pending,
      );
}

/// Une tranche d'un achat en tranches.
class Installment {
  const Installment({
    required this.id,
    required this.number,
    required this.amount,
    required this.status,
    required this.verificationStatus,
    required this.transactionReference,
    required this.submittedAt,
    required this.paidAt,
  });

  factory Installment.fromJson(Map<String, dynamic> json) => Installment(
        id: Fmt.toInt(json['id']),
        number: Fmt.toInt(json['installment_number']),
        amount: Fmt.toDouble(json['amount']),
        status: InstallmentStatus.parse(json['status']),
        verificationStatus: VerificationStatus.parse(json['verification_status']),
        transactionReference: json['transaction_reference']?.toString(),
        submittedAt: Fmt.parseDate(json['submitted_at']),
        paidAt: Fmt.parseDate(json['paid_at']),
      );

  final int id;
  final int number;
  final double amount;
  final InstallmentStatus status;

  /// Réutilise l'énumération des cotisations : le serveur emploie les mêmes
  /// valeurs (`pending`, `accepted`, `rejected`) pour les deux, et deux
  /// énumérations identiques finiraient forcément par diverger.
  final VerificationStatus verificationStatus;

  final String? transactionReference;
  final DateTime? submittedAt;
  final DateTime? paidAt;

  /// Le contrôleur refuse 409 une tranche déjà payée, et 409 une tranche dont
  /// un code est déjà en attente de vérification.
  bool get isPayable => !status.isPaid && !verificationStatus.awaitsDecision;
}

/// Un achat en tranches.
///
/// `GET /mes-achats/{id}` et `POST /tranches/{id}/pay` renvoient TOUTES LES
/// DEUX cette ressource — le paiement d'une tranche ne renvoie pas la tranche,
/// mais l'achat entier. C'est ce qui permet de remplacer l'affichage sans
/// redemander la fiche.
class InstallmentPurchase {
  const InstallmentPurchase({
    required this.id,
    required this.product,
    required this.installmentsCount,
    required this.installmentAmount,
    required this.productPrice,
    required this.status,
    required this.deliveryStatus,
    required this.paidInstallmentsCount,
    required this.installments,
    required this.createdAt,
  });

  factory InstallmentPurchase.fromJson(Map<String, dynamic> json) {
    final product = json['product'];
    final installments = json['installments'];

    return InstallmentPurchase(
      id: Fmt.toInt(json['id']),
      product: product is Map ? Product.fromJson(Map<String, dynamic>.from(product)) : null,
      installmentsCount: Fmt.toInt(json['installments_count']),
      installmentAmount: Fmt.toDouble(json['installment_amount']),
      productPrice: Fmt.toDouble(json['product_price']),
      status: json['status']?.toString() ?? '',
      deliveryStatus: PurchaseDeliveryStatus.parse(json['delivery_status']),
      paidInstallmentsCount: Fmt.toInt(json['paid_installments_count']),
      installments: installments is List
          ? installments
              .whereType<Map>()
              .map((item) => Installment.fromJson(Map<String, dynamic>.from(item)))
              .toList(growable: false)
          : const [],
      createdAt: Fmt.parseDate(json['created_at']),
    );
  }

  final int id;
  final Product? product;
  final int installmentsCount;
  final double installmentAmount;
  final double productPrice;
  final String status;
  final PurchaseDeliveryStatus deliveryStatus;
  final int paidInstallmentsCount;
  final List<Installment> installments;
  final DateTime? createdAt;

  String get title => product?.name ?? 'Achat en tranches';

  /// Progression des versements, bornée pour l'affichage.
  double get paidRatio => installmentsCount <= 0
      ? 0
      : (paidInstallmentsCount / installmentsCount).clamp(0.0, 1.0);

  /// La prochaine tranche à payer, ou `null` si tout est réglé.
  ///
  /// On prend la PREMIÈRE non payée et non en attente de vérification, dans
  /// l'ordre du serveur : c'est celle que l'utilisateur doit solder avant les
  /// suivantes.
  Installment? get nextInstallment {
    for (final installment in installments) {
      if (installment.isPayable) return installment;
    }
    return null;
  }

  bool get isFullyPaid => installmentsCount > 0 && paidInstallmentsCount >= installmentsCount;
}
