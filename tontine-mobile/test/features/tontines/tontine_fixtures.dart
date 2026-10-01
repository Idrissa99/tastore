/// Une réponse `TontineResource` complète, avec les valeurs par défaut qu'on
/// MODIFIE ensuite au cas par cas.
///
/// Écrire le JSON en entier à chaque test coûte cent lignes et laisse passer
/// des oublis : une clé oubliée dans le test n'est pas un test raté, c'est un
/// test qui teste moins que prévu. Ici, seule la clé qu'on veut réellement
/// éprouver est écrite.
///
/// [overrides] est appliqué EN DERNIER et peut valoir `null` : il sert à
/// éprouver une clé réellement absente du JSON, ce qu'un paramètre typé ne peut
/// pas exprimer — passer `null` à un `String totalAmount = '…'` retomberait sur
/// la valeur par défaut et le test ne testerait rien.
Map<String, dynamic> tontineJson({
  int id = 1,
  String name = 'Tontine Téléphone',
  String type = 'product',
  Map<String, dynamic>? product = const {
    'id': 10,
    'name': 'Smartphone',
    'price': '300000.00',
    'image': '/storage/products/photo.jpg',
    'merchant_id': 4,
  },
  String totalAmount = '300000.00',
  String contributionAmount = '25000.00',
  String frequency = 'monthly',
  int maxMembers = 12,
  int currentMembers = 3,
  String status = 'open',
  Map<String, dynamic>? round,
  List<Map<String, dynamic>>? members,
  bool rotationRevealed = false,
  bool isMember = false,
  bool isCreator = false,
  bool canEdit = false,
  String? editLockedReason,
  Object? startDate = '2026-10-01T00:00:00.000000Z',
  Map<String, dynamic> overrides = const {},
}) {
  return {
    'id': id,
    'name': name,
    'type': type,
    'product': product,
    'total_amount': totalAmount,
    'contribution_amount': contributionAmount,
    'commission_rate': '0.030000',
    'frequency': frequency,
    'max_members': maxMembers,
    'current_members': currentMembers,
    'current_round': 1,
    'status': status,
    'start_date': startDate,
    'created_at': '2026-09-20T10:00:00.000000Z',
    'round': round ??
        {
          'number': 1,
          'expected_members': 12,
          'paid_members': 3,
          'is_funded': false,
        },
    'members': members ??
        [
          {
            'id': 1,
            'user_id': 7,
            'user_name': 'Awa Sanou',
            'position': null,
            'status': 'active',
            'delivery_status': 'not_applicable',
            'beneficiary_round': null,
            'delivered_at': null,
            'is_awaiting_delivery': false,
            'is_delivered': false,
          },
        ],
    'rotation_revealed': rotationRevealed,
    'is_member': isMember,
    'my_status': isMember ? 'active' : null,
    'my_delivery_status': null,
    'is_creator': isCreator,
    'can_edit': canEdit,
    'edit_locked_reason': editLockedReason,
    ...overrides,
  };
}

/// Un membre unique, dans la forme `TontineResource` l'envoie.
Map<String, dynamic> memberJson({
  int userId = 7,
  String name = 'Awa Sanou',
  int? position,
  String status = 'active',
  String deliveryStatus = 'not_applicable',
}) {
  return {
    'id': userId,
    'user_id': userId,
    'user_name': name,
    'position': position,
    'status': status,
    'delivery_status': deliveryStatus,
    'beneficiary_round': null,
    'delivered_at': null,
    'is_awaiting_delivery': false,
    'is_delivered': false,
  };
}

/// Un produit SANS photo.
///
/// Les tests d'écran doivent être déterministes : une vraie image passe par
/// `CachedNetworkImage`, donc par le réseau, donc par une attente variable.
/// Sans photo, le rendu prend l'avatar, qui est purement local.
Map<String, dynamic> productWithoutImage({int id = 10, String name = 'Smartphone'}) {
  return {
    'id': id,
    'name': name,
    'price': '300000.00',
    'merchant_id': 4,
  };
}

/// Enveloppe paginée Laravel autour de [items].
///
/// [currentPage] est paramétré parce que le contrôleur lit la page courante
/// dans la réponse : une fixture figée à 1 ferait croire que « charger plus »
/// n'avance jamais, et le test passerait à côté du vrai comportement.
Map<String, dynamic> pagedJson(
  List<Map<String, dynamic>> items, {
  int currentPage = 1,
  int lastPage = 1,
  int total = 0,
}) {
  return {
    'data': items,
    'links': {
      'first': 'http://api.test/api/tontines?page=1',
      'last': 'http://api.test/api/tontines?page=$lastPage',
      'prev': currentPage > 1 ? 'http://api.test/api/tontines?page=${currentPage - 1}' : null,
      'next': currentPage < lastPage ? 'http://api.test/api/tontines?page=${currentPage + 1}' : null,
    },
    'meta': {
      'current_page': currentPage,
      'from': 1,
      'last_page': lastPage,
      'per_page': 12,
      'to': items.length,
      'total': total,
    },
  };
}
