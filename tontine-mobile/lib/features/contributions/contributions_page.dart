import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/core/sync/live_sync.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/core/widgets/app_badge.dart';
import 'package:tontine_achat_store/core/widgets/state_views.dart';
import 'package:tontine_achat_store/features/contributions/contribution.dart';
import 'package:tontine_achat_store/features/contributions/contribution_providers.dart';
import 'package:tontine_achat_store/features/payments/payment_channel_picker.dart';
import 'package:tontine_achat_store/features/payments/payment_channels.dart';
import 'package:tontine_achat_store/features/payments/payment_sheet.dart';
import 'package:tontine_achat_store/routing/app_router.dart';

/// Moyen de paiement retenu pour une cotisation.
///
/// Mémorisé **par cotisation** et non globalement : un même utilisateur peut
/// solder une tontine à l'agence et une autre par virement bancaire, et un
/// choix unique afficherait le mauvais moyen sur l'autre carte.
///
/// Volontairement SANS `autoDispose` : les cartes vivent dans une liste
/// défilable, et une cotisation qui sort de l'écran pour y revenir doit
/// retrouver son canal — pas retomber sur le premier de la liste, qui n'est
/// peut-être pas celui qu'il vient de choisir.
final contributionChannelProvider = StateProvider.family<String?, int>((ref, id) => null);

/// Onglet « Mes cotisations ».
///
/// L'historique des versements et le point d'entrée du paiement. Trois
/// onglets, calqués sur les trois états qui appellent une action différente :
/// ce qui reste à payer, ce qui attend un administrateur, et ce qui est réglé.
///
/// Les routes `/contributions/{id}` et `/mes-achats` ne sont PAS des branches de cette
/// coque : tout ce qui est accessible par onglet est ici, tout ce qui est une
/// lecture longue est une route plein écran.
class ContributionsPage extends ConsumerStatefulWidget {
  const ContributionsPage({super.key});

  @override
  ConsumerState<ContributionsPage> createState() => _ContributionsPageState();
}

