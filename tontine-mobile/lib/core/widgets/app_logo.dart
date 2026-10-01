import 'package:flutter/material.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';

/// Marque de l'application.
///
/// Un monogramme seul ne disait rien : « TA » n'évoquait ni la boutique, ni
/// l'épargne. La marque est désormais un **bloc-marque** — le glyphe dans son
/// carreau dégradé, suivi du nom « TAStore » — repris à l'identique sur le
/// splash, la connexion et l'inscription.
class AppLogo extends StatelessWidget {
  const AppLogo.tinted({super.key, this.markSize = 56}) : tone = AppLogoTone.tinted;

  const AppLogo.onDark({super.key, this.markSize = 72}) : tone = AppLogoTone.onDark;

  final double markSize;
  final AppLogoTone tone;

  @override
  Widget build(BuildContext context) {
    final onDark = tone == AppLogoTone.onDark;

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        TontineMark(size: markSize, tone: tone),
        SizedBox(width: markSize * 0.24),
        Text(
          'TAStore',
          style: TextStyle(
            fontSize: markSize * 0.44,
            fontWeight: FontWeight.w800,
            // L'espacement négatif resserre les lettres : sans lui, un nom
            // court tracé en grosses lettres paraît disjoint et peu soigné.
            letterSpacing: -markSize * 0.012,
            height: 1,
            color: onDark ? Colors.white : AppColors.ink900,
          ),
        ),
      ],
    );
  }
}

/// Le glyphe : un « A » qui se lit aussi comme un toit sur une base d'épargne.
///
/// Dessiné au trait, il reste net sur toutes les densités d'écran et ne dépend
/// d'aucun fichier de police de marque. Le carreau porte le dégradé de la
/// marque, pour que le logo et l'icône du lanceur soient la même image.
class TontineMark extends StatelessWidget {
  const TontineMark({super.key, this.size = 56, this.tone = AppLogoTone.tinted});

  /// Couleur du trait, dans les deux déclinaisons.
  ///
  /// Exposée pour pouvoir être vérifiée : c'est le trait blanc posé PAR-DESSUS
  /// l'ombre qui rend le glyphe lisible sur le dégradé. Le remplacer par la
  /// couleur d'ombre l'aisserait sombre sur sombre.
  static const Color glyphColor = Colors.white;

  final double size;
  final AppLogoTone tone;

  @override
  Widget build(BuildContext context) {
    final onDark = tone == AppLogoTone.onDark;

    return Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(size * 0.28),
        color: onDark ? Colors.white.withValues(alpha: 0.14) : null,
        gradient: onDark ? null : markGradient,
      ),
      child: CustomPaint(
        painter: _MarkPainter(onDark: onDark),
        size: Size.square(size),
      ),
    );
  }
}

/// Dégradé du carreau, partagé par le logo et par le fond de l'icône.
const LinearGradient markGradient = LinearGradient(
  begin: Alignment.topLeft,
  end: Alignment.bottomRight,
  colors: [AppColors.primary500, AppColors.primary800],
);

class _MarkPainter extends CustomPainter {
  const _MarkPainter({required this.onDark});

  /// Sur le fond sombre du splash, le glyphe est peint en blanc plein ; en
  /// version claire, il reçoit une ombre **dessous** pour se détacher du
  /// dégradé.
  final bool onDark;

  @override
  void paint(Canvas canvas, Size size) {
    final stroke = size.shortestSide * 0.105;

    // Deux passes : l'ombre d'abord, le trait blanc par-dessus. Peindre
    // l'ombre À LA PLACE du trait rendait le glyphe presque invisible sur le
    // dégradé — il ne s'en distinguait plus que par un relief.
    if (!onDark) {
      _strokes(
        canvas,
        size,
        stroke,
        Paint()
          ..color = AppColors.primary900.withValues(alpha: 0.35)
          ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 2.4),
      );
    }

    _strokes(canvas, size, stroke, Paint()..color = TontineMark.glyphColor);
  }

  void _strokes(Canvas canvas, Size size, double stroke, Paint paint) {
    paint
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;

    Offset at(double x, double y) => Offset(size.width * x, size.height * y);

    // Le toit : deux traits qui montent vers le sommet et redescendent.
    final roof = Path()
      ..moveTo(at(0.28, 0.60).dx, at(0.28, 0.60).dy)
      ..lineTo(at(0.50, 0.28).dx, at(0.50, 0.28).dy)
      ..lineTo(at(0.72, 0.60).dx, at(0.72, 0.60).dy);

    canvas
      ..drawPath(roof, paint)
      // La traverse, qui achève la lettre.
      ..drawLine(at(0.365, 0.47), at(0.635, 0.47), paint);

    // Le socle : la réserve posée sous le toit.
    paint.strokeWidth = stroke * 0.8;
    canvas.drawLine(at(0.34, 0.76), at(0.66, 0.76), paint);
  }

  @override
  bool shouldRepaint(_MarkPainter oldDelegate) => oldDelegate.onDark != onDark;
}

/// Fonds possibles du marqueur. Public pour que le champ [AppLogo.tone] ne
/// soit pas un type privé exposé par une API publique.
enum AppLogoTone { tinted, onDark }
