import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/core/network/api_exception.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/core/widgets/error_banner.dart';
import 'package:tontine_achat_store/features/payments/payment_channel_picker.dart';
import 'package:tontine_achat_store/features/payments/payment_channels.dart';

/// Feuille de versement, commune aux cotisations et aux tranches.
///
/// L'API impose deux voies distinctes et ne les accepte pas l'une pour l'autre :
///  - canal automatique (Orange Money, Airtel, Moov, carte, virement) :
///    `…/pay` ;
///  - canal manuel (MyNita, Amana) : `…/soumettre-code` avec le code de
///    transfert. Envoyer `pay` pour ces canaux renvoie **422**.
///
/// Le formulaire s'adapte donc au canal choisi, au lieu d'afficher les deux et
/// de laisser l'utilisateur découvrir l'erreur. C'est aussi pourquoi le
/// composant ne connaît NI la cotisation NI la tranche : il ne fait qu'appeler
/// [onSubmit] avec le canal et la valeur du champ, et chaque écran décide de
/// quel endpoint s'agit.
class PaymentSheet extends ConsumerStatefulWidget {
  const PaymentSheet({
    super.key,
    required this.title,
    required this.subtitle,
    required this.amount,
    required this.submitLabel,
    required this.onSubmit,
    this.initialChannel,
  });

  final String title;
  final String subtitle;
  final double amount;
  final String submitLabel;

  /// Canal déjà choisi en amont.
  ///
  /// L'onglet « Cotisations » affiche les moyens de paiement AVANT le bouton
  /// « Payer » : l'utilisateur a donc déjà fait son choix, et répéter la liste
  /// ici l'obligerait à la recommencer. La feuille ne montre alors plus que ce
  /// qui reste à faire — saisir le code, ou confirmer.
  ///
  /// `null` laisse la feuille se comporter comme avant : elle affiche la liste et
  /// présélectionne le premier moyen.
  final String? initialChannel;

  /// Renvoie l'exception de l'API en cas de refus ; la feuille l'affiche.
  final Future<void> Function(String channel, String? reference, String? transferCode) onSubmit;

  /// Ouvre la feuille. Renvoie `true` si le versement a été envoyé.
  static Future<bool> show(
    BuildContext context, {
    required String title,
    required String subtitle,
    required double amount,
    required String submitLabel,
    required Future<void> Function(String channel, String? reference, String? transferCode) onSubmit,
    String? initialChannel,
  }) async {
    final sent = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) => PaymentSheet(
        title: title,
        subtitle: subtitle,
        amount: amount,
        submitLabel: submitLabel,
        initialChannel: initialChannel,
        onSubmit: onSubmit,
      ),
    );

    return sent ?? false;
  }

  @override
  ConsumerState<PaymentSheet> createState() => _PaymentSheetState();
}

class _PaymentSheetState extends ConsumerState<PaymentSheet> {
  final _codeController = TextEditingController();
  final _referenceController = TextEditingController();

  /// Choix fait DANS la feuille. `null` tant que l'utilisateur n'a pas tapped
  /// une puce : le canal retenu est alors déduit, voir [_channel].
  String? _picked;
  bool _busy = false;
  String? _error;

  /// Le canal confirmé en amont, ou `null` s'il n'est pas proposé par le serveur.
  ///
  /// La validation se fait à chaque build plutôt qu'une fois pour toutes : la
  /// configuration arrive de façon asynchrone, et un canal figé au premier
  /// frame resterait ignoré — l'utilisateur retombait alors sur une feuille vide
  /// à propos de la vérification de son e-mail.
  String? get _upstreamChannel {
    final preset = widget.initialChannel;
    if (preset == null) return null;

    final known = ref.read(paymentChannelsProvider).valueOrNull;
    if (known == null) return null;

    return known.labels.containsKey(preset) ? preset : null;
  }

  /// Le canal qui sera envoyé.
  ///
  /// Trois cas, dans cet ordre : ce que l'utilisateur vient de choisir ici, ce
  /// que l'écran appelant a déjà choisi, sinon le premier moyen configuré.
  ///
  /// Le dernier cas est un choix par défaut légitime — les moyens viennent de
  /// `GET /config` — et il évite une feuille qui n'afficherait qu'un titre tant
  /// que l'utilisateur n'a pas cliqué. Le champ correspondant (code ou
  /// référence) apparaît donc dès l'ouverture, ce qui est précisément le
  /// reproche fait à l'ancien écran : il fallait choisir pour découvrir ce
  /// qu'on attendait de soi.
  String? get _channel {
    if (_picked != null) return _picked;

    final known = ref.read(paymentChannelsProvider).valueOrNull;
    if (known == null || known.isEmpty) return null;

    return _upstreamChannel ?? known.entries.first.key;
  }

  @override
  void dispose() {
    _codeController.dispose();
    _referenceController.dispose();
    super.dispose();
  }

  bool get _isManual {
    final channel = _channel;
    if (channel == null) return false;

    return ref.read(paymentChannelsProvider).valueOrNull?.isManual(channel) ?? false;
  }

