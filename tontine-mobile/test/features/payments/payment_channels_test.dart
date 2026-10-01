import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/features/contributions/contribution.dart';
import 'package:tontine_achat_store/features/contributions/contributions_page.dart';
import 'package:tontine_achat_store/features/payments/payment_channel_picker.dart';
import 'package:tontine_achat_store/features/payments/payment_channels.dart';
import 'package:tontine_achat_store/features/payments/payment_sheet.dart';

/// Un `ContributionResource` minimal mais complet.
Map<String, dynamic> contributionJson({int id = 1, int round = 1}) {
  return {
    'id': id,
    'round': round,
    'amount': '133750.00',
    'commission_amount': '3750.00',
    'currency': 'XOF',
    'payment_method': null,
    'status': 'pending',
    'verification_status': 'not_applicable',
    'transaction_reference': null,
    'transfer_code': null,
    'payment_failure_reason': null,
    'submitted_at': null,
    'paid_at': null,
    'tontine': {'id': 11, 'name': 'test2', 'status': 'active', 'type': 'cash'},
  };
}

/// Les moyens déclarés par `GET /config`.
const allChannels = PaymentChannels({
  'orange_money': 'Orange Money',
  'mynita': 'MyNita',
  'bank_transfer': 'Virement bancaire',
});

/// Ouvre la feuille de versement et renvoie les canaux effectivement envoyés.
Future<List<String>> pumpSheet(
  WidgetTester tester, {
  String? initialChannel,
  PaymentChannels channels = allChannels,
  Future<void> Function(String, String?, String?)? onSubmit,
}) async {
  final submitted = <String>[];

  await tester.pumpWidget(
    ProviderScope(
      // Le VRAI provider, pas un doublon : la feuille lit `paymentChannelsProvider`
      // et afficherait sinon un indicateur de chargement éternel, faute de
      // serveur.
      overrides: [paymentChannelsProvider.overrideWith((ref) async => channels)],
      child: MaterialApp(
        theme: AppTheme.light,
        home: Scaffold(
          body: Builder(
            builder: (context) => ElevatedButton(
              onPressed: () => PaymentSheet.show(
                context,
                title: 'Cotiser',
                subtitle: 'test2 · tour 1',
                amount: 133750,
                submitLabel: 'Confirmer le versement',
                initialChannel: initialChannel,
                onSubmit: (channel, reference, code) async {
                  submitted.add(channel);
                  await onSubmit?.call(channel, reference, code);
                },
              ),
              child: const Text('ouvrir'),
            ),
          ),
        ),
      ),
    ),
  );

  await tester.tap(find.text('ouvrir'));
  await tester.pumpAndSettle();

  return submitted;
}

