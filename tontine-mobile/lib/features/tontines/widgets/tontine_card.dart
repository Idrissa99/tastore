import 'package:flutter/material.dart';
import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/core/widgets/app_badge.dart';
import 'package:tontine_achat_store/core/widgets/progress_bar.dart';
import 'package:tontine_achat_store/features/tontines/tontine.dart';
import 'package:tontine_achat_store/features/tontines/widgets/tontine_cover.dart';

/// Carte d'une tontine dans le catalogue.
///
/// Reproduit la carte du SPA web (`TontineCard.jsx`) : visuel, nom, montant
/// du versement, remplissage et progression financière. Les deux clients
/// montrent la même chose — un montant qu'on compare, une place qu'on prend,
/// un état de financement qu'on suit.
class TontineCard extends StatelessWidget {
  const TontineCard({super.key, required this.tontine, required this.onTap});

  final Tontine tontine;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final isCash = tontine.type == TontineType.cash;
    final percent = (tontine.fundingRatio * 100).round();

    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            TontineCover(tontine: tontine),

            Padding(
              padding: const EdgeInsets.fromLTRB(14, 12, 14, 14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    tontine.name,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.w800,
                      color: AppColors.ink900,
                      height: 1.25,
                    ),
                  ),

                  if (!isCash && tontine.product != null) ...[
                    const SizedBox(height: 2),
                    Text(
                      'Produit : ${tontine.product!.name}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(color: AppColors.ink500, fontSize: 12),
                    ),
                  ],

                  const SizedBox(height: 10),
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      Expanded(
                        child: Text.rich(
                          TextSpan(
                            children: [
                              TextSpan(
                                text: Fmt.fcfa(tontine.contributionAmount),
                                style: const TextStyle(
                                  fontSize: 17,
                                  fontWeight: FontWeight.w800,
                                  color: AppColors.primary800,
                                ),
                              ),
                              TextSpan(
                                text: ' ${tontine.frequency.label}',
                                style: const TextStyle(
                                  fontSize: 11,
                                  fontWeight: FontWeight.w600,
                                  color: AppColors.ink500,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                      const SizedBox(width: 8),
                      // Largeur fixe : dans une `Row`, un enfant non flexible
                      // reçoit une largeur infinie, ce que la barre de
                      // remplissage refuse. Elle gagne ainsi une largeur
                      // stable, alignée d'une carte à l'autre.
                      SizedBox(width: 92, child: _Members(tontine: tontine)),
                    ],
                  ),

                  const SizedBox(height: 12),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        isCash ? 'Collecté' : 'Financé',
                        style: const TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.w600,
                          color: AppColors.ink500,
                        ),
                      ),
                      Text(
                        '$percent %',
                        style: const TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.w800,
                          color: AppColors.ink700,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 6),
                  ProgressBar(value: tontine.fundingRatio),
                  const SizedBox(height: 5),
                  Text(
                    '${Fmt.fcfa(tontine.collectedAmount)} / ${Fmt.fcfa(tontine.totalAmount)}',
                    style: const TextStyle(fontSize: 11, color: AppColors.ink400),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// « 7 / 12 », avec la pastille « complète » quand il n'y a plus de place.
///
/// Le mot « complète » est affiché et pas seulement suggéré : c'est
/// précisément l'information qui empêche de remplir un panier d'adhésion
/// impossible.
class _Members extends StatelessWidget {
  const _Members({required this.tontine});

  final Tontine tontine;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.group_outlined, size: 14, color: AppColors.ink500),
            const SizedBox(width: 4),
            Text(
              '${tontine.currentMembers}/${tontine.maxMembers}',
              style: const TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w700,
                color: AppColors.ink600,
              ),
            ),
          ],
        ),
        const SizedBox(height: 3),
        ProgressBar(value: tontine.fillRatio, height: 4),
      ],
    );
  }
}

/// Carte fantôme, affichée pendant le premier chargement.
///
/// Elle reprend la forme exacte de [TontineCard] : une silhouette de taille
/// différente ferait sautiller la liste à l'apparition des vraies cartes.
class TontineCardSkeleton extends StatelessWidget {
  const TontineCardSkeleton({super.key});

  @override
  Widget build(BuildContext context) {
    Widget bar(double width, double height) => Container(
          width: width,
          height: height,
          decoration: BoxDecoration(
            color: AppColors.ink100,
            borderRadius: BorderRadius.circular(AppRadius.xs),
          ),
        );

    return Card(
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const AspectRatio(
            aspectRatio: 16 / 9,
            child: ColoredBox(color: AppColors.ink100),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                bar(double.infinity, 15),
                const SizedBox(height: 10),
                bar(140, 11),
                const SizedBox(height: 12),
                bar(110, 17),
                const SizedBox(height: 12),
                const _SkeletonBar(),
                const SizedBox(height: 10),
                bar(150, 11),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _SkeletonBar extends StatelessWidget {
  const _SkeletonBar();

  @override
  Widget build(BuildContext context) {
    return Container(
      height: 7,
      decoration: BoxDecoration(
        color: AppColors.ink100,
        borderRadius: BorderRadius.circular(4),
      ),
    );
  }
}

/// Pastille « X places restantes », reprise sur la fiche.
class RemainingSlotsBadge extends StatelessWidget {
  const RemainingSlotsBadge({super.key, required this.tontine});

  final Tontine tontine;

  @override
  Widget build(BuildContext context) {
    if (tontine.joinAvailability == JoinAvailability.alreadyMember) {
      return const AppBadge(label: 'Tu es membre', tone: AppTone.success, icon: Icons.check);
    }

    if (tontine.isFull) {
      return const AppBadge(label: 'Complète', tone: AppTone.danger, icon: Icons.block);
    }

    final remaining = tontine.remainingSlots;

    return AppBadge(
      label: "${Fmt.plural(remaining, 'place')} restante${remaining > 1 ? 's' : ''}",
      tone: AppTone.brand,
      icon: Icons.event_seat_outlined,
    );
  }
}
