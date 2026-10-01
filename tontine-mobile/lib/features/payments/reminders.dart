import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/features/contributions/contribution.dart';
import 'package:tontine_achat_store/features/tontines/tontine.dart';

/// Rappel local : quoi dire, et sous quel identifiant.
///
/// Un « rappel » est une échéance à surveiller, pas un message à envoyer tout
/// de suite : il est PROGRAMMÉ, et le système le déclenche même application
/// fermée. C'est ce qui évite d'avoir à exécuter du Dart en arrière-plan
/// toutes les heures — un travail que le système bride très vite, et qu'un
/// utilisateur finit par payer en batterie.
class PaymentReminder {
  const PaymentReminder({
    required this.id,
    required this.kind,
    required this.title,
    required this.body,
  });

  /// Identifiant stable du RAPPEL, pas d'une occurrence.
  ///
  /// Un rappel de cotisation se répète : une même échéance est relancée toutes
  /// les [ReminderRules.repeatEveryHours] heures pendant [ReminderRules
  /// .horizonHours] heures. Chacune de ces occurrences a son propre identifiant
  /// Android — [notificationIdFor] les dérive de celui-ci — car deux
  /// notifications de même identifiant se remplacent l'une l'autre au lieu de
  /// s'empiler.
  final String id;

  final ReminderKind kind;
  final String title;
  final String body;

  @override
  bool operator ==(Object other) =>
      other is PaymentReminder &&
      other.id == id &&
      other.title == title &&
      other.body == body;

  @override
  int get hashCode => Object.hash(id, title, body);
}

/// Ce qui déclenche le rappel.
enum ReminderKind {
  /// Une cotisation arrive à échéance aujourd'hui.
  contributionDue('cotisation à solder'),

  /// L'utilisateur est bénéficiaire du tour courant : il doit aller chercher
  /// ce qui revient à sa tontine.
  turnToCollect('tour à récupérer');

  const ReminderKind(this.label);

  final String label;
}

/// Règles d'échéance et de rappel.
///
/// **L'API n'expose aucune date d'échéance sur les cotisations** : elle ne
/// connaît que `tontine.start_date`, `tontine.frequency` et le numéro du
/// round. L'échéance est donc ESTIMÉE, avec exactement la règle du SPA web
/// (`src/utils/schedule.js`) : round 1 le jour du démarrage, round N
/// `start_date + (N - 1) × fréquence`. Les deux clients doivent calculer la
/// même date, sinon le web annonce « dans 3 jours » et l'application « dans 2 ».
///
/// Une date estimée est un rappel, pas un fait : le texte des notifications le
/// dit, et une tontine sans `start_date` ne produit aucun rappel plutôt qu'un
/// rappel à une date inventée.
class ReminderRules {
  const ReminderRules._();

  /// Première heure de la journée où l'on relance, et heure limite EXCLUSIVE.
  ///
  /// « Toute la journée » ne peut pas littéralement vouloir dire 3 h du matin :
  /// un rappel nocturne est un motif de désinstallation. La plage couvre toute
  /// la journée éveillée.
  static const firstHour = 7;
  static const lastHour = 21;

  /// Délai entre deux relances, en heures.
  ///
  /// Quatre heures : assez espacé pour ne pas assommer le téléphone, assez
  /// serré pour que l'échéance ne soit pas oubliée avant la fin de la journée.
  static const repeatEveryHours = 4;

  /// Durée totale de la relance, en heures — soit trois jours.
  ///
  /// L'échéance étant ESTIMÉE (une tontine peut démarrer un jour plus tard ou
  /// plus tôt que prévu), le rappel la suit pendant [horizonHours] après
  /// qu'elle soit atteinte, le temps que le versement réel soit fait. Passé ce
  /// délai, la tontine est visiblement en défaut et insister n'aide plus.
  static const horizonHours = 72;

  /// Délai du premier rappel quand l'application est ouverte en journée.
  ///
  /// Assez court pour que l'utilisateur soit prévenu tout de suite, assez long
  /// pour être réellement dans le FUTUR au moment de la programmation : une
  /// date déjà passée dépend du comportement de la plateforme : elle peut être
  /// livrée dans la seconde, ou refusée.
  static const firstNudge = Duration(minutes: 2);

  /// Une heure appartient-elle à la journée éveillée ?
  static bool isWakingHour(DateTime moment) =>
      moment.hour >= firstHour && moment.hour < lastHour;

