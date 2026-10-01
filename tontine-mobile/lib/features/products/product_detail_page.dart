import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/core/network/api_exception.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/core/widgets/app_badge.dart';
import 'package:tontine_achat_store/core/widgets/network_image.dart';
import 'package:tontine_achat_store/core/widgets/state_views.dart';
import 'package:tontine_achat_store/features/purchases/installment_purchase.dart';
import 'package:tontine_achat_store/features/purchases/purchase_providers.dart';
import 'package:tontine_achat_store/routing/app_router.dart';

/// Fiche d'un produit, et achat en tranches.
///
/// L'aperçu du nombre de tranches est demandé AU SERVEUR à chaque changement :
/// c'est lui qui applique la commission. Diviser le prix affiché par le nombre
/// choisi donnerait un montant différent de celui qui sera débité —
/// précisément le chiffre sur lequel l'utilisateur s'engage.
class ProductDetailPage extends ConsumerStatefulWidget {
  const ProductDetailPage({super.key, required this.productId});

  final int productId;

  @override
  ConsumerState<ProductDetailPage> createState() => _ProductDetailPageState();
}

class _ProductDetailPageState extends ConsumerState<ProductDetailPage> {
  int _installments = defaultInstallments;
  bool _busy = false;
  String? _error;

  Future<void> _buy(Product product) async {
    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final purchase = await ref.read(purchaseRepositoryProvider).createPurchase(
            widget.productId,
            installmentsCount: _installments,
          );

      if (!mounted) return;
      // `go` et non `pushReplacement` : la fiche du produit n'a plus d'intérêt
      // une fois l'achat créé, et empiler l'écran d'achat dessus ferait
      // revenir l'utilisateur sur une fiche vide s'il réessayait « retour ».
      context.go(AppRoutes.purchase(purchase.id));
    } on ApiException catch (error) {
      if (mounted) setState(() => _error = error.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final product = ref.watch(productDetailProvider(widget.productId));

    return Scaffold(
      appBar: AppBar(title: const Text('Produit')),
      body: product.when(
        loading: () => const LoadingView(label: 'Chargement du produit…'),
        error: (error, _) => ErrorView(
          message: '$error',
          onRetry: () => ref.invalidate(productDetailProvider(widget.productId)),
        ),
        data: (data) => Column(
          children: [
            Expanded(
              child: ListView(
                padding: const EdgeInsets.only(bottom: 20),
                children: [
                  _Gallery(product: data),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          data.name,
                          style: const TextStyle(
                            fontSize: 20,
                            fontWeight: FontWeight.w800,
                            color: AppColors.ink900,
                            height: 1.25,
                          ),
                        ),
                        if (data.merchantLine.isNotEmpty) ...[
                          const SizedBox(height: 4),
                          Text(
                            data.merchantLine,
                            style: const TextStyle(color: AppColors.ink600, fontSize: 13),
                          ),
                        ],
                        const SizedBox(height: 14),
                        Wrap(
                          spacing: 8,
                          runSpacing: 8,
                          children: [
                            AppBadge(
                              label: Fmt.fcfa(data.price),
                              tone: AppTone.brand,
                              icon: Icons.payments_outlined,
                            ),
                            if (data.stock > 0)
                              AppBadge(label: '${Fmt.number(data.stock)} en stock', tone: AppTone.success)
                            else
                              const AppBadge(label: 'Rupture de stock', tone: AppTone.danger),
                            if (data.hasRating)
                              AppBadge(
                                label: '${Fmt.number(data.merchantRating)} / 5',
                                tone: AppTone.accent,
                                icon: Icons.star,
                              ),
                          ],
                        ),
                        if (data.description.isNotEmpty) ...[
                          const SizedBox(height: 16),
                          Text(
                            data.description,
                            style: const TextStyle(
                              color: AppColors.ink600,
                              fontSize: 14,
                              height: 1.5,
                            ),
                          ),
                        ],
                        const SizedBox(height: 24),
                        _TrancheChooser(
                          count: _installments,
                          productId: widget.productId,
                          onChanged: (value) => setState(() => _installments = value),
                        ),
                        if (_error != null) ...[
                          const SizedBox(height: 14),
                          Text(
                            _error!,
                            style: const TextStyle(color: AppColors.danger600, fontSize: 13),
                          ),
                        ],
                      ],
                    ),
                  ),
                ],
              ),
            ),
            _BuyBar(
              busy: _busy,
              enabled: data.stock > 0,
              label: 'Acheter en $_installments tranches',
              onPressed: () => _buy(data),
            ),
          ],
        ),
      ),
    );
  }
}

class _Gallery extends StatefulWidget {
  const _Gallery({required this.product});

  final Product product;

  @override
  State<_Gallery> createState() => _GalleryState();
}