  Future<void> _submit() async {
    final channel = _channel;
    if (channel == null) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      await widget.onSubmit(
        channel,
        _isManual ? null : _referenceController.text.trim(),
        _isManual ? Fmt.normalizeTransferCode(_codeController.text) : null,
      );

      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (error) {
      // Le motif du refus est affiché tel quel : « déjà payée », « code en
      // attente », « e-mail non vérifié » sont des informations que
      // l'utilisateur doit connaître, pas un échec technique à triturer.
      if (mounted) setState(() => _error = error.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  bool get _canSubmit {
    if (_channel == null) return false;
    if (!_isManual) return true;

    // Le serveur exige au moins 3 caractères sur le code ; ne pas laisser
    // envoyer une valeur qu'il refusera d'office.
    return Fmt.normalizeTransferCode(_codeController.text).length >= 3;
  }

  @override
  Widget build(BuildContext context) {
    final channels = ref.watch(paymentChannelsProvider);

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
              Text(widget.title, style: Theme.of(context).textTheme.titleLarge),
              const SizedBox(height: 4),
              Text(
                widget.subtitle,
                style: const TextStyle(color: AppColors.ink500, fontSize: 13),
              ),
              const SizedBox(height: 14),

              // Le montant est rappelé avant tout choix : c'est la décision que
              // l'utilisateur prend, et elle doit rester visible pendant
              // qu'il choisit comment payer.
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: AppColors.primary50,
                  borderRadius: BorderRadius.circular(AppRadius.md),
                  border: Border.all(color: AppColors.primary200),
                ),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    const Text(
                      'Montant à verser',
                      style: TextStyle(color: AppColors.primary800, fontSize: 13),
                    ),
                    Text(
                      Fmt.fcfa(widget.amount),
                      style: const TextStyle(
                        color: AppColors.primary900,
                        fontSize: 18,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ],
                ),
              ),

              if (_error != null) ...[
                const SizedBox(height: 14),
                ErrorBanner(message: _error!),
              ],

              // Le sélecteur n'est montré que si le choix n'a PAS déjà été fait
              // et validé par l'écran appelant. Le ré-afficher obligerait
              // l'utilisateur à décider deux fois, alors que sa décision est
              // déjà visible derrière la feuille.
              if (_upstreamChannel == null) ...[
                const SizedBox(height: 18),
                const Text(
                  'Moyen de paiement',
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    color: AppColors.ink600,
                  ),
                ),
                const SizedBox(height: 8),

                channels.when(
                  loading: () => const Center(child: CircularProgressIndicator()),
                  error: (error, _) => ErrorBanner(message: '$error'),
                  data: (data) => PaymentChannelPicker(
                    channels: data,
                    value: _channel,
                    enabled: !_busy,
                    onChanged: (channel) => setState(() => _picked = channel),
                  ),
                ),
              ] else
                Padding(
                  padding: const EdgeInsets.only(bottom: 4),
                  child: Row(
                    children: [
                      const Icon(Icons.check_circle_outline, size: 15, color: AppColors.success600),
                      const SizedBox(width: 6),
                      Text(
                        'Moyen de paiement : ${channels.valueOrNull!.labels[_upstreamChannel]}',
                        style: const TextStyle(fontSize: 12, color: AppColors.ink500),
                      ),
                    ],
                  ),
                ),

              if (_channel != null) ...[
                const SizedBox(height: 18),
                _field(),
              ],

              const SizedBox(height: 22),
              FilledButton(
                onPressed: _busy || !_canSubmit ? null : _submit,
                child: _busy
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(
                          strokeWidth: 2.2,
                          valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
                        ),
                      )
                    : Text(widget.submitLabel),
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// Le champ dépend du canal : un code de transfert n'a de sens que pour les
  /// canaux vérifiés à la main ; une référence libre est facultative partout.
  Widget _field() {
    if (_isManual) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          TextField(
            controller: _codeController,
            enabled: !_busy,
            autocorrect: false,
            textCapitalization: TextCapitalization.characters,
            // `_canSubmit` dépend de ce champ : sans reconstruction à la
            // saisie, le bouton resterait désactivé alors que le code est
            // déjà valide.
            onChanged: (_) => setState(() {}),
            inputFormatters: [
              FilteringTextInputFormatter.allow(RegExp('[A-Za-z0-9]')),
              // Les codes d'opérateur sont lus au téléphone : passer en
              // majuscules à la saisie évite un refus du serveur pour une
              // minuscule.
              TextInputFormatter.withFunction(
                (oldValue, newValue) => newValue.copyWith(text: newValue.text.toUpperCase()),
              ),
            ],
            decoration: const InputDecoration(
              labelText: 'Code de transfert',
              helperText: 'Reçu de l\'agence, sans espaces ni tirets.',
            ),
          ),
          const SizedBox(height: 8),
          const Text(
            'Un administrateur vérifie le code avant que le versement soit '
            'compté comme payé.',
            style: TextStyle(fontSize: 12, color: AppColors.ink500, height: 1.4),
          ),
        ],
      );
    }

    return TextField(
      controller: _referenceController,
      enabled: !_busy,
      decoration: const InputDecoration(
        labelText: 'Référence de transaction (facultatif)',
        helperText: 'Utile pour retrouver le versement dans l\'historique.',
      ),
    );
  }
}
