import 'package:flutter/material.dart';
import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/core/network/media.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';

/// Avatar : photo réelle si disponible, sinon initiales sur dégradé.
///
/// Le dégradé est DÉTERMINISTE — même nom, même couleur d'un écran à l'autre et
/// d'un client à l'autre. La fonction de hachage reproduit celle du SPA web
/// (`gradientFor` dans `Avatar.jsx`) : sans cela, un même commerçant changerait
/// de couleur en passant du catalogue web à l'application mobile, et
/// l'utilisateur y verrait deux personnes différentes.
class AppAvatar extends StatelessWidget {
  const AppAvatar({
    super.key,
    required this.name,
    this.url,
    this.size = 44,
    this.badge,
  });

  final String name;

  /// Photo de profil ou image produit, absolue ou relative au backend.
  final String? url;

  final double size;

  /// Sigle superposé en bas à droite — le rôle de la tontine (« TA » / « TP »).
  /// Le débord est volontaire : la boîte doit laisser 2 px de marge, sinon le
  /// badge serait rogné.
  final String? badge;

  /// Les six dégradés du SPA web, dans le même ordre.
  static const _gradients = <List<Color>>[
    [AppColors.primary600, AppColors.primary800],
    [AppColors.ink700, AppColors.ink900],
    [AppColors.accent500, AppColors.accent700],
    [AppColors.primary800, AppColors.ink900],
    [AppColors.success600, AppColors.primary800],
    [AppColors.ink800, AppColors.primary900],
  ];

  /// Reproduit `hash = (hash * 31 + code) % 100000`, puis `gradients[hash % 6]`.
  static List<Color> gradientFor(String seed) {
    var hash = 0;
    for (final unit in seed.codeUnits) {
      hash = (hash * 31 + unit) % 100000;
    }
    return _gradients[hash % _gradients.length];
  }

  @override
  Widget build(BuildContext context) {
    final source = mediaUrl(url);
    final colors = gradientFor(name);

    final avatar = Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: source == null ? colors.first : AppColors.ink100,
        gradient: source == null
            ? LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: colors,
              )
            : null,
      ),
      child: source != null
          ? Image.network(
              source,
              width: size,
              height: size,
              fit: BoxFit.cover,
              // Une photo cassée ne doit pas laisser un trou gris au milieu
              // d'une carte : on retombe sur les initiales.
              errorBuilder: (context, _, __) => _initials(name, size),
            )
          : _initials(name, size),
    );

    if (badge == null) return avatar;

    return Stack(
      clipBehavior: Clip.none,
      children: [
        avatar,
        Positioned(
          right: -2,
          bottom: -2,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
            decoration: BoxDecoration(
              color: colors.last,
              borderRadius: BorderRadius.circular(AppRadius.xs),
              border: Border.all(color: Colors.white, width: 2),
            ),
            child: Text(
              badge!,
              style: TextStyle(
                color: Colors.white,
                fontSize: size * 0.26,
                fontWeight: FontWeight.w800,
                height: 1.2,
              ),
            ),
          ),
        ),
      ],
    );
  }

  Widget _initials(String value, double fontBase) {
    return Text(
      Fmt.initials(value),
      style: TextStyle(
        color: Colors.white,
        fontSize: fontBase * 0.36,
        fontWeight: FontWeight.w800,
        height: 1,
      ),
    );
  }
}
