import 'dart:convert';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:tontine_achat_store/core/config/app_config.dart';
import 'package:tontine_achat_store/core/di/providers.dart';
import 'package:tontine_achat_store/core/network/api_client.dart';
import 'package:tontine_achat_store/core/sync/live_sync.dart';
import 'package:tontine_achat_store/features/contributions/contribution_providers.dart';
import 'package:tontine_achat_store/features/tontines/tontine_providers.dart';

/// Un `ContributionResource` minimal mais complet.
Map<String, dynamic> contributionJson({int id = 1, String status = 'pending'}) {
  return {
    'id': id,
    'round': 1,
    'amount': '10000.00',
    'commission_amount': '0.00',
    'currency': 'XOF',
    'payment_method': null,
    'status': status,
    'verification_status': 'not_applicable',
    'transaction_reference': null,
    'transfer_code': null,
    'payment_failure_reason': null,
    'submitted_at': null,
    'paid_at': null,
    'tontine': {'id': 11, 'name': 'test2', 'status': 'active', 'type': 'cash'},
  };
}

http.Response jsonBody(Object body, {int status = 200}) => http.Response(
      jsonEncode(body),
      status,
      headers: {'content-type': 'application/json'},
    );

/// Journal des requêtes reçues.
class Calls {
  final List<String> entries = [];

  int countOf(String suffix) => entries.where((line) => line.endsWith(suffix)).length;
}

/// Laisse Riverpod exécuter ses reconstructions différées.
///
/// `ref.invalidate` ne reconstruit pas immédiatement : le framework programme la
/// tâche sur un timer de durée nulle. Sans ce tour de boucle, le test lirait un
/// état encore périmé — et laisserait un minuteur en attente.
Future<void> settle() => Future<void>.delayed(const Duration(milliseconds: 20));

/// Un conteneur dont l'API répond à `/contributions` — en marquant le tour 1
/// « payé » à partir du SECOND appel, ce qui simule une validation faite ailleurs
/// pendant que l'application était ouverte — et renvoie une liste vide partout
/// ailleurs.
ProviderContainer mount(Calls calls, {Duration interval = const Duration(minutes: 1)}) {
  return ProviderContainer(
    overrides: [
      // Le minuteur est piloté par le test, qui veut un rythme de quelques
      // millisecondes et non d'une minute.
      liveSyncProvider.overrideWith((ref) {
        final sync = LiveSync(ref, interval: interval, autoStart: false);
        ref.onDispose(sync.dispose);

        return sync;
      }),
      apiClientProvider.overrideWith((ref) {
        final client = ApiClient(
          httpClient: MockClient((request) async {
            calls.entries.add('${request.method} ${request.url.path}');

            if (!request.url.path.endsWith('/contributions')) {
              return jsonBody({'data': []});
            }

            final seen = calls.countOf('/contributions');
            return jsonBody([contributionJson(status: seen > 1 ? 'completed' : 'pending')]);
          }),
          baseUrl: 'http://api.test/api',
          readToken: () async => 'jeton',
          writeToken: (_) async {},
          clearToken: () async {},
        );
        ref.onDispose(client.dispose);

        return client;
      }),
    ],
  );
}

