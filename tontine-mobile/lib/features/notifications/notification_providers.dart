import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:tontine_achat_store/core/di/providers.dart';
import 'package:tontine_achat_store/features/notifications/notification.dart';
import 'package:tontine_achat_store/features/notifications/notification_repository.dart';

final notificationRepositoryProvider = Provider<NotificationRepository>(
  (ref) => NotificationRepository(api: ref.read(apiClientProvider)),
);

/// Liste des notifications et actions de lecture.
class NotificationsController extends AutoDisposeAsyncNotifier<List<AppNotification>> {
  @override
  Future<List<AppNotification>> build() async {
    return ref.read(notificationRepositoryProvider).index();
  }

  Future<void> markAsRead(int id) async {
    await ref.read(notificationRepositoryProvider).markAsRead(id);

    final snapshot = state.valueOrNull;
    if (snapshot == null) return;

    state = AsyncData([
      for (final notification in snapshot)
        if (notification.id == id) _asRead(notification) else notification,
    ]);
  }

  Future<void> markAllAsRead() async {
    await ref.read(notificationRepositoryProvider).markAllAsRead();

    final snapshot = state.valueOrNull;
    if (snapshot == null) return;

    state = AsyncData([for (final notification in snapshot) _asRead(notification)]);
  }

  Future<void> reload() async {
    state = const AsyncLoading<List<AppNotification>>().copyWithPrevious(state);
    state = await AsyncValue.guard(() => ref.read(notificationRepositoryProvider).index());
  }
}

/// `autoDispose` : un message reçu pendant que l'application était fermée doit
/// apparaître au retour, pas au prochain redémarrage.
final notificationsProvider =
    AutoDisposeAsyncNotifierProvider<NotificationsController, List<AppNotification>>(
  NotificationsController.new,
);

/// Nombre de notifications non lues, pour la pastille de l'accueil.
///
/// Il se DÉDUIT de la liste tenue par [notificationsProvider] au lieu de refaire
/// sa propre requête : auparavant il interrogeait `/notifications` de son côté,
/// et le marquage « lu » ne le touchait pas. La pastille restait donc rouge
/// après consultation — le seul endroit où elle se trouve étant l'accueil,
/// que l'on quitte pour lire, la pastille avait donc toutes les chances de
/// rester rouge toute la session.
///
/// Conséquence acceptée : consulter les notifications ne les marque pas comme
/// lues. Seul l'appui sur une notification, ou « Tout lire », le fait.
final unreadNotificationsProvider = FutureProvider<int>((ref) async {
  final all = await ref.watch(notificationsProvider.future);

  return all.where((notification) => !notification.isRead).length;
});

AppNotification _asRead(AppNotification notification) => AppNotification(
      id: notification.id,
      type: notification.type,
      data: notification.data,
      isRead: true,
      createdAt: notification.createdAt,
    );
