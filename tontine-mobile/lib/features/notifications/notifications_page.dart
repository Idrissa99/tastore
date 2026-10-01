import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/core/network/api_exception.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/core/widgets/state_views.dart';
import 'package:tontine_achat_store/features/notifications/notification.dart';
import 'package:tontine_achat_store/features/notifications/notification_providers.dart';

/// Écran des notifications.
///
/// Lisant. Le message vient du backend, l'icône de la famille d'événement, et
/// la destination du chemin du lien — voir [AppNotification.mobilePath].
///
/// **Consulter l'écran vaut lecture.** Dès que la liste est affichée, toutes
/// les notifications sont marquées comme lues. C'est le geste attendu de
/// l'utilisateur : il ouvre ses messages, il les a lus. La pastille rouge de
/// l'accueil doit descendre à zéro du simple fait d'être ici, sans qu'il ait à
/// trouver « Tout lire » — ni à ouvrir chaque message un par un. Le marquage
/// individuel reste en place, utile quand on arrive sur une notification précise
/// sans passer par l'écran.
class NotificationsPage extends ConsumerStatefulWidget {
  const NotificationsPage({super.key});

  @override
  ConsumerState<NotificationsPage> createState() => _NotificationsPageState();
}

class _NotificationsPageState extends ConsumerState<NotificationsPage> {
  /// Une seule fois par ouverture : sans ce verrou, chaque mise à jour de la
  /// liste (dont le rafraîchissement automatique) relancerait un « tout lire ».
  bool _clearedOnOpen = false;

  /// Ouvre une notification : la marque lue, puis va à sa destination.
  ///
  /// Le marquage précède la navigation et n'attend pas la réponse : partir sans
  /// attendre laisserait la pastille rouge affichée si l'utilisateur revenait
  /// immédiatement en arrière.
  Future<void> _open(AppNotification notification) async {
    if (!notification.isRead) {
      unawaited(_markAsRead(notification.id));
    }

    final path = notification.mobilePath;

    // `push` et non `go` : la notification s'ouvre PAR-DESSUS l'écran
    // courant, et le retour ramène à la liste — le comportement attendu
    // quand on ouvre une notification depuis son badge.
    if (path != null && mounted) await context.push(path);
  }

  Future<void> _markAsRead(int id) async {
    try {
      await ref.read(notificationsProvider.notifier).markAsRead(id);
    } on ApiException {
      // Un échec de lecture ne doit pas empêcher de lire le message.
    }
  }

  /// Marque tout comme lu dès que la liste existe.
  ///
  /// Déclenché depuis un `listen` plutôt qu'à l'ouverture : la liste arrive de
  /// façon asynchrone, et marquer avant qu'elle soit connue n'aurait rien à
  /// marquer.
  void _clearOnOpen(AsyncValue<List<AppNotification>> next) {
    if (_clearedOnOpen) return;

    final all = next.valueOrNull;
    if (all == null || all.isEmpty) return;

    _clearedOnOpen = true;

    // Rien à écrire si tout est déjà lu : un `POST` inutile à chaque ouverture
    // d'un écran déjà à jour n'aurait aucun effet visible.
    if (all.every((notification) => notification.isRead)) return;

    // différé d'un tour de boucle : l'appel réécrit l'état du provider, et le
    // faire pendant la construction de l'écran le ferait au milieu d'un build.
    Future<void>.microtask(() async {
      try {
        await ref.read(notificationsProvider.notifier).markAllAsRead();
      } on ApiException {
        // La pastille peut rester rouge si le serveur refuse : on le saura à la
        // synchronisation suivante, et l'utilisateur peut retenter.
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    ref.listen(notificationsProvider, (_, next) => _clearOnOpen(next));

    final notifications = ref.watch(notificationsProvider);
    final unread = notifications.valueOrNull?.where((n) => !n.isRead).length ?? 0;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Notifications'),
        actions: [
          if (unread > 0)
            TextButton(
              onPressed: () => ref.read(notificationsProvider.notifier).markAllAsRead(),
              child: const Text('Tout lire'),
            ),
          const SizedBox(width: 8),
        ],
      ),
      body: notifications.when(
        loading: () => const LoadingView(label: 'Chargement des notifications…'),
        error: (error, _) => ErrorView(
          message: '$error',
          onRetry: () => ref.read(notificationsProvider.notifier).reload(),
        ),
        data: (all) {
          if (all.isEmpty) {
            return const EmptyView(
              icon: Icons.notifications_none,
              title: 'Aucune notification',
              message: 'Tu seras prévenu quand ta tontine ou un versement '
                  'évolue.',
            );
          }

          return RefreshIndicator(
            onRefresh: () => ref.read(notificationsProvider.notifier).reload(),
            child: ListView.separated(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
              itemCount: all.length,
              separatorBuilder: (_, __) => const SizedBox(height: 10),
              itemBuilder: (context, index) {
                final notification = all[index];

                return Card(
                  child: InkWell(
                    borderRadius: BorderRadius.circular(AppRadius.lg),
                    // L'appui est TOUJOURS actif, destination ou non : sans
                    // elle, une notification dont le lien ne se traduit pas
                    // restait non lue à jamais — c'est-à-dire une pastille rouge
                    // que rien ne pouvait éteindre.
                    onTap: () => _open(notification),
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Container(
                            padding: const EdgeInsets.all(8),
                            decoration: BoxDecoration(
                              color: notification.tone.background,
                              borderRadius: BorderRadius.circular(AppRadius.sm),
                            ),
                            child: Icon(notification.icon, size: 18, color: notification.tone.foreground),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  notification.message,
                                  style: TextStyle(
                                    fontSize: 14,
                                    height: 1.4,
                                    fontWeight:
                                        notification.isRead ? FontWeight.w500 : FontWeight.w700,
                                    color: AppColors.ink900,
                                  ),
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  Fmt.relative(notification.createdAt),
                                  style: const TextStyle(fontSize: 11, color: AppColors.ink400),
                                ),
                              ],
                            ),
                          ),
                          if (!notification.isRead)
                            Container(
                              width: 8,
                              height: 8,
                              margin: const EdgeInsets.only(top: 6, left: 6),
                              decoration: const BoxDecoration(
                                color: AppColors.primary600,
                                shape: BoxShape.circle,
                              ),
                            ),
                        ],
                      ),
                    ),
                  ),
                );
              },
            ),
          );
        },
      ),
    );
  }
}