class _ContributionsPageState extends ConsumerState<ContributionsPage>
    with SingleTickerProviderStateMixin {
  late final TabController _tabs = TabController(length: 3, vsync: this);

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  Future<void> _pay(Contribution contribution, PaymentChannels channels) async {
    final controller = ref.read(contributionsProvider.notifier);

    // Le canal vient de la carte : l'utilisateur l'a choisi avant d'arriver ici,
    // et la feuille n'affiche plus que ce qu'il reste à saisir.
    final channel = ref.read(contributionChannelProvider(contribution.id)) ??
        (channels.isEmpty ? null : channels.entries.first.key);

    final sent = await PaymentSheet.show(
      context,
      title: 'Cotiser',
      subtitle: '${contribution.title} · tour ${contribution.round}',
      amount: contribution.amount,
      submitLabel: 'Confirmer le versement',
      initialChannel: channel,
      onSubmit: (channel, reference, transferCode) => transferCode == null
          ? controller.pay(contribution.id, channel: channel, reference: reference)
          : controller.submitTransferCode(
              contribution.id,
              channel: channel,
              transferCode: transferCode,
            ),
    );

    if (!sent || !mounted) return;

    // La feuille ne renvoie qu'un booléen : c'est l'état à jour, rechargé depuis
    // le serveur, qui dit ce qu'il faut annoncer. Il est lu AVANT la
    // resynchronisation, qui invalide la liste et la remplacerait par une
    // requête asynchrone.
    final updated = ref
            .read(contributionsProvider)
            .valueOrNull
            ?.all
            .where((item) => item.id == contribution.id)
            .firstOrNull ??
        contribution;

    // Un versement change ce qui est à payer, ici et ailleurs : les
    // notifications, les achats, les tranches. On en profite pour relire le
    // reste, dont l'état a pu bouger pendant la saisie.
    ref.read(liveSyncProvider).syncNow();

    if (!mounted) return;

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          updated.verificationStatus.isRejected
              ? 'Ton code a été refusé : paie à nouveau.'
              : updated.awaitsVerification
                  ? 'Code envoyé : il sera vérifié par un administrateur.'
                  : '${Fmt.fcfa(updated.amount)} enregistrés.',
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final contributions = ref.watch(contributionsProvider);

    // Les moyens de paiement sont demandés ICI, et non à l'ouverture de la
    // feuille : `GET /config` est ainsi déjà en cache quand l'utilisateur
    // choisit de payer, la feuille s'ouvre sur le montant et le champ utile au
    // lieu d'un indicateur de chargement — et la carte peut afficher les moyens
    // AVANT qu'il décide de payer.
    final channels = ref.watch(paymentChannelsProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Mes cotisations'),
        bottom: TabBar(
          controller: _tabs,
          labelStyle: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700),
          tabs: const [
            Tab(text: 'À payer'),
            Tab(text: 'En attente'),
            Tab(text: 'Terminées'),
          ],
        ),
      ),
      body: contributions.when(
        loading: () => const LoadingView(label: 'Chargement des cotisations…'),
        error: (error, _) => ErrorView(
          message: '$error',
          onRetry: () => ref.read(contributionsProvider.notifier).reload(),
        ),
        data: (state) {
          if (state.isEmpty) {
            return const EmptyView(
              icon: Icons.receipt_long_outlined,
              title: 'Aucune cotisation',
              message: 'Tes cotisations apparaissent ici dès que tu rejoins une '
                  'tontine démarrée.',
            );
          }

          final configured = channels.valueOrNull ?? PaymentChannels.empty;

          return TabBarView(
            controller: _tabs,
            children: [
              _List(
                contributions: state.todo,
                channels: configured,
                empty: const EmptyView(
                  icon: Icons.check_circle_outline,
                  title: 'Rien à payer',
                  message: 'Tu es à jour pour toutes tes tontines.',
                ),
                onPay: _pay,
              ),
              _List(
                contributions: state.awaiting,
                channels: configured,
                empty: const EmptyView(
                  icon: Icons.schedule,
                  title: 'Rien en attente',
                  message: 'Aucun code de transfert n\'attend de vérification.',
                ),
                onPay: null,
              ),
              _List(
                contributions: [...state.settled, ...state.rejected],
                channels: configured,
                empty: const EmptyView(
                  icon: Icons.history,
                  title: 'Aucun versement',
                  message: 'Tes cotisations payées apparaîtront ici.',
                ),
                onPay: null,
              ),
            ],
          );
        },
      ),
    );
  }
}

class _List extends StatelessWidget {
  const _List({
    required this.contributions,
    required this.channels,
    required this.empty,
    this.onPay,
  });

  final List<Contribution> contributions;
  final PaymentChannels channels;
  final Widget empty;

  /// Passage à l'action de paiement, avec les moyens configurés : la carte a
  /// besoin de la même source que la feuille pour proposer le bon canal.
  final void Function(Contribution contribution, PaymentChannels channels)? onPay;

  @override
  Widget build(BuildContext context) {
    if (contributions.isEmpty) return empty;

    return ListView.separated(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
      itemCount: contributions.length,
      separatorBuilder: (_, __) => const SizedBox(height: 12),
      itemBuilder: (context, index) => _Card(
        contribution: contributions[index],
        channels: channels,
        onPay: onPay,
      ),
    );
  }
}

class _Card extends ConsumerWidget {
  const _Card({required this.contribution, required this.channels, this.onPay});

  final Contribution contribution;
  final PaymentChannels channels;
  final void Function(Contribution contribution, PaymentChannels channels)? onPay;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final payable = contribution.isPayable && onPay != null;
    final chosen = ref.watch(contributionChannelProvider(contribution.id));

