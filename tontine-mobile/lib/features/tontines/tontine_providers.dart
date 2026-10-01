import 'dart:math' show max, min;

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:tontine_achat_store/core/auth/auth_controller.dart';
import 'package:tontine_achat_store/core/config/app_config.dart';
import 'package:tontine_achat_store/core/di/providers.dart';
import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/features/tontines/tontine.dart';
import 'package:tontine_achat_store/features/tontines/tontine_repository.dart';

/// Identifiant de l'utilisateur connecté, ou `null` hors session.
///
/// `TontineResource` ne dit pas lequel de ses membres est « moi » : il faut le
/// savoir localement pour mettre en évidence la bonne ligne de la fiche. Une
/// session inconnue vaut `null`, et l'interface n'insiste alors sur personne.
final currentUserIdProvider = Provider<int?>((ref) {
  final user = ref.watch(authControllerProvider).user;
  if (user == null) return null;

  final id = Fmt.toInt(user['id']);
  return id <= 0 ? null : id;
});

final tontineRepositoryProvider = Provider<TontineRepository>(
  (ref) => TontineRepository(api: ref.watch(apiClientProvider)),
);

/// Fiche d'une tontine. `autoDispose` : une fiche quittée n'a pas à rester en
/// mémoire, et surtout à devenir périmée — elle sera relue si on y revient.
final tontineDetailProvider = FutureProvider.autoDispose
    .family<Tontine, int>((ref, id) async {
  return ref
      .read(tontineRepositoryProvider)
      .detail(id, currentUserId: ref.read(currentUserIdProvider));
});

/* ------------------------------------------------------------------ */
/* Filtres                                                             */
/* ------------------------------------------------------------------ */

/// Critères de recherche du catalogue.
///
/// L'API n'expose que le filtre `available`. Le reste est appliqué sur les
/// pages déjà téléchargées, d'où la séparation : [availableOnly] déclenche un
/// NOUVEL appel réseau, les autres critères ne sont qu'une transformation de ce
/// qui est en mémoire.
class TontineFilters {
  const TontineFilters({
    this.query = '',
    this.type,
    this.frequency,
    this.maxAmount,
    this.availableOnly = false,
    this.slotsOnly = false,
  });

  static const none = TontineFilters();

  final String query;
  final TontineType? type;
  final TontineFrequency? frequency;

  /// Plafond de comparaison, appliqué à [Tontine.comparableAmount].
  final double? maxAmount;

  /// Seules les tontines « open ». Seul critère que l'API sait appliquer.
  final bool availableOnly;

  /// Masque les tontines déjà complètes.
  final bool slotsOnly;

  TontineFilters copyWith({
    String? query,
    TontineType? type,
    TontineFrequency? frequency,
    double? maxAmount,
    bool? availableOnly,
    bool? slotsOnly,
    bool clearType = false,
    bool clearFrequency = false,
    bool clearMaxAmount = false,
  }) {
    return TontineFilters(
      query: query ?? this.query,
      type: clearType ? null : (type ?? this.type),
      frequency: clearFrequency ? null : (frequency ?? this.frequency),
      maxAmount: clearMaxAmount ? null : (maxAmount ?? this.maxAmount),
      availableOnly: availableOnly ?? this.availableOnly,
      slotsOnly: slotsOnly ?? this.slotsOnly,
    );
  }

  /// Nombre de critères actifs, hors recherche textuelle : c'est ce chiffre
  /// qu'affiche la pastille du bouton « Filtres ».
  ///
  /// La recherche a son propre champ, toujours visible ; la compter ici ferait
  /// annoncer « 1 filtre » posé alors qu'aucun ne l'est.
  int get activeCount => [
        type,
        frequency,
        maxAmount,
        if (availableOnly) true,
        if (slotsOnly) true,
      ].where((value) => value != null).length;

  bool get hasCriteria => activeCount > 0 || query.trim().isNotEmpty;

  String get normalizedQuery => query.trim().toLowerCase();
}

/// Filtre un ensemble de tontines — fonction PURE, donc directement testable.
///
/// Volontairement sans Riverpod ni widget : une erreur ici ne plante jamais,
/// elle se manifeste sous la forme d'une tontine qui disparaît du catalogue
/// alors qu'elle existe, ou d'une tontine affichée malgré un critère posé.
List<Tontine> filterTontines(List<Tontine> pool, TontineFilters filters) {
  final needle = filters.normalizedQuery;

  return pool.where((tontine) {
    // Une tontine pleine reste visible : elle continue d'exister et ses membres
    // la suivent. C'est « rejoignables » qui la masque, pas « places
    // disponibles », qui ne porte que sur les places.
    if (filters.availableOnly && !tontine.status.acceptsMembers) return false;
    if (filters.type != null && tontine.type != filters.type) return false;
    if (filters.frequency != null && tontine.frequency != filters.frequency) return false;
    if (filters.slotsOnly && tontine.isFull) return false;

    final ceiling = filters.maxAmount;
    if (ceiling != null && tontine.comparableAmount > ceiling) return false;

    if (needle.isEmpty) return true;

    // Le nom de la tontine ET celui du produit sont affichés sur la carte :
    // les deux doivent donc répondre à la recherche.
    final haystack = '${tontine.name} ${tontine.searchLabel}'.toLowerCase();
    return haystack.contains(needle);
  }).toList(growable: false);
}

