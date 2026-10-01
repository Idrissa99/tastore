import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:tontine_achat_store/core/di/providers.dart';
import 'package:tontine_achat_store/features/merchant/merchant_models.dart';
import 'package:tontine_achat_store/features/merchant/merchant_repository.dart';

final merchantRepositoryProvider = Provider<MerchantRepository>(
  (ref) => MerchantRepository(api: ref.watch(apiClientProvider)),
);

/* ------------------------------------------------------------------ */
/* Tableau de bord                                                      */
/* ------------------------------------------------------------------ */

/// `autoDispose` : les chiffres du tableau de bord vieillissent vite, et un
/// commerçant qui revient d'une livraison doit les voir recalculés plutôt que
/// rejouer un cache de l'avant.
final merchantDashboardProvider = FutureProvider.autoDispose<MerchantDashboard>((ref) async {
  return ref.watch(merchantRepositoryProvider).dashboard();
});

/* ------------------------------------------------------------------ */
/* Produits                                                            */
/* ------------------------------------------------------------------ */

/// Le catalogue du vendeur.
///
/// `autoDispose` pour la même raison que le tableau de bord : un stock modifié
/// dans la liste de produits — ou ailleurs — doit se voir au retour.
final merchantProductsProvider = FutureProvider.autoDispose<List<MerchantProduct>>((ref) async {
  return ref.watch(merchantRepositoryProvider).products();
});

/* ------------------------------------------------------------------ */
/* Livraisons                                                          */
/* ------------------------------------------------------------------ */

/// File des livraisons de tontine, à l'exclusion de celles déjà remises.
///
/// Le tri est fait ici plutôt que sur l'écran : « ce qui attend une action »
/// est une propriété de la donnée — le serveur a calculé [DeliveryItem.isEligible]
/// — et non une préférence d'affichage.
final merchantDeliveryQueueProvider = FutureProvider.autoDispose<List<DeliveryItem>>((ref) async {
  final items = await ref.watch(merchantRepositoryProvider).deliveryQueue();

  return items.where((item) => !item.isDelivered).toList(growable: false);
});

final merchantInstallmentQueueProvider =
    FutureProvider.autoDispose<List<InstallmentDelivery>>((ref) async {
  final items = await ref.watch(merchantRepositoryProvider).installmentDeliveryQueue();

  return items.where((item) => !item.isDelivered).toList(growable: false);
});