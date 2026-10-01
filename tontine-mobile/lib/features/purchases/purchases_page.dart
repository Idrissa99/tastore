import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/core/widgets/app_badge.dart';
import 'package:tontine_achat_store/core/widgets/network_image.dart';
import 'package:tontine_achat_store/core/widgets/state_views.dart';
import 'package:tontine_achat_store/features/products/products_page.dart';
import 'package:tontine_achat_store/features/purchases/installment_purchase.dart';
import 'package:tontine_achat_store/features/purchases/purchase_providers.dart';
import 'package:tontine_achat_store/routing/app_router.dart';

/// « Mes achats » : les produits achetés en tranches.
///
/// Route poussée depuis l'accueil plutôt qu'onglet : c'est un historique, on
/// le consulte quand on cherche à payer quelque chose, pas en permanence.
class PurchasesPage extends ConsumerWidget {
  const PurchasesPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final purchases = ref.watch(purchasesProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Mes achats')),
      body: purchases.when(
        loading: () => const LoadingView(label: 'Chargement des achats…'),
        error: (error, _) => ErrorView(
          message: '$error',
          onRetry: () => ref.invalidate(purchasesProvider),
        ),
        data: (all) {
          if (all.isEmpty) {
            return EmptyView(
              icon: Icons.shopping_bag_outlined,
              title: 'Aucun achat',
              message: 'Aucun produit n\'a été acheté en tranches pour le moment.',
              actionLabel: 'Voir le catalogue',
              onAction: () => context.go(AppRoutes.products),
            );
          }

          return RefreshIndicator(
            onRefresh: () => ref.refresh(purchasesProvider.future),
            child: ListView.separated(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
              itemCount: all.length,
              separatorBuilder: (_, __) => const SizedBox(height: 12),
              itemBuilder: (context, index) => _Card(purchase: all[index]),
            ),
          );
        },
      ),
    );
  }
}

class _Card extends StatelessWidget {
  const _Card({required this.purchase});

  final InstallmentPurchase purchase;

  @override
  Widget build(BuildContext context) {
    final next = purchase.nextInstallment;

    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => context.push(AppRoutes.purchase(purchase.id)),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (purchase.product?.coverUrl != null)
              AspectRatio(
                aspectRatio: 16 / 6,
                child: AppNetworkImage(url: purchase.product!.coverUrl),
              ),
            Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: Text(
                          purchase.title,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            fontSize: 15,
                            fontWeight: FontWeight.w800,
                            color: AppColors.ink900,
                            height: 1.25,
                          ),
                        ),
                      ),
                      const SizedBox(width: 10),
                      AppBadge(
                        label: purchase.deliveryStatus.label,
                        tone: purchase.deliveryStatus.tone,
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  PurchaseProgress(purchase: purchase),

                  // La prochaine tranche est ce que l'utilisateur cherche en
                  // ouvrant cet écran : son montant est mis en avant, pas
                  // noyé dans la liste des tranches déjà réglées.
                  if (next != null) ...[
                    const SizedBox(height: 12),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                      decoration: BoxDecoration(
                        color: AppColors.primary50,
                        borderRadius: BorderRadius.circular(AppRadius.md),
                      ),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          const Text(
                            'Tranche suivante',
                            style: TextStyle(
                              fontSize: 12,
                              fontWeight: FontWeight.w700,
                              color: AppColors.primary800,
                            ),
                          ),
                          Text(
                            Fmt.fcfa(next.amount),
                            style: const TextStyle(
                              fontSize: 15,
                              fontWeight: FontWeight.w800,
                              color: AppColors.primary800,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