  /// Date estimée d'un round, ou `null` si la tontine n'a pas de démarrage.
  ///
  /// Le mois compte 30 jours, comme le fait le SPA (`FREQUENCIES.monthly`) :
  /// les deux clients doivent tirer la même date de la même règle.
  static DateTime? estimatedDueDate(Tontine tontine, int round) {
    final start = tontine.startDate;
    if (start == null) return null;

    final days = switch (tontine.frequency) {
      TontineFrequency.daily => 1,
      TontineFrequency.weekly => 7,
      TontineFrequency.monthly => 30,
    };

    return DateTime(start.year, start.month, start.day).add(
      Duration(days: days * (round < 1 ? 0 : round - 1)),
    );
  }

  /// La fenêtre de relance d'une échéance : [from, to].
  ///
  /// Elle couvre AUJOURD'HUI et les [graceDays] jours PRÉCÉDENTS — pas les jours
  /// suivants. Une échéance future n'est pas encore due, et surtout une
  /// échéance dépassée est justement ce qui doit être rappelé : c'est la
  /// cotisation impayée depuis trois jours qui a le plus besoin d'un rappel,
  /// et la fenêtre ne peut pas s'arrêter à minuit.
  static (DateTime from, DateTime to) _reminderWindow(DateTime today) {
    final midnight = DateTime(today.year, today.month, today.day);

    return (midnight.subtract(const Duration(hours: horizonHours)), midnight);
  }

  /// Une échéance est-elle dans la fenêtre de relance ?
  static bool _inWindow(DateTime? due, DateTime today) {
    if (due == null) return false;

    final window = _reminderWindow(today);

    return !due.isAfter(window.$2) && !due.isBefore(window.$1);
  }

  /// Une cotisation donne-t-elle lieu à un rappel aujourd'hui ?
  static bool isDueToday(Contribution contribution, Tontine? tontine, DateTime today) {
    if (tontine == null) return false;
    if (!contribution.isPayable) return false;

    return _inWindow(estimatedDueDate(tontine, contribution.round), today);
  }

  /// L'utilisateur est-il bénéficiaire du tour à venir ?
  ///
  /// Réponse du serveur (`beneficiary_round`), pas une déduction : le tirage
  /// n'a lieu qu'au lancement, et afficher un tour avant le tirage serait
  /// inventer un résultat.
  static bool isBeneficiaryOfCurrentRound(Tontine tontine, int? currentUserId) {
    if (currentUserId == null || !tontine.rotationRevealed) return false;

    final me = tontine.members.where((member) => member.isMe);
    if (me.isEmpty) return false;

    return me.first.beneficiaryRound == tontine.round.number;
  }

  /// Un tour est-il à récupérer aujourd'hui ?
  ///
  /// Même fenêtre que pour une cotisation : l'échéance du tour suit la
  /// périodicité de la tontine, et le bénéficiaire a trois jours pour passer.
  static bool isTurnToday(Tontine tontine, int? currentUserId, DateTime today) {
    if (!isBeneficiaryOfCurrentRound(tontine, currentUserId)) return false;
    if (tontine.round.number <= 0) return false;

    return _inWindow(estimatedDueDate(tontine, tontine.round.number), today);
  }

  /// Les moments où une relance doit être livrée, depuis maintenant.
  ///
  /// Trois jours au rythme de [repeatEveryHours], en journée éveillée seulement.
  ///
  /// La marche part de [now] et non de minuit : les créneaux sont donc à
  /// intervalles réguliers, ce qui évite d'avertir systématiquement au même
  /// moment de la journée. Ceux qui tombent la nuit sont écartés — et comme le
  /// pas est de quatre heures, l'écart au moment réellement livré peut
  /// légèrement varier d'un jour à l'autre. C'est le compromis entre « ne pas
  /// réveiller quelqu'un à 3 h » et « ne pas toujours dériver au même moment ».
  ///
  /// Un rappel immédiat est ajouté quand l'application est ouverte en journée :
  /// c'est là que l'on sait que l'échéance est due, et attendre quatre heures
  /// pour l'annoncer serait absurde.
  static List<DateTime> slots(DateTime now) {
    final result = <DateTime>[];

    if (isWakingHour(now)) result.add(now.add(firstNudge));

    for (var hours = repeatEveryHours; hours <= horizonHours; hours += repeatEveryHours) {
      final slot = now.add(Duration(hours: hours));
      if (isWakingHour(slot)) result.add(slot);
    }

    return result;
  }

  /// L'unique moment où annoncer un tour à récupérer.
  ///
  /// Un tour n'est pas une relance : le dire toutes les quatre heures pendant
  /// trois jours serait du bruit. Un seul message, au plus tôt possible en
  /// journée — le lendemain matin si l'application s'ouvre le soir.
  static DateTime turnMoment(DateTime now) {
    if (isWakingHour(now)) return now.add(firstNudge);

    // Le matin qui vient, s'il n'est pas déjà derrière nous — sinon celui
    // d'après. `firstNudge` garantit un instant strictement futur : programmé à
    // 7 h 00 alors qu'il est 6 h 59, le rappel serait perdu si l'utilisateur
    // refermait l'application.
    final morning = DateTime(now.year, now.month, now.day, firstHour).add(firstNudge);
    if (now.hour < firstHour) return morning;

    return morning.add(const Duration(days: 1));
  }
}

