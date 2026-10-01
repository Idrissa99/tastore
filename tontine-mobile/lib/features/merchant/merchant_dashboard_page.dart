import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/core/widgets/app_badge.dart';
import 'package:tontine_achat_store/core/widgets/app_logo.dart';
import 'package:tontine_achat_store/core/widgets/state_views.dart';
import 'package:tontine_achat_store/features/merchant/merchant_models.dart';
import 'package:tontine_achat_store/features/merchant/merchant_providers.dart';
import 'package:tontine_achat_store/features/merchant/merchant_repository.dart';
import 'package:tontine_achat_store/routing/app_router.dart';

/// Accueil de l'espace commerçant.
///
/// Un tableau de bord, pas un inventaire : le vendeur veut savoir combien il a
/// encaissé, ce qu'il doit livrer, et si un produit est en rupture. Le détail
/// du catalogue et des livraisons a ses propres écrans, poussés d'ici.
class MerchantDashboardPage extends ConsumerWidget {
  const MerchantDashboardPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final dashboard = ref.watch(merchantDashboardProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Mon espace')),
      body: dashboard.when(
        loading: () => const LoadingView(label: 'Chargement de ton espace…'),
        error: (error, _) => ErrorView(
          message: '$error',
          onRetry: () => ref.invalidate(merchantDashboardProvider),
        ),
        data: (data) => _Body(dashboard: data),
      ),
    );
  }
}

class _Body extends ConsumerWidget {
  const _Body({required this.dashboard});

  final MerchantDashboard dashboard;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final pending = dashboard.pendingTontineDeliveries.where((item) => item.isEligible).toList();
    final installmentPending =
        dashboard.pendingInstallmentDeliveries.where((item) => item.isPending).toList();

    // Le rafraîchissement doit RELIRE le tableau de bord, pas seulement
    // rejouer l'état courant : c'est le geste naturel après avoir encaissé une
    // cotisation. Sans lui, tirer l'écran ne changerait rien à l'écran.
    return RefreshIndicator(
      onRefresh: () async => ref.refresh(merchantDashboardProvider.future),
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
        children: [
          _ShopCard(dashboard: dashboard),
          const SizedBox(height: 16),

          _RevenueCard(revenue: dashboard.revenue),
          const SizedBox(height: 16),

          Row(
            children: [
              Expanded(
                child: _StatTile(
                  label: 'Produits publiés',
                  value: '${dashboard.publishedCount}',
                  icon: Icons.storefront_outlined,
                  onTap: () => context.push(AppRoutes.merchantProducts),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: _StatTile(
                  label: 'En rupture',
                  value: '${dashboard.outOfStockCount}',
                  icon: Icons.production_quantity_limits_outlined,
                  tone: dashboard.outOfStockCount > 0 ? AppTone.danger : AppTone.neutral,
                  onTap: () => context.push(AppRoutes.merchantProducts),
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),

          _SectionHeader(
            title: 'Livraisons à confirmer',
            count: pending.length + installmentPending.length,
            onSeeAll: () => context.push(AppRoutes.merchantDeliveries),
          ),
          const SizedBox(height: 10),

          if (pending.isEmpty && installmentPending.isEmpty)
            const _InfoCard(
              icon: Icons.check_circle_outline,
              text: 'Rien à livrer pour le moment. Tes confirmations apparaîtront ici.',
            )
          else ...[
            for (final item in pending.take(3))
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: _DeliveryRow(item: item),
              ),
            for (final item in installmentPending.take(2))
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: _InstallmentRow(delivery: item),
              ),
            if (pending.length > 3 || installmentPending.length > 2)
              OutlinedButton.icon(
                onPressed: () => context.push(AppRoutes.merchantDeliveries),
                icon: const Icon(Icons.arrow_forward, size: 16),
                label: const Text('Toutes les livraisons'),
              ),
          ],

          const SizedBox(height: 20),
          FilledButton.icon(
            onPressed: () => context.push(AppRoutes.merchantProducts),
            icon: const Icon(Icons.add, size: 18),
            label: const Text('Gérer mon catalogue'),
          ),
        ],
      ),
    );
  }
}

/// Identité de la boutique, avec sa note.
class _ShopCard extends StatelessWidget {
  const _ShopCard({required this.dashboard});

  final MerchantDashboard dashboard;

  @override
  Widget build(BuildContext context) {
    final location = [
      dashboard.city,
      if (dashboard.rating > 0) 'Note ${Fmt.number(dashboard.rating)}/5',
    ].join(' · ');

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Row(
          children: [
            const TontineMark(size: 46),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    dashboard.businessName.isEmpty ? 'Ma boutique' : dashboard.businessName,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      fontSize: 16,
                      fontWeight: FontWeight.w800,
                      color: AppColors.ink900,
                    ),
                  ),
                  if (location.isNotEmpty) ...[
                    const SizedBox(height: 2),
                    Text(
                      location,
                      style: const TextStyle(color: AppColors.ink500, fontSize: 12),
                    ),
                  ],
                ],
              ),
            ),
            if (dashboard.rating > 0) ...[
              const SizedBox(width: 8),
              const Icon(Icons.star, size: 18, color: AppColors.accent500),
            ],
          ],
        ),
      ),
    );
  }
}

