import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/core/network/media.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';

/// Statut d'une tontine.
///
/// L'API renvoie la chaîne brute ; chaque valeur porte ici son libellé français
/// et sa teinte, repris du `status.js` du SPA web. Un statut inconnu n'est pas
/// traité comme une absence : [TontineStatus.unknown] s'affiche « Statut
/// inconnu » en gris, là où un libellé manquant ferait disparaître le badge et
/// présenterait une tontine comme rejoignable.
enum TontineStatus {
  open('open', 'Ouverte', AppTone.brand),
  active('active', 'En cours', AppTone.success),
  completed('completed', 'Terminée', AppTone.info),
  cancelled('cancelled', 'Annulée', AppTone.danger),
  unknown('', 'Statut inconnu', AppTone.neutral);

  const TontineStatus(this.wire, this.label, this.tone);

  final String wire;
  final String label;
  final AppTone tone;

  /// Seule une tontine « open » accueille de nouveaux membres : c'est la règle
  /// du serveur (`join()`), reproduite ici pour ne pas afficher un bouton qui
  /// serait refusé. Le serveur reste seul juge — cette propriété ne dispense
  /// d'aucun contrôle, elle évite juste un aller-retour avoidable et un message
  /// d'erreur sur une action impossible.
  bool get acceptsMembers => this == TontineStatus.open;

  static TontineStatus parse(Object? value) => values.firstWhere(
        (status) => status.wire == value,
        orElse: () => TontineStatus.unknown,
      );
}

/// Nature de l'épargne : un produit acheté à terme, ou une somme versée.
enum TontineType {
  cash('cash', 'Tontine argent', 'Argent', AppTone.brand),
  product('product', 'Tontine produit', 'Produit', AppTone.accent);

  const TontineType(this.wire, this.label, this.shortLabel, this.tone);

  final String wire;
  final String label;

  /// Libellé pour la pastille, sans le préfixe « Tontine » : sur une carte
  /// déjà intitulée par le nom de la tontine, « Tontine produit » doublonne.
  final String shortLabel;

  final AppTone tone;

  /// Sigle affiché sur l'avatar de repli, quand il n'y a pas de photo.
  String get badge => this == TontineType.cash ? 'TA' : 'TP';

  static TontineType parse(Object? value) => values.firstWhere(
        (type) => type.wire == value,
        orElse: () => TontineType.cash,
      );
}

/// Périodicité des versements.
enum TontineFrequency {
  daily('daily', 'par jour', 'Jour'),
  weekly('weekly', 'par semaine', 'Semaine'),
  monthly('monthly', 'par mois', 'Mois');

  const TontineFrequency(this.wire, this.label, this.shortLabel);

  final String wire;

  /// « par semaine » — se colle au montant : « 12 500 FCFA par semaine ».
  final String label;

  /// « Semaine » — se colle à un intitulé : « Versement hebdomadaire ».
  final String shortLabel;

  /// Le backend valide `in:daily,weekly,monthly`. Une fréquence hors de cet
  /// ensemble ne peut donc pas exister : le repli n'est qu'un garde-fou pour
  /// une chaîne illisible, jamais un cas de figure à traiter.
  static TontineFrequency parse(Object? value) => values.firstWhere(
        (frequency) => frequency.wire == value,
        orElse: () => TontineFrequency.monthly,
      );
}

/// État de participation d'un membre.
///
/// Distinct de l'état de livraison : on peut être bénéficiaire du tour tout en
/// attendant que le round soit financé ([DeliveryStatus.awaitingPayment]).
enum TontineMemberStatus {
  active('active', 'Actif', AppTone.neutral),
  beneficiary('beneficiary', 'Bénéficiaire', AppTone.accent),
  completed('completed', 'A servi', AppTone.success),
  withdrawn('withdrawn', 'Sorti', AppTone.neutral);

  const TontineMemberStatus(this.wire, this.label, this.tone);

  final String wire;
  final String label;
  final AppTone tone;

  static TontineMemberStatus parse(Object? value) => values.firstWhere(
        (status) => status.wire == value,
        orElse: () => TontineMemberStatus.active,
      );
}

/// État de livraison du produit au bénéficiaire désigné.
enum DeliveryStatus {
  notApplicable('not_applicable', 'Sans objet', AppTone.neutral),
  awaitingPayment('awaiting_payment', 'En attente du tour', AppTone.warning),
  pending('pending', 'À livrer', AppTone.warning),
  delivered('delivered', 'Livré', AppTone.success);