void main() {
  group('les moyens sont affichés avant de choisir', () {
    testWidgets('la feuille les présente tous d\'emblée', (tester) async {
      await pumpSheet(tester);

      for (final label in ['Orange Money', 'MyNita', 'Virement bancaire']) {
        expect(find.text(label), findsOneWidget, reason: label);
      }
    });

    testWidgets('le champ utile apparaît sans qu\'on ait à choisir', (tester) async {
      await pumpSheet(tester);

      // Le premier moyen est présélectionné : l'utilisateur voit tout de suite
      // ce qu'il doit fournir, au lieu d'un écran vide qui n'attend qu'un appui.
      expect(find.text('Référence de transaction (facultatif)'), findsOneWidget);
    });

    testWidgets('choisir un canal manuel fait apparaître le code de transfert', (
      tester,
    ) async {
      await pumpSheet(tester);

      await tester.tap(find.text('MyNita'));
      await tester.pumpAndSettle();

      expect(find.text('Code de transfert'), findsOneWidget);
      expect(find.text('Référence de transaction (facultatif)'), findsNothing);
    });

    testWidgets('un canal choisi en amont n\'est pas redemandé', (tester) async {
      final submitted = await pumpSheet(tester, initialChannel: 'mynita');

      // Le choix a déjà été fait et affiché sur la carte : la feuille ne
      // répète pas la liste, elle confirme et demande le code.
      expect(find.text('Code de transfert'), findsOneWidget);
      expect(find.text('Moyen de paiement'), findsNothing);
      expect(find.textContaining('Moyen de paiement : MyNita'), findsOneWidget);

      // Ni « Orange Money » ni « Virement bancaire » : il n'y a plus à trancher.
      expect(find.text('Orange Money'), findsNothing);
      expect(find.text('Virement bancaire'), findsNothing);

      await tester.enterText(find.byType(TextField), 'AB12CD');
      await tester.pumpAndSettle();
      await tester.tap(find.text('Confirmer le versement'));
      await tester.pumpAndSettle();

      expect(submitted, ['mynita']);
    });

    testWidgets('un canal inconnu de la configuration est ignoré', (tester) async {
      // `initialChannel` vient de l'écran appelant, qui lit la même `/config` :
      // la garde est là pour qu'un canal retiré côté serveur ne produise pas un
      // 422 au moment de valider, et pour que l'utilisateur puisse encore
      // choisir — plutôt que de valider dans le vide.
      final submitted = await pumpSheet(tester, initialChannel: 'espece');

      // Le sélecteur est de retour : il n'y a plus de choix fiable à confirmer.
      expect(find.text('Moyen de paiement'), findsOneWidget);
      expect(find.textContaining('Moyen de paiement :'), findsNothing);
      expect(find.text('Référence de transaction (facultatif)'), findsOneWidget);

      await tester.tap(find.text('Confirmer le versement'));
      await tester.pumpAndSettle();

      // Retombé sur la présélection par défaut, pas sur le canal fantôme.
      expect(submitted, ['orange_money']);
    });
  });

  group('le sélecteur part de la configuration du serveur', () {
    testWidgets('aucun moyen configuré le dit, plutôt que d\'afficher une liste vide', (
      tester,
    ) async {
      await pumpSheet(tester, channels: PaymentChannels.empty);

      expect(find.textContaining('Aucun moyen de paiement'), findsOneWidget);
    });

    testWidgets('un moyen unique reste sélectionnable', (tester) async {
      const only = PaymentChannels({'mynita': 'MyNita'});
      await pumpSheet(tester, channels: only);

      expect(find.text('MyNita'), findsOneWidget);
      expect(find.text('Code de transfert'), findsOneWidget);
    });
  });

  group('canal retenu par cotisation', () {
    test('un choix ne déborde pas sur les autres cotisations', () {
      final container = ProviderContainer();
      addTearDown(container.dispose);

      container.read(contributionChannelProvider(1).notifier).state = 'mynita';

      expect(container.read(contributionChannelProvider(1)), 'mynita');
      // Un même utilisateur règle une tontine à l'agence et une autre par
      // virement : la carte de la seconde ne doit pas afficher « MyNita ».
      expect(container.read(contributionChannelProvider(2)), isNull);
    });

    test('la contribution est bien celle du canal choisi', () {
      final container = ProviderContainer();
      addTearDown(container.dispose);

      final contribution = Contribution.fromJson(contributionJson(id: 7, round: 3));

      container.read(contributionChannelProvider(contribution.id).notifier).state = 'bank_transfer';

      expect(container.read(contributionChannelProvider(7)), 'bank_transfer');
    });
  });

  group('le sélecteur partagé', () {
    testWidgets('signale le canal sélectionné et notifie le changement', (tester) async {
      final picked = <String>[];

      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          home: Scaffold(
            body: PaymentChannelPicker(
              channels: allChannels,
              value: 'orange_money',
              onChanged: picked.add,
            ),
          ),
        ),
      );

      final orange = tester.widget<ChoiceChip>(find.widgetWithText(ChoiceChip, 'Orange Money'));
      expect(orange.selected, isTrue);

      await tester.tap(find.text('Virement bancaire'));
      expect(picked, ['bank_transfer']);
    });

    testWidgets('un sélecteur désactivé ne notifie rien', (tester) async {
      final picked = <String>[];

      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          home: Scaffold(
            body: PaymentChannelPicker(
              channels: allChannels,
              value: null,
              enabled: false,
              onChanged: picked.add,
            ),
          ),
        ),
      );

      await tester.tap(find.text('MyNita'));
      expect(picked, isEmpty);
    });

    testWidgets('les libellés des puces sont lisibles sur fond clair', (tester) async {
      // Régression : le thème fournissait un `labelStyle` de puce SANS couleur.
      // Material 3 ne retombe alors sur aucune couleur de repli, et les puces
      // non sélectionnées s'affichaient en blanc sur la carte blanche — des
      // rectangles vides. Seule la puce sélectionnée restait lisible, ce qui
      // rendait le défaut particulièrement trompeur : le sélecteur PARAÎSSAIT
      // vide alors qu'il était complet.
      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          home: const Scaffold(
            backgroundColor: Colors.white,
            body: PaymentChannelPicker(
              channels: allChannels,
              value: null,
              onChanged: _noop,
            ),
          ),
        ),
      );

      for (final label in ['Orange Money', 'MyNita', 'Virement bancaire']) {
        expect(find.widgetWithText(ChoiceChip, label), findsOneWidget, reason: label);

        // Le libellé est résolu par le thème, pas porté par la puce : c'est
        // donc le thème qu'il faut interroger.
        final color = Theme.of(
          tester.element(find.widgetWithText(ChoiceChip, label)),
        ).chipTheme.labelStyle?.color;

        expect(color, isNotNull, reason: label);
        expect(color, isNot(Colors.white), reason: '$label serait invisible sur une carte blanche');
      }
    });
  });
}

void _noop(String _) {}
