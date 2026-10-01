import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:tontine_achat_store/core/di/providers.dart';
import 'package:tontine_achat_store/core/network/api_client.dart';
import 'package:tontine_achat_store/core/storage/token_store.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/features/auth/register_page.dart';

class _MemoryTokenStore extends TokenStore {
  String? token;

  @override
  Future<String?> read() async => token;

  @override
  Future<void> write(String value) async => token = value;

  @override
  Future<void> clear() async => token = null;
}

/// Corps de la dernière requête émise, ou null si l'écran n'a rien envoyé.
Map<String, dynamic>? lastBody;

Future<void> pumpRegister(
  WidgetTester tester, {
  int status = 201,
}) async {
  final store = _MemoryTokenStore();
  lastBody = null;

  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        tokenStoreProvider.overrideWithValue(store),
        apiClientProvider.overrideWith((ref) {
          final client = ApiClient(
            httpClient: MockClient((request) async {
              lastBody = jsonDecode(request.body);

              if (status == 422) {
                // Forme réelle d'un 422 Laravel : un message par champ, sous
                // la clé EXACTE du champ — c'est elle que l'écran cherche.
                return http.Response(
                  jsonEncode({
                    'message': 'Les données fournies sont invalides.',
                    'errors': {
                      'name': ['Le champ name est obligatoire.'],
                      'email': ['Cet email est déjà utilisé.'],
                    },
                  }),
                  status,
                  headers: {'content-type': 'application/json'},
                );
              }

              return http.Response(
                jsonEncode({
                  'token': 'jeton-1',
                  'user': {'id': 7, 'name': 'Awa', 'role': 'merchant'},
                }),
                status,
                headers: {'content-type': 'application/json'},
              );
            }),
            baseUrl: 'http://api.test/api',
            readToken: store.read,
            writeToken: store.write,
            clearToken: store.clear,
          );
          ref.onDispose(client.dispose);
          return client;
        }),
      ],
      child: MaterialApp(
        theme: AppTheme.light,
        home: const RegisterPage(),
      ),
    ),
  );
}

/// Touche le bouton de soumission. Deux précautions : le libellé « Créer mon
/// compte » est aussi le titre de l'écran (c'est le type du bouton qui lève
/// l'ambiguïté), et le formulaire est plus haut que la surface de test par
/// défaut — sans défilement préalable, le toucher tomberait dans le vide.
Future<void> submit(WidgetTester tester) async {
  final button = find.widgetWithText(FilledButton, 'Créer mon compte');
  await tester.ensureVisible(button);
  await tester.pumpAndSettle();
  await tester.tap(button);
  await tester.pump();
}

/// Choisit un profil, après avoir fait défiler jusqu'aux cartes : elles sont
/// sous la surface de test, le toucher ne tomberait sinon dans le vide.
Future<void> pickRole(WidgetTester tester, String label) async {
  final card = find.text(label);
  await tester.ensureVisible(card);
  await tester.pumpAndSettle();
  await tester.tap(card);
  await tester.pump();
}

/// Remplit un champ par son étiquette de formulaire.
Future<void> fill(WidgetTester tester, String label, String value) async {
  await tester.enterText(find.widgetWithText(TextFormField, label), value);
}

