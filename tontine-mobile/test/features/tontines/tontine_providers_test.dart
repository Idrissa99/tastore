import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:tontine_achat_store/core/auth/auth_controller.dart';
import 'package:tontine_achat_store/core/di/providers.dart';
import 'package:tontine_achat_store/core/network/api_client.dart';
import 'package:tontine_achat_store/core/network/api_exception.dart';
import 'package:tontine_achat_store/core/storage/token_store.dart';
import 'package:tontine_achat_store/features/tontines/tontine.dart';
import 'package:tontine_achat_store/features/tontines/tontine_providers.dart';

import 'tontine_fixtures.dart';

/// `TokenStore` en mémoire, comme dans le test de session.
class _MemoryTokenStore extends TokenStore {
  String? token;

  @override
  Future<String?> read() async => token;

  @override
  Future<void> write(String value) async => token = value;

  @override
  Future<void> clear() async => token = null;
}

/// Session ouverte (ou fermée) donnée d'office aux tests.
///
/// La vraie session passe par le keystore et `GET /me` : ici on veut tester
/// le catalogue, pas l'authentification. [user] à `null` signifie « pas de
/// session », ce qui n'est pas la même chose qu'un utilisateur sans nom.
class _FakeAuthController extends AuthController {
  _FakeAuthController(super.ref, Map<String, dynamic>? user) {
    state = AuthState(
      user == null ? AuthStatus.unauthenticated : AuthStatus.authenticated,
      user: user,
    );
  }
}

/// Une requête réellement émise, pour vérifier ce que l'app demande.
class _Call {
  _Call(this.method, this.uri);

  final String method;
  final Uri uri;

  String get path => uri.path;

  int? get page => int.tryParse(uri.queryParameters['page'] ?? '');

  /// Le serveur ne sait appliquer que ce filtre-là ; le reste est local.
  bool get filtersOnServer => uri.queryParameters.containsKey('available');
}

/// Conteneur de test : l'API répond via [handler], et les requêtes sont
/// conservées pour être inspectées.
class _Harness {
  _Harness(this.container, this.calls);

  final ProviderContainer container;
  final List<_Call> calls;

  void reset() => calls.clear();
}