/* ------------------------------------------------------------------ */
/* État du catalogue                                                    */
/* ------------------------------------------------------------------ */

/// Ce que l'écran du catalogue affiche à un instant donné.
///
/// [pool] est l'accumulé des pages téléchargées, pas la page affichée : les
/// filtres s'appliquent dessus, puis [visibleAt] découpe le résultat. C'est ce
/// qui permet de proposer recherche et filtres sans que l'API les sache faire.
class TontineCatalogState {
  const TontineCatalogState({
    required this.filters,
    required this.pool,
    required this.currentPage,
    required this.lastPage,
    required this.total,
    this.loadingMore = false,
  });

  factory TontineCatalogState.firstPage(
    TontineFilters filters,
    PagedResult<Tontine> page,
  ) {
    return TontineCatalogState(
      filters: filters,
      pool: page.items,
      currentPage: page.currentPage,
      lastPage: page.lastPage,
      total: page.total,
    );
  }

  final TontineFilters filters;
  final List<Tontine> pool;
  final int currentPage;
  final int lastPage;
  final int total;

  /// Un « Charger plus » est en cours. Distinct du chargement initial : la
  /// liste est déjà à l'écran et doit y rester.
  final bool loadingMore;

  List<Tontine> get filtered => filterTontines(pool, filters);

  /// Pagination AFFICHÉE, calculée sur le résultat filtré.
  ///
  /// Elle ne peut pas venir du serveur dès qu'un filtre est posé : le serveur
  /// pagine l'ensemble, pas la sélection.
  int get pageCount => max(1, (filtered.length / AppConfig.pageSize).ceil());

  List<Tontine> visibleAt(int page) {
    final target = page.clamp(1, pageCount);
    final start = (target - 1) * AppConfig.pageSize;
    if (start >= filtered.length) return const [];

    return filtered.sublist(start, min(start + AppConfig.pageSize, filtered.length));
  }

  /// Combien de tontines acceptent vraiment une adhésion.
  ///
  /// Ce chiffre porte le mot « disponible » : une tontine affichée mais déjà
  /// complète n'est pas une offre, et compter les deux ensemble ferait
  /// annoncer des places qui n'existent pas.
  int get joinableCount => filtered
      .where((tontine) => tontine.joinAvailability == JoinAvailability.open)
      .length;

  /// Le plafond qu'on peut honnêtement atteindre.
  ///
  /// `AppConfig.maxPages` évite de télécharger toute la base ; au-delà, la
  /// liste s'arrête et l'interface le dit, plutôt que de laisser croire que
  /// la totalité du catalogue est affichée.
  int get reachablePage => min(lastPage, AppConfig.maxPages);

  bool get canLoadMore => !loadingMore && currentPage < reachablePage;

  /// Vrai quand la base dépasse le plafond : le catalogue affiché n'est alors
  /// qu'un échantillon, et le filtrage ne porte que sur lui.
  bool get poolIsTruncated => lastPage > AppConfig.maxPages;

  /// Versement le plus bas parmi les tontines rejoignables, ou `null`.
  double? get lowestJoinableContribution {
    final amounts = filtered
        .where((tontine) => tontine.joinAvailability == JoinAvailability.open)
        .map((tontine) => tontine.contributionAmount)
        .where((amount) => amount > 0);

    return amounts.isEmpty ? null : amounts.reduce(min);
  }

  TontineCatalogState copyWith({
    TontineFilters? filters,
    List<Tontine>? pool,
    int? currentPage,
    int? lastPage,
    int? total,
    bool? loadingMore,
  }) {
    return TontineCatalogState(
      filters: filters ?? this.filters,
      pool: pool ?? this.pool,
      currentPage: currentPage ?? this.currentPage,
      lastPage: lastPage ?? this.lastPage,
      total: total ?? this.total,
      loadingMore: loadingMore ?? this.loadingMore,
    );
  }
}

/* ------------------------------------------------------------------ */
/* Contrôleur du catalogue                                              */
/* ------------------------------------------------------------------ */

/// Catalogue : chargement, pagination, filtres.
class TontineCatalogController extends AutoDisposeAsyncNotifier<TontineCatalogState> {
  @override
  Future<TontineCatalogState> build() async {
    return _fetch(TontineFilters.none);
  }