/// Fabrique les rappels à partir de l'état réel de l'utilisateur.
///
/// Fonction PURE : elle ne touche ni au système ni au réseau, et se teste donc
/// directement. Le service se contente ensuite de PROGRAMMER ce qu'elle
/// renvoie — et d'annuler ce qui n'y est plus.
///
/// **Les cotisations sont regroupées en UN seul rappel.** Trois cotisations
/// échues ne produisent pas trois séries de notifications : l'utilisateur
/// recevrait dix-huit messages en trois jours pour une seule décision — celle
/// d'ouvrir l'onglet « Cotisations ». Le rappel annonce donc le nombre de
/// cotisations et le total à solder, ce qui est l'information qu'il cherche
/// quand il voit une notification.
List<PaymentReminder> buildReminders({
  required List<Contribution> contributions,
  required Map<int, Tontine> tontines,
  required int? currentUserId,
  required DateTime now,
}) {
  final due = <Contribution>[];

  for (final contribution in contributions) {
    final tontine = tontines[contribution.tontine.id];
    if (!ReminderRules.isDueToday(contribution, tontine, now)) continue;

    due.add(contribution);
  }

  final reminders = <PaymentReminder>[];

  if (due.isNotEmpty) {
    reminders.add(
      PaymentReminder(
        id: 'contributions',
        kind: ReminderKind.contributionDue,
        title: due.length == 1 ? 'Cotisation à verser' : 'Cotisations à verser',
        body: _dueBody(due, tontines),
      ),
    );
  }

  for (final tontine in tontines.values) {
    if (!ReminderRules.isTurnToday(tontine, currentUserId, now)) continue;

    reminders.add(
      PaymentReminder(
        id: 'turn:${tontine.id}:${tontine.round.number}',
        kind: ReminderKind.turnToCollect,
        title: 'C\'est ton tour !',
        body: _turnBody(tontine),
      ),
    );
  }

  return reminders;
}

/// Le texte du rappel de cotisations : le montant, et d'où il vient.
///
/// Une seule cotisation est nommée — l'utilisateur sait alors exactement ce
/// qu'il doit, et où. À plusieurs, le total et le nombre suffisent : les nommer
/// toutes produirait une notification illisible sous un verrou d'écran.
String _dueBody(List<Contribution> due, Map<int, Tontine> tontines) {
  final total = due.fold<double>(0, (sum, contribution) => sum + contribution.amount);

  if (due.length == 1) {
    final only = due.first;
    final name = tontines[only.tontine.id]?.name ?? only.tontine.name;
    final label = name.isEmpty ? 'ta tontine' : name;

    return 'Tour ${only.round} de $label : ${Fmt.fcfa(only.amount)} à solder.';
  }

  return '${due.length} cotisations à solder, ${Fmt.fcfa(total)} au total.';
}

/// Le texte du rappel de tour : le montant que l'utilisateur va recevoir.
///
/// Le montant est celui du tour tel que le serveur le décrit. Un tour
/// FINANCÉ est un fait ; un tour encore en cours de collecte n'est qu'une
/// cible, et l'annoncer sans le dire ferait promettre une somme qui peut
/// diminuer.
String _turnBody(Tontine tontine) {
  final amount = Fmt.fcfa(tontine.roundPayout);

  if (tontine.round.isFunded) {
    return 'Tour ${tontine.round.number} de ${tontine.name} : tu reçois $amount.';
  }

  return 'Tour ${tontine.round.number} de ${tontine.name} : tu devrais recevoir '
      '$amount, en attente que le tour soit financé.';
}

/// Identifiant Android d UNE occurrence de rappel.
///
/// Dérivé de [key] — donc stable d'une exécution à l'autre, contrairement à
/// `hashCode` — et non incrémenté : un identifiant qui changerait au
/// redémarrage rendrait les rappels déjà programmés impossibles à annuler après
/// un versement.
int notificationIdFor(String key) {
  var hash = 0;

  for (final unit in key.codeUnits) {
    hash = (hash * 31 + unit) & 0x7FFFFFFF;
  }

  // Doit rester sous 2^31 et être positif : Android réserve les petits
  // identifiants à certaines notifications système.
  return 1000 + (hash % 2000000000);
}

/// La clé d'une occurrence : le rappel, et son rang dans la série.
String slotKey(String reminderId, int index) => '$reminderId#$index';
