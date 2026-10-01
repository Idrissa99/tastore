import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:tontine_achat_store/core/di/providers.dart';
import 'package:tontine_achat_store/core/network/api_client.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/features/contributions/contributions_page.dart';
import 'package:tontine_achat_store/features/payments/payment_channels.dart';

Map<String, dynamic> contributionJson({
  int id = 1,
  int round = 1,
  String status = 'pending',
  String verificationStatus = 'not_applicable',
}) {
  return {
    'id': id,
    'round': round,
    'amount': '133750.00',
    'commission_amount': '3750.00',
    'currency': 'XOF',
    'payment_method': null,
    'status': status,
    'verification_status': verificationStatus,
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

/// Les moyens déclarés par `GET /config`.
const allChannels = PaymentChannels({
  'orange_money': 'Orange Money',
  'mynita': 'MyNita',
  'bank_transfer': 'Virement bancaire',
});

const configJson = {
  'payment_channels': {
    'orange_money': 'Orange Money',
    'mynita': 'MyNita',
    'bank_transfer': 'Virement bancaire',
  },
};

/// Monte l'onglet « Cotisations » avec un seul appel à chaque ressource.
Future<void> pumpContributions(
  WidgetTester tester, {
  List<Map<String, dynamic>>? contributions,
  List<String>? log,
}) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        apiClientProvider.overrideWith((ref) {
          final client = ApiClient(
            httpClient: MockClient((request) async {
              log?.add('${request.method} ${request.url.path}');

              if (request.url.path.endsWith('/config')) return jsonBody(configJson);
              if (request.url.path.endsWith('/contributions')) {
                return jsonBody(contributions ?? [contributionJson()]);
              }
              if (request.url.path.endsWith('/tontines/11')) {
                return jsonBody({
                  'data': {
                    'id': 11,
                    'name': 'test2',
                    'type': 'cash',
                    'status': 'active',
                    'frequency': 'monthly',
                    'start_date': '2026-09-01',
                  },
                });
              }

              return jsonBody({'data': []});
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
      child: MaterialApp(
        theme: AppTheme.light,
        home: const ContributionsPage(),
      ),
    ),
  );

  await tester.pump();
  await tester.pump();
}

void main() {
  testWidgets('les moyens sont visibles sur la carte, avant « Payer »', (tester) async {
    await pumpContributions(tester);

    // Régression de la demande : les moyens n'étaient proposés qu'à l'intérieur
    // de la feuille. Il fallait donc engager la procédure pour découvrir ses
    // options, alors que l'utilisateur devait justement les voir AVANT de
    // choisir — et savoir qu'un code de transfert lui serait demandé.
    expect(find.text('Comment veux-tu payer ?'), findsOneWidget);
    for (final label in ['Orange Money', 'MyNita', 'Virement bancaire']) {
      expect(find.text(label), findsOneWidget, reason: label);
    }
  });

  testWidgets('un canal manuel annonce le code de transfert dès la carte', (tester) async {
    await pumpContributions(tester);

    await tester.tap(find.text('MyNita'));
    await tester.pump();

    expect(find.textContaining('code de transfert'), findsOneWidget);
  });

  testWidgets('le canal retenu sur la carte est celui envoyé', (tester) async {
    final log = <String>[];

    await pumpContributions(tester, log: log);

    await tester.ensureVisible(find.text('Virement bancaire'));
    await tester.tap(find.text('Virement bancaire'));
    await tester.pump();

    // On ouvre la feuille : elle ne doit plus redemander le moyen.
    await tester.ensureVisible(find.text('Payer'));
    await tester.tap(find.text('Payer'));
    await tester.pumpAndSettle();

    expect(find.text('Code de transfert'), findsNothing);
    expect(find.textContaining('Moyen de paiement : Virement bancaire'), findsOneWidget);

    // Portée à la FEUILLE : la carte reste derrière le fond, ses puces y sont
    // toujours affichées — c'est bien le but.
    final inSheet = find.descendant(of: find.byType(BottomSheet), matching: find.text('Orange Money'));
    expect(inSheet, findsNothing);
  });

  testWidgets('sans choix explicite, le premier moyen reste payable', (tester) async {
    await pumpContributions(tester);

    // Le bouton ne doit pas être un piège : le premier moyen configuré est
    // présélectionné, et reste modifiable sur la carte.
    final button = tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Payer'));
    expect(button.onPressed, isNotNull);
  });

  testWidgets('la configuration des moyens est demandée avec les cotisations', (tester) async {
    final log = <String>[];

    await pumpContributions(tester, log: log);

    // Un seul aller-retour pour l'écran, et non un second au moment de payer :
    // la liste doit être là AVANT que l'utilisateur décide.
    expect(log.where((line) => line.endsWith('/config')), hasLength(1));
  });

  testWidgets('une cotisation déjà payée ne propose aucun moyen', (tester) async {
    await pumpContributions(
      tester,
      contributions: [contributionJson(status: 'completed')],
    );

    // Onglet « Terminées » : proposer de payer serait absurde.
    await tester.tap(find.text('Terminées'));
    await tester.pumpAndSettle();

    expect(find.text('Comment veux-tu payer ?'), findsNothing);
    expect(find.text('Payer'), findsNothing);
  });

  testWidgets('un code en attente de vérification n\'est pas repayable', (tester) async {
    await pumpContributions(
      tester,
      contributions: [contributionJson(verificationStatus: 'pending')],
    );

    await tester.tap(find.text('En attente'));
    await tester.pumpAndSettle();

    expect(find.text('Comment veux-tu payer ?'), findsNothing);
    expect(find.textContaining('en attente de vérification'), findsOneWidget);
  });

  testWidgets('le canal retenu est conservé d\'une carte à l\'autre', (tester) async {
    await pumpContributions(
      tester,
      contributions: [
        contributionJson(id: 1, round: 1),
        contributionJson(id: 2, round: 2),
      ],
    );

    await tester.tap(find.text('MyNita').first);
    await tester.pump();

    // Les deux cartes sont affichées : choisir « MyNita » sur l'une ne doit pas
    // changer le moyen de l'autre.
    final selected = tester
        .widgetList<ChoiceChip>(find.byType(ChoiceChip))
        .where((chip) => (chip.label as Text).data == 'MyNita' && chip.selected)
        .length;

    expect(selected, 1);
  });
}
