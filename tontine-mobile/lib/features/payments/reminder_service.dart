import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:timezone/data/latest_all.dart' as tzdata;
import 'package:timezone/timezone.dart' as tz;

import 'package:tontine_achat_store/features/payments/reminders.dart';

/// Ce que le service demande au système de notifications.
///
/// Une interface, et non le greffon direct, pour une raison très concrète :
/// `FlutterLocalNotificationsPlugin` est un *factory*ingleton, donc
/// impossible à étendre, et ses canaux de plateforme n'existent pas sous
/// `flutter test`. Sans cette couture, le seul test possible aurait été
/// « ça n'a pas planté » — c'est-à-dire rien du tout sur la partie qui compte :
/// **quelles notifications sont programmées, à quelles dates, sous quels
/// identifiants**. Un rappel fantôme ou une relance annulée par erreur ne
/// provoquent aucune exception.
abstract class ReminderScheduler {
  /// Demande l'autorisation d'afficher des notifications.
  ///
  /// `false` signifie que l'utilisateur ne veut pas être dérangé : la
  /// programmation est alors inutile — et contre-productive.
  Future<bool> requestPermission();

  /// Supprime toutes les notifications programmées.
  Future<void> cancelAll();

  /// Programme une notification pour [at].
  Future<void> schedule({
    required int id,
    required DateTime at,
    required String title,
    required String body,
    required String payload,
  });
}

/// Le greffon réel, au-dessus de `flutter_local_notifications`.
class PluginReminderScheduler implements ReminderScheduler {
  PluginReminderScheduler({FlutterLocalNotificationsPlugin? plugin})
      : _plugin = plugin ?? FlutterLocalNotificationsPlugin();

  final FlutterLocalNotificationsPlugin _plugin;

  static const channelId = 'paiements_rappels';
  static const channelName = 'Rappels de paiement';
  static const channelDescription =
      'Te rappelle quand une cotisation arrive à échéance ou quand c\'est ton '
      'tour de recueillir le versement de ta tontine.';

  @override
  Future<bool> requestPermission() async {
    final android = _plugin.resolvePlatformSpecificImplementation<
        AndroidFlutterLocalNotificationsPlugin>();

    await android?.createNotificationChannel(
      const AndroidNotificationChannel(
        channelId,
        channelName,
        description: channelDescription,
        importance: Importance.high,
      ),
    );

    return await android?.requestNotificationsPermission() ?? true;
  }

  @override
  Future<void> cancelAll() => _plugin.cancelAll();

  @override
  Future<void> schedule({
    required int id,
    required DateTime at,
    required String title,
    required String body,
    required String payload,
  }) async {
    await _plugin.zonedSchedule(
      id: id,
      title: title,
      body: body,
      scheduledDate: tz.TZDateTime.from(at, tz.local),
      // `inexactAllowWhileIdle` : le système peut regrouper la livraison
      // plutôt que de réveiller l'appareil à l'heure exacte. Le garde-fou Doze
      // l'autorise, là où `exactAllowWhileIdle` exigerait une permission
      // supplémentaire — que l'utilisateur refuserait, et qui disable tout.
      androidScheduleMode: AndroidScheduleMode.inexactAllowWhileIdle,
      notificationDetails: const NotificationDetails(
        android: AndroidNotificationDetails(
          channelId,
          channelName,
          channelDescription: channelDescription,
          importance: Importance.high,
          priority: Priority.high,
        ),
      ),
      payload: payload,
    );
  }
}

/// Programme les rappels de cotisation et de tour.
///
/// **Pourquoi programmer plutôt que sonder.** Faire tourner une tâche Dart
/// toutes les heures en arrière-plan suppose un travail que le système bride
/// très vite (Doze, économie d'énergie) et qui se solde toujours par de la
/// batterie perdue. En planifiant les notifications à l'avance — ce que
/// `zonedSchedule` fait, avec le réveil de l'appareil nécessaire — le rappel
/// arrive même application fermée, sans rien exécuter.
///
/// Le prix de ce choix : il faut resynchroniser à chaque nouvelle information.
/// C'est fait dans `syncPaymentReminders`, appelé après chaque chargement de
/// cotisations — et la resynchronisation automatique y contribue, puisqu'elle
/// relit la liste au moins une fois par minute.
class PaymentReminderService {
  PaymentReminderService({ReminderScheduler? scheduler})
      : _scheduler = scheduler ?? PluginReminderScheduler();

