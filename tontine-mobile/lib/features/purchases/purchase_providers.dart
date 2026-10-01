import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:tontine_achat_store/core/config/app_config.dart';
import 'package:tontine_achat_store/core/di/providers.dart';
import 'package:tontine_achat_store/features/purchases/installment_purchase.dart';
import 'package:tontine_achat_store/features/purchases/purchase_repository.dart';
import 'package:tontine_achat_store/features/tontines/tontine.dart';

final purchaseRepositoryProvider = Provider<PurchaseRepository>(
  (ref) => PurchaseRepository(api: ref.watch(apiClientProvider)),
);

/* ------------------------------------------------------------------ */
/* Catalogue produits                                                  */
/* ------------------------------------------------------------------ */

/// Catalogue produits.
///
/// Contrairement aux tontines, le serveur sait appliquer la recherche (`q`) et
/// la catégorie : il est donc interrogé à chaque changement de critère, et rien
/// n'est filtré côté client. Aucun état intermédiaire n'est nécessaire non
/// plus.
class ProductsController extends AutoDisposeAsyncNotifier<PagedResult<Product>> {
  String _query = '';
  String? _category;

  @override
  Future<PagedResult<Product>> build() async {
    return ref.read(purchaseRepositoryProvider).products(
          query: _query.isEmpty ? null : _query,
          category: _category,
        );
  }

  Future<void> search(String value) async {
    if (value == _query) return;

    _query = value;
    state = const AsyncLoading<PagedResult<Product>>().copyWithPrevious(state);
    state = await AsyncValue.guard(() => ref.read(purchaseRepositoryProvider).products(query: value));
  }

  bool isCategory(String category) => _category == category;

  bool get isFilteringCategory => _category != null;

  Future<void> filterCategory(String? category) async {
    if (category == _category) return;

    _category = category;
    state = const AsyncLoading<PagedResult<Product>>().copyWithPrevious(state);
    state = await AsyncValue.guard(
      () => ref.read(purchaseRepositoryProvider).products(query: _query, category: category),
    );
  }
}

/// `autoDispose` : un prix ou un stock modifié par le commerçant doit se
/// voir au retour sur le catalogue.
final productsProvider =
    AutoDisposeAsyncNotifierProvider<ProductsController, PagedResult<Product>>(
  ProductsController.new,
);

/// Catégories proposées en filtre.
///
/// Elles sont déduites des produits CHARGÉS plutôt que d'une liste figée :
/// le backend ne publie pas de catalogue de catégories, et une liste écrite en
/// dur proposerait des filtres vides.
/// Fiche d'un produit. `autoDispose` : une fiche quittée n'a pas à rester en
/// mémoire, et surtout à devenir périmée.
final productDetailProvider = FutureProvider.autoDispose.family<Product, int>((ref, id) async {
  return ref.watch(purchaseRepositoryProvider).product(id);
});

final productCategoriesProvider = Provider<List<String>>((ref) {
  final page = ref.watch(productsProvider).valueOrNull;

  return (page?.items ?? const <Product>[])
      .map((product) => product.category)
      .where((category) => category.isNotEmpty)
      .toSet()
      .toList(growable: false)
    ..sort();
});

/* ------------------------------------------------------------------ */
/* Achats en tranches                                                  */
/* ------------------------------------------------------------------ */

/// `autoDispose` : une tranche payée ailleurs ne doit pas rester « à payer »
/// hors du contexte de l'écran.
final purchasesProvider = FutureProvider.autoDispose<List<InstallmentPurchase>>((ref) async {
  return ref.watch(purchaseRepositoryProvider).purchases();
});

final purchaseDetailProvider =
    FutureProvider.autoDispose.family<InstallmentPurchase, int>((ref, id) async {
  return ref.watch(purchaseRepositoryProvider).purchase(id);
});

/* ------------------------------------------------------------------ */
/* Achat                                                               */
/* ------------------------------------------------------------------ */

/// Le nombre de tranches proposé, et l'aperçu correspondant.
///
/// L'aperçu est demandé à chaque changement : il est calculé par le serveur,
/// commission comprise. Le diviser soi-même donnerait un montant différent de
/// celui qui sera débité — précisément le chiffre sur lequel l'utilisateur
/// s'engage.
class PurchaseDraft {
  const PurchaseDraft({required this.installmentsCount, required this.preview});

  final int installmentsCount;
  final InstallmentPreview? preview;
}

final purchaseDraftProvider = StateProvider<PurchaseDraft>((ref) {
  return const PurchaseDraft(installmentsCount: 3, preview: null);
});

final installmentPreviewProvider = FutureProvider.autoDispose
    .family<InstallmentPreview, ({int productId, int count})>((ref, args) async {
  return ref.watch(purchaseRepositoryProvider).preview(args.productId, installmentsCount: args.count);
});

/// Bornes du nombre de tranches, imposées par `StoreInstallmentPurchaseRequest`
/// côté backend (`min:2`, `max:24`).
///
/// Elles sont dupliquées ici pour rendre le sélecteur honnête : proposer 30
/// tranches n'aurait pour seule conséquence un 422.
const minInstallments = 2;
const maxInstallments = 24;

/// Le nombre de tranches par défaut.
///
/// Six tranches : assez pour que chaque versement reste supportable, assez peu
/// pour que le cycle tienne dans l'année.
const defaultInstallments = 6;

/// Taille de page du catalogue produits, alignée sur le backend
/// (`GET /produits` pagine à 12).
const productsPageSize = AppConfig.pageSize;
