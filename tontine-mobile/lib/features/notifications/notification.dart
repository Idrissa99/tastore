import 'package:flutter/material.dart';
import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';

/// Une notification de l'utilisateur.
///
/// `GET /notifications` ne renvoie PAS une ressource dédiée : c'est le
/// paginateur brut des notifications de base de Laravel. Chaque entrée porte
/// donc un `type` qui est un NOM DE CLASSE PHP (`App\Notifications\…`), un
/// `data` libre, et un `url` déjà construit par le backend.
class AppNotification {
  const AppNotification({
    required this.id,
    required this.type,
    required this.data,
    required this.isRead,
    required this.createdAt,
  });

  factory AppNotification.fromJson(Map<String, dynamic> json) {
    final data = json['data'];

    return AppNotification(
      id: Fmt.toInt(json['id']),
      type: json['type']?.toString() ?? '',
      data: data is Map ? Map<String, dynamic>.from(data) : const {},
      isRead: json['read_at'] != null,
      createdAt: Fmt.parseDate(json['created_at']),
    );
  }

  final int id;
  final String type;
  final Map<String, dynamic> data;
  final bool isRead;
  final DateTime? createdAt;

  /// Nom de la classe, sans le namespace — c'est ce qui sert à choisir
  /// l'icône et la teinte.
  ///
  /// Le séparateur est une ANTISLASH : Laravel sérialise le FQCN
  /// `App\Notifications\…`, pas un nom pointé. Découper sur un point ne
  /// retirerait donc rien et laisserait l'icône de repli sur toutes les
  /// notifications.
  String get kind {
    final parts = type.split(RegExp(r'[.\\]')).where((part) => part.isNotEmpty);
    return parts.isEmpty ? '' : parts.last;
  }

  /// Le texte à afficher.
  ///
  /// `message` est produit par le backend pour chaque notification ; sa valeur
  /// est reprise telle quelle plutôt que reconstituée ici, faute de quoi le
  /// mobile finirait par raconter les événements avec ses propres mots — et
  /// divergerait du web.
  String get message {
    final text = data['message']?.toString() ?? '';
    if (text.isNotEmpty) return text;

    return 'Mise à jour de ${data['tontine_name'] ?? data['product_name'] ?? 'votre espace'}.';
  }

  /// Destination dans l'application, déduite du CHEMIN du lien fourni par le
  /// backend.
  ///
  /// Le `url` pointe vers le SPA web (`http://localhost:5173/mes-cotisations`),
  /// inutilisable sur mobile. Son **chemin**, en revanche, est la seule chose
  /// qui exprime l'intention : il dit où l'utilisateur veut aller. On le traduit
  /// donc au lieu de dupliquer, côté client, une table par type de notification
  /// — cette table serait à maintenir en même temps que le backend.
  ///
  /// Une route inconnue ne produit aucune destination : la notification reste
  /// lisible, elle n'est simplement pas cliquable.
  String? get mobilePath {
    final url = data['url']?.toString() ?? '';
    if (url.isEmpty) return null;

    final path = Uri.tryParse(url)?.path ?? url;

    for (final mapping in _pathTranslations) {
      if (path == mapping.spa || path.startsWith('${mapping.spa}/')) {
        final id = path.substring(mapping.spa.length).replaceFirst('/', '');
        final parsed = int.tryParse(id);

        // Les onglets n'existent que comme racines ; un identifiant manquant
        // ou non numérique ne doit pas fabriquer une route.
        return mapping.mobile == null || parsed == null ? mapping.mobile : '${mapping.mobile}/$parsed';
      }
    }

    return null;
  }

  /// Icône et teinte par famille d'événement.
  IconData get icon => switch (kind) {
        'BecameBeneficiaryNotification' => Icons.emoji_events_outlined,
        'TontineUpdatedNotification' => Icons.campaign_outlined,
        'DeliveryConfirmedNotification' => Icons.local_shipping_outlined,
        'ContributionVerificationUpdatedNotification' => Icons.fact_check_outlined,
        'InstallmentPurchaseCompletedNotification' => Icons.shopping_bag_outlined,
        'InstallmentDeliveryConfirmedNotification' => Icons.inventory_2_outlined,
        'InstallmentVerificationUpdatedNotification' => Icons.receipt_outlined,
        'EmailVerifiedByAdminNotification' => Icons.verified_outlined,
        _ => Icons.notifications_none,
      };

  AppTone get tone => switch (kind) {
        'EmailVerifiedByAdminNotification' => AppTone.success,
        'InstallmentDeliveryConfirmedNotification' => AppTone.success,
        _ => isRead ? AppTone.neutral : AppTone.brand,
      };
}

/// Traduction des routes du SPA web vers celles de l'application.
///
/// Volontairement restreint aux seules destinations qui existent côté mobile :
/// une entrée de plus que d'écrans, et l'application ouvrirait une page qui ne
/// mène nulle part.
///
/// `/litiges` en est absent faute d'écran. `/profil` y figure : l'écran existe,
/// et son absence ici rendait les notifications de validation d'e-mail
/// IMPOSSIBLES à marquer comme lues — sans destination, l'app ne leur
/// accolait aucun appui, donc elles restaient non lues pour toujours et la
/// pastille rouge de l'accueil ne descendait jamais à zéro.
const _pathTranslations = <({String spa, String? mobile})>[
  (spa: '/tontines', mobile: '/tontines'),
  (spa: '/mes-cotisations', mobile: '/cotisations'),
  (spa: '/mes-achats', mobile: '/mes-achats'),
  (spa: '/profil', mobile: '/profil'),
  (spa: '/notifications', mobile: null),
];
