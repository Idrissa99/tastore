import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:tontine_achat_store/core/auth/auth_controller.dart';
import 'package:tontine_achat_store/core/network/api_exception.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';

/// Bandeau « e-mail non vérifié ».
///
/// Ce n'est pas une simple information : les routes de versement sont derrière
/// le middleware `verified`, donc un compte non vérifié ne peut **pas** payer.
/// Sans ce bandeau, l'utilisateur découvre le blocage au moment de solder une
/// cotisation, après avoir choisi son moyen de paiement.
///
/// L'action proposée est celle qui le débloque : renvoyer le lien de validation.
class EmailVerificationBanner extends ConsumerStatefulWidget {
  const EmailVerificationBanner({super.key});

  @override
  ConsumerState<EmailVerificationBanner> createState() => _EmailVerificationBannerState();
}

class _EmailVerificationBannerState extends ConsumerState<EmailVerificationBanner> {
  bool _busy = false;
  String? _error;
  String? _sent;

  Future<void> _resend() async {
    setState(() {
      _busy = true;
      _error = null;
      _sent = null;
    });

    try {
      await ref.read(authControllerProvider.notifier).resendVerificationEmail();
      if (mounted) setState(() => _sent = 'Lien renvoyé. Vérifie ta boîte de réception.');
    } on ApiException catch (error) {
      // Le rate limiting du backend (5 envois par minute) doit rester lisible :
      // « Trop de tentatives », pas un message technique.
      if (mounted) setState(() => _error = error.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.warning50,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: AppColors.warning200),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(
                Icons.warning_amber_outlined,
                size: 20,
                color: AppColors.warning700,
              ),
              SizedBox(width: 10),
              Expanded(
                child: Text(
                  'Vérifie ton adresse e-mail',
                  style: TextStyle(
                    fontWeight: FontWeight.w800,
                    color: AppColors.warning700,
                    fontSize: 14,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          const Text(
            'Sans validation, tu ne peux pas solder tes cotisations : le serveur '
            'refuse tout versement sur un compte non vérifié.',
            style: TextStyle(color: AppColors.ink700, fontSize: 13, height: 1.4),
          ),
          if (_error != null) ...[
            const SizedBox(height: 8),
            Text(
              _error!,
              style: const TextStyle(color: AppColors.danger600, fontSize: 12),
            ),
          ],
          if (_sent != null) ...[
            const SizedBox(height: 8),
            Text(
              _sent!,
              style: const TextStyle(color: AppColors.success600, fontSize: 12),
            ),
          ],
          const SizedBox(height: 12),
          OutlinedButton.icon(
            onPressed: _busy ? null : _resend,
            icon: _busy
                ? const SizedBox(
                    width: 16,
                    height: 16,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.send_outlined, size: 18),
            label: const Text('Renvoyer le lien'),
            style: OutlinedButton.styleFrom(
              foregroundColor: AppColors.warning700,
              side: const BorderSide(color: AppColors.warning200),
              backgroundColor: Colors.white,
            ),
          ),
        ],
      ),
    );
  }
}