  const DeliveryStatus(this.wire, this.label, this.tone);

  final String wire;
  final String label;
  final AppTone tone;

  static DeliveryStatus parse(Object? value) => values.firstWhere(
        (status) => status.wire == value,
        orElse: () => DeliveryStatus.notApplicable,
      );
}

/// Produit financé par la tontine, dans la forme allégée qu'envoie
/// `TontineResource` (`id`, `name`, `price`, `image`, `merchant_id`).
///
/// [raw] conserve le JSON d'origine : `productImage()` sait lire deux
/// représentations possibles, et le modèle ne peut pas deviner laquelle le
/// backend enverra demain. Le garder coûte un champ ; en reconstruire une
/// partie à la main coûterait une divergence silencieuse entre deux écrans.
class TontineProduct {
  const TontineProduct({
    required this.id,
    required this.name,
    required this.price,
    required this.raw,
  });

  factory TontineProduct.fromJson(Map<String, dynamic> json) => TontineProduct(
        id: Fmt.toInt(json['id']),
        name: json['name']?.toString() ?? '',
        price: Fmt.toDouble(json['price']),
        raw: json,
      );

  final int id;
  final String name;
  final double price;
  final Map<String, dynamic> raw;

  String? get imageUrl => productImage(raw);
}

/// État financier du tour courant.
///
/// C'est ce bloc qui autorise — ou non — la livraison au bénéficiaire désigné.
/// Sans lui, on ne saurait pas distinguer « personne n'a cotisé » d'un tour
/// « sans round ».
class TontineRound {
  const TontineRound({
    required this.number,
    required this.expectedMembers,
    required this.paidMembers,
    required this.isFunded,
  });

  static const empty = TontineRound(
    number: 0,
    expectedMembers: 0,
    paidMembers: 0,
    isFunded: false,
  );

  factory TontineRound.fromJson(Map<String, dynamic> json) => TontineRound(
        number: Fmt.toInt(json['number']),
        expectedMembers: Fmt.toInt(json['expected_members']),
        paidMembers: Fmt.toInt(json['paid_members']),
        isFunded: json['is_funded'] == true,
      );

  final int number;
  final int expectedMembers;
  final int paidMembers;
  final bool isFunded;

  double get ratio =>
      expectedMembers <= 0 ? 0 : (paidMembers / expectedMembers).clamp(0.0, 1.0);
}

/// Membre inscrit à la tontine.
class TontineMember {
  const TontineMember({
    required this.id,
    required this.userId,
    required this.userName,
    required this.status,
    required this.deliveryStatus,
    required this.position,
    required this.beneficiaryRound,
    required this.isMe,
  });

  factory TontineMember.fromJson(Map<String, dynamic> json, {required int? currentUserId}) {
    final userId = Fmt.toInt(json['user_id']);

    return TontineMember(
      id: Fmt.toInt(json['id']),
      userId: userId,
      userName: json['user_name']?.toString() ?? '',
      status: TontineMemberStatus.parse(json['status']),
      deliveryStatus: DeliveryStatus.parse(json['delivery_status']),
      position: _nullableInt(json['position']),
      beneficiaryRound: _nullableInt(json['beneficiary_round']),
      isMe: currentUserId != null && currentUserId == userId,
    );
  }

  final int id;
  final int userId;
  final String userName;
  final TontineMemberStatus status;
  final DeliveryStatus deliveryStatus;

  /// Rang dans l'ordre de passage. **Absent** avant le lancement : ce n'est
  /// alors qu'un ordre d'arrivée, et l'afficher laisserait croire qu'un tirage
  /// a eu lieu. Le serveur renvoie `null` dans ce cas, et `rotation_revealed`
  /// décide s'il a le droit d'être montré.
  final int? position;

  final int? beneficiaryRound;
  final bool isMe;

  String get initials => Fmt.initials(userName);
}

/// Une tontine, telle que `TontineResource` la sérialise.
///
/// Les montants sont convertis en `double` au décodage ([Fmt.toDouble]) : les
/// casts `decimal:2` du backend les envoient en chaîne, et une tontine affichée
/// à « 0 FCFA » parce que la chaîne n'a pas été lue est exactement le genre de
/// défaut qui ne se remarque qu'à l'écran.
class Tontine {
  const Tontine({
    required this.id,
    required this.name,
    required this.type,
    required this.product,
    required this.totalAmount,
    required this.contributionAmount,
    required this.commissionRate,
    required this.frequency,
    required this.maxMembers,
    required this.currentMembers,
    required this.currentRound,
    required this.status,
    required this.startDate,
    required this.round,
    required this.members,
    required this.rotationRevealed,
    required this.isMember,
    required this.myStatus,
    required this.isCreator,
    required this.canEdit,
    required this.editLockedReason,
  });

