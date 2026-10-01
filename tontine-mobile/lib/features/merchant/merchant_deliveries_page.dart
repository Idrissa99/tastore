import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/core/network/api_exception.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/core/widgets/app_badge.dart';
import 'package:tontine_achat_store/core/widgets/state_views.dart';
import 'package:tontine_achat_store/features/merchant/merchant_models.dart';
import 'package:tontine_achat_store/features/merchant/merchant_providers.dart';

/// File des livraisons à confirmer.
///
/// Deux files distinctes, un seul écran : les tontines et les tranches ont
/// chacune leur endpoint, leurs règles d'éligibilité et leur verbe de
/// confirmation. Les séparer en deux onglets les ferait oublier l'une des deux
/// — c'est la file oubliée qui devient un client sans merchandise.
class MerchantDeliveriesPage extends ConsumerWidget {
  const MerchantDeliveriesPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final tontines = ref.watch(merchantDeliveryQueueProvider);
    final installments = ref.watch(merchantInstallmentQueueProvider);

    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Livraisons'),
          bottom: TabBar(
            labelColor: AppColors.primary700,
            unselectedLabelColor: AppColors.ink500,
            indicatorColor: AppColors.primary600,
            tabs: [
              Tab(text: tontines.valueOrNull == null ? 'Tontines' : 'Tontines (${tontines.value!.length})'),
              Tab(
                text: installments.valueOrNull == null
                    ? 'Tranches'
                    : 'Tranches (${installments.value!.length})',
              ),
            ],
          ),
        ),
        body: TabBarView(
          children: [
            _TontineQueue(state: tontines),
            _InstallmentQueue(state: installments),
          ],
        ),
      ),
    );
  }
}

/// File des livraisons de tontine, séparée entre « à confirmer » et « en
/// attente ».
///
/// La distinction n'est pas cosmétique : une livraison `awaiting_payment` n'est
/// pas livrable. La proposer quand même laisserait le commerçant remettre un
/// produit contre un versement que personne n'a validé.
class _TontineQueue extends ConsumerWidget {
  const _TontineQueue({required this.state});

  final AsyncValue<List<DeliveryItem>> state;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return state.when(
      loading: () => const LoadingView(label: 'Chargement des livraisons…'),
      error: (error, _) => ErrorView(
        message: '$error',
        onRetry: () => ref.invalidate(merchantDeliveryQueueProvider),
      ),
      data: (items) {
        final ready = items.where((item) => item.isEligible).toList();
        final blocked = items.where((item) => !item.isEligible && !item.isDelivered).toList();

        if (items.isEmpty) {
          return const EmptyView(
            icon: Icons.local_shipping_outlined,
            title: 'Aucune livraison',
            message: 'Les membres qui attendent leur produit apparaîtront ici.',
          );
        }

        return RefreshIndicator(
          onRefresh: () async => ref.refresh(merchantDeliveryQueueProvider.future),
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
            children: [
              if (ready.isEmpty)
                const _Banner(
                  icon: Icons.info_outline,
                  tone: AppTone.info,
                  text: 'Aucune livraison ne peut être confirmée pour l\'instant.',
                )
              else ...[
                _GroupTitle(label: 'À confirmer', count: ready.length),
                for (final item in ready)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: _DeliveryCard(item: item, onConfirm: () => _confirm(context, ref, item)),
                  ),
              ],

              if (blocked.isNotEmpty) ...[
                const SizedBox(height: 20),
                _GroupTitle(label: "En attente d'un versement", count: blocked.length),
                for (final item in blocked)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: _DeliveryCard(item: item),
                  ),
              ],
            ],
          ),
        );
      },
    );
  }

  Future<void> _confirm(BuildContext context, WidgetRef ref, DeliveryItem item) async {
    try {
      await ref.read(merchantRepositoryProvider).confirmTontineDelivery(item.id);

      ref.invalidate(merchantDeliveryQueueProvider);
      ref.invalidate(merchantInstallmentQueueProvider);
      ref.invalidate(merchantDashboardProvider);

      if (!context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Livraison confirmée pour ${item.userName}.')),
      );
    } on ApiException catch (error) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(error.message)),
      );
    }
  }
}

/// File des livraisons d'achats en tranches.
class _InstallmentQueue extends ConsumerWidget {
  const _InstallmentQueue({required this.state});

  final AsyncValue<List<InstallmentDelivery>> state;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return state.when(
      loading: () => const LoadingView(label: 'Chargement des livraisons…'),
      error: (error, _) => ErrorView(
        message: '$error',
        onRetry: () => ref.invalidate(merchantInstallmentQueueProvider),
      ),
      data: (items) {
        if (items.isEmpty) {
          return const EmptyView(
            icon: Icons.inventory_2_outlined,
            title: 'Aucune livraison',
            message: 'Les achats en tranches soldés apparaîtront ici.',
          );
        }

        return RefreshIndicator(
          onRefresh: () async => ref.refresh(merchantInstallmentQueueProvider.future),
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
            children: [
              for (final item in items)
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: _InstallmentCard(delivery: item, onConfirm: () => _confirm(context, ref, item)),
                ),
            ],
          ),
        );
      },
    );
  }

  Future<void> _confirm(
    BuildContext context,
    WidgetRef ref,
    InstallmentDelivery item,
  ) async {
    try {
      await ref.read(merchantRepositoryProvider).confirmInstallmentDelivery(item.id);

      ref.invalidate(merchantInstallmentQueueProvider);
      ref.invalidate(merchantDashboardProvider);

      if (!context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Livraison confirmée.')),
      );
    } on ApiException catch (error) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(error.message)),
      );
    }
  }
}

