import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';
import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/core/network/api_exception.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/core/widgets/app_badge.dart';
import 'package:tontine_achat_store/core/widgets/network_image.dart';
import 'package:tontine_achat_store/core/widgets/state_views.dart';
import 'package:tontine_achat_store/features/merchant/merchant_models.dart';
import 'package:tontine_achat_store/features/merchant/merchant_providers.dart';
import 'package:tontine_achat_store/features/merchant/merchant_repository.dart';
import 'package:tontine_achat_store/routing/app_router.dart';

/// Catalogue du commerçant : ses produits, leur stock, leur publication.
///
/// Le stock s'édite **sur place**, sans ouvrir la fiche : c'est l'action la plus
/// fréquente en boutique, et elle ne devrait pas coûter une navigation par
/// correction. Le reste passe par la fiche d'édition.
class MerchantProductsPage extends ConsumerWidget {
  const MerchantProductsPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final products = ref.watch(merchantProductsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Mes produits')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => context.push(AppRoutes.merchantProductCreate),
        icon: const Icon(Icons.add),
        label: const Text('Nouveau produit'),
      ),
      body: RefreshIndicator(
        onRefresh: () async => ref.refresh(merchantProductsProvider.future),
        child: products.when(
          loading: () => const LoadingView(label: 'Chargement de ton catalogue…'),
          error: (error, _) => ErrorView(
            message: '$error',
            onRetry: () => ref.invalidate(merchantProductsProvider),
          ),
          data: (items) {
            if (items.isEmpty) {
              // `ListView` et non `EmptyView` : ce dernier est centré et non
              // défilable, ce qui empêche le RefreshIndicator de se déclencher
              // quand il est le seul contenu de l'écran.
              return ListView(
                children: const [
                  SizedBox(height: 80),
                  EmptyView(
                    icon: Icons.inventory_2_outlined,
                    title: 'Aucun produit',
                    message: 'Ajoute ton premier produit pour commencer à vendre.',
                  ),
                ],
              );
            }

            return ListView.separated(
              padding: const EdgeInsets.fromLTRB(16, 14, 16, 90),
              itemCount: items.length,
              separatorBuilder: (_, __) => const SizedBox(height: 12),
              itemBuilder: (context, index) => _ProductCard(product: items[index]),
            );
          },
        ),
      ),
    );
  }
}

class _ProductCard extends ConsumerWidget {
  const _ProductCard({required this.product});

