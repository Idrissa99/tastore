import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:tontine_achat_store/core/di/providers.dart';
import 'package:tontine_achat_store/core/network/api_client.dart';
import 'package:tontine_achat_store/features/contributions/contribution.dart';
import 'package:tontine_achat_store/features/contributions/contribution_providers.dart';

/// Un `ContributionResource` complet, dans la forme exacte du serveur.
Map<String, dynamic> contributionJson({
  int id = 1,
  int round = 1,
  String amount = '133750.00',
  String status = 'pending',
  String verificationStatus = 'not_applicable',
  String? paymentMethod,
  String? verificationReason,
  Map<String, dynamic>? tontine,
}) {
  return {
    'id': id,
    'round': round,
    'amount': amount,
    'commission_amount': '3750.00',
    'currency': 'XOF',
    'payment_method': paymentMethod,
    'status': status,
    'verification_status': verificationStatus,
    'transaction_reference': null,
    'transfer_code': null,
    'payment_failure_reason': verificationReason,
    'submitted_at': null,
    'paid_at': null,
    'tontine': tontine ??
        {'id': 11, 'name': 'test2', 'status': 'active', 'type': 'cash'},
  };
}

http.Response jsonBody(Object body, {int status = 200}) => http.Response(
      jsonEncode(body),
      status,
      headers: {'content-type': 'application/json'},
    );

