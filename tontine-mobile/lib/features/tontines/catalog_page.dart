import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:tontine_achat_store/core/config/app_config.dart';
import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/core/widgets/state_views.dart';
import 'package:tontine_achat_store/features/tontines/tontine.dart';
import 'package:tontine_achat_store/features/tontines/tontine_providers.dart';
import 'package:tontine_achat_store/features/tontines/widgets/tontine_card.dart';
import 'package:tontine_achat_store/routing/app_router.dart';

/// Catalogue des tontines.
///
/// Parcours principal de l'application : trouver une tontine, puis y adhérer.
/// `GET /tontines` est public, mais l'écran vit dans la coquille connectée où
/// l'authentification est déjà résolue — le catalogue n'a donc pas à savoir
/// gérer deux régimes d'accès.
///
/// Deux paginations coexistent, et c'est délibéré :
///  - le serveur découpe par [AppConfig.pageSize], et
///    [TontineCatalogController] empile les pages jusqu'à `maxPages` ;
///  - les filtres sont appliqués sur cet empilement, puis la page AFFICHÉE est
///    redécoupée à la même taille. La pagination affichée est donc celle du
///    résultat filtré, et non celle du serveur : sinon « page 2 » pourrait être
///    vide alors que des tontines correspondent encore.
class TontineCatalogPage extends ConsumerStatefulWidget {
  const TontineCatalogPage({super.key});

  @override
  ConsumerState<TontineCatalogPage> createState() => _TontineCatalogPageState();
}

class _TontineCatalogPageState extends ConsumerState<TontineCatalogPage> {
  final _searchController = TextEditingController();
  int _page = 1;

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _openFilters(TontineFilters filters) async {
    final updated = await showModalBottomSheet<TontineFilters>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) => _FilterSheet(initial: filters),
    );

    // Feuille fermée sans valider : on ne touche à rien.
    if (updated == null) return;

    setState(() => _page = 1);
    await ref.read(tontineCatalogProvider.notifier).applyFilters(updated);
  }

  void _applyQuery(String value) {
    final filters = ref.read(tontineCatalogProvider).valueOrNull?.filters;
    if (filters == null) return;

    setState(() => _page = 1);
    ref.read(tontineCatalogProvider.notifier).applyFilters(filters.copyWith(query: value));
  }

  @override
  Widget build(BuildContext context) {
    final filters = ref.watch(tontineCatalogProvider).valueOrNull?.filters;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Tontines'),
        actions: [
          if (filters != null)
            TextButton.icon(
              onPressed: () => _openFilters(filters),
              icon: const Icon(Icons.tune, size: 18),
              label: Text(
                filters.activeCount == 0 ? 'Filtres' : 'Filtres (${filters.activeCount})',
              ),
              style: TextButton.styleFrom(
                foregroundColor:
                    filters.activeCount == 0 ? AppColors.ink700 : AppColors.primary700,
              ),
            ),
          const SizedBox(width: 8),
        ],
      ),
      body: Column(
        children: [
          _SearchField(controller: _searchController, onChanged: _applyQuery),
          const Divider(height: 1),
          Expanded(child: _Results(page: _page, onPage: (value) => setState(() => _page = value))),
        ],
      ),
    );
  }
}

/// Champ de recherche, toujours visible.
///
/// Il filtre l'accumulé déjà téléchargé : la frappe est donc instantanée, sans
/// délai ni indicateur — et sans un appel réseau par lettre.
class _SearchField extends StatelessWidget {
  const _SearchField({required this.controller, required this.onChanged});

  final TextEditingController controller;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
      child: TextField(
        controller: controller,
        onChanged: onChanged,
        textInputAction: TextInputAction.search,
        autocorrect: false,
        decoration: InputDecoration(
          hintText: 'Rechercher un produit ou une tontine…',
          prefixIcon: const Icon(Icons.search, size: 20),
          suffixIcon: ValueListenableBuilder<TextEditingValue>(
            valueListenable: controller,
            builder: (context, value, _) => value.text.isEmpty
                ? const SizedBox.shrink()
                : IconButton(
                    tooltip: 'Effacer',
                    icon: const Icon(Icons.close, size: 18),
                    onPressed: () {
                      controller.clear();
                      onChanged('');
                    },
                  ),
          ),
        ),
      ),
    );
  }
}

/// Corps de l'écran : squelettes, erreur, liste vide, ou résultats.
class _Results extends ConsumerWidget {
  const _Results({required this.page, required this.onPage});

  final int page;
  final ValueChanged<int> onPage;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final catalog = ref.watch(tontineCatalogProvider);

