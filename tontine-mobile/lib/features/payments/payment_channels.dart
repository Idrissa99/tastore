import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:tontine_achat_store/core/di/providers.dart';

/// Les moyens de paiement, tels que `GET /config` les déclare.
///
/// Une seule source de vérité : le serveur envoie `payment_channels`, et
/// l'application ne doit jamais inventer un moyen de paiement qui n'existe pas
/// côté back-end. Une liste écrite en dur dans l'app disparaîtrait en silence
/// dès qu'une agence ou un canal serait ajouté.
class PaymentChannels {
  const PaymentChannels(this.labels);

  static const empty = PaymentChannels({});

  /// Clé de canal → libellé affiché (« Orange Money », « Virement bancaire »).
  final Map<String, String> labels;

  List<MapEntry<String, String>> get entries => labels.entries.toList(growable: false);

  bool get isEmpty => labels.isEmpty;

  /// Canaux dont le virement doit être vérifié par un administrateur.
  ///
  /// Repris de `payments.manual_verification_channels` (`mynita`, `amana`).
  /// Le serveur ne publie PAS cette liste dans `/config` : elle est donc
  /// reproduite ici, et le commentaire la rattache à sa source pour qu'une
  /// évolution du backend ne passe pas inaperçue.
  static const manualChannels = {'mynita', 'amana'};

  /// MyNita et Amana exigent un code de transfert, les autres une simple
  /// confirmation.
  ///
  /// Cette distinction n'est pas cosmétique : `POST /contributions/{id}/pay`
  /// renvoie **422** si on lui envoie un canal manuel. C'est l'API qui décide
  /// quelle voie employer, et le serveur rejette le mauvais appel.
  bool isManual(String channel) => manualChannels.contains(channel);
}

final paymentChannelsProvider = FutureProvider<PaymentChannels>((ref) async {
  final response = await ref.read(apiClientProvider).get('/config');

  final map = response is Map ? Map<String, dynamic>.from(response) : const <String, dynamic>{};
  final raw = map['payment_channels'];

  if (raw is! Map) return PaymentChannels.empty;

  return PaymentChannels({
    for (final entry in raw.entries) entry.key.toString(): entry.value.toString(),
  });
});