ProviderContainer mount(Future<http.Response> Function(http.Request request) handler) {
  final calls = <http.Request>[];

  return ProviderContainer(
    overrides: [
      apiClientProvider.overrideWith((ref) {
        final client = ApiClient(
          httpClient: MockClient((request) {
            calls.add(request);
            return handler(request);
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
  group('enveloppes : le contrat réel de cette ressource', () {
    test('GET /contributions renvoie un TABLEAU nu, pas un objet', () async {
      // Les contrôleurs rendent `…->resolve($request)`, qui ne produit pas
      // d'enveloppe. Appliquer la décompression des autres ressources y
      // renverrait un objet sans champs, et la liste paraîtrait vide sans
      // aucune erreur.
      final container = mount((_) async => jsonBody([contributionJson(id: 1)]));
      addTearDown(container.dispose);

      final all = await container.read(contributionRepositoryProvider).index();

      expect(all.length, 1);
      expect(all.single.amount, 133750);
      expect(all.single.tontine.name, 'test2');
    });

    test('une enveloppe « data » reste acceptée', () async {
      // Le format des autres ressources : s'il revenait un jour, l'écran ne
      // doit pas se vider en silence.
      final container = mount((_) async => jsonBody({'data': [contributionJson(id: 2)]}));
      addTearDown(container.dispose);

      final all = await container.read(contributionRepositoryProvider).index();

      expect(all.single.id, 2);
    });

    test('pay renvoie un objet NU, pas enveloppé', () async {
      final container = mount(
        (request) async => jsonBody(
          request.method == 'POST'
              ? contributionJson(id: 3, status: 'completed')
              : const <dynamic>[],
        ),
      );
      addTearDown(container.dispose);

      final updated = await container
          .read(contributionRepositoryProvider)
          .pay(3, channel: 'orange_money');

      expect(updated.id, 3);
      expect(updated.status, ContributionStatus.completed);
      expect(updated.isSettled, isTrue);
    });

    test('soumettre-code renvoie aussi un objet nu', () async {
      final container = mount(
        (request) async => jsonBody(
          request.method == 'POST'
              ? contributionJson(id: 4, verificationStatus: 'pending', paymentMethod: 'mynita')
              : const <dynamic>[],
        ),
      );
      addTearDown(container.dispose);

      final updated = await container
          .read(contributionRepositoryProvider)
          .submitTransferCode(4, channel: 'mynita', transferCode: 'ABC123');

      expect(updated.verificationStatus, VerificationStatus.pending);
      expect(updated.awaitsVerification, isTrue);
    });
  });

  group('répartition', () {
    test('les quatre états ne se confondent pas', () {
      final state = ContributionsState([
        Contribution.fromJson(contributionJson(id: 1)), // à payer
        Contribution.fromJson(contributionJson(id: 2, verificationStatus: 'pending')),
        Contribution.fromJson(contributionJson(id: 3, status: 'completed')),
        Contribution.fromJson(contributionJson(id: 4, verificationStatus: 'rejected')),
        Contribution.fromJson(contributionJson(id: 5, status: 'cancelled')),
      ]);

      expect(state.awaiting.map((c) => c.id), [2]);
      expect(state.settled.map((c) => c.id), [3]);
      expect(state.rejected.map((c) => c.id), [4]);

      // La 4 revient dans « à payer » : un code refusé laisse la cotisation
      // `pending` côté serveur, elle est donc à repayer. La 5, annulée, n'est
      // ni à payer ni réglée.
      expect(state.todo.map((c) => c.id), [1, 4]);
    });

    test('un code REFUSé redevient payable', () {
      // Le serveur ne remet PAS le statut à « pending » : la cotisation l'est
      // déjà, seule la vérification change. La laisser hors des deux listes
      // la rendrait invisible et non payable.
      final rejected = Contribution.fromJson(contributionJson(verificationStatus: 'rejected'));

      expect(rejected.isSettled, isFalse);
      expect(rejected.isPayable, isTrue);
      expect(rejected.verificationStatus.isRejected, isTrue);
    });

    test('le reste à verser additionne les cotisations payables', () {
      final state = ContributionsState([
        Contribution.fromJson(contributionJson(id: 1, amount: '10000.00')),
        Contribution.fromJson(contributionJson(id: 2, amount: '25000.00')),
        Contribution.fromJson(contributionJson(id: 3, amount: '90000.00', status: 'completed')),
      ]);

      expect(state.remainingAmount, 35000);
    });

    test('un code en attente de vérification sort du reste à verser', () {
      final state = ContributionsState([
        Contribution.fromJson(contributionJson(id: 1, amount: '10000.00')),
        Contribution.fromJson(
          contributionJson(id: 2, amount: '70000.00', verificationStatus: 'pending'),
        ),
      ]);

      expect(state.remainingAmount, 10000);
    });
  });

  group('motifs de refus', () {
    String reasonFor(Map<String, dynamic> json) =>
        Contribution.fromJson(json).payBlockedReason(manualChannel: false);

    test('déjà payée', () {
      expect(reasonFor(contributionJson(status: 'completed')), 'Cette cotisation est déjà payée.');
    });

    test('annulée', () {
      expect(reasonFor(contributionJson(status: 'cancelled')), contains('annulée'));
    });

    test('code déjà en attente', () {
      expect(reasonFor(contributionJson(verificationStatus: 'pending')), contains('déjà en attente'));
    });

    test('une cotisation payable n\'a aucun motif', () {
      expect(reasonFor(contributionJson()), isEmpty);
    });
  });

  group('contrôleur', () {
    test('une cotisation payée quitte « à payer » sans rechargement', () async {
      final container = mount((request) async {
        return jsonBody(
          request.method == 'POST'
              ? contributionJson(id: 1, status: 'completed')
              : [contributionJson(id: 1), contributionJson(id: 2)],
        );
      });
      addTearDown(container.dispose);

      final controller = container.read(contributionsProvider.notifier);
      await container.read(contributionsProvider.future);
      expect(container.read(contributionsProvider).requireValue.todo.length, 2);

      await controller.pay(1, channel: 'orange_money');

      final state = container.read(contributionsProvider).requireValue;
      expect(state.todo.map((c) => c.id), [2]);
      expect(state.settled.map((c) => c.id), [1]);
    });

    test('un refus 409 remonte en exception, sans être avalé', () async {
      final container = mount((request) async {
        if (request.method == 'POST') {
          return jsonBody({'message': 'Cette cotisation est déjà payée.'}, status: 409);
        }
        return jsonBody([contributionJson(id: 1)]);
      });
      addTearDown(container.dispose);

      final controller = container.read(contributionsProvider.notifier);
      await container.read(contributionsProvider.future);

      await expectLater(
        controller.pay(1, channel: 'orange_money'),
        throwsA(
          isA<Object>().having((error) => '$error', 'message', contains('déjà payée')),
        ),
      );
    });
  });
}
