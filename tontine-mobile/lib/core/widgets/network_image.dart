import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:tontine_achat_store/core/network/media.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';

/// Image distante avec repli, servie par le cache disque.
///
/// `cached_network_image` évite de retélécharger la même photo produit à
/// chaque retour sur le catalogue : sans cela, faire défiler la liste trois
/// fois recharge trois fois les mêmes vignettes.
///
/// **L'URL est résolue ici, et une seule fois.** Le chemin brut du backend
/// (`/storage/…`) ou une URL absolue bâtie sur un `APP_URL` périmé ne sont
/// pas des adresses que le chargeur d'images comprend : le premier n'a pas
/// d'hôte, le second pointe vers une machine qui n'est plus la bonne. Les
/// vérifier pour décider du repli, puis charger l'original, donnait une image
/// absente sans message — exactement le « Pas de photo » qui ment. On résout
/// donc une fois, et c'est la valeur résolue qui part au chargeur.
///
/// [mediaUrl] est idempotent sur une URL déjà résolue : l'appliquer deux fois
/// rend la même chose, ce qui laisse les appelants libres de résoudre en amont.
class AppNetworkImage extends StatelessWidget {
  const AppNetworkImage({
    super.key,
    required this.url,
    this.fallbackLabel = '',
    this.fit = BoxFit.cover,
  });

  final String? url;

  /// Texte affiché sous l'icône quand il n'y a pas d'image. Vide pour un
  /// repli muet (vignette d'avatar).
  final String fallbackLabel;

  final BoxFit fit;

  @override
  Widget build(BuildContext context) {
    final resolved = mediaUrl(url);
    if (resolved == null) return _Fallback(label: fallbackLabel);

    return CachedNetworkImage(
      imageUrl: resolved,
      fit: fit,
      fadeInDuration: const Duration(milliseconds: 180),
      placeholder: (context, _) => const _Fallback(),
      errorWidget: (context, _, __) => _Fallback(label: fallbackLabel),
    );
  }
}

class _Fallback extends StatelessWidget {
  const _Fallback({this.label = ''});

  final String label;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [AppColors.ink50, AppColors.ink100],
        ),
      ),
      child: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.image_not_supported_outlined, size: 24, color: AppColors.ink300),
            if (label.isNotEmpty) ...[
              const SizedBox(height: 4),
              Text(
                label,
                style: const TextStyle(
                  color: AppColors.ink400,
                  fontSize: 11,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