    // Le canal retenu, ou le premier moyen configuré — même règle que celle de
    // la feuille de versement, pour que le bouton et le formulaire qu'il ouvre
    // ne versent pas sur des canaux différents.
    final selected = chosen != null && channels.labels.containsKey(chosen)
        ? chosen
        : (channels.isEmpty ? null : channels.entries.first.key);

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        contribution.tontine.name.isEmpty
                            ? 'Cotisation'
                            : contribution.tontine.name,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.w800,
                          color: AppColors.ink900,
                          height: 1.25,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        'Tour ${contribution.round}',
                        style: const TextStyle(color: AppColors.ink500, fontSize: 12),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 10),
                Text(
                  Fmt.fcfa(contribution.amount),
                  style: const TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.w800,
                    color: AppColors.primary800,
                  ),
                ),
              ],
            ),

            const SizedBox(height: 12),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                AppBadge(
                  label: contribution.status.label,
                  tone: contribution.status.tone,
                ),
                if (contribution.verificationStatus != VerificationStatus.notApplicable)
                  AppBadge(
                    label: contribution.verificationStatus.label,
                    tone: contribution.verificationStatus.tone,
                  ),
                if (contribution.tontine.id > 0)
                  const AppBadge(
                    label: 'Voir la tontine',
                    tone: AppTone.neutral,
                    icon: Icons.chevron_right,
                  ),
              ],
            ),

            // Le motif du refus vaut mieux qu'un bouton gris : l'utilisateur
            // doit comprendre pourquoi il ne peut pas payer, sinon il cherche
            // ailleurs en croyant à une panne.
            if (!payable) ...[
              const SizedBox(height: 10),
              Text(
                contribution.payBlockedReason(
                  manualChannel: contribution.paymentMethod == 'mynita' ||
                      contribution.paymentMethod == 'amana',
                ),
                style: const TextStyle(fontSize: 12, color: AppColors.ink500, height: 1.4),
              ),
            ],

            if (contribution.paymentFailureReason != null) ...[
              const SizedBox(height: 8),
              Text(
                'Échec du versement : ${contribution.paymentFailureReason}',
                style: const TextStyle(fontSize: 12, color: AppColors.danger600),
              ),
            ],

            if (contribution.transactionReference != null) ...[
              const SizedBox(height: 8),
              Text(
                'Référence : ${Fmt.truncate(contribution.transactionReference, 28)}',
                style: const TextStyle(fontSize: 11, color: AppColors.ink400),
              ),
            ],

            // **Les moyens de paiement sont listés AVANT le bouton « Payer ».**
            // L'utilisateur voit d'abord comment il peut régler, et ce qu'il
            // aura à fournir pour chacun — un code de transfert à faire
            // régler à l'agence pour MyNita ou Amana, une simple confirmation
            // pour Orange Money. Choisir à l'aveugle, puis découvrir la
            // contrainte dans la feuille, c'est un aller-retour de plus et un
            // versement refusé (422) quand on envoie un canal manuel à `pay`.
            if (payable) ...[
              const SizedBox(height: 16),
              const Text(
                'Comment veux-tu payer ?',
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: AppColors.ink600,
                ),
              ),
              const SizedBox(height: 8),
              PaymentChannelPicker(
                channels: channels,
                value: selected,
                onChanged: (channel) => ref
                    .read(contributionChannelProvider(contribution.id).notifier)
                    .state = channel,
              ),
              if (selected != null && channels.isManual(selected))
                const Padding(
                  padding: EdgeInsets.only(top: 8),
                  child: Text(
                    'Tu recevras un code de transfert à saisir à l\'étape suivante, '
                    'puis un administrateur le vérifiera.',
                    style: TextStyle(fontSize: 11, color: AppColors.ink500, height: 1.4),
                  ),
                ),
            ],

            const SizedBox(height: 14),
            Row(
              children: [
                if (contribution.tontine.id > 0)
                  TextButton.icon(
                    onPressed: () => context.push(AppRoutes.detail(contribution.tontine.id)),
                    icon: const Icon(Icons.savings_outlined, size: 18),
                    label: const Text('La tontine'),
                  ),
                const Spacer(),
                if (payable)
                  FilledButton(
                    onPressed: selected == null
                        ? null
                        : () => onPay!(contribution, channels),
                    style: FilledButton.styleFrom(
                      minimumSize: const Size(140, 44),
                    ),
                    child: const Text('Payer'),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
