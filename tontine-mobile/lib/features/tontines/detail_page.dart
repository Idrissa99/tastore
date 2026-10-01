import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:tontine_achat_store/core/auth/auth_controller.dart';
import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/core/network/api_exception.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/core/widgets/app_badge.dart';
import 'package:tontine_achat_store/core/widgets/avatar.dart';
import 'package:tontine_achat_store/core/widgets/error_banner.dart';
import 'package:tontine_achat_store/core/widgets/progress_bar.dart';
import 'package:tontine_achat_store/core/widgets/state_views.dart';
import 'package:tontine_achat_store/features/tontines/tontine.dart';
import 'package:tontine_achat_store/features/tontines/tontine_providers.dart';
import 'package:tontine_achat_store/features/tontines/widgets/tontine_card.dart';
import 'package:tontine_achat_store/features/tontines/widgets/tontine_cover.dart';
import 'package:tontine_achat_store/routing/app_router.dart';

/// Fiche d'une tontine, et adhésion.
///
/// `GET /tontines/{id}` est public : l'écran reste lisible sans session, et
/// c'est ce qui permet de découvrir une tontine puis de se connecter. Le
/// bouton d'adhésion, lui, dépend de la session — il ne propose pas « Rejoindre »
/// à quelqu'un qui ne peut pas le faire.
class TontineDetailPage extends ConsumerStatefulWidget {
  const TontineDetailPage({super.key, required this.tontineId});

  final int tontineId;

  @override
  ConsumerState<TontineDetailPage> createState() => _TontineDetailPageState();
}

class _TontineDetailPageState extends ConsumerState<TontineDetailPage> {
  /// Copie affichée, qui remplace la valeur du fournisseur après une adhésion.
  ///
  /// Le serveur renvoie déjà la tontine à jour : la réutiliser évite un second
  /// aller-retour dont le résultat pourrait contredire le bouton qu'on vient de
  /// toucher (« 10 membres » affiché juste après avoir rejoint la onzième
  /// place). Le fournisseur reste la source au retour sur l'écran.
  Tontine? _joined;

  bool _busy = false;
  String? _error;

  Future<void> _join(Tontine tontine) async {
    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final joined = await ref.read(joinTontineProvider(tontine.id))();
      if (!mounted) return;

      setState(() {
        _joined = joined;
        _busy = false;
      });

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Tu as rejoint « ${joined.name} ».')),
      );
    } on ApiException catch (error) {
      // Le refus est affiché tel quel : tontine pleine, adhésion en double,
      // e-mail non vérifié sont des réponses métier que l'utilisateur doit lire,
      // pas un échec technique à avaler.
      if (mounted) setState(() => _error = error.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final detail = ref.watch(tontineDetailProvider(widget.tontineId));

    return Scaffold(
      appBar: AppBar(title: const Text('Tontine')),
      body: detail.when(
        loading: () => const LoadingView(label: 'Chargement de la tontine…'),
        error: (error, _) => ErrorView(
          message: '$error',
          onRetry: () => ref.invalidate(tontineDetailProvider(widget.tontineId)),
        ),
        data: (fetched) {
          final tontine = _joined ?? fetched;

          return _Body(
            tontine: tontine,
            error: _error,
            onJoin: _join,
            busy: _busy,
          );
        },
      ),
    );
  }
}

class _Body extends ConsumerWidget {
  const _Body({
    required this.tontine,
    required this.onJoin,
    required this.busy,
    this.error,
  });

  final Tontine tontine;
  final Future<void> Function(Tontine tontine) onJoin;
  final bool busy;
  final String? error;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final isCash = tontine.type == TontineType.cash;

