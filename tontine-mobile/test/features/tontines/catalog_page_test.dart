import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:tontine_achat_store/core/auth/auth_controller.dart';
import 'package:tontine_achat_store/core/di/providers.dart';
import 'package:tontine_achat_store/core/network/api_client.dart';
import 'package:tontine_achat_store/core/storage/token_store.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/features/tontines/catalog_page.dart';
import 'package:tontine_achat_store/features/tontines/widgets/tontine_card.dart';
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

http.Response jsonResponse(Object body, {int status = 200}) => http.Response(
      jsonEncode(body),
      status,
      headers: {'content-type': 'application/json'},
    );

/// Une page de catalogue de [count] tontines, SANS photo de produit.
///
/// Sans photo, le rendu passe par l'avatar, donc reste local : aucun test
/// d'écran ne dépend ici du réseau.
Map<String, dynamic> catalogPage(int count, {int lastPage = 1}) {
  return pagedJson(
    [
      for (var index = 0; index < count; index++)
        tontineJson(
          id: index + 1,
          name: 'Tontine ${index + 1}',
          product: productWithoutImage(name: 'Produit ${index + 1}'),
        ),
    ],
    lastPage: lastPage,
  );
}

Future<void> pumpCatalog(
  WidgetTester tester,
  Future<http.Response> Function(http.Request request) handler,
) async {
  // Une `ListView` ne construit que ce qui est visible : sur les 600 px de la
  // surface par défaut, la plupart des cartes n'existeraient pas dans l'arbre.
  tester.view.physicalSize = const Size(900, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);

  final store = _MemoryTokenStore();

  final router = GoRouter(
    initialLocation: AppRoutes.catalog,
    routes: [
      GoRoute(
        path: AppRoutes.catalog,
        builder: (context, state) => const TontineCatalogPage(),
      ),
      GoRoute(
        path: AppRoutes.detailPattern,
        builder: (context, state) => Scaffold(
          body: Center(child: Text('Fiche ${state.pathParameters['id']}')),
        ),
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
            httpClient: MockClient(handler),
            baseUrl: 'http://api.test/api',
            readToken: store.read,
            writeToken: store.write,
            clearToken: store.clear,
          );
          ref.onDispose(client.dispose);
          return client;
        }),
        authControllerProvider.overrideWith(
          (ref) => _FakeAuthController(ref, const {'id': 7, 'name': 'Awa', 'role': 'client'}),
        ),
      ],
      child: MaterialApp.router(theme: AppTheme.light, routerConfig: router),
    ),
  );

  await tester.pumpAndSettle();
}

/// Le nom d'une tontine, cherché dans les CARTES seulement.
///
/// `find.text` chercherait aussi dans le champ de recherche : dès que l'écran
/// est filtré, la saisie contient le nom recherché, et tout comptage global
/// compterait deux fois.
Finder cardNamed(String name) => find.descendant(
      of: find.byType(TontineCard),
      matching: find.text(name),
    );

void main() {
  testWidgets('le catalogue liste les tontines et annonce le nombre', (tester) async {
    await pumpCatalog(tester, (_) async => jsonResponse(catalogPage(3)));

    expect(cardNamed('Tontine 1'), findsOneWidget);
    expect(cardNamed('Tontine 3'), findsOneWidget);
    expect(find.text('Produit : Produit 2'), findsOneWidget);

    // 3 tontines affichées, 3 rejoignables : le résumé ne doit pas laisser
    // croire à un total différent.
    expect(find.text('3 tontines disponibles'), findsOneWidget);
  });

  testWidgets('une carte ouvre la fiche correspondante', (tester) async {
    await pumpCatalog(tester, (_) async => jsonResponse(catalogPage(3)));

    await tester.tap(cardNamed('Tontine 2'));
    await tester.pumpAndSettle();

    expect(find.text('Fiche 2'), findsOneWidget);
  });

  testWidgets('la recherche filtre la liste sans nouvel appel réseau', (tester) async {
    var calls = 0;

    await pumpCatalog(tester, (_) async {
      calls++;
      return jsonResponse(catalogPage(3));
    });

    await tester.enterText(find.byType(TextField), 'Tontine 3');
    await tester.pumpAndSettle();

    expect(cardNamed('Tontine 1'), findsNothing);
    expect(cardNamed('Tontine 3'), findsOneWidget);
    // Le filtre est local : la frappe ne doit coûter aucune requête de plus.
    expect(calls, 1);
  });

  testWidgets('une recherche sans résultat propose de tout réinitialiser', (tester) async {
    await pumpCatalog(tester, (_) async => jsonResponse(catalogPage(3)));

    await tester.enterText(find.byType(TextField), 'vélo');
    await tester.pumpAndSettle();

    expect(find.text('Aucune tontine ne correspond'), findsOneWidget);

    await tester.tap(find.text('Réinitialiser les filtres'));
    await tester.pumpAndSettle();

    expect(cardNamed('Tontine 1'), findsOneWidget);
  });

  testWidgets('un catalogue vide ne prétend pas qu\'un filtre est en cause', (tester) async {
    // « Aucune tontine » et « aucune ne correspond » ne se réparent pas de la
    // même façon : le premier invite à revenir, le second à élargir.
    await pumpCatalog(tester, (_) async => jsonResponse(pagedJson(const [])));

    expect(find.text('Aucune tontine'), findsOneWidget);
    expect(find.text('Réinitialiser les filtres'), findsNothing);
  });

  testWidgets('un serveur injoignable propose de réessayer', (tester) async {
    var attempts = 0;

    await pumpCatalog(tester, (_) async {
      attempts++;
      return jsonResponse({'message': 'Serveur indisponible.'}, status: 500);
    });

    expect(find.textContaining('Serveur indisponible.'), findsOneWidget);

    await tester.tap(find.text('Réessayer'));
    await tester.pumpAndSettle();

    expect(attempts, 2, reason: 'réessayer doit bien refaire la requête');
  });

  testWidgets('« charger plus » ajoute la page suivante', (tester) async {
    await pumpCatalog(tester, (request) async {
      final page = int.tryParse(request.url.queryParameters['page'] ?? '') ?? 1;

      if (page == 1) return jsonResponse(catalogPage(2, lastPage: 2));

      return jsonResponse(
        pagedJson(
          [tontineJson(id: 3, name: 'Tontine 3', product: productWithoutImage(name: 'Produit 3'))],
          currentPage: 2,
          lastPage: 2,
        ),
      );
    });

    expect(cardNamed('Tontine 2'), findsOneWidget);
    expect(cardNamed('Tontine 3'), findsNothing);

    await tester.tap(find.text('Charger plus de tontines'));
    await tester.pumpAndSettle();

    expect(cardNamed('Tontine 3'), findsOneWidget);
  });

  testWidgets('la feuille de filtres se remplit et se valide', (tester) async {
    await pumpCatalog(tester, (_) async => jsonResponse(catalogPage(3)));

    await tester.tap(find.text('Filtres'));
    await tester.pumpAndSettle();

    expect(find.text('Rejoignables uniquement'), findsOneWidget);

    await tester.tap(find.text('Argent'));
    await tester.pumpAndSettle();

    // Le bouton annonce désormais un filtre posé, et non « Filtres » nu.
    await tester.tap(find.text('Appliquer les filtres'));
    await tester.pumpAndSettle();

    expect(find.text('Filtres (1)'), findsOneWidget);
    expect(find.text('Aucune tontine ne correspond'), findsOneWidget);
  });
}
