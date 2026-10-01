import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/features/tontines/tontine.dart';

/// État de la cotisation elle-même.
enum ContributionStatus {
  pending('pending', 'En attente', AppTone.warning),
  completed('completed', 'Payée', AppTone.success),
  failed('failed', 'Échoué', AppTone.danger),
  cancelled('cancelled', 'Annulée', AppTone.neutral);

  const ContributionStatus(this.wire, this.label, this.tone);

  final String wire;
  final String label;
  final AppTone tone;

  static ContributionStatus parse(Object? value) => values.firstWhere(
        (status) => status.wire == value,
        orElse: () => ContributionStatus.pending,
      );
}

/// Vérification humaine du virement, pour les canaux manuels.
///
/// `not_applicable` signifie que le canal n'exige pas de vérification : c'est
/// la valeur par défaut d'une cotisation payée par Orange Money. La distinguer
/// de `accepted` évite d'écrire « vérifié » sur un paiement automatique.
enum VerificationStatus {
  notApplicable('not_applicable', 'Sans vérification'),
  pending('pending', 'En attente de vérification', AppTone.warning),
  accepted('accepted', 'Vérifié', AppTone.success),
  rejected('rejected', 'Code refusé', AppTone.danger);

  const VerificationStatus(this.wire, this.label, [this.tone = AppTone.neutral]);

  final String wire;
  final String label;
  final AppTone tone;

  bool get awaitsDecision => this == VerificationStatus.pending;

  bool get isRejected => this == VerificationStatus.rejected;

  static VerificationStatus parse(Object? value) => values.firstWhere(
        (status) => status.wire == value,
        orElse: () => VerificationStatus.notApplicable,
      );
}

/// La tontine à laquelle la cotisation se rattache.
///
/// `ContributionResource` n'en renvoie qu'un extrait (`id`, `name`, `status`,
/// `type`) : c'est suffisant pour afficher « Cotisation de X » et aller à la
/// fiche, sans embarquer la tontine entière.
class ContributionTontine {
  const ContributionTontine({
    required this.id,
    required this.name,
    required this.status,
    required this.type,
  });

  factory ContributionTontine.fromJson(Map<String, dynamic> json) => ContributionTontine(
        id: Fmt.toInt(json['id']),
        name: json['name']?.toString() ?? '',
        status: TontineStatus.parse(json['status']),
        type: TontineType.parse(json['type']),
      );

  final int id;
  final String name;
  final TontineStatus status;
  final TontineType type;
}

/// Une cotisation de l'utilisateur connecté.
///
/// `ContributionResource` la sérialise en champs simples, sans enveloppe :
/// `GET /contributions` renvoie un TABLEAU JSON nu, et `pay` /
/// `soumettre-code` un objet nu. C'est le seul contrat de l'API qui ne soit pas
/// enveloppé — d'où une décompression dédiée dans le dépôt.
class Contribution {
  const Contribution({
    required this.id,
    required this.round,
    required this.amount,
    required this.commissionAmount,
    required this.currency,
    required this.paymentMethod,
    required this.status,
    required this.verificationStatus,
    required this.transactionReference,
    required this.transferCode,
    required this.paymentFailureReason,
    required this.submittedAt,
    required this.paidAt,
    required this.tontine,
  });

  factory Contribution.fromJson(Map<String, dynamic> json) {
    final tontine = json['tontine'];

    return Contribution(
      id: Fmt.toInt(json['id']),
      round: Fmt.toInt(json['round']),
      amount: Fmt.toDouble(json['amount']),
      commissionAmount: Fmt.toDouble(json['commission_amount']),
      currency: json['currency']?.toString() ?? 'XOF',
      paymentMethod: json['payment_method']?.toString(),
      status: ContributionStatus.parse(json['status']),
      verificationStatus: VerificationStatus.parse(json['verification_status']),
      transactionReference: json['transaction_reference']?.toString(),
      transferCode: json['transfer_code']?.toString(),
      paymentFailureReason: json['payment_failure_reason']?.toString(),
      submittedAt: Fmt.parseDate(json['submitted_at']),
      paidAt: Fmt.parseDate(json['paid_at']),
      tontine: tontine is Map
          ? ContributionTontine.fromJson(Map<String, dynamic>.from(tontine))
          : const ContributionTontine(id: 0, name: '', status: TontineStatus.unknown, type: TontineType.cash),
    );
  }

  final int id;
  final int round;
  final double amount;

  /// Commission portée par CETTE cotisation, distincte du taux global : c'est
  /// le montant réellement inclus dans `amount` qui est débité.
  final double commissionAmount;

  final String currency;
  final String? paymentMethod;
  final ContributionStatus status;
  final VerificationStatus verificationStatus;
  final String? transactionReference;
  final String? transferCode;
  final String? paymentFailureReason;
  final DateTime? submittedAt;
  final DateTime? paidAt;
  final ContributionTontine tontine;

  /// La cotisation est-elle réglée ?
  ///
  /// Le statut seul suffit, et il faut s'y tenir : `Admin\PaymentVerificationController`
  /// n'accepte un code que s'il est en attente de vérification, puis appelle
  /// `recordPayment` — donc une cotisation `completed` a toujours été vérifiée.
  /// Inversement, un code REFUSÉ laisse `status: pending` : la cotisation
  /// redevient payable, ce qui est le comportement voulu.
  ///
  /// La combinaison `completed` + `rejected` est donc INATTEIGNABLE. Or c'est
  /// exactement ce qu'un jeu de données écrit à la main fournit quand on
  /// `completed && !rejected` : la cotisation sort des deux listes, sans bouton
  /// et sans explication.
  bool get isSettled => status == ContributionStatus.completed;

  /// Un code de transfert attend-il une décision d'administrateur ?
  bool get awaitsVerification => verificationStatus.awaitsDecision;

  /// Reste-t-il quelque chose à faire ?
  ///
  /// Reproduit les trois refus du contrôleur : une cotisation déjà payée, une
  /// cotisation annulée, et un code déjà en attente de vérification. Les
  /// trois renvoient 409, et `pay` renvoie en plus 422 sur un canal manuel.
  bool get isPayable =>
      status == ContributionStatus.pending &&
      !awaitsVerification &&
      !isSettled;

  /// Motif du refus, ou chaîne vide si le versement est possible.
  String payBlockedReason({required bool manualChannel}) {
    if (isPayable) return '';

    if (status == ContributionStatus.completed) return 'Cette cotisation est déjà payée.';
    if (status == ContributionStatus.cancelled) {
      return 'Cette cotisation a été annulée et ne peut plus être payée.';
    }
    if (awaitsVerification) {
      return 'Un code de transfert est déjà en attente de vérification.';
    }

    return 'Ce versement n\'est pas possible.';
  }

  String get title => tontine.name.isEmpty ? 'Cotisation' : 'Cotisation ${tontine.name}';
}
