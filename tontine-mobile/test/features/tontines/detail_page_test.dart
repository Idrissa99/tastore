import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:tontine_achat_store/core/auth/auth_controller.dart';
import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/core/di/providers.dart';
import 'package:tontine_achat_store/core/network/api_client.dart';
import 'package:tontine_achat_store/core/storage/token_store.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/features/tontines/detail_page.dart';
import 'package:tontine_achat_store/routing/app_router.dart';

import 'tontine_fixtures.dart';

class _MemoryTokenStore extends TokenStore {
  String? token = 'jeton-1';

  @override
  Future<String?> read() async => token;

  @override
  Future<void> write(String value) async => token = value;

  @override
  Future<void> clear() async => token = null;
}

class _FakeAuthController extends AuthController {
  _FakeAuthController(super.ref, Map<String, dynamic>? user) {
    state = AuthState(
      user == null ? AuthStatus.unauthenticated : AuthStatus.authenticated,
      user: user,
    );
  }
}

/// Requêtes réellement émises par l'écran.
class _Call {
  _Call(this.method, this.path);

  final String method;
  final String path;
}

Future<void> pumpDetail(
  WidgetTester tester, {
  required Map<String, dynamic> tontine,
  int joinStatus = 200,
  Map<String, dynamic>? joinedBody,
  Map<String, dynamic>? user = const {'id': 7, 'name': 'Awa Sanou', 'role': 'client'},
}) async {
  useTallSurface(tester);

  final store = _MemoryTokenStore();
  final calls = <_Call>[];

  final router = GoRouter(
    initialLocation: AppRoutes.detail(1),
    routes: [
      GoRoute(
        path: AppRoutes.detailPattern,
        builder: (context, state) =>
            TontineDetailPage(tontineId: int.parse(state.pathParameters['id']!)),
      ),
      GoRoute(
        path: AppRoutes.login,
        builder: (context, state) => const Scaffold(body: Center(child: Text('Écran de connexion'))),
      ),
    ],
  );
  addTearDown(router.dispose);

  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        tokenStoreProvider.overrideWithValue(store),
        apiClientProvider.overrideWith((ref) {
          final client = ApiClient(
            httpClient: MockClient((request) async {
              calls.add(_Call(request.method, request.url.path));

              // Une ERREUR Laravel n'est jamais encapsulée dans `data` :
              // n'envelopper que les succès reproduit le contrat réel.
              if (request.method == 'POST') {
                final body = joinStatus >= 200 && joinStatus < 300
                    ? {'data': joinedBody ?? tontine}
                    : (joinedBody ?? const <String, dynamic>{});

                return http.Response(
                  jsonEncode(body),
                  joinStatus,
                  headers: {'content-type': 'application/json'},
                );
              }

              // Forme RÉELLE de l'API : un `JsonResource` est renvoyé sous
              // `{"data": {...}}`. Le simuler dépouillé masquerait le défaut
              // de déballage, qui ne se voyait que sur l'appareil.
              return http.Response(
                jsonEncode({'data': tontine}),
                200,
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
        authControllerProvider.overrideWith((ref) => _FakeAuthController(ref, user)),
      ],
      child: MaterialApp.router(
        theme: AppTheme.light,
        routerConfig: router,
      ),
    ),
  );

  await tester.pumpAndSettle();
}

/// Une `ListView` ne construit que ce qui est visible : sur les 600 px de la
/// surface par défaut, la moitié de la fiche n'existerait pas dans l'arbre, et
/// les assertions porteraient sur un écran tronqué.
void useTallSurface(WidgetTester tester) {
  tester.view.physicalSize = const Size(900, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

/// Libellé exact du bouton d'adhésion, construit par le formateur.
///
/// Écrire « 25 000 » en dur ici échouerait : le séparateur de milliers est une
/// espace insécable fine (U+202F), pas une espace ordinaire. On dérive donc la
/// chaîne attendue du même code qui produit l'affichage — sinon le test
/// vérifierait une convention de ponctuation, pas l'écran.
String joinLabel({double amount = 25000, String frequency = 'par mois'}) =>
    'Rejoindre pour ${Fmt.fcfa(amount)} $frequency';

void main() {
  testWidgets('la fiche montre le montant, le produit et les participants', (tester) async {
    await pumpDetail(
      tester,
      tontine: tontineJson(
        product: productWithoutImage(),
        members: [memberJson(userId: 7, name: 'Awa Sanou')],
      ),
    );

    expect(find.text('Tontine Téléphone'), findsOneWidget);
    expect(find.text('Produit financé : Smartphone'), findsOneWidget);
    // Le montant seul ne dit pas à quelle rythme on paie, et c'est cette
    // information qui décide d'une adhésion.
    expect(find.text(joinLabel()), findsWidgets);
    expect(find.text('Awa Sanou'), findsOneWidget);
    // Dérivé du formateur : le libellé est composé à la volée, et une espace
    // insécable traîne dans « 9 places ».
    expect(find.text("${Fmt.plural(9, 'place')} restantes"), findsOneWidget);
  });

  testWidgets('l\'ordre de passage reste caché avant le tirage', (tester) async {
    // `position` ne porte que l'ordre d'arrivée tant que la tontine n'a pas
    // démarré : l'afficher ferait croire qu'un tirage a eu lieu.
    await pumpDetail(tester, tontine: tontineJson(product: productWithoutImage()));

    expect(find.textContaining('Position'), findsNothing);
  });

  testWidgets('l\'ordre de passage apparaît une fois le tirage révélé', (tester) async {
    await pumpDetail(
      tester,
      tontine: tontineJson(
        product: productWithoutImage(),
        rotationRevealed: true,
        // Un AUTRE membre : la ligne personnelle porte « Toi » et aucune
        // position, l'ordre de passage ne concernant que les rounds futurs.
        members: [memberJson(userId: 99, name: 'Ibrahim Kone', position: 3)],
      ),
    );

    expect(find.text('Position 3'), findsOneWidget);
    expect(find.text('Ibrahim Kone'), findsOneWidget);
  });

  testWidgets('le bouton propose de rejoindre une tontine ouverte', (tester) async {
    await pumpDetail(tester, tontine: tontineJson(product: productWithoutImage()));

    expect(find.text(joinLabel()), findsOneWidget);
  });

  testWidgets('rejoindre envoie l\'adhésion et réaffiche la tontine à jour', (tester) async {
    // Le serveur renvoie la tontine complète : s'en servir évite un second
    // aller-retour dont le résultat pourrait contredire le bouton touché.
    await pumpDetail(
      tester,
      tontine: tontineJson(product: productWithoutImage()),
      joinedBody: tontineJson(
        product: productWithoutImage(),
        currentMembers: 4,
        isMember: true,
      ),
    );

    await tester.tap(find.text(joinLabel()));
    await tester.pumpAndSettle();

    expect(find.text('Tu es membre'), findsWidgets);
    expect(find.text(joinLabel()), findsNothing);
  });

  testWidgets('une tontine complète explique pourquoi on ne peut pas rejoindre', (tester) async {
    // Un bouton désactivé sans motif se prend pour une panne : l'utilisateur
    // ne verrait pas que la tontine est pleine, et chercherait ailleurs.
    await pumpDetail(
      tester,
      tontine: tontineJson(product: productWithoutImage(), maxMembers: 3, currentMembers: 3),
    );

    expect(find.text('Cette tontine est déjà complète.'), findsOneWidget);
    expect(find.text('Complète'), findsWidgets);

    final button = tester.widget<FilledButton>(find.byType(FilledButton));
    expect(button.onPressed, isNull);
  });

  testWidgets('une tontine démarrée donne son propre motif de refus', (tester) async {
    await pumpDetail(
      tester,
      tontine: tontineJson(product: productWithoutImage(), status: 'active'),
    );

    expect(find.text('Cette tontine n\'accepte plus de nouveaux membres.'), findsOneWidget);
  });

  testWidgets('un refus du serveur est affiché, pas avalé', (tester) async {
    // « Tu es déjà membre », « e-mail non vérifié » : ce sont des réponses
    // métier que l'utilisateur doit lire, pas un échec technique à remplacer
    // par un message générique.
    await pumpDetail(
      tester,
      tontine: tontineJson(product: productWithoutImage()),
      joinStatus: 409,
      joinedBody: const {'message': 'Tu es déjà membre de cette tontine.'},
    );

    await tester.tap(find.text(joinLabel()));
    await tester.pumpAndSettle();

    expect(find.text('Tu es déjà membre de cette tontine.'), findsOneWidget);

    // La fiche reste lisible : seul le bandeau d'erreur s'est ajouté.
    expect(find.text('Tontine Téléphone'), findsOneWidget);
  });

  testWidgets('un e-mail non vérifié affiche le motif du serveur', (tester) async {
    await pumpDetail(
      tester,
      tontine: tontineJson(product: productWithoutImage()),
      joinStatus: 403,
      joinedBody: const {'message': 'Ton adresse e-mail n\'est pas encore vérifiée.'},
    );

    await tester.tap(find.text(joinLabel()));
    await tester.pumpAndSettle();

    expect(find.text('Ton adresse e-mail n\'est pas encore vérifiée.'), findsOneWidget);
  });

  testWidgets('hors session, l\'écran propose la connexion plutôt qu\'une adhésion', (tester) async {
    await pumpDetail(
      tester,
      tontine: tontineJson(product: productWithoutImage()),
      user: null,
    );

    // La consultation est libre ; l'adhésion, non. Proposer « Rejoindre »
    // enverrait un appel refusé, suivi d'un message d'erreur.
    expect(find.text('Connecte-toi pour rejoindre'), findsOneWidget);
    expect(find.text(joinLabel()), findsNothing);

    await tester.tap(find.text('Connecte-toi pour rejoindre'));
    await tester.pumpAndSettle();

    expect(find.text('Écran de connexion'), findsOneWidget);
  });
}