    // Premier chargement : des silhouettes de la taille exacte des cartes, pour
    // que rien ne saute à leur apparition.
    if (catalog.isLoading && !catalog.hasValue) {
      return ListView.separated(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 28),
        itemCount: 3,
        separatorBuilder: (_, __) => const SizedBox(height: 14),
        itemBuilder: (_, __) => const TontineCardSkeleton(),
      );
    }

    if (catalog.hasError && !catalog.hasValue) {
      return ErrorView(
        message: '${catalog.error}',
        onRetry: () => ref.invalidate(tontineCatalogProvider),
      );
    }

    final state = catalog.requireValue;
    final visible = state.visibleAt(page);

    if (visible.isEmpty) {
      return EmptyView(
        icon: Icons.savings_outlined,
        title: state.filters.hasCriteria ? 'Aucune tontine ne correspond' : 'Aucune tontine',
        message: state.filters.hasCriteria
            ? 'Élargis tes filtres ou cherche un autre terme pour découvrir plus de tontines.'
            : 'Le catalogue est vide pour le moment. Reviens bientôt.',
        actionLabel: state.filters.hasCriteria ? 'Réinitialiser les filtres' : null,
        onAction: state.filters.hasCriteria
            ? () => ref.read(tontineCatalogProvider.notifier).clearFilters()
            : null,
      );
    }

    return RefreshIndicator(
      onRefresh: () => ref.refresh(tontineCatalogProvider.future),
      child: ListView.separated(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 28),
        // Résumé en tête, cartes au milieu, pied de pagination en fin de liste :
        // c'est ce qui laisse le geste de défilement atteindre le bouton
        // « Charger plus » sans avoir à le chercher dans la barre d'outils.
        itemCount: visible.length + 2,
        separatorBuilder: (_, __) => const SizedBox(height: 14),
        itemBuilder: (context, index) {
          if (index == 0) return _Summary(state: state);

          if (index == visible.length + 1) {
            return _Footer(state: state, page: page, onPage: onPage);
          }

          final tontine = visible[index - 1];

          return TontineCard(
            tontine: tontine,
            onTap: () => context.push(AppRoutes.detail(tontine.id)),
          );
        },
      ),
    );
  }
}

/// Bandeau de synthèse : combien de tontines, combien rejoignables.
///
/// Le mot « rejoignables » porte le chiffre qui décide : une tontine affichée
/// mais complète n'est pas une offre, et un total brut ferait annoncer des
/// places qui n'existent pas.
class _Summary extends StatelessWidget {
  const _Summary({required this.state});

  final TontineCatalogState state;

  @override
  Widget build(BuildContext context) {
    final found = state.filtered.length;
    final joinable = state.joinableCount;
    final lowest = state.lowestJoinableContribution;

    final head = joinable == found
        ? "${Fmt.plural(found, 'tontine')}${found > 1 ? ' disponibles' : ' disponible'}"
        : "${Fmt.plural(found, 'tontine')} — dont $joinable rejoignable${joinable > 1 ? 's' : ''}";

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          head,
          style: const TextStyle(
            fontSize: 12,
            fontWeight: FontWeight.w700,
            color: AppColors.ink600,
          ),
        ),
        const SizedBox(height: 2),
        Text(
          [
            if (lowest != null) 'Versement minimum : ${Fmt.fcfa(lowest)}',
            if (state.poolIsTruncated)
              'Catalogue limité aux ${AppConfig.maxPages} premières pages sur '
                  '${Fmt.number(state.total)} tontines.',
          ].join(' · '),
          style: const TextStyle(fontSize: 11, color: AppColors.ink400, height: 1.4),
        ),
      ],
    );
  }
}

/// Pied de liste : « Charger plus », puis navigation entre les pages filtrées.
class _Footer extends ConsumerWidget {
  const _Footer({required this.state, required this.page, required this.onPage});

  final TontineCatalogState state;
  final int page;
  final ValueChanged<int> onPage;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final controller = ref.read(tontineCatalogProvider.notifier);
    final current = state.visibleAt(page).isEmpty ? 1 : page;

    return Column(
      children: [
        if (state.canLoadMore || state.loadingMore)
          OutlinedButton.icon(
            onPressed: state.loadingMore ? null : controller.loadMore,
            icon: state.loadingMore
                ? const SizedBox(
                    width: 16,
                    height: 16,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.expand_more, size: 18),
            label: Text(state.loadingMore ? 'Chargement…' : 'Charger plus de tontines'),
            style: OutlinedButton.styleFrom(
              foregroundColor: AppColors.ink700,
              side: const BorderSide(color: AppColors.ink200),
              minimumSize: const Size.fromHeight(48),
            ),
          ),
        if (state.pageCount > 1) ...[
          const SizedBox(height: 14),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              IconButton(
                onPressed: current > 1 ? () => onPage(current - 1) : null,
                icon: const Icon(Icons.chevron_left),
                tooltip: 'Page précédente',
              ),
              Text(
                'Page $current sur ${state.pageCount}',
                style: const TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: AppColors.ink600,
                ),
              ),
              IconButton(
                onPressed: current < state.pageCount ? () => onPage(current + 1) : null,
                icon: const Icon(Icons.chevron_right),
                tooltip: 'Page suivante',
              ),
            ],
          ),
        ],
      ],
    );
  }
}