  final MerchantProduct product;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => context.push(AppRoutes.merchantProductEdit(product.id)),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            AspectRatio(
              aspectRatio: 16 / 9,
              child: Stack(
                fit: StackFit.expand,
                children: [
                  AppNetworkImage(url: product.coverUrl, fallbackLabel: 'Pas de photo'),
                  if (product.isOutOfStock)
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
              padding: const EdgeInsets.fromLTRB(14, 12, 14, 14),
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
                  const SizedBox(height: 8),
                  Wrap(
                    spacing: 6,
                    runSpacing: 6,
                    children: [
                      AppBadge(label: product.status.label, tone: statusTone(product.status)),
                      if (product.category.isNotEmpty)
                        AppBadge(
                          label: Fmt.capitalize(product.category),
                          tone: AppTone.neutral,
                        ),
                      if (product.tontinesCount > 0)
                        AppBadge(
                          label: '${Fmt.plural(product.tontinesCount, 'tontine')} — linked',
                          tone: AppTone.info,
                          icon: Icons.link,
                        ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    crossAxisAlignment: CrossAxisAlignment.center,
                    children: [
                      Text(
                        Fmt.fcfa(product.price),
                        style: const TextStyle(
                          fontSize: 17,
                          fontWeight: FontWeight.w800,
                          color: AppColors.primary800,
                        ),
                      ),
                      _StockField(product: product),
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

/// Palette du statut de publication.
///
/// `draft` et `archived` ne sont pas la même chose : le premier est en
/// préparation, le second a été volontairement retiré de la vente. Les
/// distinguer évite qu'un vendeur cherche pourquoi son produit « en cours »
/// n'apparaît pas dans le catalogue.
AppTone statusTone(ProductStatus status) => switch (status) {
      ProductStatus.published => AppTone.success,
      ProductStatus.draft => AppTone.warning,
      ProductStatus.archived => AppTone.neutral,
    };

/// Champ de stock éditable en place.
///
/// La modification part au `onSubmitted` et non à chaque frappe : une requête
/// par caractère saturerait le serveur et, en cas de perte de réseau, effacerait
/// des stocks partiels. Un vendeur qui corrige « 3 » en « 30 » voit la valeur
/// rester locale jusqu'à sa validation.
class _StockField extends ConsumerStatefulWidget {
  const _StockField({required this.product});

  final MerchantProduct product;

  @override
  ConsumerState<_StockField> createState() => _StockFieldState();
}

class _StockFieldState extends ConsumerState<_StockField> {
  late final TextEditingController _controller =
      TextEditingController(text: '${widget.product.stock}');

  bool _saving = false;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final parsed = int.tryParse(_controller.text.trim());
    if (parsed == null || parsed < 0 || parsed == widget.product.stock) return;

    try {
      setState(() => _saving = true);
      await ref
          .read(merchantRepositoryProvider)
          .updateStock(widget.product.id, parsed);

      // Le catalogue est relu plutôt que corrigé localement : la réponse du
      // serveur fait autorité, et c'est elle qui peut refuser.
      ref.invalidate(merchantProductsProvider);
      ref.invalidate(merchantDashboardProvider);

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Stock mis à jour : $parsed.')),
      );
    } on ApiException catch (error) {
      if (!mounted) return;
      // Le champ est remis à la valeur du serveur : sans cela, l'écran
      // continuerait d'afficher un stock que l'API n'a jamais accepté.
      _controller.text = '${widget.product.stock}';
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(error.message)),
      );
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 96,
      child: TextField(
        controller: _controller,
        enabled: !_saving,
        keyboardType: TextInputType.number,
        textAlign: TextAlign.end,
        onSubmitted: (_) => _submit(),
        inputFormatters: [FilteringTextInputFormatter.digitsOnly],
        decoration: InputDecoration(
          labelText: 'Stock',
          isDense: true,
          suffixIcon: _saving
              ? const Padding(
                  padding: EdgeInsets.all(10),
                  child: SizedBox(
                    width: 14,
                    height: 14,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  ),
                )
              : null,
        ),
      ),
    );
  }
}

/// Création et modification d'un produit.
///
/// Un seul écran pour les deux : le formulaire est identique, et la seule
/// différence tient à l'identifiant. Dupliquer un formulaire pour un
/// paramètre, c'est garantir que les deux copies divergent — et qu'une
/// correction de validation n'est appliquée qu'à l'une.
///
/// **Tous les champs sont envoyés, toujours.** `StoreProductRequest` sert la
/// création et la mise à jour : un formulaire d'édition qui n'enverrait que le
/// nom se ferait refuser en 422 sur `price`. L'édition part donc du produit
/// existant, jamais d'un formulaire vide.
class MerchantProductEditPage extends ConsumerStatefulWidget {
  const MerchantProductEditPage({super.key, this.productId});

  /// Nul en création, renseigné en modification.
  final int? productId;

  @override
  ConsumerState<MerchantProductEditPage> createState() => _MerchantProductEditPageState();
}

class _MerchantProductEditPageState extends ConsumerState<MerchantProductEditPage> {
  final _formKey = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _description = TextEditingController();
  final _category = TextEditingController();
  final _price = TextEditingController();
  final _stock = TextEditingController();

  ProductStatus _status = ProductStatus.draft;
  final List<String> _imagePaths = [];
  String? _videoPath;

  MerchantProduct? _existing;
  bool _loading = false;
  bool _saving = false;
  bool _initialized = false;
  String? _loadError;

  bool get _isEditing => widget.productId != null;

  @override
  void initState() {
    super.initState();
    if (_isEditing) _load();
  }

  @override
  void dispose() {
    _name.dispose();
    _description.dispose();
    _category.dispose();
    _price.dispose();
    _stock.dispose();
    super.dispose();
  }

  /// Le backend n'expose PAS `GET /merchant/products/{id}`.
  ///
  /// Le catalogue est donc relu et filtré sur l'identifiant, comme le fait
  /// l'écran web. Le prix est renvoyé en chaîne décimale par la ressource, et
  /// les deux seuls points possibles d'un nombre sans zéros inutiles.
  Future<void> _load() async {
    setState(() {
      _loading = true;
      _loadError = null;
    });

    try {
      final items = await ref.read(merchantRepositoryProvider).products();
      final found = items.where((item) => item.id == widget.productId).firstOrNull;

      if (found == null) {
        setState(() => _loadError = "Ce produit n'existe plus dans ton catalogue.");
        return;
      }

      setState(() {
        _existing = found;
        _name.text = found.name;
        _description.text = found.description;
        _category.text = found.category;
        _price.text = _amountToText(found.price);
        _stock.text = '${found.stock}';
        _status = found.status;
        _initialized = true;
      });
    } on ApiException catch (error) {
      if (mounted) setState(() => _loadError = error.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  /// « 15000.00 » plutôt que « 15000 » : c'est ce que l'API renvoie, et le
  /// champ ne sert qu'à être renvoyé tel quel.
  String _amountToText(double value) =>
      value == value.roundToDouble() ? value.toStringAsFixed(0) : value.toStringAsFixed(2);

  Future<void> _pickImages() async {
    final remaining = maxImagesPerUpload - _imagePaths.length;
    if (remaining <= 0) return;

    final picked = await ImagePicker().pickMultiImage(limit: remaining);

    for (final file in picked.take(remaining)) {
      final oversized = await file.length() > maxImageKilobytes * 1024;
      if (oversized) {
        if (!mounted) return;
        _toast('Photo trop lourde : 5 Mo maximum par image.');
        continue;
      }
      _imagePaths.add(file.path);
    }

    if (mounted) setState(() {});
  }

  Future<void> _pickVideo() async {
    final picked = await ImagePicker().pickVideo(source: ImageSource.gallery);

    if (picked == null) return;

    if (await picked.length() > maxVideoKilobytes * 1024) {
      if (mounted) _toast('Vidéo trop lourde : 50 Mo maximum.');
      return;
    }

    setState(() => _videoPath = picked.path);
  }

  void _toast(String message) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
  }

  Future<void> _submit() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;

    final price = Fmt.toDouble(_price.text);

    setState(() => _saving = true);

    final draft = ProductDraft(
      name: _name.text,
      description: _description.text,
      category: _category.text,
      price: price,
      stock: Fmt.toInt(_stock.text),
      status: _status,
      imagePaths: List.unmodifiable(_imagePaths),
      videoPath: _videoPath,
    );

    try {
      final repository = ref.read(merchantRepositoryProvider);
      if (_isEditing) {
        await repository.updateProduct(widget.productId!, draft);
      } else {
        await repository.createProduct(draft);
      }

      ref.invalidate(merchantProductsProvider);
      ref.invalidate(merchantDashboardProvider);

      if (!mounted) return;
      _toast(_isEditing ? 'Produit mis à jour.' : 'Produit créé.');
      context.pop();
    } on ApiException catch (error) {
      if (mounted) _toast(error.message);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _confirmDelete() async {
    final product = _existing;
    if (product == null) return;

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Supprimer ce produit ?'),
        content: const Text(
          'Cette action est définitive. Si le produit est lié à une tontine '
          'existante, le serveur refusera : archive-le plutôt.',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Annuler')),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            style: FilledButton.styleFrom(backgroundColor: AppColors.danger500),
            child: const Text('Supprimer'),
          ),
        ],
      ),
    );

    if (confirmed != true || !mounted) return;

    try {
      await ref.read(merchantRepositoryProvider).deleteProduct(product.id);
      ref.invalidate(merchantProductsProvider);
      ref.invalidate(merchantDashboardProvider);

      if (!mounted) return;
      _toast('Produit supprimé.');
      context.pop();
    } on ApiException catch (error) {
      if (mounted) _toast(error.message);
    }
  }

  Future<void> _deleteMedia(ProductMedia media) async {
    try {
      await ref.read(merchantRepositoryProvider).deleteMedia(media.id);
      await _load();
      if (mounted) _toast(media.isVideo ? 'Vidéo supprimée.' : 'Photo supprimée.');
    } on ApiException catch (error) {
      if (mounted) _toast(error.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final existing = _existing;

    return Scaffold(
      appBar: AppBar(
        title: Text(_isEditing ? 'Modifier le produit' : 'Nouveau produit'),
        actions: [
          // La suppression n'a de sens que sur un produit déjà enregistré :
          // en création, il n'existe pas encore côté serveur.
          if (existing != null)
            IconButton(
              tooltip: 'Supprimer',
              icon: const Icon(Icons.delete_outline),
              onPressed: _saving ? null : _confirmDelete,
            ),
        ],
      ),
      body: _loading
          ? const LoadingView(label: 'Chargement du produit…')
          : _loadError != null
              ? ErrorView(message: _loadError!, onRetry: _load)
              : _initialized || !_isEditing
                  ? _form(existing)
                  : const LoadingView(),
      bottomNavigationBar: (_loading || _loadError != null || (_isEditing && !_initialized))
          ? null
          : SafeArea(
              minimum: const EdgeInsets.fromLTRB(16, 0, 16, 12),
              child: FilledButton(
                onPressed: _saving ? null : _submit,
                child: _saving
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(
                          strokeWidth: 2.2,
                          valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
                        ),
                      )
                    : Text(_isEditing ? 'Enregistrer' : 'Créer le produit'),
              ),
            ),
    );
  }

  Widget _form(MerchantProduct? existing) {
    return Form(
      key: _formKey,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
        children: [
          if (existing != null) ...[
            _MediaStrip(existing: existing, onDelete: _deleteMedia),
            const SizedBox(height: 18),
          ],

          TextFormField(
            controller: _name,
            enabled: !_saving,
            textCapitalization: TextCapitalization.sentences,
            decoration: const InputDecoration(labelText: 'Nom du produit'),
            validator: (value) =>
                (value ?? '').trim().isEmpty ? 'Renseigne le nom du produit.' : null,
          ),
          const SizedBox(height: 14),

          TextFormField(
            controller: _description,
            enabled: !_saving,
            maxLines: 4,
            textCapitalization: TextCapitalization.sentences,
            decoration: const InputDecoration(labelText: 'Description'),
          ),
          const SizedBox(height: 14),

          TextFormField(
            controller: _category,
            enabled: !_saving,
            textCapitalization: TextCapitalization.words,
            decoration: const InputDecoration(
              labelText: 'Catégorie',
              helperText: 'Facultatif. Aide les clients à trouver le produit.',
            ),
          ),
          const SizedBox(height: 14),

          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: TextFormField(
                  controller: _price,
                  enabled: !_saving,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  decoration: const InputDecoration(labelText: 'Prix'),
                  validator: (value) {
                    final amount = Fmt.toDouble(value);
                    if (amount <= 0) return 'Prix obligatoire.';
                    return null;
                  },
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: TextFormField(
                  controller: _stock,
                  enabled: !_saving,
                  keyboardType: TextInputType.number,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  decoration: const InputDecoration(labelText: 'Stock'),
                  validator: (value) =>
                      Fmt.toInt(value) < 0 ? 'Stock invalide.' : null,
                ),
              ),
            ],
          ),
          const SizedBox(height: 18),

          const Text(
            'Visibilité',
            style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13),
          ),
          const SizedBox(height: 8),
          // Le statut est ici un choix explicite et non une liste figée : les
          // trois valeurs sont celles que le serveur valide, et un vendeur doit
          // pouvoir publier ou archiver depuis le même écran.
          SegmentedButton<ProductStatus>(
            segments: [
              for (final status in ProductStatus.values)
                ButtonSegment(value: status, label: Text(status.label)),
            ],
            selected: {_status},
            onSelectionChanged: (selection) => setState(() => _status = selection.first),
          ),
          const SizedBox(height: 6),
          Text(
            _status == ProductStatus.published
                ? 'Le produit apparaîtra dans le catalogue public.'
                : _status == ProductStatus.draft
                    ? "Le produit reste invisible du catalogue tant qu'il n'est pas publié."
                    : 'Le produit est retiré du catalogue sans être supprimé.',
            style: const TextStyle(fontSize: 12, color: AppColors.ink500, height: 1.4),
          ),
          const SizedBox(height: 22),

          const Text(
            'Photos',
            style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13),
          ),
          const SizedBox(height: 4),
          const Text(
            'Jusqu\'à $maxImagesPerUpload photos par envoi, 5 Mo chacune. '
            'Elles s\'ajoutent aux photos déjà en ligne.',
            style: TextStyle(fontSize: 12, color: AppColors.ink500, height: 1.4),
          ),
          const SizedBox(height: 10),
          if (_imagePaths.isNotEmpty) _PendingStrip(paths: _imagePaths, onRemove: _removePending),
          OutlinedButton.icon(
            onPressed: _saving ? null : _pickImages,
            icon: const Icon(Icons.add_photo_alternate_outlined, size: 18),
            label: const Text('Ajouter des photos'),
          ),
          const SizedBox(height: 22),

          const Text(
            'Vidéo',
            style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13),
          ),
          const SizedBox(height: 10),
          if (existing?.video != null)
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.movie_outlined),
              title: const Text('Vidéo en ligne'),
              trailing: IconButton(
                tooltip: 'Supprimer',
                icon: const Icon(Icons.close, size: 18),
                onPressed: () => _deleteMedia(existing!.video!),
              ),
            ),
          OutlinedButton.icon(
            onPressed: _saving ? null : _pickVideo,
            icon: const Icon(Icons.videocam_outlined, size: 18),
            label: Text(_videoPath == null ? 'Ajouter une vidéo' : 'Remplacer la vidéo'),
          ),
        ],
      ),
    );
  }

  void _removePending(String path) {
    setState(() => _imagePaths.remove(path));
  }
}