/// Chiffre d'affaires, décomposé.
///
/// Le total est mis en avant et les deux postes dessous : c'est le total qu'on
/// vient vérifier, et la répartition sert à comprendre d'où il vient — une
/// boutique qui vend surtout en tranches ne se pilote pas comme une autre.
class _RevenueCard extends StatelessWidget {
  const _RevenueCard({required this.revenue});

  final MerchantRevenue revenue;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              "Chiffre d'affaires encaissé",
              style: TextStyle(color: AppColors.ink500, fontSize: 12, fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 6),
            Text(
              Fmt.fcfa(revenue.total),
              style: const TextStyle(
                fontSize: 26,
                fontWeight: FontWeight.w800,
                color: AppColors.primary800,
              ),
            ),
            const SizedBox(height: 14),
            Row(
              children: [
                Expanded(
                  child: _RevenueLine(
                    label: 'Tontines',
                    amount: revenue.contributions,
                    color: AppColors.primary500,
                  ),
                ),
                Expanded(
                  child: _RevenueLine(
                    label: 'Tranches',
                    amount: revenue.installments,
                    color: AppColors.accent500,
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _RevenueLine extends StatelessWidget {
  const _RevenueLine({required this.label, required this.amount, required this.color});

  final String label;
  final double amount;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Container(
              width: 8,
              height: 8,
              decoration: BoxDecoration(color: color, shape: BoxShape.circle),
            ),
            const SizedBox(width: 6),
            Text(label, style: const TextStyle(color: AppColors.ink500, fontSize: 12)),
          ],
        ),
        const SizedBox(height: 3),
        Text(
          Fmt.fcfa(amount),
          style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w800, color: AppColors.ink900),
        ),
      ],
    );
  }
}

class _StatTile extends StatelessWidget {
  const _StatTile({
    required this.label,
    required this.value,
    required this.icon,
    required this.onTap,
    this.tone = AppTone.neutral,
  });

  final String label;
  final String value;
  final IconData icon;
  final VoidCallback onTap;
  final AppTone tone;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(icon, size: 20, color: tone.foreground),
              const SizedBox(height: 8),
              Text(
                value,
                style: TextStyle(
                  fontSize: 22,
                  fontWeight: FontWeight.w800,
                  color: tone == AppTone.neutral ? AppColors.ink900 : tone.foreground,
                ),
              ),
              const SizedBox(height: 2),
              Text(
                label,
                maxLines: 2,
                style: const TextStyle(color: AppColors.ink500, fontSize: 11, height: 1.2),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _SectionHeader extends StatelessWidget {
  const _SectionHeader({required this.title, required this.count, required this.onSeeAll});

  final String title;
  final int count;
  final VoidCallback onSeeAll;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: Row(
            children: [
              Text(
                title,
                style: const TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.w800,
                  color: AppColors.ink900,
                ),
              ),
              if (count > 0) ...[
                const SizedBox(width: 8),
                AppBadge(label: '$count', tone: AppTone.brand),
              ],
            ],
          ),
        ),
        TextButton(
          onPressed: onSeeAll,
          child: const Text('Tout voir'),
        ),
      ],
    );
  }
}

class _InfoCard extends StatelessWidget {
  const _InfoCard({required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          children: [
            Icon(icon, size: 20, color: AppColors.success600),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                text,
                style: const TextStyle(color: AppColors.ink600, fontSize: 13, height: 1.4),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Ligne de livraison de tontine, réutilisée par le tableau de bord et par
/// l'écran des livraisons.
class _DeliveryRow extends StatelessWidget {
  const _DeliveryRow({required this.item});

  final DeliveryItem item;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
        leading: CircleAvatar(
          backgroundColor: AppColors.primary50,
          child: Text(
            Fmt.initials(item.userName),
            style: const TextStyle(
              color: AppColors.primary800,
              fontWeight: FontWeight.w800,
              fontSize: 13,
            ),
          ),
        ),
        title: Text(
          item.userName.isEmpty ? 'Membre' : item.userName,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14),
        ),
        subtitle: Text(
          item.productName.isEmpty ? item.tontineName : item.productName,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: const TextStyle(color: AppColors.ink500, fontSize: 12),
        ),
        trailing: item.isAwaitingPayment
            ? const AppBadge(label: 'En attente de paiement', tone: AppTone.warning)
            : AppBadge(
                label: 'Tour ${item.beneficiaryRound}',
                tone: item.isEligible ? AppTone.success : AppTone.neutral,
              ),
      ),
    );
  }
}

/// Ligne de livraison d'achat en tranches.
class _InstallmentRow extends StatelessWidget {
  const _InstallmentRow({required this.delivery});

  final InstallmentDelivery delivery;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
        leading: const CircleAvatar(
          backgroundColor: AppColors.accent50,
          child: Icon(Icons.schedule_outlined, size: 18, color: AppColors.accent700),
        ),
        title: Text(
          delivery.productName.isEmpty ? 'Achat en tranches' : delivery.productName,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14),
        ),
        subtitle: Text(
          delivery.userName.isEmpty
              ? '${delivery.installmentsCount} tranches'
              : '${delivery.userName} · ${delivery.installmentsCount} tranches',
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: const TextStyle(color: AppColors.ink500, fontSize: 12),
        ),
        trailing: const AppBadge(label: 'À livrer', tone: AppTone.warning),
      ),
    );
  }
}