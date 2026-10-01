import 'package:flutter/material.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';

/// Barre de progression — l'équivalent mobile du `<ProgressBar>` du SPA web.
///
/// Le ratio est borné à [0, 1] ici plutôt que chez l'appelant : une tontine
/// argent peut afficher un pourcentage au-delà de 100 % (les cotisations
/// continuam après le tour), et une barre qui déborde de son conteneur est un
/// défaut visible, pas une donnée à vérifier trois fois à chaque écran.
class ProgressBar extends StatelessWidget {
  const ProgressBar({
    super.key,
    required this.value,
    this.color = AppColors.primary600,
    this.trackColor = AppColors.ink200,
    this.height = 7,
  });

  final double value;
  final Color color;
  final Color trackColor;
  final double height;

  @override
  Widget build(BuildContext context) {
    final ratio = (value.isNaN ? 0.0 : value).clamp(0.0, 1.0);

    // Le `SizedBox` n'est pas décoratif : dans une `Column`, la hauteur
    // transmise à l'enfant est infinie, et `LinearProgressIndicator` — qui
    // occupe toute la hauteur disponible — la refuse. On impose donc
    // une hauteur finie ici plutôt que chez chaque appelant.
    return SizedBox(
      height: height,
      width: double.infinity,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(height / 2),
        child: LinearProgressIndicator(
          value: ratio,
          minHeight: height,
          backgroundColor: trackColor,
          valueColor: AlwaysStoppedAnimation<Color>(color),
        ),
      ),
    );
  }
}
