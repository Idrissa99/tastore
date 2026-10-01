import 'package:flutter_test/flutter_test.dart';
import 'package:tontine_achat_store/features/notifications/notification.dart';

/// Une notification telle que le paginateur Laravel les rend : `type` est un
/// NOM DE CLASSE PHP, `data` est libre, et `url` pointe vers le SPA web.
Map<String, dynamic> notificationJson({
  int id = 1,
  String type = r'App\Notifications\BecameBeneficiaryNotification',
  Map<String, dynamic>? data,
  Object? readAt,
}) {
  return {
    'id': id,
    'type': type,
    'notifiable_type': r'App\Models\User',
    'notifiable_id': 7,
    'data': data ??
        {
          'tontine_id': 11,
          'tontine_name': 'test2',
          'round': 1,
          'message': 'Tu es devenu bénéficiaire de la tontine « test2 ».',
          'url': 'http://localhost:5173/tontines/11',
        },
    'read_at': readAt,
    'created_at': '2026-09-28T10:00:00.000000Z',
  };
}

void main() {
  group('lecture', () {
    test('le message vient du backend, jamais reconstruit', () {
      // Le mobile et le web racontent le même événement : si l'un des deux
      // reformulait, l'utilisateur lirait deux versions du même fait.
      final notification = AppNotification.fromJson(notificationJson());

      expect(notification.message, 'Tu es devenu bénéficiaire de la tontine « test2 ».');
      expect(notification.kind, 'BecameBeneficiaryNotification');
      expect(notification.isRead, isFalse);
    });

    test('un message absent ne rend pas une ligne vide', () {
      final notification = AppNotification.fromJson(
        notificationJson(data: const {'tontine_name': 'test2'}),
      );

      expect(notification.message, isNotEmpty);
    });

    test('read_at absent signifie non lue', () {
      expect(AppNotification.fromJson(notificationJson()).isRead, isFalse);
      expect(
        AppNotification.fromJson(notificationJson(readAt: '2026-09-28T11:00:00Z')).isRead,
        isTrue,
      );
    });
  });

  group('destination mobile', () {
    // Le champ `url` pointe vers le SPA web sur `localhost:5173` : l'ouvrir
    // depuis un téléphone mènerait à une page injoignable. C'est son CHEMIN qui
    // est exploitable.
    String? pathOf(String spaPath) => AppNotification.fromJson(
          notificationJson(data: {'url': 'http://localhost:5173$spaPath'}),
        ).mobilePath;

    test('une tontine garde le même chemin', () {
      expect(pathOf('/tontines/11'), '/tontines/11');
    });

    test('les cotisations sont rebaptisées', () {
      // Le SPA appelle l'onglet « mes cotisations », l'application « Cotisations ».
      expect(pathOf('/mes-cotisations'), '/cotisations');
    });

    test('les achats gardent leur chemin', () {
      expect(pathOf('/mes-achats'), '/mes-achats');
      expect(pathOf('/mes-achats/7'), '/mes-achats/7');
    });

    test('une route sans écran mobile n\'est pas cliquable', () {
      // `/litiges` n'a pas d'écran dans l'application : le proposer mènerait à
      // une impasse.
      expect(pathOf('/litiges/3'), isNull);
    });

    test('le profil est joignable depuis une notification', () {
      // Régression : `/profil` manquait dans la table de traduction alors que
      // l'écran existe. Les notifications de validation d'e-mail n'avaient donc
      // aucune destination — et l'application n'accordant aucun appui à une
      // notification sans destination, elles ne pouvaient JAMAIS être marquées
      // comme lues. La pastille rouge de l'accueil restait donc allumée.
      expect(pathOf('/profil'), '/profil');
    });

    test('une notification sans lien n\'est pas cliquable', () {
      final notification = AppNotification.fromJson(
        notificationJson(data: const {'message': 'Bonjour'}),
      );

      expect(notification.mobilePath, isNull);
    });

    test('les notifications pointant sur l\'onglet des notifications ne s\'auto-ouvrent pas', () {
      // S'y ouvrir créerait une boucle : la notification conduit à l'écran
      // qui affiche cette notification.
      expect(pathOf('/notifications'), isNull);
    });
  });
}