/// Feuille de filtres.
///
/// Les critères sont posés dans un état local et renvoyés en un seul bloc : les
/// appliquer à chaque changement relancerait le catalogue à chaque tape, alors
/// que seul `availableOnly` est connu du serveur.
class _FilterSheet extends StatefulWidget {
  const _FilterSheet({required this.initial});

  final TontineFilters initial;

  @override
  State<_FilterSheet> createState() => _FilterSheetState();
}

class _FilterSheetState extends State<_FilterSheet> {
  late TontineFilters _draft = widget.initial;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          20,
          0,
          20,
          20 + MediaQuery.of(context).viewInsets.bottom,
        ),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text('Filtres', style: theme.textTheme.titleLarge),
              const SizedBox(height: 18),

              const _Label('Type de tontine'),
              _Segmented<TontineType>(
                values: TontineType.values,
                selected: _draft.type,
                labelOf: (type) => type.shortLabel,
                onChanged: (value) => setState(
                  () => _draft = value == null
                      ? _draft.copyWith(clearType: true)
                      : _draft.copyWith(type: value),
                ),
              ),
              const SizedBox(height: 18),

              const _Label('Fréquence des versements'),
              _Segmented<TontineFrequency>(
                values: TontineFrequency.values,
                selected: _draft.frequency,
                labelOf: (frequency) => frequency.shortLabel,
                onChanged: (value) => setState(
                  () => _draft = value == null
                      ? _draft.copyWith(clearFrequency: true)
                      : _draft.copyWith(frequency: value),
                ),
              ),
              const SizedBox(height: 18),

              const _Label('Montant maximum'),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final ceiling in const [10000.0, 25000.0, 50000.0, 100000.0])
                    ChoiceChip(
                      label: Text('Jusqu\'à ${Fmt.fcfaCompact(ceiling)}'),
                      selected: _draft.maxAmount == ceiling,
                      onSelected: (selected) => setState(
                        () => _draft = selected
                            ? _draft.copyWith(maxAmount: ceiling)
                            : _draft.copyWith(clearMaxAmount: true),
                      ),
                    ),
                ],
              ),
              const SizedBox(height: 8),

              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                value: _draft.availableOnly,
                onChanged: (value) =>
                    setState(() => _draft = _draft.copyWith(availableOnly: value)),
                title: const Text(
                  'Rejoignables uniquement',
                  style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600),
                ),
                subtitle: const Text(
                  'Masque les tontines qui ont démarré, sont terminées ou annulées',
                  style: TextStyle(fontSize: 12, color: AppColors.ink500),
                ),
              ),

              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                value: _draft.slotsOnly,
                onChanged: (value) => setState(() => _draft = _draft.copyWith(slotsOnly: value)),
                title: const Text(
                  'Places disponibles uniquement',
                  style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600),
                ),
                subtitle: const Text(
                  'Masque les tontines déjà complètes',
                  style: TextStyle(fontSize: 12, color: AppColors.ink500),
                ),
              ),

              const SizedBox(height: 20),
              FilledButton(
                onPressed: () => Navigator.of(context).pop(_draft),
                child: Text(
                  _draft.activeCount == 0 ? 'Voir les résultats' : 'Appliquer les filtres',
                ),
              ),
              if (_draft.activeCount > 0) ...[
                const SizedBox(height: 8),
                TextButton(
                  onPressed: () => Navigator.of(context).pop(TontineFilters.none),
                  child: const Text('Réinitialiser les filtres'),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _Label extends StatelessWidget {
  const _Label(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Text(
        text,
        style: const TextStyle(
          fontSize: 12,
          fontWeight: FontWeight.w700,
          color: AppColors.ink600,
        ),
      ),
    );
  }
}

/// Sélecteur à choix unique avec une entrée « Tous ».
class _Segmented<T> extends StatelessWidget {
  const _Segmented({
    required this.values,
    required this.selected,
    required this.labelOf,
    required this.onChanged,
  });

  final List<T> values;
  final T? selected;
  final String Function(T value) labelOf;
  final ValueChanged<T?> onChanged;

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        ChoiceChip(
          label: const Text('Tous'),
          selected: selected == null,
          onSelected: (_) => onChanged(null),
        ),
        for (final value in values)
          ChoiceChip(
            label: Text(labelOf(value)),
            selected: selected == value,
            onSelected: (isSelected) => onChanged(isSelected ? value : null),
          ),
      ],
    );
  }
}