/// Photos et vidéo déjà en ligne, avec leur suppression.
///
/// La suppression passe par l'identifiant du média et non par son URL :
/// l'API expose `DELETE /merchant/products/media/{id}`, et l'URL affichée à
/// l'écran n'est pas une clé d'API.
class _MediaStrip extends StatelessWidget {
  const _MediaStrip({required this.existing, required this.onDelete});

  final MerchantProduct existing;
  final Future<void> Function(ProductMedia media) onDelete;

  @override
  Widget build(BuildContext context) {
    if (existing.images.isEmpty && existing.video == null) {
      return const Card(
        child: Padding(
          padding: EdgeInsets.all(16),
          child: Row(
            children: [
              Icon(Icons.image_not_supported_outlined, size: 20, color: AppColors.ink400),
              SizedBox(width: 12),
              Expanded(
                child: Text(
                  'Aucune photo en ligne. Les clients voient un cadre vide sur la fiche.',
                  style: TextStyle(color: AppColors.ink600, fontSize: 13, height: 1.4),
                ),
              ),
            ],
          ),
        ),
      );
    }

    return SizedBox(
      height: 96,
      child: ListView(
        scrollDirection: Axis.horizontal,
        children: [
          for (final media in existing.images)
            _Thumb(url: media.url, onDelete: () => onDelete(media)),
          if (existing.video case final video?)
            _Thumb(
              url: video.url,
              label: 'Vidéo',
              onDelete: () => onDelete(video),
            ),
        ],
      ),
    );
  }
}