  final ReminderScheduler _scheduler;

  bool _ready = false;

  /// Demande l'autorisation, une seule fois.
  ///
  /// Android 13 (API 33) exige une permission explicite, refusée par défaut
  /// pour une application installée après cette version. Sans elle, tout le
  /// reste fonctionne et l'utilisateur ne voit RIEN : l'échec est donc
  /// silencieux, d'où le retour explicite.
  Future<bool> prepare() async {
    if (_ready) return true;

    try {
      // Le fuseau doit être initialisé avant toute conversion : `tz.local` est
      // inconnu tant que les données du paquet ne sont pas chargées.
      tzdata.initializeTimeZones();

      _ready = await _scheduler.requestPermission();

      return _ready;
    } on Object catch (error) {
      // Une plateforme qui refuse l'initialisation ne doit pas empêcher
      // l'application de tourner : les rappels sont un confort, pas une
      // fonction dont dépend le reste.
      debugPrint('Rappels de paiement indisponibles : $error');
      _ready = false;
      return false;
    }
  }

  /// Aligne les notifications programmées sur [reminders].
  ///
  /// Tout est annulé puis reprogramé : la liste est courte, et l'état précédent
  /// peut contenir des rappels devenus faux — une cotisation réglée entre-temps,
  /// un tour dont on n'est plus le bénéficiaire. Un calcul différentiel serait
  /// plus fin, mais l'erreur d'un seul rappel fantôme est plus coûteuse que la
  /// reprogrammation.
  Future<void> sync(List<PaymentReminder> reminders, {required DateTime now}) async {
    if (!await prepare()) return;

    try {
      await _scheduler.cancelAll();

      if (reminders.isEmpty) return;

      // Un rappel de cotisation se RÉPÈTE : autant d'occurrences que de créneaux,
      // chacune avec son propre identifiant. C'est le détail qui faisait que la
      // relance n'existait pas — toutes les occurrences partaient sous le même
      // identifiant Android, et chacune remplaçait la précédente : il ne restait
      // qu'une notification, celle du dernier créneau, annulée à la
      // synchronisation suivante.
      final slots = ReminderRules.slots(now);

      for (final reminder in reminders) {
        final moments = switch (reminder.kind) {
          ReminderKind.contributionDue => slots,
          // Un tour n'est pas une relance : un seul message, au plus tôt.
          ReminderKind.turnToCollect => [ReminderRules.turnMoment(now)],
        };

        for (var index = 0; index < moments.length; index++) {
          await _scheduler.schedule(
            id: notificationIdFor(slotKey(reminder.id, index)),
            at: moments[index],
            title: reminder.title,
            body: reminder.body,
            payload: reminder.id,
          );
        }
      }
    } on Object catch (error) {
      debugPrint('Programmation des rappels impossible : $error');
    }
  }

  /// Supprime tous les rappels, à la déconnexion.
  ///
  /// Sans cela, l'application continuerait de relancer un utilisateur qui n'a
  /// plus de session — et dont les cotisations peuvent être réglées depuis un
  /// autre appareil.
  Future<void> clear() async {
    try {
      await _scheduler.cancelAll();
    } on Object catch (error) {
      debugPrint('Annulation des rappels impossible : $error');
    }
  }
}

final paymentReminderServiceProvider = Provider<PaymentReminderService>(
  (ref) => PaymentReminderService(),
);

/// Programme les rappels correspondant à l'état courant.
///
/// Volontairement tolérant aux échecs : une notification impossible à
/// programmer ne doit jamais faire échouer le chargement des cotisations.
Future<void> syncPaymentReminders(
  Ref ref, {
  required List<PaymentReminder> reminders,
  required DateTime now,
}) async {
  final service = ref.read(paymentReminderServiceProvider);

  await service.sync(reminders, now: now);
}