void main() {
  testWidgets('affiche les cinq champs et les deux profils', (tester) async {
    await pumpRegister(tester);

    expect(find.widgetWithText(FilledButton, 'Créer mon compte'), findsOneWidget);
    expect(
      find.text('Rejoins une tontine en une minute. Ton inscription est gratuite.'),
      findsOneWidget,
    );
    for (final label in [
      'Nom complet',
      'Adresse e-mail',
      'Téléphone',
      'Mot de passe',
      'Confirmer le mot de passe',
    ]) {
      expect(find.widgetWithText(TextFormField, label), findsOneWidget, reason: label);
    }

    expect(find.text('Client'), findsOneWidget);
    expect(find.text('Commerçant'), findsOneWidget);
  });

  testWidgets('un formulaire vide n\'envoie rien et signale chaque champ', (tester) async {
    await pumpRegister(tester);

    await submit(tester);

    expect(lastBody, isNull);
    expect(find.text('Renseigne ton nom complet.'), findsOneWidget);
    expect(find.text('Renseigne ton adresse e-mail.'), findsOneWidget);
    expect(find.text('Renseigne ton numéro de téléphone.'), findsOneWidget);
    expect(find.text('Choisis un mot de passe.'), findsOneWidget);
    expect(find.text('Confirme ton mot de passe.'), findsOneWidget);
  });

  testWidgets('la confirmation doit correspondre au mot de passe', (tester) async {
    await pumpRegister(tester);

    await fill(tester, 'Nom complet', 'Awa Sanou');
    await fill(tester, 'Adresse e-mail', 'awa@exemple.ne');
    await fill(tester, 'Téléphone', '+227 90 00 00 00');
    await fill(tester, 'Mot de passe', 'motdepasse');
    await fill(tester, 'Confirmer le mot de passe', 'autrechose');

    await submit(tester);

    expect(lastBody, isNull);
    expect(
      find.text('La confirmation ne correspond pas au mot de passe.'),
      findsOneWidget,
    );
  });

  testWidgets('un mot de passe trop court est refusé sans appel réseau', (tester) async {
    await pumpRegister(tester);

    await fill(tester, 'Nom complet', 'Awa Sanou');
    await fill(tester, 'Adresse e-mail', 'awa@exemple.ne');
    await fill(tester, 'Téléphone', '+227 90 00 00 00');
    await fill(tester, 'Mot de passe', 'court');
    await fill(tester, 'Confirmer le mot de passe', 'court');

    await submit(tester);

    expect(lastBody, isNull);
    expect(find.text('8 caractères minimum.'), findsOneWidget);
  });

  testWidgets('le profil choisi est envoyé, et « client » par défaut', (tester) async {
    await pumpRegister(tester);

    await fill(tester, 'Nom complet', 'Awa Sanou');
    await fill(tester, 'Adresse e-mail', 'awa@exemple.ne');
    await fill(tester, 'Téléphone', '+227 90 00 00 00');
    await fill(tester, 'Mot de passe', 'motdepasse');
    await fill(tester, 'Confirmer le mot de passe', 'motdepasse');

    await submit(tester);
    await tester.pumpAndSettle();

    expect(lastBody?['role'], 'client');
    expect(lastBody?['name'], 'Awa Sanou');
  });

  testWidgets('choisir « commerçant » remplace le rôle par défaut', (tester) async {
    await pumpRegister(tester);

    await fill(tester, 'Nom complet', 'Awa Sanou');
    await fill(tester, 'Adresse e-mail', 'awa@exemple.ne');
    await fill(tester, 'Téléphone', '+227 90 00 00 00');
    await fill(tester, 'Mot de passe', 'motdepasse');
    await fill(tester, 'Confirmer le mot de passe', 'motdepasse');

    await pickRole(tester, 'Commerçant');
    await submit(tester);
    await tester.pumpAndSettle();

    expect(lastBody?['role'], 'merchant');
  });

  testWidgets('une erreur 422 s\'affiche sous le champ concerné', (tester) async {
    await pumpRegister(tester, status: 422);

    await fill(tester, 'Nom complet', 'Awa Sanou');
    await fill(tester, 'Adresse e-mail', 'awa@exemple.ne');
    await fill(tester, 'Téléphone', '+227 90 00 00 00');
    await fill(tester, 'Mot de passe', 'motdepasse');
    await fill(tester, 'Confirmer le mot de passe', 'motdepasse');

    await submit(tester);
    await tester.pumpAndSettle();

    // Le message global est MASQUÉ : chaque erreur a sa place, sous son champ.
    expect(find.text('Les données fournies sont invalides.'), findsNothing);
    expect(find.text('Le champ name est obligatoire.'), findsOneWidget);
    expect(find.text('Cet email est déjà utilisé.'), findsOneWidget);
  });

  testWidgets('retoucher un champ efface l\'erreur du serveur', (tester) async {
    await pumpRegister(tester, status: 422);

    await fill(tester, 'Nom complet', 'Awa Sanou');
    await fill(tester, 'Adresse e-mail', 'awa@exemple.ne');
    await fill(tester, 'Téléphone', '+227 90 00 00 00');
    await fill(tester, 'Mot de passe', 'motdepasse');
    await fill(tester, 'Confirmer le mot de passe', 'motdepasse');

    await submit(tester);
    await tester.pumpAndSettle();
    expect(find.text('Le champ name est obligatoire.'), findsOneWidget);

    await fill(tester, 'Nom complet', 'Awa Sanou II');
    await tester.pump();

    // L'erreur du champ que l'on corrige disparaît ; celle de l'autre
    // champ reste, elle n'a pas été traitée.
    expect(find.text('Le champ name est obligatoire.'), findsNothing);
    expect(find.text('Cet email est déjà utilisé.'), findsOneWidget);
  });
}
