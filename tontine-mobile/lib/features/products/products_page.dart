import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/core/widgets/network_image.dart';
import 'package:tontine_achat_store/core/widgets/progress_bar.dart';
import 'package:tontine_achat_store/core/widgets/state_views.dart';
import 'package:tontine_achat_store/features/purchases/installment_purchase.dart';
import 'package:tontine_achat_store/features/purchases/purchase_providers.dart';
import 'package:tontine_achat_store/routing/app_router.dart';

/// Catalogue des produits achetables en tranches.
///
/// Route poussée depuis l'accueil, et non onglet : le parcours d'achat est une
/// détour — on regarde, on achète, on revient cotiser — et lui consacrer un
/// onglet permanent le laisserait à égalité avec le catalogue des tontines.
///
/// Contrairement aux tontines, le serveur sait appliquer la recherche et la
/// catégorie : chaque changement de critère interroge l'API, rien n'est filtré
/// dans l'appareil.
class ProductsPage extends ConsumerStatefulWidget {
  const ProductsPage({super.key});

  @override
  ConsumerState<ProductsPage> createState() => _ProductsPageState();
}

class _ProductsPageState extends ConsumerState<ProductsPage> {
  final _searchController = TextEditingController();

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final products = ref.watch(productsProvider);
    final categories = ref.watch(productCategoriesProvider);
    final controller = ref.read(productsProvider.notifier);

    return Scaffold(
      appBar: AppBar(title: const Text('Produits')),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
            child: TextField(
              controller: _searchController,
              onChanged: controller.search,
              textInputAction: TextInputAction.search,
              autocorrect: false,
              decoration: InputDecoration(
                hintText: 'Rechercher un produit…',
                prefixIcon: const Icon(Icons.search, size: 20),
                suffixIcon: ValueListenableBuilder<TextEditingValue>(
                  valueListenable: _searchController,
                  builder: (context, value, _) => value.text.isEmpty
                      ? const SizedBox.shrink()
                      : IconButton(
                          tooltip: 'Effacer',
                          icon: const Icon(Icons.close, size: 18),
                          onPressed: () {
                            _searchController.clear();
                            controller.search('');
                          },
                        ),
                ),
              ),
            ),
          ),

          // Les catégories viennent des produits chargés : le backend ne
          // publie pas de catalogue de catégories, et une liste figée
          // proposerait des filtres vides.
          if (categories.isNotEmpty)
            SizedBox(
              height: 46,
              child: ListView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 16),
                children: [
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: ChoiceChip(
                      label: const Text('Toutes'),
                      selected: controller.isFilteringCategory,
                      onSelected: (_) => controller.filterCategory(null),
                    ),
                  ),
                  for (final category in categories)
                    Padding(
                      padding: const EdgeInsets.only(right: 8),
                      child: ChoiceChip(
                        label: Text(Fmt.capitalize(category)),
                        selected: controller.isCategory(category),
                        onSelected: (selected) =>
                            controller.filterCategory(selected ? category : null),
                      ),
                    ),
                ],
              ),
            ),

          const Divider(height: 1),
          Expanded(
            child: products.when(
              loading: () => const LoadingView(label: 'Chargement des produits…'),
              error: (error, _) => ErrorView(
                message: '$error',
                onRetry: () => ref.invalidate(productsProvider),
              ),
              data: (page) {
                if (page.items.isEmpty) {
                  return const EmptyView(
                    icon: Icons.inventory_2_outlined,
                    title: 'Aucun produit',
                    message: 'Aucun produit ne correspond à cette recherche.',
                  );
                }

                return ListView.separated(
                  padding: const EdgeInsets.fromLTRB(16, 14, 16, 28),
                  itemCount: page.items.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 12),
                  itemBuilder: (context, index) => _ProductCard(product: page.items[index]),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}

class _ProductCard extends StatelessWidget {
  const _ProductCard({required this.product});

  final Product product;

  @override
  Widget build(BuildContext context) {
    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => context.push(AppRoutes.product(product.id)),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            AspectRatio(
              aspectRatio: 16 / 9,
              child: Stack(
                fit: StackFit.expand,
                children: [
                  AppNetworkImage(url: product.coverUrl, fallbackLabel: 'Pas de photo'),
                  if (product.stock <= 0)
                    Container(
                      color: AppColors.ink900.withValues(alpha: 0.55),
                      alignment: Alignment.center,
                      child: const Text(
                        'Rupture de stock',
                        style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800),
                      ),
                    ),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    product.name,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.w800,
                      color: AppColors.ink900,
                      height: 1.25,
                    ),
                  ),
                  if (product.merchantLine.isNotEmpty) ...[
                    const SizedBox(height: 2),
                    Text(
                      product.merchantLine,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(color: AppColors.ink500, fontSize: 12),
                    ),
                  ],
                  const SizedBox(height: 10),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        Fmt.fcfa(product.price),
                        style: const TextStyle(
                          fontSize: 17,
                          fontWeight: FontWeight.w800,
                          color: AppColors.primary800,
                        ),
                      ),
                      if (product.hasRating)
                        Row(
                          children: [
                            const Icon(Icons.star, size: 15, color: AppColors.accent500),
                            const SizedBox(width: 3),
                            Text(
                              Fmt.number(product.merchantRating),
                              style: const TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.w700,
                                color: AppColors.ink600,
                              ),
                            ),
                          ],
                        ),
                    ],
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

/// Barre de progression d'un achat, réutilisée par la liste et la fiche.
class PurchaseProgress extends StatelessWidget {
  const PurchaseProgress({super.key, required this.purchase});

  final InstallmentPurchase purchase;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        ProgressBar(value: purchase.paidRatio),
        const SizedBox(height: 6),
        Text(
          '${purchase.paidInstallmentsCount}/${purchase.installmentsCount} tranches payées',
          style: const TextStyle(fontSize: 11, color: AppColors.ink500),
        ),
      ],
    );
  }
}