class _Thumb extends StatelessWidget {
  const _Thumb({required this.url, required this.onDelete, this.label});

  final String url;
  final VoidCallback onDelete;
  final String? label;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(right: 10),
      child: Stack(
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(AppRadius.md),
            child: SizedBox(
              width: 128,
              height: 96,
              child: label != null
                  ? Container(
                      color: AppColors.ink100,
                      child: Center(
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Icon(Icons.movie_outlined, size: 22, color: AppColors.ink500),
                            const SizedBox(height: 4),
                            Text(label!, style: const TextStyle(fontSize: 11, color: AppColors.ink600)),
                          ],
                        ),
                      ),
                    )
                  : AppNetworkImage(url: url),
            ),
          ),
          Positioned(
            top: 2,
            right: 2,
            child: InkWell(
              onTap: onDelete,
              child: Container(
                padding: const EdgeInsets.all(3),
                decoration: BoxDecoration(
                  color: AppColors.ink900.withValues(alpha: 0.6),
                  shape: BoxShape.circle,
                ),
                child: const Icon(Icons.close, size: 14, color: Colors.white),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Photos sélectionnées mais pas encore envoyées.
///
/// Elles sont montrées avant l'enregistrement : choisir six photos, voir un
/// formulaire vide et ne les voir réapparaître qu'après coup ferait croire
/// qu'elles ont été perdues.
class _PendingStrip extends StatelessWidget {
  const _PendingStrip({required this.paths, required this.onRemove});

  final List<String> paths;
  final ValueChanged<String> onRemove;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: SizedBox(
        height: 88,
        child: ListView(
          scrollDirection: Axis.horizontal,
          children: [
            for (final path in paths)
              Padding(
                padding: const EdgeInsets.only(right: 8),
                child: Stack(
                  children: [
                    ClipRRect(
                      borderRadius: BorderRadius.circular(AppRadius.sm),
                      child: Image.file(
                        File(path),
                        width: 88,
                        height: 88,
                        fit: BoxFit.cover,
                        errorBuilder: (_, __, ___) => Container(
                          width: 88,
                          height: 88,
                          color: AppColors.ink100,
                          child: const Icon(Icons.broken_image_outlined, size: 18),
                        ),
                      ),
                    ),
                    Positioned(
                      top: 2,
                      right: 2,
                      child: InkWell(
                        onTap: () => onRemove(path),
                        child: Container(
                          padding: const EdgeInsets.all(3),
                          decoration: BoxDecoration(
                            color: AppColors.ink900.withValues(alpha: 0.6),
                            shape: BoxShape.circle,
                          ),
                          child: const Icon(Icons.close, size: 14, color: Colors.white),
                        ),
                      ),
                    ),
                    Positioned(
                      left: 4,
                      bottom: 4,
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                        decoration: BoxDecoration(
                          color: AppColors.ink900.withValues(alpha: 0.65),
                          borderRadius: BorderRadius.circular(4),
                        ),
                        child: const Text(
                          'À envoyer',
                          style: TextStyle(color: Colors.white, fontSize: 9, fontWeight: FontWeight.w700),
                        ),
                      ),
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