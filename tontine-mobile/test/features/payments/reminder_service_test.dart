import 'package:flutter_test/flutter_test.dart';
import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/features/contributions/contribution.dart';
import 'package:tontine_achat_store/features/payments/reminder_service.dart';
import 'package:tontine_achat_store/features/payments/reminders.dart';
import 'package:tontine_achat_store/features/tontines/tontine.dart';

import '../contributions/contributions_test.dart' show contributionJson;
import '../tontines/tontine_fixtures.dart' show memberJson, tontineJson;

/// Une notification programmée, telle que le service l'a demandée.
class Scheduled {
  const Scheduled(this.id, this.title, this.body, this.at, this.payload);

  final int id;
  final String title;
  final String body;
  final DateTime at;
  final String payload;
}

/// Un ordonnanceur qui enregistre au lieu d'appeler la plateforme.
///
/// Le greffon réel n'a pas de canaux hors application : sous `flutter test` il
/// lève. Ce double permet de vérifier CE QUE le service programme — combien de
/// notifications, à quelles dates, sous quels identifiants — qui est tout ce qui
/// peut réellement casser sans téléphone. Un rappel fantôme, ou une relance
/// annulée par erreur, ne provoquent aucune exception : seule l'observation de
/// la liste programmée les révèle.
class RecordingScheduler implements ReminderScheduler {
  final List<Scheduled> scheduled = [];
  int cancellations = 0;
  int permissionRequests = 0;
  bool permissionGranted = true;

  /// Fait échouer l'initialisation, comme un appareil qui refuse tout.
  Object? permissionError;

  @override
  Future<bool> requestPermission() async {
    permissionRequests++;

    final error = permissionError;
    if (error != null) throw error;

    return permissionGranted;
  }

  @override
  Future<void> cancelAll() async {
    cancellations++;
    scheduled.clear();
  }

  @override
  Future<void> schedule({
    required int id,
    required DateTime at,
    required String title,
    required String body,
    required String payload,
  }) async {
    scheduled.add(Scheduled(id, title, body, at, payload));
  }

  Iterable<Scheduled> titled(String title) => scheduled.where((s) => s.title == title);
}

Tontine tontineOf({int id = 11, int currentRound = 3}) {
  return Tontine.fromJson(
    tontineJson(
      id: id,
      // `daily` : l'échéance du round 3 tombe alors le 2 octobre, date des
      // tests. En `monthly` elle tomberait le 30 octobre, et aucun rappel ne
      // serait produit — l'échec viendrait de la fixture, pas du code.
      frequency: 'daily',
      rotationRevealed: true,
      members: [
        memberJson(userId: 7, position: 1).cast<String, dynamic>()
          ..['beneficiary_round'] = currentRound,
      ],
      overrides: {
        'current_round': currentRound,
        'start_date': '2026-09-30T00:00:00.000000Z',
      },
      round: {
        'number': currentRound,
        'expected_members': 12,
        'paid_members': 0,
        'is_funded': false,
      },
    ),
    currentUserId: 7,
  );
}

Contribution contributionOf({int id = 1, int round = 3, String status = 'pending'}) {
  return Contribution.fromJson(
    contributionJson(
      id: id,
      round: round,
      status: status,
      tontine: const {'id': 11, 'name': 'test2', 'status': 'active', 'type': 'cash'},
    ),
  );
}

const dueReminder = PaymentReminder(
  id: 'contributions',
  kind: ReminderKind.contributionDue,
  title: 'Cotisation à verser',
  body: '133 750 FCFA à solder.',
);

const turnReminder = PaymentReminder(
  id: 'turn:11:3',
  kind: ReminderKind.turnToCollect,
  title: 'C\'est ton tour !',
  body: 'tu reçois 300 000 FCFA.',
);