_Harness _mount(
  Future<http.Response> Function(http.Request request) handler, {
  Map<String, dynamic>? user = const {'id': 7, 'name': 'Awa Sanou', 'role': 'client'},
}) {
  final store = _MemoryTokenStore()..token = 'jeton-1';
  final calls = <_Call>[];

  final container = ProviderContainer(
    overrides: [
      tokenStoreProvider.overrideWithValue(store),
      apiClientProvider.overrideWith((ref) {
        final client = ApiClient(
          httpClient: MockClient((request) {
            calls.add(_Call(request.method, request.url));
            return handler(request);
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
  );

  return _Harness(container, calls);
}

http.Response jsonBody(Object body, {int status = 200}) => http.Response(
      jsonEncode(body),
      status,
      headers: {'content-type': 'application/json'},
    );

/// Un catalogue de [count] tontines distinctes, annonçant [lastPage] pages.
Map<String, dynamic> catalogOf(int count, {int lastPage = 1}) {
  return pagedJson(
    [
      for (var index = 0; index < count; index++)
        tontineJson(id: index + 1, name: 'Tontine ${index + 1}'),
    ],
    lastPage: lastPage,
    total: lastPage * count,
  );
}

Tontine tontineOf(Map<String, dynamic> json) => Tontine.fromJson(json);

void main() {
  group('filtres, fonction pure', () {
    late List<Tontine> pool;

    setUp(() {
      pool = [
        tontineOf(tontineJson(id: 1, name: 'Téléphones', type: 'product', frequency: 'monthly')),
        tontineOf(tontineJson(id: 2, name: 'Motos', type: 'product', frequency: 'weekly')),
        tontineOf(
          tontineJson(
            id: 3,
            name: 'Épargne',
            type: 'cash',
            product: null,
            frequency: 'monthly',
            status: 'active',
          ),
        ),
        tontineOf(
          tontineJson(
            id: 4,
            name: 'Ordinateurs',
            type: 'product',
            frequency: 'monthly',
            maxMembers: 5,
            currentMembers: 5,
            status: 'open',
          ),
        ),
      ];
    });

    List<int> idsWith(TontineFilters filters) =>
        filterTontines(pool, filters).map((tontine) => tontine.id).toList();

    test('sans critère, tout reste visible', () {
      expect(idsWith(TontineFilters.none), [1, 2, 3, 4]);
    });

    test('le texte cherche dans le nom de la tontine ET celui du produit', () {
      // Les deux sont affichés sur la carte : ne répondre qu'à l'un des deux
      // ferait disparaître des résultats que l'utilisateur voit à l'écran.
      // La 3 est une tontine ARGENT : sans produit, c'est son propre nom qui
      // sert — le nom du produit ne doit pas la faire apparaître.
      expect(idsWith(const TontineFilters(query: 'Téléphones')), [1]);
      expect(idsWith(const TontineFilters(query: 'smartphone')), [1, 2, 4]);
      expect(idsWith(const TontineFilters(query: '  motos ')), [2]);
    });

    test('la recherche ignore la casse', () {
      expect(idsWith(const TontineFilters(query: 'MOTOS')), [2]);
    });

    test('« rejoignables » masque ce qui a démarré', () {
      expect(idsWith(const TontineFilters(availableOnly: true)), [1, 2, 4]);
    });

    test('« places disponibles » masque les tontines complètes', () {
      // La 4 est complète MAIS ouverte : elle reste visible avec
      // `availableOnly` et disparaît avec `slotsOnly`. Ce sont deux filtres
      // différents, et les confondre cacherait ici la seule tontine
      // rejoignable qu'il reste.
      expect(idsWith(const TontineFilters(slotsOnly: true)), [1, 2, 3]);
    });

    test('le type et la fréquence se cumulent', () {
      expect(idsWith(const TontineFilters(type: TontineType.cash)), [3]);
      expect(idsWith(const TontineFilters(frequency: TontineFrequency.weekly)), [2]);
      const cumul = TontineFilters(
        type: TontineType.product,
        frequency: TontineFrequency.monthly,
      );
      expect(idsWith(cumul), [1, 4]);
    });

    test('le plafond compare le bon montant selon le type', () {
      // Une tontine produit s'annonce par son versement (25 000), une tontine
      // argent par ce qu'elle vise (300 000). Comparer le mauvais des deux à un
      // plafond ferait disparaître un groupe entier.
      expect(idsWith(const TontineFilters(maxAmount: 30000)), [1, 2, 4]);
      expect(idsWith(const TontineFilters(maxAmount: 10000)), isEmpty);
    });

    test('les critères se cumulent et donnent le plus petit ensemble', () {
      const cumul = TontineFilters(
        availableOnly: true,
        frequency: TontineFrequency.monthly,
      );
      expect(idsWith(cumul), [1, 4]);
    });

    test('un critère qui ne correspond à rien donne une liste vide, sans erreur', () {
      expect(idsWith(const TontineFilters(query: 'vélo')), isEmpty);
    });
  });

  group('état du catalogue', () {
    TontineCatalogState stateOf(
      List<Tontine> pool, {
      TontineFilters filters = TontineFilters.none,
      int currentPage = 1,
      int lastPage = 1,
    }) {
      return TontineCatalogState(
        filters: filters,
        pool: pool,
        currentPage: currentPage,
        lastPage: lastPage,
        total: pool.length,
      );
    }

    test('la page affichée se recoupe sur le résultat FILTRÉ', () {
      // C'est tout l'intérêt du filtre local : la page 1 doit contenir les
      // premières tontines qui correspondent, pas les 12 premières du serveur
      // quand le résultat filtré n'en fait que 20.
      final pool = [
        for (var index = 0; index < 20; index++)
          tontineOf(tontineJson(id: index + 1, name: 'Tontine ${index + 1}')),
      ];
      final state = stateOf(pool, filters: const TontineFilters(query: 'Tontine 1'));

      expect(state.filtered.length, 11);
      expect(state.pageCount, 1);
      expect(state.visibleAt(1).first.id, 1);
    });

    test('une page hors bornes est ramenée dans le résultat', () {
      // Après un filtre plus restrictif, la page mémorisée n'existe plus : sans
      // correction, l'écran afficherait une liste vide sans explication.
      final state = stateOf([
        tontineOf(tontineJson(id: 1, name: 'Téléphones')),
        tontineOf(tontineJson(id: 2, name: 'Motos')),
      ]);

      expect(state.visibleAt(99).map((tontine) => tontine.id), [1, 2]);
      expect(state.visibleAt(0).map((tontine) => tontine.id), [1, 2]);
    });

    test('le compte « rejoignables » exclut les tontines pleines', () {
      final state = stateOf([
        tontineOf(tontineJson(id: 1)),
        tontineOf(tontineJson(id: 2, maxMembers: 4, currentMembers: 4)),
        tontineOf(tontineJson(id: 3, status: 'active')),
      ]);

      // 3 tontines affichées, 1 réellement rejoinable : annoncer « 3
      // disponibles » ferait croire à deux places qui n'existent pas.
      expect(state.filtered.length, 3);
      expect(state.joinableCount, 1);
    });

    test('le versement minimum ne considère que les tontines rejoignables', () {
      final state = stateOf([
        tontineOf(tontineJson(id: 1, contributionAmount: '40000.00')),
        tontineOf(tontineJson(id: 2, contributionAmount: '10000.00', status: 'active')),
        tontineOf(tontineJson(id: 3, contributionAmount: '25000.00')),
      ]);

      expect(state.lowestJoinableContribution, 25000);
    });

    test('aucune tontine rejoignable laisse le minimum à null', () {
      final state = stateOf([tontineOf(tontineJson(id: 1, status: 'active'))]);

      expect(state.lowestJoinableContribution, isNull);
    });

    test('« charger plus » s\'arrête au plafond de pages', () {
      final state = stateOf(const [], currentPage: 1, lastPage: 40);

      // Au-delà de `maxPages`, on ne télécharge pas toute la base — et l'écran
      // prévient qu'il n'affiche qu'un échantillon.
      expect(state.canLoadMore, isTrue);
      expect(state.poolIsTruncated, isTrue);
      expect(state.reachablePage, lessThan(40));
    });

    test('une dernière page atteinte ne propose plus rien', () {
      final state = stateOf(const [], currentPage: 2, lastPage: 2);

      expect(state.canLoadMore, isFalse);
      expect(state.poolIsTruncated, isFalse);
    });
  });

  group('contrôleur du catalogue', () {
    test('la première page alimente l\'accumulé', () async {
      final harness = _mount((_) async => jsonBody(catalogOf(3, lastPage: 4)));
      addTearDown(harness.container.dispose);

      final state = await harness.container.read(tontineCatalogProvider.future);

      expect(state.pool.length, 3);
      expect(state.lastPage, 4);
      expect(state.total, 12);
      expect(state.canLoadMore, isTrue);
    });

    test('« charger plus » empile sans dupliquer', () async {
      // Le serveur renvoie en partie des tontines déjà connues : les recopier
      // ferait apparaître deux fois la même carte dans la même liste.
      final harness = _mount((request) async {
        final page = int.tryParse(request.url.queryParameters['page'] ?? '') ?? 1;

        if (page == 1) return jsonBody(catalogOf(3, lastPage: 2));

        return jsonBody(
          pagedJson(
            [
              tontineJson(id: 3, name: 'Tontine 3'),
              tontineJson(id: 4, name: 'Tontine 4'),
            ],
            currentPage: 2,
            lastPage: 2,
          ),
        );
      });
      addTearDown(harness.container.dispose);

      final controller = harness.container.read(tontineCatalogProvider.notifier);
      await harness.container.read(tontineCatalogProvider.future);
      await controller.loadMore();

      final state = harness.container.read(tontineCatalogProvider).requireValue;
      expect(state.pool.map((tontine) => tontine.id), [1, 2, 3, 4]);
      expect(state.canLoadMore, isFalse);
    });

    test('« charger plus » ne redemande rien une fois la dernière page atteinte', () async {
      final harness = _mount((_) async => jsonBody(catalogOf(2)));
      addTearDown(harness.container.dispose);

      await harness.container.read(tontineCatalogProvider.future);
      harness.reset();

      await harness.container.read(tontineCatalogProvider.notifier).loadMore();

      expect(harness.calls, isEmpty);
    });

    test('un échec sur « charger plus » laisse la liste affichée intacte', () async {
      // Remplacer des tontines visibles par une erreur plein écran serait
      // absurde : la coupure est temporaire, le catalogue, lui, est bon.
      final harness = _mount((request) async {
        if ((request.url.queryParameters['page'] ?? '1') != '1') {
          throw http.ClientException('réseau coupé');
        }
        return jsonBody(catalogOf(3, lastPage: 2));
      });
      addTearDown(harness.container.dispose);

      final controller = harness.container.read(tontineCatalogProvider.notifier);
      await harness.container.read(tontineCatalogProvider.future);
      await controller.loadMore();

      final state = harness.container.read(tontineCatalogProvider).requireValue;
      expect(state.pool.length, 3);
      expect(state.loadingMore, isFalse);
      expect(state.canLoadMore, isTrue, reason: 'un nouvel essai doit rester possible');
    });

    test('« rejoignables uniquement » redemande la première page', () async {
      // C'est le SEUL critère que le serveur applique. Le filtrer sur
      // l'accumulé donnerait un catalogue faux dès qu'une tontine rejoignable
      // passe la douzième page.
      final harness = _mount((request) async {
        return jsonBody(
          request.url.queryParameters.containsKey('available') ? catalogOf(1) : catalogOf(3),
        );
      });
      addTearDown(harness.container.dispose);

      final controller = harness.container.read(tontineCatalogProvider.notifier);
      await harness.container.read(tontineCatalogProvider.future);

      await controller.applyFilters(const TontineFilters(availableOnly: true));

      expect(harness.calls.last.filtersOnServer, isTrue);
      expect(harness.container.read(tontineCatalogProvider).requireValue.pool.length, 1);
    });

    test('un critère purement local ne déclenche aucun appel réseau', () async {
      final harness = _mount((_) async => jsonBody(catalogOf(3, lastPage: 3)));
      addTearDown(harness.container.dispose);

      final controller = harness.container.read(tontineCatalogProvider.notifier);
      await harness.container.read(tontineCatalogProvider.future);
      harness.reset();

      await controller.applyFilters(const TontineFilters(query: 'Tontine 2'));

      expect(harness.calls, isEmpty);
      expect(harness.container.read(tontineCatalogProvider).requireValue.filtered.length, 1);
    });

    test('réinitialiser les filtres revient à zéro', () async {
      final harness = _mount((_) async => jsonBody(catalogOf(3)));
      addTearDown(harness.container.dispose);

      final controller = harness.container.read(tontineCatalogProvider.notifier);
      await harness.container.read(tontineCatalogProvider.future);

      await controller.applyFilters(const TontineFilters(query: 'Tontine 2'));
      expect(harness.container.read(tontineCatalogProvider).requireValue.filtered.length, 1);

      await controller.clearFilters();
      expect(harness.container.read(tontineCatalogProvider).requireValue.filtered.length, 3);
    });

    test('un catalogue en erreur reste en erreur, sans faux catalogue vide', () async {
      // Distinguer « rien à afficher » de « serveur injoignable » : le premier
      // se contente d'un message, le second doit proposer de réessayer.
      final harness =
          _mount((_) async => jsonBody({'message': 'Serveur indisponible.'}, status: 500));
      addTearDown(harness.container.dispose);

      await expectLater(
        harness.container.read(tontineCatalogProvider.future),
        throwsA(isA<ApiException>()),
      );
      expect(harness.container.read(tontineCatalogProvider).hasError, isTrue);
    });
  });

  group('dépôt', () {
    test('la fiche est déballée de son enveloppe « data »', () async {
      // Laravel sérialise un `JsonResource` sous `{"data": {...}}`. Renvoyer
      // l'enveloppe à `Tontine.fromJson` ne produit AUCUNE erreur : chaque
      // champ est simplement absent, et la fiche s'affiche vide — montant 0,
      // nom vide, statut « inconnu ». C'est ce que voyait le téléphone.
      final harness = _mount((_) async => jsonBody({'data': tontineJson(id: 13, name: 'Tarif Famille')}));
      addTearDown(harness.container.dispose);

      final tontine = await harness.container.read(tontineRepositoryProvider).detail(13);

      expect(tontine.id, 13);
      expect(tontine.name, 'Tarif Famille');
      expect(tontine.status, TontineStatus.open);
      expect(tontine.contributionAmount, 25000);
    });

    test('l\'adhésion est déballée de la même façon', () async {
      // `join()` renvoie lui aussi une ressource unique, donc enveloppée. Le
      // bouton afficherait « 0 place restante » juste après avoir rejoint.
      final harness = _mount((_) async => jsonBody({'data': tontineJson(isMember: true)}));
      addTearDown(harness.container.dispose);

      final joined = await harness.container.read(joinTontineProvider(1))();

      expect(joined.isMember, isTrue);
      expect(joined.joinAvailability, JoinAvailability.alreadyMember);
    });

    test('une réponse déjà déballée reste lue', () async {
      // On ne dépend pas de l'enveloppe : si le backend la retirait un jour,
      // la fiche ne doit pas devenir vide du même coup.
      final harness = _mount((_) async => jsonBody(tontineJson(name: 'Sans enveloppe')));
      addTearDown(harness.container.dispose);

      final tontine = await harness.container.read(tontineRepositoryProvider).detail(1);

      expect(tontine.name, 'Sans enveloppe');
    });

    test('le catalogue n\'est PAS déballé deux fois', () async {
      // Le `data` du paginateur est la LISTE, pas une enveloppe : le traiter
      // comme tel ferait disappear toutes les tontines du catalogue.
      final harness = _mount((_) async => jsonBody(catalogOf(3)));
      addTearDown(harness.container.dispose);

      final page = await harness.container.read(tontineRepositoryProvider).catalog();

      expect(page.items.length, 3);
      expect(page.items.first.name, 'Tontine 1');
    });

    test('la fiche reçoit l\'identifiant du membre connecté', () async {
      // `TontineResource` ne dit pas lequel de ses membres est « moi » : sans
      // l'identifiant local, la ligne personnelle serait indiscernable.
      final harness = _mount((_) async => jsonBody(tontineJson(members: [memberJson(userId: 99)])));
      addTearDown(harness.container.dispose);

      final tontine = await harness.container.read(tontineRepositoryProvider).detail(1);

      expect(harness.calls.single.path, '/api/tontines/1');
      expect(harness.container.read(currentUserIdProvider), 7);
      expect(tontine.members.single.isMe, isFalse);
    });

    test('hors session, aucun identifiant courant n\'est supposé', () async {
      final harness = _mount((_) async => jsonBody(tontineJson()), user: null);
      addTearDown(harness.container.dispose);

      expect(harness.container.read(currentUserIdProvider), isNull);
    });

    test('l\'adhésion renvoie la tontine à jour', () async {
      // Le serveur renvoie la tontine complète après l'adhésion : s'en servir
      // évite un second aller-retour dont le résultat pourrait contredire le
      // bouton qu'on vient de toucher.
      final harness = _mount((request) async {
        return jsonBody(
          request.method == 'POST' ? tontineJson(currentMembers: 4, isMember: true) : catalogOf(2),
        );
      });
      addTearDown(harness.container.dispose);

      final joined = await harness.container.read(joinTontineProvider(1))();

      expect(harness.calls.single.method, 'POST');
      expect(harness.calls.single.path, '/api/tontines/1/join');
      expect(joined.currentMembers, 4);
      expect(joined.isMember, isTrue);
      expect(joined.joinAvailability, JoinAvailability.alreadyMember);
    });

    test('après une adhésion, le catalogue est relu', () async {
      // Le catalogue annonce les places restantes : sans relecture, l'écran
      // suivant afficherait le nombre de membres d'avant l'adhésion.
      final harness = _mount((request) async {
        return jsonBody(request.method == 'POST' ? tontineJson(isMember: true) : catalogOf(2));
      });
      addTearDown(harness.container.dispose);

      await harness.container.read(tontineCatalogProvider.future);
      await harness.container.read(joinTontineProvider(1))();
      harness.reset();

      await harness.container.read(tontineCatalogProvider.future);

      expect(harness.calls.single.path, '/api/tontines');
    });

    test('un refus d\'adhésion remonte en exception, sans être avalé', () async {
      // Tontine pleine, adhésion en double, e-mail non vérifié : ce sont des
      // réponses métier que l'utilisateur doit LIRE, pas un échec technique à
      // remplacer par un message générique.
      final harness = _mount(
        (_) async => jsonBody({'message': 'Tu es déjà membre de cette tontine.'}, status: 409),
      );
      addTearDown(harness.container.dispose);

      await expectLater(
        harness.container.read(joinTontineProvider(1))(),
        throwsA(
          isA<ApiException>()
              .having((error) => error.isConflict, 'isConflict', isTrue)
              .having((error) => error.message, 'message', 'Tu es déjà membre de cette tontine.'),
        ),
      );
    });
  });
}
