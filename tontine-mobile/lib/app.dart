import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:tontine_achat_store/core/auth/auth_controller.dart';
import 'package:tontine_achat_store/core/config/app_config.dart';
import 'package:tontine_achat_store/core/di/providers.dart';
import 'package:tontine_achat_store/core/sync/live_sync.dart';
import 'package:tontine_achat_store/features/payments/reminder_service.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/routing/app_router.dart';

/// Racine de l'application.
///
/// Elle fait deux branchements que rien ne faisait jusqu'ici :
///  - relie le client HTTP à l'état de session, via `onUnauthorized`, ce
///    callback existed mais n'était appelé par personne ;
///  - installe le `GoRouter`, qui porte la règle de redirection.
class TontineApp extends ConsumerStatefulWidget {
  const TontineApp({super.key});

  @override
  ConsumerState<TontineApp> createState() => _TontineAppState();
}

class _TontineAppState extends ConsumerState<TontineApp> {
  @override
  void initState() {
    super.initState();
    // Un 401 reçu sur une route métier (une cotisation, un catalogue privé)
    // doit ramener l'utilisateur vers la connexion. Le client HTTP sait qu'il
    // vient d'être déconnecté, mais c'est l'application qui détient l'état :
    // le lien se fait ici, une fois pour toute la session.
    ref.read(apiClientProvider).onUnauthorized =
        ref.read(authControllerProvider.notifier).onSessionExpired;

    // La permission d'afficher des notifications est demandée au démarrage, et
    // non au premier paiement : la demander au moment de solder une cotisation
    // interromprait l'action en cours pour une question sans rapport avec elle.
    // Android 13+ la refuse par défaut, et son refus est silencieux — d'où la
    // demande explicite dès l'ouverture.
    ref.read(paymentReminderServiceProvider).prepare();

    // Les données affichées changent ailleurs qu'à l'écran : un administrateur
    // valide un code de virement, un autre appareil règle une cotisation, un
    // round se solde. La resynchronisation garde l'application alignée sur le
    // backend sans que l'utilisateur ait à rafraîchir à la main.
    ref.read(liveSyncProvider);
  }

  @override
  Widget build(BuildContext context) {
    final router = ref.watch(routerProvider);

    return MaterialApp.router(
      title: AppConfig.appName,
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light,
      routerConfig: router,
    );
  }
}