    return Column(
      children: [
        Expanded(
          child: ListView(
            padding: const EdgeInsets.only(bottom: 20),
            children: [
              TontineCover(tontine: tontine),

              Padding(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      tontine.name,
                      style: const TextStyle(
                        fontSize: 20,
                        fontWeight: FontWeight.w800,
                        color: AppColors.ink900,
                        height: 1.25,
                      ),
                    ),
                    if (!isCash && tontine.product != null) ...[
                      const SizedBox(height: 4),
                      Text(
                        'Produit financé : ${tontine.product!.name}',
                        style: const TextStyle(color: AppColors.ink600, fontSize: 13),
                      ),
                    ],
                    const SizedBox(height: 12),
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        RemainingSlotsBadge(tontine: tontine),
                        AppBadge(
                          label: 'Round ${tontine.round.number}',
                          tone: AppTone.neutral,
                          icon: Icons.refresh,
                        ),
                        if (tontine.startDate != null)
                          AppBadge(
                            label: 'Départ ${Fmt.countdown(tontine.startDate)}',
                            tone: AppTone.info,
                            icon: Icons.event_outlined,
                          ),
                      ],
                    ),

                    if (error != null) ...[
                      const SizedBox(height: 16),
                      ErrorBanner(message: error!),
                    ],

                    const SizedBox(height: 20),
                    _Amounts(tontine: tontine),
                    const SizedBox(height: 20),
                    _Round(tontine: tontine),
                    const SizedBox(height: 20),
                    _Members(tontine: tontine),

                    if (tontine.editLockedReason != null) ...[
                      const SizedBox(height: 20),
                      _Notice(message: tontine.editLockedReason!),
                    ],
                  ],
                ),
              ),
            ],
          ),
        ),
        _JoinBar(tontine: tontine, busy: busy, onJoin: onJoin),
      ],
    );
  }
}

/// Les deux montants qui décident d'une adhésion : ce qu'on verse à chaque
/// tour, et ce que la tontine finance en tout.
class _Amounts extends StatelessWidget {
  const _Amounts({required this.tontine});

  final Tontine tontine;

  @override
  Widget build(BuildContext context) {
    final rows = <(String, String)>[
      ('Versement', '${Fmt.fcfa(tontine.contributionAmount)} ${tontine.frequency.label}'),
      (
        tontine.type == TontineType.cash ? 'Montant visé' : 'Montant du produit',
        Fmt.fcfa(tontine.type == TontineType.cash ? tontine.totalAmount : tontine.product?.price),
      ),
      ('Commission inscrite', Fmt.percent(tontine.commissionRate, decimals: 1)),
    ];

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            for (var index = 0; index < rows.length; index++) ...[
              if (index > 0) const Padding(
                padding: EdgeInsets.symmetric(vertical: 9),
                child: Divider(height: 1),
              ),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    rows[index].$1,
                    style: const TextStyle(color: AppColors.ink500, fontSize: 13),
                  ),
                  Text(
                    rows[index].$2,
                    style: const TextStyle(
                      color: AppColors.ink900,
                      fontSize: 14,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// État financier du tour courant — ce qui autorise ou non la livraison.
class _Round extends StatelessWidget {
  const _Round({required this.tontine});

  final Tontine tontine;

  @override
  Widget build(BuildContext context) {
    final round = tontine.round;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  'Tour ${round.number == 0 ? '—' : round.number}',
                  style: const TextStyle(
                    fontWeight: FontWeight.w800,
                    color: AppColors.ink900,
                  ),
                ),
                Text(
                  '${round.paidMembers}/${round.expectedMembers} cotisés',
                  style: const TextStyle(color: AppColors.ink500, fontSize: 12),
                ),
              ],
            ),
            const SizedBox(height: 10),
            ProgressBar(value: round.ratio, height: 8),
            const SizedBox(height: 8),
            Text(
              '${Fmt.fcfa(tontine.collectedAmount)} collectés sur ${Fmt.fcfa(tontine.totalAmount)}',
              style: const TextStyle(fontSize: 12, color: AppColors.ink500),
            ),

            if (round.number > 0) ...[
              const SizedBox(height: 12),
              AppBadge(
                label: round.isFunded
                    ? 'Tour financé : la livraison est ouverte'
                    : 'Tour incomplet : la livraison reste bloquée',
                tone: round.isFunded ? AppTone.success : AppTone.warning,
                icon: round.isFunded ? Icons.check_circle_outline : Icons.schedule,
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// Les inscrits.
///
/// L'ordre de passage n'est affiché QUE si le serveur l'a révélé
/// ([Tontine.rotationRevealed]) : avant le lancement, `position` ne porte que
/// l'ordre d'arrivée, et le montrer ferait croire qu'un tirage a eu lieu.
class _Members extends StatelessWidget {
  const _Members({required this.tontine});

  final Tontine tontine;

  @override
  Widget build(BuildContext context) {
    final members = tontine.members;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                const Text(
                  'Participants',
                  style: TextStyle(fontWeight: FontWeight.w800, color: AppColors.ink900),
                ),
                Text(
                  '${tontine.currentMembers}/${tontine.maxMembers}',
                  style: const TextStyle(color: AppColors.ink500, fontSize: 12),
                ),
              ],
            ),
            const SizedBox(height: 12),
            for (final member in members) ...[
              _MemberRow(member: member, tontine: tontine),
              if (member != members.last) const Divider(height: 20),
            ],
          ],
        ),
      ),
    );
  }
}