  factory Tontine.fromJson(Map<String, dynamic> json, {int? currentUserId}) {
    final product = json['product'];
    final round = json['round'];
    final members = json['members'];

    return Tontine(
      id: Fmt.toInt(json['id']),
      name: json['name']?.toString() ?? '',
      type: TontineType.parse(json['type']),
      product: product is Map ? TontineProduct.fromJson(_asMap(product)) : null,
      totalAmount: Fmt.toDouble(json['total_amount']),
      contributionAmount: Fmt.toDouble(json['contribution_amount']),
      commissionRate: Fmt.toDouble(json['commission_rate']),
      frequency: TontineFrequency.parse(json['frequency']),
      maxMembers: Fmt.toInt(json['max_members']),
      currentMembers: Fmt.toInt(json['current_members']),
      currentRound: Fmt.toInt(json['current_round']),
      status: TontineStatus.parse(json['status']),
      startDate: Fmt.parseDate(json['start_date']),
      round: round is Map ? TontineRound.fromJson(_asMap(round)) : TontineRound.empty,
      members: members is List
          ? members
              .whereType<Map>()
              .map(
                (member) => TontineMember.fromJson(
                  _asMap(member),
                  currentUserId: currentUserId,
                ),
              )
              .toList(growable: false)
          : const [],
      rotationRevealed: json['rotation_revealed'] == true,
      isMember: json['is_member'] == true,
      myStatus: json['my_status']?.toString(),
      isCreator: json['is_creator'] == true,
      canEdit: json['can_edit'] == true,
      editLockedReason: json['edit_locked_reason']?.toString(),
    );
  }

  final int id;
  final String name;
  final TontineType type;
  final TontineProduct? product;
  final double totalAmount;
  final double contributionAmount;
  final double commissionRate;
  final TontineFrequency frequency;
  final int maxMembers;
  final int currentMembers;
  final int currentRound;
  final TontineStatus status;
  final DateTime? startDate;
  final TontineRound round;
  final List<TontineMember> members;

  /// Vrai seulement à partir du lancement. Un `position` présent sans ce
  /// drapeau serait un ordre de passage annoncé avant qu'il existe.
  final bool rotationRevealed;

  final bool isMember;
  final String? myStatus;

  final bool isCreator;
  final bool canEdit;
  final String? editLockedReason;

  /// Montant déjà versé sur le tour courant.
  ///
  /// Le backend ne l'envoie pas tel quel : il donne un NOMBRE de cotisants
  /// (`round.paid_members`) et un montant par tour. Le produit des deux est
  /// reconstitué ici, comme le fait la carte du SPA web.
  double get collectedAmount => round.paidMembers * contributionAmount;

  /// Ce que le bénéficiaire du tour courant va recueillir.
  ///
  /// Un tour FINANCÉ : le montant est un fait, `expected_members` ont payé.
  ///
  /// Un tour EN COURS DE COLLECTE : le montant n'est qu'une cible. Le
  /// backend ne connaît que le nombre de cotisants et le montant unitaire, et
  /// rien ne garantit que les absents paieront — l'annoncer sans réserve
  /// ferait promettre une somme qui peut diminuer. L'appelant doit donc dire
  /// sur quoi il s'appuie, et c'est pourquoi les deux cas sont distincts plutôt
  /// qu'un chiffre assorti d'un coefficient de confiance caché.
  double get roundPayout => round.isFunded
      ? collectedAmount
      : (round.expectedMembers * contributionAmount);

  /// Le montant ci-dessus est-il garanti, ou prévisionnel ?
  bool get isRoundPayoutFinal => round.isFunded;

  /// Progression financière, bornée pour l'affichage.
  double get fundingRatio =>
      totalAmount <= 0 ? 0 : (collectedAmount / totalAmount).clamp(0.0, 1.0);

  /// Progression des inscriptions, bornée pour l'affichage.
  double get fillRatio =>
      maxMembers <= 0 ? 0 : (currentMembers / maxMembers).clamp(0.0, 1.0);