  Future<TontineCatalogState> _fetch(TontineFilters filters) async {
    final page = await ref.read(tontineRepositoryProvider).catalog(
          availableOnly: filters.availableOnly,
          currentUserId: ref.read(currentUserIdProvider),
        );

    return TontineCatalogState.firstPage(filters, page);
  }

  /// Pose de nouveaux critères.
  ///
  /// Changer `availableOnly` redemande la première page, parce que c'est le
  /// seul critère que le serveur applique : le filtrer sur le stock déjà
  /// téléchargé donnerait un catalogue faux dès qu'une tontine rejoignable
  /// passe la onzième page.
  ///
  /// Les autres critères ne relancent rien — ils sont réappliqués sur place.
  Future<void> applyFilters(TontineFilters filters) async {
    final snapshot = state.valueOrNull;
    if (snapshot == null) return;

    if (filters.availableOnly != snapshot.filters.availableOnly) {
      state = const AsyncLoading<TontineCatalogState>().copyWithPrevious(state);
      state = await AsyncValue.guard(() => _fetch(filters));
      return;
    }

    state = AsyncData(snapshot.copyWith(filters: filters, loadingMore: false));
  }

  /// Remet tous les critères à zéro.
  Future<void> clearFilters() => applyFilters(TontineFilters.none);

  /// Page suivante du catalogue.
  ///
  /// Un échec ici ne doit pas casser la liste déjà affichée : on rend la main et
  /// on laisse réessayer, au lieu de remplacer des tontines visibles par une
  /// erreur plein écran.
  Future<void> loadMore() async {
    final snapshot = state.valueOrNull;
    if (snapshot == null || !snapshot.canLoadMore) return;

    state = AsyncData(snapshot.copyWith(loadingMore: true));

    try {
      final page = await ref.read(tontineRepositoryProvider).catalog(
            page: snapshot.currentPage + 1,
            availableOnly: snapshot.filters.availableOnly,
            currentUserId: ref.read(currentUserIdProvider),
          );

      // Un critère posé pendant la requête a pu remplacer le stock : on repart
      // de l'état courant, pas de la copie prise avant l'appel.
      final current = state.valueOrNull;
      if (current == null) return;

      final seen = current.pool.map((tontine) => tontine.id).toSet();

      state = AsyncData(
        current.copyWith(
          pool: [
            ...current.pool,
            ...page.items.where((tontine) => !seen.contains(tontine.id)),
          ],
          currentPage: page.currentPage,
          lastPage: page.lastPage,
          total: page.total,
          loadingMore: false,
        ),
      );
    } on Object {
      final current = state.valueOrNull;
      if (current != null) state = AsyncData(current.copyWith(loadingMore: false));
    }
  }
}

/// `autoDispose` : sans cela le catalogue reste en cache toute la session et
/// l'utilisateur ne voit jamais une tontine créée ou rejointe depuis un autre
/// appareil tant qu'il n'a pas redémarré l'application. Quitter l'écran libère
/// l'état, y revenir recharge.
final tontineCatalogProvider =
    AutoDisposeAsyncNotifierProvider<TontineCatalogController, TontineCatalogState>(
  TontineCatalogController.new,
);

/* ------------------------------------------------------------------ */
/* Adhésion                                                            */
/* ------------------------------------------------------------------ */

/// `POST /tontines/{id}/join`, avec ses effets de bord.
///
/// Volontairement un appel et non un `AsyncNotifier` : l'état « en cours » et
/// le message d'erreur appartiennent à l'écran qui déclenche l'action, et les
/// deux ont leur place dans le bouton et le bandeau. Un état global
/// reproduirait ces informations à un second endroit où elles se
/// désynchroniseraient.
///
/// Le refus n'est pas masqué : tontine pleine, adhésion en double, e-mail non
/// vérifié sont des réponses métier que l'utilisateur doit LIRE. Elles
/// remontent donc en exception, et c'est l'appelant qui les affiche.
class JoinTontineController {
  const JoinTontineController(this._ref, this._id);

  final Ref _ref;
  final int _id;

  Future<Tontine> call() async {
    final joined = await _ref.read(tontineRepositoryProvider).join(
          _id,
          currentUserId: _ref.read(currentUserIdProvider),
        );

    // Le catalogue annonce les places restantes et le statut d'adhésion : il
    // devient faux dès l'adhésion. On l'invalide plutôt que de le reconstruire
    // à la main — sa source est la même requête.
    _ref.invalidate(tontineCatalogProvider);

    return joined;
  }
}

final joinTontineProvider = Provider.family<JoinTontineController, int>(
  (ref, id) => JoinTontineController(ref, id),
);
