import 'package:flutter/material.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/core/widgets/avatar.dart';
import 'package:tontine_achat_store/core/widgets/app_badge.dart';
import 'package:tontine_achat_store/core/widgets/network_image.dart';
import 'package:tontine_achat_store/features/tontines/tontine.dart';

/// Visuel d'une tontine : photo du produit, ou avatar à défaut.
///
/// Deux cas, dans cet ordre :
///
///  1. une photo RÉELLE existe pour le produit — on l'affiche, une photo vaut
///     mieux qu'un avatar ;
///  2. sinon (tontine argent, ou tontine produit sans photo) — initiales sur
///     dégradé. C'est exactement le rendu de l'avatar de profil par défaut,
///     parce que c'est le MÊME composant, non une copie à maintenir en
///     parallèle.
///
/// Le badge TA / TP rappelle la nature de la tontine, positionné comme un
/// badge de rôle sur le cercle : il ne déforme pas l'avatar.
class TontineCover extends StatelessWidget {
  const TontineCover({
    super.key,
    required this.tontine,
    this.hero = true,
  });

  final Tontine tontine;

  /// Grand bloc 16/9 pour les cartes et la fiche ; vignette carrée sinon.
  final bool hero;

  @override
  Widget build(BuildContext context) {
    // Une tontine argent n'a pas de produit : les initiales sont alors celles
    // de la tontine. Sur une tontine produit, ce sont celles du PRODUIT — deux
    // tontines vendues sur le même produit se ressemblent, ce qui est vrai.
    final photo = tontine.type == TontineType.cash ? null : tontine.product?.imageUrl;
    final label = tontine.product?.name ?? tontine.name;

    return ClipRRect(
      borderRadius: hero ? BorderRadius.zero : BorderRadius.circular(AppRadius.md),
      child: AspectRatio(
        aspectRatio: hero ? 16 / 9 : 1,
        child: photo != null
            ? Stack(
                fit: StackFit.expand,
                children: [
                  AppNetworkImage(url: photo),
                  if (hero) _badges(context),
                ],
              )
            : DecoratedBox(
                decoration: const BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors: [AppColors.ink50, AppColors.ink100],
                  ),
                ),
                child: Stack(
                  fit: StackFit.expand,
                  children: [
                    Center(
                      child: AppAvatar(
                        name: label,
                        size: hero ? 76 : 34,
                        badge: tontine.type.badge,
                      ),
                    ),
                    if (hero) _badges(context),
                  ],
                ),
              ),
      ),
    );
  }

  /// Type et statut, posés en haut du visuel comme sur le SPA web.
  ///
  /// Ils ne sont posés qu'en mode `hero` : sur une vignette de 40 px, deux
  /// pastilles deviennent illisibles et écrasent l'image.
  Widget _badges(BuildContext context) {
    return Positioned(
      left: 10,
      right: 10,
      top: 10,
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Flexible(child: AppBadge(label: tontine.type.shortLabel, tone: tontine.type.tone)),
          const SizedBox(width: 8),
          AppBadge(label: tontine.status.label, tone: tontine.status.tone),
        ],
      ),
    );
  }
}
