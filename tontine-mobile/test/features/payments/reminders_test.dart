import 'package:flutter_test/flutter_test.dart';
import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/features/contributions/contribution.dart';
import 'package:tontine_achat_store/features/payments/reminders.dart';
import 'package:tontine_achat_store/features/tontines/tontine.dart';

import '../contributions/contributions_test.dart' show contributionJson;
import '../tontines/tontine_fixtures.dart' show memberJson, tontineJson;

Tontine tontineOf({
  int id = 11,
  String? startDate = '2026-09-30',
  String frequency = 'daily',
  int currentRound = 3,
  List<Map<String, dynamic>>? members,
  bool rotationRevealed = true,
  int? currentUserId = 7,
  Map<String, dynamic> round = const {
    'expected_members': 12,
    'paid_members': 0,
    'is_funded': false,
  },
}) {
  return Tontine.fromJson(
    // `currentUserId` est indispensable : sans lui, `isMe` vaut toujours faux
    // et aucun membre n'est reconnu comme « moi ».
    tontineJson(
      id: id,
      frequency: frequency,
      rotationRevealed: rotationRevealed,
      members: members,
      overrides: {
        'current_round': currentRound,
        // Le backend sérialise en UTC : la fixture doit proposer la même
        // forme, sinon les dates comparées ne sont pas les mêmes.
        'start_date': startDate == null ? null : '${startDate}T00:00:00.000000Z',
        // Le `round.number` du bloc financier suit le round courant : sans
        // cela, le tour vaudrait toujours 1 et aucun rappel ne serait produit.
        'round': {...round, 'number': currentRound},
      },
    ),
    currentUserId: currentUserId,
  );
}

Contribution contributionOf({
  int id = 1,
  int round = 3,
  int tontineId = 11,
  String status = 'pending',
  String verification = 'not_applicable',
}) {
  return Contribution.fromJson(
    contributionJson(
      id: id,
      round: round,
      status: status,
      verificationStatus: verification,
      tontine: {'id': tontineId, 'name': 'test2', 'status': 'active', 'type': 'cash'},
    ),
  );
}