class _DeliveryCard extends StatelessWidget {
  const _DeliveryCard({required this.item, this.onConfirm});

  final DeliveryItem item;
  final VoidCallback? onConfirm;

  @override
  Widget build(BuildContext context) {
    final eligible = onConfirm != null;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                CircleAvatar(
                  backgroundColor: eligible ? AppColors.primary50 : AppColors.ink100,
                  child: Text(
                    Fmt.initials(item.userName),
                    style: TextStyle(
                      color: eligible ? AppColors.primary800 : AppColors.ink500,
                      fontWeight: FontWeight.w800,
                      fontSize: 13,
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        item.userName.isEmpty ? 'Membre' : item.userName,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.w800,
                          color: AppColors.ink900,
                        ),
                      ),
                      Text(
                        item.tontineName.isEmpty ? 'Tontine' : item.tontineName,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(color: AppColors.ink500, fontSize: 12),
                      ),
                    ],
                  ),
                ),
                if (item.isAwaitingPayment)
                  const AppBadge(label: 'Versement en attente', tone: AppTone.warning)
                else if (eligible)
                  const AppBadge(label: 'Prête', tone: AppTone.success, icon: Icons.check),
              ],
            ),
            const SizedBox(height: 12),

            _DetailRow(label: 'Produit', value: item.productName.isEmpty ? '—' : item.productName),
            _DetailRow(
              label: 'Tour',
              value: '${item.beneficiaryRound} · round actuel ${item.currentRound}',
            ),

            // Les obstacles sont expliqués, pas seulement comptés : « 2
            // blocages » ne dit pas quoi faire. Le serveur fournit les codes,
            // [deliveryBlockerLabel] les traduit.
            if (item.blockers.isNotEmpty) ...[
              const SizedBox(height: 10),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: AppColors.warning50,
                  borderRadius: BorderRadius.circular(AppRadius.sm),
                  border: Border.all(color: AppColors.warning200),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    for (final blocker in item.blockers)
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 2),
                        child: Text(
                          '• ${deliveryBlockerLabel(blocker)}',
                          style: const TextStyle(fontSize: 12, color: AppColors.ink700, height: 1.35),
                        ),
                      ),
                  ],
                ),
              ),
            ],

            if (eligible) ...[
              const SizedBox(height: 14),
              SizedBox(
                width: double.infinity,
                child: FilledButton.icon(
                  onPressed: onConfirm,
                  icon: const Icon(Icons.check, size: 18),
                  label: const Text('Confirmer la remise'),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _InstallmentCard extends StatelessWidget {
  const _InstallmentCard({required this.delivery, required this.onConfirm});

  final InstallmentDelivery delivery;
  final VoidCallback onConfirm;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const CircleAvatar(
                  backgroundColor: AppColors.accent50,
                  child: Icon(Icons.schedule_outlined, size: 18, color: AppColors.accent700),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        delivery.productName.isEmpty ? 'Achat en tranches' : delivery.productName,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.w800,
                          color: AppColors.ink900,
                        ),
                      ),
                      Text(
                        delivery.userName.isEmpty ? 'Client' : delivery.userName,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(color: AppColors.ink500, fontSize: 12),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            _DetailRow(label: 'Tranches', value: '${delivery.installmentsCount}'),

            const SizedBox(height: 14),
            SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: onConfirm,
                icon: const Icon(Icons.check, size: 18),
                label: const Text('Confirmer la remise'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _DetailRow extends StatelessWidget {
  const _DetailRow({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 96,
            child: Text(label, style: const TextStyle(color: AppColors.ink500, fontSize: 12)),
          ),
          Expanded(
            child: Text(
              value,
              style: const TextStyle(
                color: AppColors.ink900,
                fontSize: 12,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _GroupTitle extends StatelessWidget {
  const _GroupTitle({required this.label, required this.count});

  final String label;
  final int count;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        children: [
          Text(
            label,
            style: const TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w800,
              color: AppColors.ink900,
            ),
          ),
          const SizedBox(width: 8),
          AppBadge(label: '$count', tone: AppTone.neutral),
        ],
      ),
    );
  }
}

class _Banner extends StatelessWidget {
  const _Banner({required this.icon, required this.tone, required this.text});

  final IconData icon;
  final AppTone tone;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: tone.background,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: tone.border),
      ),
      child: Row(
        children: [
          Icon(icon, size: 18, color: tone.foreground),
          const SizedBox(width: 10),
          Expanded(
            child: Text(text, style: TextStyle(fontSize: 13, color: tone.foreground, height: 1.4)),
          ),
        ],
      ),
    );
  }
}