void main() {
  group('la relance se répète', () {
    test('une notification par créneau, sur trois jours', () async {
      final scheduler = RecordingScheduler();
      final service = PaymentReminderService(scheduler: scheduler);
      final now = DateTime(2026, 10, 2, 9);

      await service.sync([dueReminder], now: now);

      final expected = ReminderRules.slots(now);

      // Régression : toutes les occurrences partaient sous le MÊME identifiant
      // Android, et chacune remplaçait la précédente. Il ne restait donc qu'une
      // notification — celle du dernier créneau — et la synchronisation suivante
      // l'annulait. Aucune relance n'arrivait jamais.
      expect(scheduler.scheduled, hasLength(expected.length));
      expect(scheduler.scheduled.map((s) => s.id).toSet(), hasLength(expected.length));
      expect(expected.length, greaterThan(6), reason: 'plusieurs rappels sur trois jours');

      final times = scheduler.scheduled.map((s) => s.at).toList()..sort();
      for (var i = 0; i < expected.length; i++) {
        expect(times[i].millisecondsSinceEpoch, expected[i].millisecondsSinceEpoch);
      }
    });

    test('aucun rappel la nuit', () async {
      final scheduler = RecordingScheduler();
      final service = PaymentReminderService(scheduler: scheduler);

      await service.sync([dueReminder], now: DateTime(2026, 10, 2, 22));

      expect(scheduler.scheduled, isNotEmpty);
      for (final notification in scheduler.scheduled) {
        expect(
          ReminderRules.isWakingHour(notification.at),
          isTrue,
          reason: '${notification.at} est un rappel nocturne',
        );
      }
    });

    test('une échéance vue la nuit est relancée le matin même', () async {
      final scheduler = RecordingScheduler();
      final service = PaymentReminderService(scheduler: scheduler);

      await service.sync([dueReminder], now: DateTime(2026, 10, 2, 2));

      expect(scheduler.scheduled, isNotEmpty);

      final now = DateTime(2026, 10, 2, 2);
      final horizon = now.add(const Duration(hours: ReminderRules.horizonHours));

      for (final notification in scheduler.scheduled) {
        expect(notification.at.isAfter(now), isTrue, reason: '${notification.at} est déjà passé');
        expect(notification.at.isAfter(horizon), isFalse, reason: 'au-delà des 72 h');
        expect(ReminderRules.isWakingHour(notification.at), isTrue);
      }

      // Le premier créneau est dans la matinée du même jour : pas vingt heures
      // de retard sur une cotisation due.
      expect(scheduler.scheduled.first.at.day, 2);
    });
  });

  group('le tour n\'est pas une relance', () {
    test('un seul message, au plus tôt en journée', () async {
      final scheduler = RecordingScheduler();
      final service = PaymentReminderService(scheduler: scheduler);
      final now = DateTime(2026, 10, 2, 9);

      await service.sync([turnReminder], now: now);

      // Le répéter toutes les quatre heures pendant trois jours serait du bruit.
      expect(scheduler.scheduled, hasLength(1));
      expect(scheduler.scheduled.single.at, now.add(ReminderRules.firstNudge));
    });

    test('cotisation et tour ne se marchent pas dessus', () async {
      final scheduler = RecordingScheduler();
      final service = PaymentReminderService(scheduler: scheduler);
      final now = DateTime(2026, 10, 2, 9);

      await service.sync([dueReminder, turnReminder], now: now);

      expect(scheduler.scheduled.map((s) => s.id).toSet(), hasLength(scheduler.scheduled.length));
    });
  });

  group('la resynchronisation', () {
    test('rien à rappeler efface les séries précédentes', () async {
      final scheduler = RecordingScheduler();
      final service = PaymentReminderService(scheduler: scheduler);
      final now = DateTime(2026, 10, 2, 9);

      await service.sync([dueReminder], now: now);
      expect(scheduler.scheduled, isNotEmpty);

      // C'est ce qui se passe dès que l'utilisateur paie : les relances
      // doivent disparaître, pas continuer de sonner.
      await service.sync(const [], now: now);

      expect(scheduler.scheduled, isEmpty);
      expect(scheduler.cancellations, greaterThanOrEqualTo(2));
    });

    test('l\'autorisation n\'est demandée qu\'une fois', () async {
      final scheduler = RecordingScheduler();
      final service = PaymentReminderService(scheduler: scheduler);

      await service.prepare();
      await service.prepare();
      await service.sync([dueReminder], now: DateTime(2026, 10, 2, 9));
      await service.sync([turnReminder], now: DateTime(2026, 10, 2, 9));

      // La permission Android s'affiche une fois : la redemander à chaque
      // chargement de cotisations — donc toutes les minutes, la resynchronisation
      // passant — serait agaçant. Et le refus est définitif : la redemander
      // ne la rendrait pas.
      expect(scheduler.permissionRequests, 1);
    });
  });

  group('un appareil qui refuse', () {
    test('aucune notification n\'est programmée', () async {
      final scheduler = RecordingScheduler()..permissionGranted = false;
      final service = PaymentReminderService(scheduler: scheduler);

      await service.sync([dueReminder], now: DateTime(2026, 10, 2, 9));

      // Le refus est silencieux côté utilisateur : programmer quand même
      // produirait des notifications invisibles, donc une fausse impression que
      // la fonction marche.
      expect(scheduler.scheduled, isEmpty);
    });

    test('une plateforme qui lève ne casse pas l\'écran', () async {
      final scheduler = RecordingScheduler()..permissionError = StateError('canal absent');
      final service = PaymentReminderService(scheduler: scheduler);

      // Doit se terminer sans erreur : les rappels sont un confort, pas une
      // fonction dont dépend le chargement des cotisations.
      await service.sync([dueReminder], now: DateTime(2026, 10, 2, 9));
      await service.clear();

      expect(scheduler.scheduled, isEmpty);
    });
  });

  group('de bout en bout', () {
    test('cotisation échue ET tour du jour : les deux sont annoncés', () async {
      final scheduler = RecordingScheduler();
      final service = PaymentReminderService(scheduler: scheduler);
      final now = DateTime(2026, 10, 2, 9);
      final tontine = tontineOf();

      await service.sync(
        buildReminders(
          contributions: [contributionOf(round: 3)],
          tontines: {11: tontine},
          currentUserId: 7,
          now: now,
        ),
        now: now,
      );

      expect(scheduler.titled('Cotisation à verser'), hasLength(ReminderRules.slots(now).length));
      expect(scheduler.titled('C\'est ton tour !'), hasLength(1));
    });

    test('le tour annonce le montant que l\'utilisateur va recevoir', () async {
      final scheduler = RecordingScheduler();
      final service = PaymentReminderService(scheduler: scheduler);
      final now = DateTime(2026, 10, 2, 9);
      final tontine = tontineOf();

      await service.sync(
        buildReminders(
          contributions: const [],
          tontines: {11: tontine},
          currentUserId: 7,
          now: now,
        ),
        now: now,
      );

      final turn = scheduler.titled('C\'est ton tour !').single;

      expect(turn.body, contains(Fmt.fcfa(tontine.roundPayout)));
      expect(turn.payload, startsWith('turn:'));
    });

    test('après versement, il ne reste que le tour', () async {
      final scheduler = RecordingScheduler();
      final service = PaymentReminderService(scheduler: scheduler);
      final now = DateTime(2026, 10, 2, 9);
      final tontine = tontineOf();

      await service.sync(
        buildReminders(
          contributions: [contributionOf(round: 3)],
          tontines: {11: tontine},
          currentUserId: 7,
          now: now,
        ),
        now: now,
      );
      expect(scheduler.titled('Cotisation à verser'), isNotEmpty);

      // Le versement passe : l'état relu ne contient plus de cotisation payable.
      await service.sync(
        buildReminders(
          contributions: [contributionOf(round: 3, status: 'completed')],
          tontines: {11: tontine},
          currentUserId: 7,
          now: now,
        ),
        now: now,
      );

      expect(
        scheduler.titled('Cotisation à verser'),
        isEmpty,
        reason: 'les rappels de paiement cessent dès le versement',
      );
      expect(scheduler.titled('C\'est ton tour !'), hasLength(1));
    });
  });
}