class _MemberRow extends StatelessWidget {
  const _MemberRow({required this.member, required this.tontine});

  final TontineMember member;
  final Tontine tontine;

  @override
  Widget build(BuildContext context) {
    final badges = <Widget>[
      if (member.isMe)
        const AppBadge(label: 'Toi', tone: AppTone.brand, icon: Icons.person_outline)
      else if (tontine.rotationRevealed && member.position != null)
        AppBadge(label: 'Position ${member.position}', tone: AppTone.neutral),
      if (member.status != TontineMemberStatus.active)
        AppBadge(label: member.status.label, tone: member.status.tone),
      if (member.deliveryStatus == DeliveryStatus.delivered)
        AppBadge(label: member.deliveryStatus.label, tone: member.deliveryStatus.tone),
    ];

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Row(
        children: [
          AppAvatar(name: member.userName, size: 36),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  member.userName,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                    color: AppColors.ink900,
                  ),
                ),
                if (badges.isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Wrap(spacing: 6, runSpacing: 4, children: badges),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Encart d'explication — ici, pourquoi le créateur ne peut plus modifier.
class _Notice extends StatelessWidget {
  const _Notice({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.ink50,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: AppColors.ink200),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.info_outline, size: 18, color: AppColors.ink500),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              message,
              style: const TextStyle(color: AppColors.ink600, fontSize: 13, height: 1.4),
            ),
          ),
        ],
      ),
    );
  }
}

/// Barre d'adhésion, épinglée en bas.
///
/// Elle est toujours présente, même lorsqu'elle ne peut pas agir : c'est
/// [Tontine.joinBlockedReason] qui l'explique. Un bouton simplement absent
/// laisserait l'utilisateur sans savoir pourquoi il ne peut pas rejoindre.
class _JoinBar extends ConsumerWidget {
  const _JoinBar({required this.tontine, required this.busy, required this.onJoin});

  final Tontine tontine;
  final bool busy;
  final Future<void> Function(Tontine tontine) onJoin;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final connected = ref.watch(authControllerProvider).isAuthenticated;
    final availability = tontine.joinAvailability;

    // Sans session, l'appel serait refusé par le serveur : on propose la
    // connexion, qui est la seule action possible.
    if (!connected) {
      return _bar(
        child: FilledButton.icon(
          onPressed: () => context.go(AppRoutes.login),
          icon: const Icon(Icons.login, size: 18),
          label: const Text('Connecte-toi pour rejoindre'),
        ),
        footnote: 'La consultation est libre ; l\'adhésion demande un compte.',
      );
    }

    final label = switch (availability) {
      JoinAvailability.open =>
        'Rejoindre pour ${Fmt.fcfa(tontine.contributionAmount)} ${tontine.frequency.label}',
      _ => tontine.joinBlockedReason(),
    };

    return _bar(
      child: FilledButton(
        onPressed: availability == JoinAvailability.open && !busy
            ? () => onJoin(tontine)
            : null,
        child: busy
            ? const SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(
                  strokeWidth: 2.2,
                  valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
                ),
              )
            : Text(label),
      ),
    );
  }

  Widget _bar({required Widget child, String? footnote}) {
    return Container(
      decoration: const BoxDecoration(
        color: Colors.white,
        border: Border(top: BorderSide(color: AppColors.ink200)),
      ),
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
      child: SafeArea(
        top: false,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            child,
            if (footnote != null) ...[
              const SizedBox(height: 8),
              Text(
                footnote,
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 11, color: AppColors.ink400),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
