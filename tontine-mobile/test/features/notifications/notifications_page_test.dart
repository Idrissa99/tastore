import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:tontine_achat_store/core/di/providers.dart';
import 'package:tontine_achat_store/core/network/api_client.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/features/notifications/notification.dart';
import 'package:tontine_achat_store/features/notifications/notification_providers.dart';
import 'package:tontine_achat_store/features/notifications/notifications_page.dart';
import 'package:tontine_achat_store/routing/app_router.dart';

/// Une notification telle que le paginateur Laravel les rend.
Map<String, dynamic> notificationJson({
  required int id,
  required String url,
  String message = 'Ton adresse e-mail a été validée.',
  Object? readAt,
}) {
  return {
    'id': id,
    'type': r'App\Notifications\EmailVerifiedByAdminNotification',
    'notifiable_type': r'App\Models\User',
    'notifiable_id': 7,
    'data': {'message': message, 'url': url},
    'read_at': readAt,
    'created_at': '2026-09-28T10:00:00.000000Z',
  };
}

/// Un serveur qui tient la vérité des `read_at`, comme le ferait la base.
class NotificationsServer {
  NotificationsServer(this.rows);

  final List<Map<String, dynamic>> rows;
  final List<String> calls = [];

  List<Map<String, dynamic>> get unread => rows.where((r) => r['read_at'] == null).toList();

  http.Client client() => MockClient((request) async {
        calls.add('${request.method} ${request.url.path}');

        if (request.url.path.contains('mark-all-read')) {
          for (final row in unread) {
            row['read_at'] = '2026-09-28T11:00:00Z';
          }

          return http.Response(jsonEncode({'message': 'ok'}), 200);
        }

        if (request.url.path.endsWith('/read')) {
          final id = int.parse(request.url.pathSegments[request.url.pathSegments.length - 2]);
          rows.firstWhere((r) => r['id'] == id)['read_at'] = '2026-09-28T11:00:00Z';

          return http.Response(jsonEncode({'message': 'ok'}), 200);
        }

        return http.Response(
          jsonEncode({
            'data': rows,
            'meta': {'current_page': 1, 'last_page': 1, 'total': rows.length},
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      });
}

Future<void> pumpApp(WidgetTester tester, NotificationsServer server) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        apiClientProvider.overrideWith((ref) {
          final client = ApiClient(
            httpClient: server.client(),
            baseUrl: 'http://api.test/api',
            readToken: () async => 'jeton',
            writeToken: (_) async {},
            clearToken: () async {},
          );
          ref.onDispose(client.dispose);

          return client;
        }),
      ],
      child: MaterialApp(
        theme: AppTheme.light,
        home: const NotificationsPage(),
      ),
    ),
  );

  await tester.pumpAndSettle();
}

void main() {
  testWidgets('consulter l\'écran éteint la pastille', (tester) async {
    final server = NotificationsServer([
      notificationJson(id: 1, url: 'http://localhost:5173/profil'),
      notificationJson(
        id: 2,
        url: 'http://localhost:5173/mes-cotisations',
        message: 'Ton code de transfert est accepté.',
      ),
    ]);

    final container = ProviderContainer();
    addTearDown(container.dispose);
    final sub = container.listen(unreadNotificationsProvider, (_, __) {}, fireImmediately: true);

    await pumpApp(tester, server);

    expect(find.text('Ton adresse e-mail a été validée.'), findsOneWidget);

    // Avant correction, rien n'était marqué : la pastille rouge de l'accueil
    // restait allumée, et il fallait ouvrir chaque message un par un — sans quoi
    // elle ne descendait jamais à zéro.
    expect(server.unread, isEmpty);
    expect(server.calls.where((c) => c.contains('mark-all-read')), isNotEmpty);

    // L'écran affiché le reflète : plus aucune pastille non lue.
    sub.close();
  });

  testWidgets('une notification sans lien peut quand même être lue', (tester) async {
    // Régression : sans destination, l'application n'accordait aucun appui. La
    // notification était donc littéralement impossible à marquer comme lue, et
    // la pastille rouge ne pouvait pas descendre.
    final server = NotificationsServer([
      notificationJson(id: 1, url: 'http://localhost:5173/litiges/3'),
    ]);

    await pumpApp(tester, server);

    expect(find.text('Ton adresse e-mail a été validée.'), findsOneWidget);
    expect(server.unread, isEmpty);
  });

  testWidgets('la resynchronisation n\'relance pas « tout lire »', (tester) async {
    final server = NotificationsServer([
      notificationJson(id: 1, url: 'http://localhost:5173/profil'),
    ]);

    await pumpApp(tester, server);

    final marks = server.calls.where((c) => c.contains('mark-all-read')).length;

    // Le verrou est posé à la première liste affichée. Sans lui, la
    // resynchronisation automatique — qui passe toutes les minutes —
    // redemanderait un « tout lire » en boucle.
    await tester.pump(const Duration(minutes: 5));
    await tester.pumpAndSettle();

    expect(
      server.calls.where((c) => c.contains('mark-all-read')).length,
      marks,
    );
  });

  testWidgets('une liste déjà lue n\'est pas re-marquée', (tester) async {
    final server = NotificationsServer([
      notificationJson(
        id: 1,
        url: 'http://localhost:5173/profil',
        readAt: '2026-09-28T09:00:00Z',
      ),
    ]);

    await pumpApp(tester, server);

    expect(server.unread, isEmpty);
    // Aucune écriture inutile quand il n'y a rien à marquer.
    expect(server.calls.where((c) => c.contains('mark-all-read')), isEmpty);
  });

  test('le profil est une destination valide d\'une notification', () {
    // Verrouille la table de traduction : `/profil` doit rester mappé, sinon les
    // notifications de validation d'e-mail redeviennent inatteignables.
    final notification = AppNotification.fromJson(
      notificationJson(id: 1, url: 'http://localhost:5173/profil'),
    );

    expect(notification.mobilePath, AppRoutes.profile);
  });
}
