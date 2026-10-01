import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:tontine_achat_store/core/di/providers.dart';
import 'package:tontine_achat_store/features/contributions/contribution.dart';
import 'package:tontine_achat_store/features/contributions/contribution_repository.dart';
import 'package:tontine_achat_store/features/tontines/my_tontines_page.dart';

final contributionRepositoryProvider = Provider<ContributionRepository>(
  (ref) => ContributionRepository(api: ref.watch(apiClientProvider)),
);

/// Les cotisations, réparties comme le fait le SPA web.
///
/// Quatre ensembles et non deux : « à payer », « en attente de vérification »,
/// « terminées » et « refusées » ne se rermentent pas de la même façon. Un code
/// de virement refusé doit rester visible — c'est la seule trace du problème —
/// alors qu'une cotisation payée disparaît de la liste de travail.
class ContributionsState {
  const ContributionsState(this.all);

  final List<Contribution> all;

  List<Contribution> get awaiting => all.where((c) => c.awaitsVerification).toList(growable: false);

  List<Contribution> get rejected => all.where((c) => c.verificationStatus.isRejected).toList(growable: false);

  List<Contribution> get settled => all.where((c) => c.isSettled).toList(growable: false);

  /// Ce qui reste réellement à verser : ni payée, ni annulée, ni en attente
  /// d'une décision administrative.
  List<Contribution> get todo => all
      .where(
        (c) =>
            !c.isSettled &&
            !c.awaitsVerification &&
            c.status != ContributionStatus.cancelled,
      )
      .toList(growable: false);

  /// Somme des cotisations restant à verser, toutes tontines confondues.
  double get remainingAmount =>
      todo.fold<double>(0, (sum, contribution) => sum + contribution.amount);

  bool get isEmpty => all.isEmpty;
}

/// Liste des cotisations, et actions de paiement.
///
/// Les paiements passent par ce contrôleur plutôt que par l'écran : une
/// cotisation payée doit disparaître de « à payer » et apparaître dans
/// « terminées » sans recharger toute la liste depuis le serveur. Le serveur
/// renvoyant la cotisation à jour, la substitution est exacte.
class ContributionsController extends AutoDisposeAsyncNotifier<ContributionsState> {
  @override
  Future<ContributionsState> build() async {
    final all = await ref.read(contributionRepositoryProvider).index();

    // Chaque chargement est une occasion de recaler les rappels : c'est ici que
    // l'on sait ce qui reste à payer. Gratter la notification d'une cotisation
    // déjà réglée est aussi important que programmer celle d'une nouvelle.
    await refreshPaymentReminders(ref, contributions: all);

    return ContributionsState(all);
  }

  Future<Contribution> pay(int id, {required String channel, String? reference}) async {
    return _submit(
      () => ref.read(contributionRepositoryProvider).pay(
            id,
            channel: channel,
            reference: reference,
          ),
    );
  }

  Future<Contribution> submitTransferCode(
    int id, {
    required String channel,
    required String transferCode,
  }) async {
    return _submit(
      () => ref.read(contributionRepositoryProvider).submitTransferCode(
            id,
            channel: channel,
            transferCode: transferCode,
          ),
    );
  }

  /// Exécute l'envoi puis remplace la cotisation dans la liste.
  ///
  /// L'erreur n'est pas avalée : `409` (déjà payée, code en attente) et `422`
  /// (canal manuel envoyé à `pay`) sont des réponses métier que l'utilisateur
  /// doit lire. Elles remontent donc jusqu'à l'écran.
  Future<Contribution> _submit(Future<Contribution> Function() send) async {
    final updated = await send();

    final snapshot = state.valueOrNull;
    if (snapshot != null) {
      state = AsyncData(
        ContributionsState([
          for (final contribution in snapshot.all)
            if (contribution.id == updated.id) updated else contribution,
        ]),
      );
    }

    return updated;
  }

  Future<void> reload() async {
    state = const AsyncLoading<ContributionsState>().copyWithPrevious(state);
    state = await AsyncValue.guard(() async {
      final all = await ref.read(contributionRepositoryProvider).index();
      await refreshPaymentReminders(ref, contributions: all);

      return ContributionsState(all);
    });
  }
}

/// `autoDispose` : l'historique des cotisations est celui qui change le plus
/// vite — un versement validé par un administrateur, un tour soldé par un autre
/// appareil. Le garder en cache toute la session affichait un montant faux.
final contributionsProvider =
    AutoDisposeAsyncNotifierProvider<ContributionsController, ContributionsState>(
  ContributionsController.new,
);
