import 'package:flutter/material.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';

/// Pastille d'état — l'équivalent mobile du `<Badge>` du SPA web.
///
/// Elle ne connaît QUE la teinte ([AppTone]) et le texte : le couple
/// « statut → libellé + teinte » appartient au domaine qui porte le statut, et
/// non à ce widget. C'est ce qui permet à un statut de tontine, de cotisation
/// ou de produit de s'afficher de la même façon partout sans que ce fichier
/// ait à être modifié à chaque ajout.
class AppBadge extends StatelessWidget {
  const AppBadge({
    super.key,
    required this.label,
    required this.tone,
    this.icon,
  });

  final String label;
  final AppTone tone;

  /// Icône optionnelle, à gauche du libellé. Doit être petite : la pastille
  /// se cale sur la hauteur du texte.
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
      decoration: BoxDecoration(
        color: tone.background,
        borderRadius: BorderRadius.circular(AppRadius.xs),
        border: Border.all(color: tone.border),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[
            Icon(icon, size: 12, color: tone.foreground),
            const SizedBox(width: 4),
          ],
          // `Flexible` : un libellé composé à la volée (« 9 places
          // restantes ») peut être plus long que la pastille et fit à la
          // largeur disponible. Sans lui, le débordement est un défaut
          // visible — bande jaune — et non un simple texte tronqué.
          Flexible(
            child: Text(
              label,
              style: TextStyle(
                color: tone.foreground,
                fontSize: 11,
                fontWeight: FontWeight.w700,
                height: 1.2,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