  bool get isFull => maxMembers > 0 && currentMembers >= maxMembers;

  int get remainingSlots => (maxMembers - currentMembers).clamp(0, maxMembers);

  /// L'adhésion est-elle possible, et sinon à quel titre ?
  ///
  /// Quatre états et non deux, parce qu'ils ne se réparent pas de la même
  /// façon : « pas encore membre » se résout d'un clic, une tontine pleine en
  /// cherchant ailleurs, une tontine démarrée en revenant plus tard. Un seul
  /// « bouton désactivé » pour les trois ferait passer une situation
  /// définitive pour un simple contretemps.
  JoinAvailability get joinAvailability {
    if (isMember) return JoinAvailability.alreadyMember;
    if (!status.acceptsMembers) return JoinAvailability.closed;
    if (isFull) return JoinAvailability.full;
    return JoinAvailability.open;
  }

  /// Motif du refus, donné à l'utilisateur au lieu d'un bouton mort : un
  /// blocage sans explication se prend pour un bug.
  String joinBlockedReason() {
    return switch (joinAvailability) {
      JoinAvailability.open => '',
      JoinAvailability.alreadyMember => 'Tu es déjà membre de cette tontine.',
      JoinAvailability.full => 'Cette tontine est déjà complète.',
      JoinAvailability.closed => 'Cette tontine n\'accepte plus de nouveaux membres.',
    };
  }

  /// Le texte qu'une recherche libre doit examiner.
  ///
  /// Une tontine argent n'a pas de produit : on cherche alors dans son nom.
  String get searchLabel => product?.name ?? name;

  /// Montant de référence pour un filtre « jusqu'à X FCFA ».
  ///
  /// Une tontine produit s'annonce par son versement, une tontine argent par ce
  /// qu'elle vise : c'est le chiffre que l'utilisateur compare au sien.
  double get comparableAmount =>
      type == TontineType.cash ? totalAmount : contributionAmount;
}

/// Raisons pour lesquelles le bouton « Rejoindre » est ou non disponible.
enum JoinAvailability { open, alreadyMember, closed, full }

/// Une page de résultats paginée par Laravel.
///
/// `paginate()` renvoie `{ "data": [...], "links": {...}, "meta": {...} }`.
/// Une réponse qui n'est pas paginée — un appel à une ressource unique — n'a
/// pas de `meta` : on retombe alors sur une page unique, plutôt que
/// d'annoncer une deuxième page qui n'existe pas.
class PagedResult<T> {
  const PagedResult({
    required this.items,
    required this.currentPage,
    required this.lastPage,
    required this.total,
  });

  factory PagedResult.fromLaravel(
    Object? raw,
    T Function(Map<String, dynamic> json) parse,
  ) {
    if (raw is! Map) {
      return PagedResult.emptyOf<T>();
    }

    final map = _asMap(raw);
    final data = map['data'];
    final meta = map['meta'] is Map ? map['meta'] as Map : null;

    final items = <T>[];
    if (data is List) {
      for (final item in data.whereType<Map>()) {
        items.add(parse(_asMap(item)));
      }
    }

    return PagedResult(
      items: items,
      currentPage: _positiveInt(meta?['current_page']),
      lastPage: _positiveInt(meta?['last_page']),
      total: meta == null ? items.length : _positiveInt(meta['total']),
    );
  }

  /// Page vide : une seule page, donc aucune suivante à demander.
  static PagedResult<E> emptyOf<E>() => PagedResult<E>(
        items: <E>[],
        currentPage: 1,
        lastPage: 1,
        total: 0,
      );

  final List<T> items;
  final int currentPage;
  final int lastPage;
  final int total;

  bool get hasMore => currentPage < lastPage;

  /// Page suivante à demander, ou `null` si la dernière est atteinte.
  int? get nextPage => hasMore ? currentPage + 1 : null;
}

Map<String, dynamic> _asMap(Map source) => Map<String, dynamic>.from(source);

/// Entier positif, `null` ou négatif se ramenant à 1.
///
/// Une pagination absente ou incohérente ne doit pas produire une page 0 qui
/// ferait boucler l'écran sur « page précédente » sans fin.
int _positiveInt(Object? value) {
  final parsed = Fmt.toInt(value);
  return parsed < 1 ? 1 : parsed;
}

/// Entier optionnel : `null` reste `null`, 0 reste 0.
int? _nullableInt(Object? value) => value == null ? null : Fmt.toInt(value);
