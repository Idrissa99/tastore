import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/core/widgets/app_badge.dart';
import 'package:tontine_achat_store/core/widgets/avatar.dart';
import 'package:tontine_achat_store/core/widgets/network_image.dart';
import 'package:tontine_achat_store/core/widgets/progress_bar.dart';
import 'package:tontine_achat_store/core/widgets/state_views.dart';
import 'package:tontine_achat_store/features/contributions/contribution.dart';
import 'package:tontine_achat_store/features/payments/payment_sheet.dart';
import 'package:tontine_achat_store/features/purchases/installment_purchase.dart';
import 'package:tontine_achat_store/features/purchases/purchase_providers.dart';

/// Fiche d'un achat en tranches, et paiement tranche par tranche.
///
/// Le serveur renvoie l'ACHAT entier après le paiement d'une tranche, pas la
/// tranche : c'est cette réponse qui remplace l'affichage. Aucun second
/// aller-retour n'est nécessaire, et l'écran ne peut pas montrer un compte de
/// tranches payées qui contredirait le bouton que l'utilisateur vient de
/// toucher.
class PurchaseDetailPage extends ConsumerStatefulWidget {
  const PurchaseDetailPage({super.key, required this.purchaseId});

  final int purchaseId;

  @override
  ConsumerState<PurchaseDetailPage> createState() => _PurchaseDetailPageState();
}

class _PurchaseDetailPageState extends ConsumerState<PurchaseDetailPage> {
  /// Copie affichée, remplacée par la réponse du serveur après un paiement.
  InstallmentPurchase? _paid;

  Future<void> _pay(InstallmentPurchase purchase, Installment installment) async {
    final sent = await PaymentSheet.show(
      context,
      title: 'Tranche ${installment.number}',
      subtitle: purchase.title,
      amount: installment.amount,
      submitLabel: 'Confirmer le versement',
      onSubmit: (channel, reference, transferCode) async {
        final repository = ref.read(purchaseRepositoryProvider);

        final updated = transferCode == null
            ? await repository.payInstallment(
                installment.id,
                channel: channel,
                reference: reference,
              )
            : await repository.submitInstallmentCode(
                installment.id,
                channel: channel,
                transferCode: transferCode,
              );

        if (!mounted) return;
        setState(() => _paid = updated);

        // La liste des achats porte le même compte de tranches payées : elle
        // deviendrait fausse sinon.
        ref.invalidate(purchasesProvider);
      },
    );

    if (!sent || !mounted) return;

    final updated = _paid;
    if (updated == null) return;

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          updated.nextInstallment == null
              ? 'Toutes les tranches sont réglées.'
              : 'Tranche enregistrée.',
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final purchase = ref.watch(purchaseDetailProvider(widget.purchaseId));

    return Scaffold(
      appBar: AppBar(title: const Text('Achat en tranches')),
      body: purchase.when(
        loading: () => const LoadingView(label: 'Chargement de l\'achat…'),
        error: (error, _) => ErrorView(
          message: '$error',
          onRetry: () => ref.invalidate(purchaseDetailProvider(widget.purchaseId)),
        ),
        data: (fetched) {
          final data = _paid ?? fetched;

          return ListView(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
            children: [
              _Header(purchase: data),
              const SizedBox(height: 20),

              Text('Tranches', style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: 10),

              for (final installment in data.installments)
                _InstallmentTile(
                  installment: installment,
                  onPay: installment.isPayable ? () => _pay(data, installment) : null,
                ),
            ],
          );
        },
      ),
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.purchase});

  final InstallmentPurchase purchase;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (purchase.product?.coverUrl != null)
                  ClipRRect(
                    borderRadius: BorderRadius.circular(AppRadius.sm),
                    child: SizedBox(
                      width: 72,
                      height: 72,
                      child: AppNetworkImage(url: purchase.product!.coverUrl),
                    ),
                  ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        purchase.title,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.w800,
                          color: AppColors.ink900,
                          height: 1.25,
                        ),
                      ),
                      const SizedBox(height: 6),
                      Wrap(
                        spacing: 6,
                        runSpacing: 6,
                        children: [
                          AppBadge(
                            label: purchase.deliveryStatus.label,
                            tone: purchase.deliveryStatus.tone,
                          ),
                          if (purchase.isFullyPaid)
                            const AppBadge(
                              label: 'Entièrement payé',
                              tone: AppTone.success,
                              icon: Icons.check,
                            ),
                        ],
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 16),
            _Row(label: 'Prix du produit', value: Fmt.fcfa(purchase.productPrice)),
            const SizedBox(height: 6),
            _Row(label: 'Montant par tranche', value: Fmt.fcfa(purchase.installmentAmount)),
            const SizedBox(height: 6),
            _Row(
              label: 'Total du plan',
              value: Fmt.fcfa(purchase.installmentAmount * purchase.installmentsCount),
              strong: true,
            ),
            const SizedBox(height: 14),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  '${purchase.paidInstallmentsCount}/${purchase.installmentsCount} tranches',
                  style: const TextStyle(fontSize: 12, color: AppColors.ink600),
                ),
                Text(
                  '${Fmt.fcfa(purchase.installmentAmount * purchase.paidInstallmentsCount)} versés',
                  style: const TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    color: AppColors.primary800,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 6),
            ProgressBar(value: purchase.paidRatio),
          ],
        ),
      ),
    );
  }
}

class _InstallmentTile extends StatelessWidget {
  const _InstallmentTile({required this.installment, this.onPay});

  final Installment installment;
  final VoidCallback? onPay;

  @override
  Widget build(BuildContext context) {
    final manual = installment.verificationStatus != VerificationStatus.notApplicable;
    final payable = installment.isPayable && onPay != null;

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              AppAvatar(name: 'Tranche ${installment.number}', size: 38),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Tranche ${installment.number}',
                      style: const TextStyle(
                        fontSize: 14,
                        fontWeight: FontWeight.w700,
                        color: AppColors.ink900,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Wrap(
                      spacing: 6,
                      runSpacing: 4,
                      children: [
                        AppBadge(label: installment.status.label, tone: installment.status.tone),
                        if (manual)
                          AppBadge(
                            label: installment.verificationStatus.label,
                            tone: installment.verificationStatus.tone,
                          ),
                      ],
                    ),
                    if (installment.paidAt != null) ...[
                      const SizedBox(height: 4),
                      Text(
                        'Réglée le ${Fmt.date(installment.paidAt)}',
                        style: const TextStyle(fontSize: 11, color: AppColors.ink400),
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(width: 10),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    Fmt.fcfa(installment.amount),
                    style: const TextStyle(
                      fontSize: 14,
                      fontWeight: FontWeight.w800,
                      color: AppColors.ink900,
                    ),
                  ),
                  if (payable)
                    TextButton(
                      onPressed: onPay,
                      style: TextButton.styleFrom(
                        padding: const EdgeInsets.symmetric(horizontal: 10),
                        minimumSize: const Size(0, 32),
                      ),
                      child: const Text('Payer'),
                    ),
                ],
              ),
            ],
          ),
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