void main() {
  // `LiveSync` observe le cycle de vie de l'application : il lui faut donc un
  // binding, sans passer par `testWidgets` — dont l'horloge factice gellerait le
  // minuteur que ces tests sont précisément là pour vérifier.
  TestWidgetsFlutterBinding.ensureInitialized();

  group('resynchronisation automatique', () {
    test('marque les cotisations affichées comme périmées', () async {
      final calls = Calls();
      final container = mount(calls);
      addTearDown(container.dispose);

      // L'écran des cotisations est affiché : le provider a un observateur.
      final sub = container.listen(contributionsProvider, (_, __) {}, fireImmediately: true);
      addTearDown(sub.close);
      await container.read(contributionsProvider.future);

      expect(calls.countOf('/contributions'), 1);

      container.read(liveSyncProvider).syncNow();
      await settle();
      await container.read(contributionsProvider.future);

      // Sans resynchronisation, le tour 1 serait resté affiché comme à payer
      // alors qu'un administrateur venait de le valider depuis le back-office :
      // l'utilisateur aurait payé deux fois.
      expect(calls.countOf('/contributions'), 2);
      expect(container.read(contributionsProvider).requireValue.all.single.isSettled, isTrue);
    });

    test('ne touche pas aux écrans absents', () async {
      final calls = Calls();
      final container = mount(calls);
      addTearDown(container.dispose);

      // Personne ne regarde les notifications : les invalider ne doit lancer
      // aucune requête. Une resynchronisation qui interroge « tout » viderait la
      // batterie pour des écrans que l'utilisateur ne regarde pas.
      container.read(liveSyncProvider).syncNow();
      await settle();

      expect(calls.countOf('/notifications'), 0);
    });

    test('ne touche pas au catalogue : ses filtres sont une saisie', () async {
      // Le catalogue ne se rechargeait pas, et c'est voulu : ses filtres sont un
      // état de saisie. Les vider chaque minute reviendrait à les effacer sous
      // les doigts de l'utilisateur, en pleine recherche.
      final calls = Calls();
      final container = mount(calls);
      addTearDown(container.dispose);

      final sub = container.listen(tontineCatalogProvider, (_, __) {}, fireImmediately: true);
      addTearDown(sub.close);
      await container.read(tontineCatalogProvider.future);
      expect(calls.countOf('/tontines'), 1);

      container.read(liveSyncProvider).syncNow();
      await settle();

      expect(calls.countOf('/tontines'), 1);
    });

    test('deux resynchronisations rapprochées n\'en font qu\'une', () async {
      final calls = Calls();
      final container = mount(calls);
      addTearDown(container.dispose);

      final sub = container.listen(contributionsProvider, (_, __) {}, fireImmediately: true);
      addTearDown(sub.close);
      await container.read(contributionsProvider.future);

      final sync = container.read(liveSyncProvider);

      sync.sync();
      sync.sync();
      sync.sync();
      await settle();
      await container.read(contributionsProvider.future);

      // Changer d'onglet trois fois de suite ne doit pas produire trois
      // allers-retours : le garde-fou en absorbe deux.
      expect(calls.countOf('/contributions'), 2);
    });

    test('`syncNow` passe outre le garde-fou', () async {
      final calls = Calls();
      final container = mount(calls);
      addTearDown(container.dispose);

      final sub = container.listen(contributionsProvider, (_, __) {}, fireImmediately: true);
      addTearDown(sub.close);
      await container.read(contributionsProvider.future);

      final sync = container.read(liveSyncProvider);

      sync.sync();
      await settle();
      await container.read(contributionsProvider.future);
      expect(calls.countOf('/contributions'), 2);

      // Une action dont on sait qu'elle a changé quelque chose — un paiement,
      // une validation — ne doit pas attendre la fin de l'intervalle.
      sync.syncNow();
      await settle();
      await container.read(contributionsProvider.future);

      expect(calls.countOf('/contributions'), 3);
    });

    test('le retour au premier plan force la resynchronisation', () async {
      final calls = Calls();
      final container = mount(calls);
      addTearDown(container.dispose);

      final sub = container.listen(contributionsProvider, (_, __) {}, fireImmediately: true);
      addTearDown(sub.close);
      await container.read(contributionsProvider.future);

      final sync = container.read(liveSyncProvider);

      sync.sync();
      await settle();
      await container.read(contributionsProvider.future);
      expect(calls.countOf('/contributions'), 2);

      // Le garde-fou vient d'agir : une simple reprise du minuteur ne doit rien
      // relancer. Revenir au premier plan est une AUTRE situation — l'utilisateur
      // a pu agir sur un autre appareil pendant son absence, et attendre la fin
      // de l'intervalle le laisserait regarder un état périmé.
      sync.didChangeAppLifecycleState(AppLifecycleState.resumed);
      await settle();
      await container.read(contributionsProvider.future);

      expect(calls.countOf('/contributions'), 3);
    });

    test('le minuteur relit tout seul tant que l\'application est au premier plan', () async {
      final calls = Calls();
      final container = mount(calls, interval: const Duration(milliseconds: 20));
      addTearDown(container.dispose);

      final sub = container.listen(contributionsProvider, (_, __) {}, fireImmediately: true);
      addTearDown(sub.close);
      await container.read(contributionsProvider.future);
      expect(calls.countOf('/contributions'), 1);

      container.read(liveSyncProvider).start();
      await Future<void>.delayed(const Duration(milliseconds: 120));

      expect(calls.countOf('/contributions'), greaterThan(1));
    });

    test('l\'arrière-plan arrête le minuteur, la reprise le relance', () async {
      final calls = Calls();
      final container = mount(calls, interval: const Duration(milliseconds: 20));
      addTearDown(container.dispose);

      final sub = container.listen(contributionsProvider, (_, __) {}, fireImmediately: true);
      addTearDown(sub.close);
      await container.read(contributionsProvider.future);

      final sync = container.read(liveSyncProvider)..start();

      sync.didChangeAppLifecycleState(AppLifecycleState.paused);
      await Future<void>.delayed(const Duration(milliseconds: 120));

      // Un minuteur arrêté ne relance rien : c'est la reprise qui décide, pas
      // l'horloge. Aucun écran n'est relu, pas même celui qui est affiché.
      expect(calls.countOf('/contributions'), 1);

      sync.didChangeAppLifecycleState(AppLifecycleState.resumed);
      await Future<void>.delayed(const Duration(milliseconds: 120));

      expect(calls.countOf('/contributions'), greaterThan(1));

      sync.didChangeAppLifecycleState(AppLifecycleState.paused);
    });

    test('l\'interruption d\'une feuille ne suspend pas le minuteur', () async {
      // `inactive` survient à chaque ouverture de dialogue sous iOS. Le traiter
      // comme un arrière-plan suspendrait le minuteur à chaque feuille de
      // paiement — et le ferait repartir, donc relire tout, à chaque fermeture.
      final calls = Calls();
      final container = mount(calls, interval: const Duration(milliseconds: 20));
      addTearDown(container.dispose);

      final sub = container.listen(contributionsProvider, (_, __) {}, fireImmediately: true);
      addTearDown(sub.close);
      await container.read(contributionsProvider.future);
      expect(calls.countOf('/contributions'), 1);

      final sync = container.read(liveSyncProvider)..start();

      sync.didChangeAppLifecycleState(AppLifecycleState.inactive);
      await Future<void>.delayed(const Duration(milliseconds: 120));

      expect(calls.countOf('/contributions'), greaterThan(1));

      sync.didChangeAppLifecycleState(AppLifecycleState.paused);
    });
  });

  test('l\'intervalle de resynchronisation reste raisonnable', () {
    // Une requête par utilisateur et par minute : au-delà, le coût en data
    // devient visible pour une information qui change quelques fois par jour.
    expect(AppConfig.syncInterval, lessThanOrEqualTo(const Duration(minutes: 1)));
    expect(AppConfig.syncInterval, greaterThan(const Duration(seconds: 10)));
  });
}