class _GalleryState extends State<_Gallery> {
  int _index = 0;

  @override
  Widget build(BuildContext context) {
    final images = widget.product.images;

    if (images.isEmpty) {
      return const AspectRatio(
        aspectRatio: 16 / 9,
        child: AppNetworkImage(url: null, fallbackLabel: 'Pas de photo'),
      );
    }

    return Column(
      children: [
        AspectRatio(
          aspectRatio: 16 / 9,
          child: AppNetworkImage(url: images[_index.clamp(0, images.length - 1)]),
        ),
        if (images.length > 1)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 0),
            child: Row(
              children: [
                for (var index = 0; index < images.length; index++)
                  GestureDetector(
                    onTap: () => setState(() => _index = index),
                    child: Container(
                      width: index == _index ? 20 : 8,
                      height: 8,
                      margin: const EdgeInsets.only(right: 6),
                      decoration: BoxDecoration(
                        color: index == _index ? AppColors.primary600 : AppColors.ink300,
                        borderRadius: BorderRadius.circular(4),
                      ),
                    ),
                  ),
              ],
            ),
          ),
      ],
    );
  }
}

/// Sélecteur du nombre de tranches, avec l'aperçu serveur juste dessous.
///
/// Les bornes [minInstallments] et [maxInstallments] sont celles de la
/// validation backend : proposer 30 tranches n'aurait pour seule conséquence
/// un 422.
class _TrancheChooser extends ConsumerWidget {
  const _TrancheChooser({
    required this.count,
    required this.productId,
    required this.onChanged,
  });

  final int count;
  final int productId;
  final ValueChanged<int> onChanged;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final preview = ref.watch(installmentPreviewProvider((productId: productId, count: count)));

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Nombre de tranches',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 4),
            const Text(
              'De $minInstallments à $maxInstallments versements.',
              style: TextStyle(fontSize: 12, color: AppColors.ink500),
            ),
            const SizedBox(height: 12),
            Row(
              children: [
                IconButton.outlined(
                  onPressed: count > minInstallments ? () => onChanged(count - 1) : null,
                  icon: const Icon(Icons.remove, size: 18),
                  tooltip: 'Une tranche de moins',
                ),
                Expanded(
                  child: Center(
                    child: Text(
                      '$count',
                      style: const TextStyle(
                        fontSize: 22,
                        fontWeight: FontWeight.w800,
                        color: AppColors.ink900,
                      ),
                    ),
                  ),
                ),
                IconButton.outlined(
                  onPressed: count < maxInstallments ? () => onChanged(count + 1) : null,
                  icon: const Icon(Icons.add, size: 18),
                  tooltip: 'Une tranche de plus',
                ),
              ],
            ),

            const Divider(height: 24),
            preview.when(
              loading: () => const Center(
                child: Padding(
                  padding: EdgeInsets.symmetric(vertical: 8),
                  child: CircularProgressIndicator(strokeWidth: 2),
                ),
              ),
              error: (error, _) => Text(
                'Aperçu indisponible : $error',
                style: const TextStyle(fontSize: 12, color: AppColors.danger600),
              ),
              data: (data) => Column(
                children: [
                  _Row(label: 'Par tranche', value: Fmt.fcfa(data.installmentAmount)),
                  const SizedBox(height: 6),
                  _Row(
                    label: 'Commission (${Fmt.percent(data.commissionRate, decimals: 1)})',
                    value: 'incluse',
                  ),
                  const SizedBox(height: 6),
                  _Row(label: 'Total à verser', value: Fmt.fcfa(data.total), strong: true),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Row extends StatelessWidget {
  const _Row({required this.label, required this.value, this.strong = false});

  final String label;
  final String value;
  final bool strong;

  @override
  Widget build(BuildContext context) {
    final style = TextStyle(
      fontSize: strong ? 15 : 13,
      fontWeight: strong ? FontWeight.w800 : FontWeight.w500,
      color: strong ? AppColors.primary800 : AppColors.ink600,
    );

    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [Text(label, style: style), Text(value, style: style)],
    );
  }
}

class _BuyBar extends StatelessWidget {
  const _BuyBar({
    required this.busy,
    required this.enabled,
    required this.label,
    required this.onPressed,
  });

  final bool busy;
  final bool enabled;
  final String label;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        color: Colors.white,
        border: Border(top: BorderSide(color: AppColors.ink200)),
      ),
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
      child: SafeArea(
        top: false,
        child: FilledButton(
          onPressed: busy || !enabled ? null : onPressed,
          child: busy
              ? const SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(
                    strokeWidth: 2.2,
                    valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
                  ),
                )
              : Text(enabled ? label : 'Indisponible'),
        ),
      ),
    );
  }
}