void main() {
  group('échéance estimée', () {
    // La règle est celle du SPA web (`src/utils/schedule.js`). Les deux clients
    // doivent tirer la même date de la même règle, sinon l'un annonce « dans
    // 3 jours » et l'autre « dans 2 ».

    test('le round 1 tombe le jour du démarrage', () {
      final tontine = tontineOf(startDate: '2026-09-30', frequency: 'daily');

      expect(
        ReminderRules.estimatedDueDate(tontine, 1),
        DateTime(2026, 9, 30),
      );
    });

    test('chaque round avance d\'une période', () {
      final tontine = tontineOf(startDate: '2026-09-30', frequency: 'weekly');

      expect(
        ReminderRules.estimatedDueDate(tontine, 1),
        DateTime(2026, 9, 30),
      );
      expect(
        ReminderRules.estimatedDueDate(tontine, 2),
        DateTime(2026, 10, 7),
      );
      expect(
        ReminderRules.estimatedDueDate(tontine, 4),
        DateTime(2026, 10, 21),
      );
    });

    test('le mois compte 30 jours, comme le SPA', () {
      final tontine = tontineOf(startDate: '2026-01-15', frequency: 'monthly');

      expect(
        ReminderRules.estimatedDueDate(tontine, 3),
        DateTime(2026, 3, 16),
      );
    });

    test('sans date de démarrage, AUCUNE échéance plutôt qu\'une date inventée', () {
      // Une tontine sans `start_date` n'a pas de planning connu : inventer une
      // date enverrait des rappels à des jours sans rapport.
      final tontine = tontineOf(startDate: null);

      expect(tontine.startDate, isNull);
      expect(ReminderRules.estimatedDueDate(tontine, 2), isNull);
      expect(ReminderRules.isDueToday(contributionOf(), tontine, DateTime(2026, 9, 30)), isFalse);
    });
  });

  group('quand une cotisation doit-elle être rappelée', () {
    final tontine = tontineOf(startDate: '2026-09-30', frequency: 'daily');
    final today = DateTime(2026, 10, 2);

    test('une échéance atteinte est relancée, et le reste aussi', () {
      // La fenêtre va de trois jours AVANT jusqu'à aujourd'hui inclus. Les
      // rounds 1 à 3 sont atteints ou dépassés : le round 1 est surtout le
      // cas le plus important, c'est la cotisation impayée depuis deux jours.
      for (final round in [1, 2, 3]) {
        expect(
          ReminderRules.isDueToday(contributionOf(round: round), tontine, today),
          isTrue,
          reason: 'round $round',
        );
      }
    });

    test('une échéance FUTURE n\'est pas encore relancée', () {
      // Le round 4 tombe demain : il n'est pas dû, et le rappeler inventerait
      // une urgence.
      expect(
        ReminderRules.isDueToday(contributionOf(round: 4), tontine, today),
        isFalse,
      );
    });

    test('au-delà des trois jours de battement, plus de rappel', () {
      // Tontine démarrée il y a six jours : le retard est manifeste, insister
      // chaque heure n\'aiderait plus.
      final old = tontineOf(id: 12, startDate: '2026-09-26', frequency: 'daily', currentRound: 1);

      expect(
        ReminderRules.isDueToday(
          contributionOf(tontineId: 12, round: 1),
          old,
          today,
        ),
        isFalse,
      );
    });

    test('une cotisation DÉJÀ payée n\'est jamais rappelée', () {
      // C\'est LA condition d'arrêt demandée : dès que le versement est passé,
      // les rappels cessent.
      final paid = contributionOf(round: 3, status: 'completed');

      expect(paid.isPayable, isFalse);
      expect(ReminderRules.isDueToday(paid, tontine, today), isFalse);
    });

    test('une cotisation annulée n\'est jamais rappelée', () {
      final cancelled = contributionOf(round: 3, status: 'cancelled');

      expect(ReminderRules.isDueToday(cancelled, tontine, today), isFalse);
    });

    test('un code déjà en attente de vérification n\'est pas rappelé', () {
      // L'utilisateur a fait sa part ; le rappel ne ferait que le presser
      // pour une vérification qui n'est pas entre ses mains.
      final awaiting = contributionOf(round: 3, verification: 'pending');

      expect(ReminderRules.isDueToday(awaiting, tontine, today), isFalse);
    });

    test('une tontine inconnue ne produit pas de rappel', () {
      expect(ReminderRules.isDueToday(contributionOf(), null, today), isFalse);
    });
  });

  group('tour à récupérer', () {
    final today = DateTime(2026, 10, 2);

    test('l\'utilisateur bénéficiaire du tour courant est rappelé', () {
      final tontine = tontineOf(
        startDate: '2026-09-30',
        frequency: 'daily',
        currentRound: 3,
        members: [
          memberJson(userId: 7, position: 1).cast<String, dynamic>()
            ..['beneficiary_round'] = 3,
          memberJson(userId: 99, name: 'Autre', position: 2).cast<String, dynamic>()
            ..['beneficiary_round'] = 4,
        ],
      );

      expect(
        ReminderRules.isBeneficiaryOfCurrentRound(tontine, 7),
        isTrue,
        reason: 'le membre 7 est bénéficiaire du tour 3',
      );
      expect(ReminderRules.isTurnToday(tontine, 7, today), isTrue);
    });

    test('un membre qui n\'est PAS bénéficiaire n\'est pas rappelé', () {
      final tontine = tontineOf(
        members: [
          memberJson(userId: 99, name: 'Autre', position: 1).cast<String, dynamic>()
            ..['beneficiary_round'] = 3,
        ],
      );

      expect(ReminderRules.isBeneficiaryOfCurrentRound(tontine, 7), isFalse);
    });

    test('avant le tirage, aucun tour n\'est annoncé', () {
      // `beneficiary_round` vaut null tant que le tirage n'a pas eu lieu :
      // annoncer un tour serait inventer le résultat du Masque.
      final tontine = tontineOf(
        rotationRevealed: false,
        members: [memberJson(userId: 7)],
      );

      expect(tontine.rotationRevealed, isFalse);
      expect(ReminderRules.isBeneficiaryOfCurrentRound(tontine, 7), isFalse);
    });

    test('hors session, personne n\'est bénéficiaire connu', () {
      final tontine = tontineOf(members: [memberJson(userId: 7)]);

      expect(ReminderRules.isBeneficiaryOfCurrentRound(tontine, null), isFalse);
    });
  });

  group('rythme des relances', () {
    // Le rythme demandé : une notification dès que c'est le jour, puis toutes
    // les quatre heures, pendant soixante-douze heures — soit trois jours.

    test('le premier rappel part tout de suite quand il est jour', () {
      final now = DateTime(2026, 10, 2, 9, 30);
      final slots = ReminderRules.slots(now);

      // L'utilisateur vient d'ouvrir l'application : il sait que l'échéance est
      // due, attendre quatre heures pour l'en prévenir serait absurde.
      expect(slots.first.isAfter(now), isTrue);
      expect(slots.first.difference(now), ReminderRules.firstNudge);
    });

    test('aucun rappel la nuit', () {
      for (var hour = 0; hour < 24; hour++) {
        expect(
          ReminderRules.isWakingHour(DateTime(2026, 10, 2, hour)),
          hour >= ReminderRules.firstHour && hour < ReminderRules.lastHour,
          reason: 'heure $hour',
        );
      }
    });

    test('les créneaux tombent en journée, sur trois jours', () {
      final now = DateTime(2026, 10, 2, 9, 30);
      final slots = ReminderRules.slots(now);

      expect(slots, isNotEmpty);
      expect(slots.every((slot) => ReminderRules.isWakingHour(slot)), isTrue);
      expect(slots.every((slot) => slot.isAfter(now)), isTrue);

      // Trois jours : la relance cesse au bout de 72 heures, jamais au-delà.
      final horizon = now.add(const Duration(hours: ReminderRules.horizonHours));
      expect(slots.every((slot) => !slot.isAfter(horizon)), isTrue);
      expect(slots.last, horizon, reason: 'le dernier créneau est le dernier instant utile');

      // Le pas est de quatre heures. Deux exceptions : le tout premier créneau,
      // qui part tout de suite, et la nuit, où les créneaux sont écartés —
      // d'où un intervalle allant jusqu'à quatre pas d'un soir au petit matin.
      for (var i = 2; i < slots.length; i++) {
        final gap = slots[i].difference(slots[i - 1]).inHours;
        expect(gap % ReminderRules.repeatEveryHours, 0, reason: 'créneau $i');
        expect(gap, lessThanOrEqualTo(ReminderRules.repeatEveryHours * 4));
      }
    });

    test('la relance couvre bien les trois jours demandés', () {
      // Une relance arrêtée au bout d'une journée laisserait une cotisation
      // Due jamais rattrapée : c'est précisément le cas qu'on veut couvrir.
      final now = DateTime(2026, 10, 2, 7);
      final slots = ReminderRules.slots(now);

      // 72 h ne font pas trois dates civiles : ouvertes le 2 à 7 h, elles vont
      // jusqu'au 5 à 7 h. C'est la durée qui compte.
      final span = slots.last.difference(slots.first);
      expect(span, lessThanOrEqualTo(const Duration(hours: ReminderRules.horizonHours)));
      expect(span, greaterThanOrEqualTo(const Duration(hours: 60)));
      expect(
        slots.map((slot) => DateTime(slot.year, slot.month, slot.day)).toSet().length,
        greaterThanOrEqualTo(3),
      );
    });

    test('un créneau nocturne est écarté, pas décalé', () {
      // Ouvert à une heure du matin : rien ce jour-là, la première relance est
      // le matin même, et le rythme reprend à partir de là.
      final now = DateTime(2026, 10, 2, 1);
      final slots = ReminderRules.slots(now);

      expect(slots, isNotEmpty);
      expect(slots.every((slot) => ReminderRules.isWakingHour(slot)), isTrue);
      // Le pas est de quatre heures depuis 1 h : 5 h est écarté (nuit), 9 h
      // est le premier créneau éveillé.
      expect(slots.first, DateTime(2026, 10, 2, 9));
    });
  });

  group('moment du tour à recueillir', () {
    test('un seul message, au plus tôt en journée', () {
      final now = DateTime(2026, 10, 2, 9);

      expect(ReminderRules.turnMoment(now), now.add(ReminderRules.firstNudge));
    });

    test('le soir, le tour est annoncé le lendemain matin', () {
      // Un tour n'est pas une relance : le répéter toutes les quatre heures
      // pendant trois jours serait du bruit, mais le dire à 3 h du matin
      // aussi.
      expect(
        ReminderRules.turnMoment(DateTime(2026, 10, 2, 23)),
        DateTime(2026, 10, 3, ReminderRules.firstHour).add(ReminderRules.firstNudge),
      );
    });

    test('avant l\'aube, le tour est annoncé dans la matinée', () {
      expect(
        ReminderRules.turnMoment(DateTime(2026, 10, 2, 4)),
        DateTime(2026, 10, 2, ReminderRules.firstHour).add(ReminderRules.firstNudge),
      );
      // Programmé à 6 h 59 pour être livré à 7 h 01, pas perdu entre-temps.
      expect(
        ReminderRules.turnMoment(DateTime(2026, 10, 2, 6, 59)).isAfter(DateTime(2026, 10, 2, 6, 59)),
        isTrue,
      );
    });
  });

  group('identifiants d\'occurrence', () {
    test('chaque créneau a son propre identifiant', () {
      // Deux notifications de même identifiant se REMPLACENT sur Android au lieu
      // de s'empiler : c'est ce qui rendait la relance invisible, une seule
      // notification subsistant — celle du dernier créneau.
      final keys = [
        for (var i = 0; i < 5; i++) slotKey('contributions', i),
      ];

      final ids = keys.map(notificationIdFor).toList();
      expect(ids.toSet(), hasLength(keys.length));
    });

    test('l\'identifiant est stable d\'une exécution à l\'autre', () {
      // `hashCode` de Dart change à chaque exécution : les rappels déjà
      // programmés deviendraient impossibles à annuler après un versement.
      expect(notificationIdFor('contributions#0'), notificationIdFor('contributions#0'));
      expect(notificationIdFor('contributions#0'), isNot(notificationIdFor('contributions#1')));
    });

    test('les identifiants restent dans les bornes d\'Android', () {
      for (final key in ['contributions#0', 'turn:11:3#0', '']) {
        final id = notificationIdFor(key);
        expect(id, greaterThan(0));
        expect(id, lessThanOrEqualTo(0x7FFFFFFF));
      }
    });
  });

  group('rappels construits', () {
    test('une cotisation à échéance donne un rappel nommé', () {
      final now = DateTime(2026, 10, 2, 9);
      final tontine = tontineOf(startDate: '2026-09-30', frequency: 'daily', currentRound: 3);

      final reminders = buildReminders(
        contributions: [contributionOf(round: 3)],
        tontines: {11: tontine},
        currentUserId: 7,
        now: now,
      );

      expect(reminders.length, 1);
      expect(reminders.single.kind, ReminderKind.contributionDue);
      expect(reminders.single.title, 'Cotisation à verser');
      // Le montant est dérivé du formateur : son séparateur de milliers est une
      // espace insécable fine, qu'une espace ordinaire ne remplacerait pas.
      expect(reminders.single.body, contains(Fmt.fcfa(133750)));
    });

    test('une cotisation réglée ne produit plus de rappel', () {
      final tontine = tontineOf(startDate: '2026-09-30', frequency: 'daily', currentRound: 3);

      final reminders = buildReminders(
        contributions: [contributionOf(round: 3, status: 'completed')],
        tontines: {11: tontine},
        currentUserId: 7,
        now: DateTime(2026, 10, 2, 9),
      );

      expect(reminders, isEmpty);
    });

    test('un tour à récupérer donne son propre rappel', () {
      final tontine = tontineOf(
        startDate: '2026-09-30',
        frequency: 'daily',
        currentRound: 3,
        members: [
          memberJson(userId: 7, position: 1).cast<String, dynamic>()
            ..['beneficiary_round'] = 3,
        ],
      );

      final reminders = buildReminders(
        contributions: const [],
        tontines: {11: tontine},
        currentUserId: 7,
        now: DateTime(2026, 10, 2, 9),
      );

      // Le message ne dit plus « à toi de recueillir » : il annonce le MONTANT
      // que l'utilisateur va recevoir, qui est l'information cherchée quand une
      // notification s'affiche sous un verrou d'écran.
      expect(reminders.single.kind, ReminderKind.turnToCollect);
      expect(reminders.single.title, 'C\'est ton tour !');
      expect(reminders.single.body, contains('Tour 3'));
      expect(reminders.single.body, contains(Fmt.fcfa(tontine.roundPayout)));
    });

    test('l\'identifiant de notification est stable d\'un appel à l\'autre', () {
      // `hashCode` de Dart change à chaque exécution : l\'utiliser ferait
      // changer l\'identifiant à chaque démarrage, et les rappils déjà
      // programmés deviendraient impossibles à annuler après un versement.
      final tontine = tontineOf(startDate: '2026-09-30', frequency: 'daily', currentRound: 3);

      List<PaymentReminder> build() => buildReminders(
            contributions: [contributionOf(round: 3)],
            tontines: {11: tontine},
            currentUserId: 7,
            now: DateTime(2026, 10, 2, 9),
          );

      final first = build().single;
      final second = build().single;
      expect(first, second);
      // L'identifiant d'occurrence en découle, et doit donc être identique lui
      // aussi : c'est ce qui permet d'annuler les rappels après un versement.
      expect(notificationIdFor(slotKey(first.id, 0)), notificationIdFor(slotKey(second.id, 0)));
    });

    test('les cotisations échues sont regroupées en UN rappel', () {
      // Trois cotisations échues ne doivent pas produire trois séries de
      // notifications : dix-huit messages en trois jours pour une seule décision
      // — ouvrir l'onglet — Passent vite pour du harcèlement, et l'utilisateur
      // désinstalle.
      final tontine = tontineOf(startDate: '2026-09-30', frequency: 'daily', currentRound: 3);

      final reminders = buildReminders(
        contributions: [
          contributionOf(id: 1, round: 1),
          contributionOf(id: 2, round: 2),
          contributionOf(id: 3, round: 3),
        ],
        tontines: {11: tontine},
        currentUserId: 7,
        now: DateTime(2026, 10, 2, 9),
      );

      expect(reminders, hasLength(1));
      expect(reminders.single.kind, ReminderKind.contributionDue);
      expect(reminders.single.title, 'Cotisations à verser');
      expect(reminders.single.body, contains('3 cotisations à solder'));
      // 3 × 133 750 : le total est l'information que l'utilisateur cherche.
      expect(reminders.single.body, contains(Fmt.fcfa(3 * 133750)));
    });

    test('une seule cotisation échue nomme sa tontine', () {
      final tontine = tontineOf(startDate: '2026-09-30', frequency: 'daily', currentRound: 3);

      final reminders = buildReminders(
        contributions: [contributionOf(round: 3)],
        tontines: {11: tontine},
        currentUserId: 7,
        now: DateTime(2026, 10, 2, 9),
      );

      expect(reminders.single.title, 'Cotisation à verser');
      expect(reminders.single.body, contains('Tour 3'));
    });

    test('un tour financé annonce le montant exact qui revient', () {
      final tontine = tontineOf(
        startDate: '2026-09-30',
        frequency: 'daily',
        currentRound: 3,
        members: [
          memberJson(userId: 7, position: 1).cast<String, dynamic>()..['beneficiary_round'] = 3,
        ],
        round: const {'expected_members': 12, 'paid_members': 12, 'is_funded': true},
      );

      final reminders = buildReminders(
        contributions: const [],
        tontines: {11: tontine},
        currentUserId: 7,
        now: DateTime(2026, 10, 2, 9),
      );

      final reminder = reminders.singleWhere((r) => r.kind == ReminderKind.turnToCollect);

      expect(tontine.isRoundPayoutFinal, isTrue);
      // 12 cotisants × le montant unitaire de la fixture.
      expect(reminder.body, contains(Fmt.fcfa(tontine.roundPayout)));
      expect(reminder.body, contains('tu reçois'));
    });

    test('un tour en cours de collecte est annoncé comme prévisionnel', () {
      // Le backend ne connaît que le nombre de cotisants et le montant
      // unitaire : rien ne garantit que les absents paieront. Annoncer la cible
      // sans le dire ferait promettre une somme qui peut diminuer.
      final tontine = tontineOf(
        startDate: '2026-09-30',
        frequency: 'daily',
        currentRound: 3,
        members: [
          memberJson(userId: 7, position: 1).cast<String, dynamic>()..['beneficiary_round'] = 3,
        ],
      );

      final reminders = buildReminders(
        contributions: const [],
        tontines: {11: tontine},
        currentUserId: 7,
        now: DateTime(2026, 10, 2, 9),
      );

      final reminder = reminders.singleWhere((r) => r.kind == ReminderKind.turnToCollect);

      expect(tontine.isRoundPayoutFinal, isFalse);
      expect(tontine.roundPayout, 12 * tontine.contributionAmount);
      expect(reminder.body, contains('devrais recevoir'));
      expect(reminder.body, contains(Fmt.fcfa(tontine.roundPayout)));
    });

    test('sans échéance connue, aucun rappel', () {
      final reminders = buildReminders(
        contributions: [contributionOf()],
        tontines: const {},
        currentUserId: 7,
        now: DateTime(2026, 10, 2, 9),
      );

      expect(reminders, isEmpty);
    });
  });
}
