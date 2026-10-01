import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/features/contributions/contribution.dart';
import 'package:tontine_achat_store/features/payments/reminder_service.dart';
import 'package:tontine_achat_store/features/payments/reminders.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/core/widgets/app_badge.dart';
import 'package:tontine_achat_store/core/widgets/state_views.dart';
import 'package:tontine_achat_store/features/contributions/contribution_providers.dart';
import 'package:tontine_achat_store/features/tontines/tontine.dart';
import 'package:tontine_achat_store/features/tontines/tontine_providers.dart';
import 'package:tontine_achat_store/features/tontines/widgets/tontine_card.dart';
import 'package:tontine_achat_store/routing/app_router.dart';

/// Tontines indexées par identifiant, pour les règles de rappel.
///
/// La liste des rappels a besoin du `start_date` et de la `frequency` de
/// CHAQUE tontine concernée, que la ressource imbriquée d'une cotisation ne
/// contient pas. On relit donc les fiches — le même aller-retour que
/// [myTontinesProvider] fait déjà.
Future<Map<int, Tontine>> loadTontineIndex(
  Ref ref, {
  required List<Contribution> contributions,
}) async {
  final ids = <int>{};
  for (final contribution in contributions) {
    if (contribution.tontine.id > 0) ids.add(contribution.tontine.id);
  }

  if (ids.isEmpty) return const {};

  final repository = ref.read(tontineRepositoryProvider);
  final loaded = await Future.wait([for (final id in ids) repository.detail(id)]);

  return {for (final tontine in loaded) tontine.id: tontine};
}

/// Programme les rappels de cotisation et de tour.
///
/// Appelé à chaque chargement des cotisations : c'est le moment où l'on sait ce
/// qui reste à payer, et donc ce qu'il faut rappeler — ou cesser de rappeler.
Future<void> refreshPaymentReminders(
  Ref ref, {
  required List<Contribution> contributions,
}) async {
  try {
    final now = DateTime.now();
    final tontines = await loadTontineIndex(ref, contributions: contributions);

    await syncPaymentReminders(
      ref,
      reminders: buildReminders(
        contributions: contributions,
        tontines: tontines,
        currentUserId: ref.read(currentUserIdProvider),
        now: now,
      ),
      now: now,
    );
  } on Object {
    // Un rappel impossible à programmer ne doit jamais faire échouer l'écran
    // qui l'a déclenché : les cotisations restent son sujet.
  }
}

/// Les tontines auxquelles l'utilisateur appartient.
///
/// **Il n'existe aucun endpoint « mes tontines ».** L'API n'expose que le
/// catalogue ; le SPA déduit donc la liste de `GET /contributions`, puis relit
/// chaque tontine (`useMemberData.js` côté web). On fait exactement de même,
/// plutôt que d'inventer une source de données.
///
/// Conséquence à assumer : une tontine rejointe mais dont aucune cotisation
/// n'a encore été générée n'apparaît pas. C'est le cas d'une tontine qui n'a
/// pas démarré — et le catalogue, lui, les montre toutes.
final myTontinesProvider = FutureProvider.autoDispose<List<Tontine>>((ref) async {
  final state = await ref.watch(contributionsProvider.future);

  final ids = <int>[];
  for (final contribution in state.all) {
    final id = contribution.tontine.id;
    if (id > 0 && !ids.contains(id)) ids.add(id);
  }

  if (ids.isEmpty) return const [];

  final repository = ref.watch(tontineRepositoryProvider);

  // `Future.wait` plutôt qu'une boucle : les fiches sont indépendantes, et le
  // compteur n'apparaît qu'une fois toutes répondu.
  return Future.wait([
    for (final id in ids) repository.detail(id),
  ]);
});

/// Onglet « Mes tontines » : les tontines suivies, avec la position de
/// l'utilisateur.
class MyTontinesPage extends ConsumerWidget {
  const MyTontinesPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final tontines = ref.watch(myTontinesProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Mes tontines')),
      body: tontines.when(
        loading: () => const LoadingView(label: 'Chargement de tes tontines…'),
        error: (error, _) => ErrorView(
          message: '$error',
          onRetry: () => ref.invalidate(myTontinesProvider),
        ),
        data: (all) {
          if (all.isEmpty) {
            return EmptyView(
              icon: Icons.groups_outlined,
              title: 'Aucune tontine suivie',
              message: 'Les tontines que tu rejoins apparaissent ici dès '
                  'qu\'elles génèrent tes cotisations.',
              actionLabel: 'Découvrir le catalogue',
              onAction: () => context.go(AppRoutes.catalog),
            );
          }

          return RefreshIndicator(
            onRefresh: () async {
              // Les cotisations sont la source de cette liste : les relire
              // évite d'avoir à reconstruire un cache qui n'existe pas.
              await ref.read(contributionsProvider.notifier).reload();
              ref.invalidate(myTontinesProvider);
            },
            child: ListView.separated(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
              itemCount: all.length,
              separatorBuilder: (_, __) => const SizedBox(height: 14),
              itemBuilder: (context, index) {
                final tontine = all[index];

                return Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    TontineCard(
                      tontine: tontine,
                      onTap: () => context.push(AppRoutes.detail(tontine.id)),
                    ),
                    const SizedBox(height: 8),
                    _Membership(tontine: tontine),
                  ],
                );
              },
            ),
          );
        },
      ),
    );
  }
}

/// La ligne « ma place » : position dans l'ordre de passage et tour courant.
///
/// La position n'est affichée QUE si le serveur l'a révélée — avant le
/// lancement, `position` ne porte que l'ordre d'arrivée.
class _Membership extends StatelessWidget {
  const _Membership({required this.tontine});

  final Tontine tontine;

  @override
  Widget build(BuildContext context) {
    final me = tontine.members.where((member) => member.isMe).firstOrNull;

    return Row(
      children: [
        Expanded(
          child: Text(
            'Tour ${tontine.round.number} · ${Fmt.fcfa(tontine.contributionAmount)} ${tontine.frequency.label}',
            style: const TextStyle(fontSize: 12, color: AppColors.ink500),
          ),
        ),
        if (tontine.rotationRevealed && me?.position != null)
          AppBadge(
            label: 'Position ${me!.position}',
            tone: AppTone.accent,
            icon: Icons.stars_outlined,
          )
        else if (me != null)
          const AppBadge(
            label: 'Inscrit',
            tone: AppTone.brand,
            icon: Icons.person_outline,
          ),
      ],
    );
  }
}
