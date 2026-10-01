import 'package:flutter/material.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/features/payments/payment_channels.dart';

/// Sélecteur de moyen de paiement.
///
/// Partagé par la carte de cotisation et par la feuille de versement, pour que
/// la liste des moyens soit le MÊME objet dans les deux endroits : c'est
/// `GET /config` qui fait foi, et un second rendu de la liste dans la feuille
/// serait autant de code à garder aligné.
///
/// **Les moyens sont affichés AVANT toute décision.** L'utilisateur doit voir
/// comment il peut payer — et ce qu'on attend de lui pour chaque moyen — avant
/// de s'engager, pas après avoir choisi. Un canal vérifié à la main réclame en
/// effet un code de transfert à fournir à l'agence : l'ignorer au moment de
/// choisir fait envoyer un versement que le serveur refuse (422).
class PaymentChannelPicker extends StatelessWidget {
  const PaymentChannelPicker({
    super.key,
    required this.channels,
    required this.value,
    required this.onChanged,
    this.enabled = true,
  });

  final PaymentChannels channels;
  final String? value;
  final ValueChanged<String> onChanged;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    if (channels.isEmpty) {
      return const Text(
        'Aucun moyen de paiement n\'est configuré sur le serveur.',
        style: TextStyle(color: AppColors.ink500, fontSize: 13, height: 1.4),
      );
    }

    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final entry in channels.entries)
          ChoiceChip(
            label: Text(entry.value),
            selected: value == entry.key,
            onSelected: enabled ? (_) => onChanged(entry.key) : null,
          ),
      ],
    );
  }
}
