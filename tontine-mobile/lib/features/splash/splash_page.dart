import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:tontine_achat_store/core/auth/auth_controller.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/core/widgets/app_logo.dart';

/// Écran d'ouverture.
///
/// Il ne décide rien : il déclenche la lecture du jeton chiffré et laisse le
/// routeur appliquer la redirection une fois la session connue. Tant que ce
/// n'est pas fait, il affiche un logo — jamais une connexion, qui ferait
/// clignoter l'écran à un utilisateur déjà connecté.
class SplashPage extends ConsumerStatefulWidget {
  const SplashPage({super.key});

  @override
  ConsumerState<SplashPage> createState() => _SplashPageState();
}

class _SplashPageState extends ConsumerState<SplashPage> {
  @override
  void initState() {
    super.initState();
    // Le jeton est dans le keystore : c'est une lecture asynchrone, d'où le
    // ConsumerState. Le routeur bascule tout seul dès que l'état change.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      ref.read(authControllerProvider.notifier).bootstrap();
    });
  }

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      backgroundColor: AppColors.primary900,
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            AppLogo.onDark(),
            SizedBox(height: 20),
            Text(
              'Tontine Achat Store',
              style: TextStyle(
                color: Colors.white,
                fontSize: 20,
                fontWeight: FontWeight.w700,
              ),
            ),
            SizedBox(height: 28),
            SizedBox(
              width: 22,
              height: 22,
              child: CircularProgressIndicator(
                strokeWidth: 2.4,
                valueColor: AlwaysStoppedAnimation<Color>(AppColors.primary300),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
