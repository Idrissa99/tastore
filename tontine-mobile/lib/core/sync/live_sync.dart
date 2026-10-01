import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:tontine_achat_store/core/config/app_config.dart';
import 'package:tontine_achat_store/features/contributions/contribution_providers.dart';
import 'package:tontine_achat_store/features/notifications/notification_providers.dart';
import 'package:tontine_achat_store/features/purchases/purchase_providers.dart';
import 'package:tontine_achat_store/features/tontines/my_tontines_page.dart';

/// Resynchronisation automatique des données de l'application.
///
/// **Ce qui est faux sans elle.** L'état affiché change ailleurs qu'à l'écran :
/// un administrateur accepte ou refuse un code de virement depuis le back-office,
/// un autre appareil règle une cotisation, un round se solde, un commerçant
/// confirme une livraison. Sans resynchronisation, l'application continuait
/// d'afficher ces données périmées jusqu'à ce que l'utilisateur pense à tirer
/// vers le bas — c'est-à-dire jusqu'à ce qu'il décide d'agir sur une
/// information fausse. « Ma cotisation est en attente » pouvait ainsi le
/// conduire à payer deux fois.
///
/// **Pourquoi un sondage et non un canal temps réel.** Le push suppose un service
/// de notification côté serveur, un jeton d'appareil enregistré, et une
/// connexion maintenue. L'état du backend ne bouge que quelques fois par jour :
/// un sondage lent, plus une resynchronisation systématique au retour au premier
/// plan, couvrent le besoin sans infrastructure supplémentaire ni réveil en
/// arrière-plan.
///
/// **Pourquoi le conteneur, et non `ref.invalidate`.** C'est le point le plus
/// important de cette classe. `Ref.invalidate` ne se contente pas de marquer un
/// provider périmé : sur un provider qui n'existe pas encore, il le CRÉE et
/// déclenche son chargement. Une resynchronisation qui l'utiliserait irait donc
/// chercher `/notifications` et `/mes-achats` alors que l'utilisateur regarde le
/// catalogue, à chaque minute, sur le réseau mobile.
///
/// `ProviderContainer.invalidate` a exactement la sémantique voulue : ne rien
/// faire si le provider n'a jamais été initialisé, le marquer comme périmé
/// s'il l'est. On invalide donc par le conteneur, et il ne faut surtout pas
/// « simplifier » cet appel en `ref.invalidate`.
///
/// Conséquence directe : seuls les écrans réellement affichés sont relus. Les
/// autres ne sont pas « rafraîchis » — ils le seront, et pour la première fois,
/// au moment où l'utilisateur y va.
class LiveSync with WidgetsBindingObserver {
  LiveSync(
    this._ref, {
    Duration interval = AppConfig.syncInterval,
    bool autoStart = true,
  })  : _interval = interval {
    WidgetsBinding.instance.addObserver(this);

    if (autoStart) start();
  }

  final Ref _ref;
  final Duration _interval;

  /// Période du sondage. Exposée et injectable pour que le comportement soit
  /// vérifiable sans attendre une minute réelle.
  Duration get interval => _interval;

  Timer? _timer;

  /// Instant de la dernière resynchronisation, `null` tant qu'aucune n'a eu
  /// lieu. Public pour que l'écran puisse décider si une action a déjà suivi.
  DateTime? lastSyncAt;

  /// Les données qui changent sans que l'utilisateur touche l'écran.
  ///
  /// Volontairement une liste, pas une invalidation globale : le catalogue en
  /// est absent parce que ses filtres sont un état de saisie de l'utilisateur —
  /// le vider toutes les minutes reviendrait à les effacer sous ses doigts. La
  /// session (`/me`) en est absente pour une raison voisine : son contrôleur
  /// porte l'état de navigation, et le reconstruire ferait perdre le jeton que
  /// le splash vient d'ouvrir. Le profil est relu à son écran, où il est
  /// justement ce qui est consulté.
  List<ProviderOrFamily> get _volatile => [
        contributionsProvider,
        notificationsProvider,
        myTontinesProvider,
        purchasesProvider,
        purchaseDetailProvider,
      ];

  /// Démarre le sondage périodique.
  void start() {
    _timer?.cancel();
    _timer = Timer.periodic(_interval, (_) => sync());
  }

  /// Arrête le sondage. La resynchronisation ne sert qu'application visible :
  /// quand l'application est en arrière-plan, le système bride ou suspend le
  /// minuteur, et le retour au premier plan déclenche de toute façon un passage.
  void stop() {
    _timer?.cancel();
    _timer = null;
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    switch (state) {
      case AppLifecycleState.resumed:
        // Forcé, et non soumis au garde-fou : revenir au premier plan est
        // précisément le moment où l'utilisateur a pu agir sur un autre
        // appareil. S'attendre la fin de l'intervalle le laisserait regarder un
        // état périmé.
        start();
        syncNow();

      case AppLifecycleState.inactive:
        // Une feuille de dialogue, un sélecteur, une réponse d'appel : le
        // téléphone reste en main et l'application visible. Suspendre ici
        // couperait le sondage — puis le relancerait — à chaque feuille de
        // paiement.
        start();

      case AppLifecycleState.paused:
      case AppLifecycleState.hidden:
      case AppLifecycleState.detached:
        stop();
    }
  }

  /// Marque les données périmées.
  ///
  /// Immédiat et sans attente : l'invalidation déclenche une requête pour
  /// chaque écran actuellement affiché, et l'utilisateur voit la donnée neuve
  /// arriver sans geste de sa part.
  ///
  /// Sans effet si une resynchronisation a déjà eu lieu dans l'intervalle : un
  /// changement d'onglet ou un retour au premier plan ne doit pas multiplier
  /// les allers-retours quand il vient d'y en avoir un.
  void sync({bool force = false}) {
    final now = DateTime.now();

    if (!force && _tooRecent(now)) return;

    lastSyncAt = now;

    // Voir la note de classe : `Ref.invalidate` chargerait les providers
    // absents, ce qui enverrait une requête par écran non ouvert.
    final container = _ref.container;

    for (final provider in _volatile) {
      container.invalidate(provider);
    }
  }

  /// Resynchronisation immédiate et inconditionnelle.
  ///
  /// Réservée aux moments où l'on sait que quelque chose vient de changer :
  /// retour au premier plan après une longue absence, action dont l'effet est
  /// visible ailleurs.
  void syncNow() => sync(force: true);

  bool _tooRecent(DateTime now) {
    final last = lastSyncAt;

    return last != null && now.difference(last) < _interval;
  }

  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    stop();
  }
}

/// La resynchronisation vit aussi longtemps que le `ProviderContainer`.
///
/// Instanciée une seule fois depuis la racine de l'application : c'est elle qui
/// observe le cycle de vie, donc deux instances se doubleraient et
/// invalideraient deux fois les mêmes providers.
final liveSyncProvider = Provider<LiveSync>(
  (ref) {
    final sync = LiveSync(ref);
    ref.onDispose(sync.dispose);

    return sync;
  },
);
