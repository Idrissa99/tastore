import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:tontine_achat_store/core/di/providers.dart';
import 'package:tontine_achat_store/core/network/api_exception.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/core/widgets/error_banner.dart';
import 'package:tontine_achat_store/features/auth/auth_scaffold.dart';

/// Demande de réinitialisation du mot de passe (`POST /forgot-password`).
///
/// Le message affiché est volontairement prudent : la réponse de l'API est
/// identique que l'adresse existe ou non, afin de ne pas révéler quelles
/// adresses sont inscrites. Dire « e-mail envoyé » quand aucun n'a été
/// envoyé exposerait la liste des comptes.
class ForgotPasswordPage extends ConsumerStatefulWidget {
  const ForgotPasswordPage({super.key});

  @override
  ConsumerState<ForgotPasswordPage> createState() => _ForgotPasswordPageState();
}

class _ForgotPasswordPageState extends ConsumerState<ForgotPasswordPage> {
  final _email = TextEditingController();

  bool _busy = false;
  bool _sent = false;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final email = _email.text.trim();

    if (email.isEmpty || !email.contains('@') || !email.contains('.')) {
      setState(() => _error = 'Renseigne une adresse e-mail valide.');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      await ref.read(apiClientProvider).post(
        '/forgot-password',
        body: {'email': email},
      );

      if (!mounted) return;
      setState(() => _sent = true);
    } on ApiException catch (error) {
      // 429 après 5 tentatives par minute : le dire empêche de croire à un
      // échec de l'envoi alors que c'est le débit qui bloque.
      if (mounted) setState(() => _error = error.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AuthScaffold(
      title: 'Mot de passe oublié',
      subtitle: 'Ressaisis ton adresse : nous enverrons un lien de réinitialisation.',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_error != null) ...[
            ErrorBanner(message: _error!),
            const SizedBox(height: 20),
          ],

          if (_sent)
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: AppColors.success50,
                borderRadius: BorderRadius.circular(AppRadius.md),
                border: Border.all(color: AppColors.success200),
              ),
              child: const Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Icon(
                        Icons.mark_email_read_outlined,
                        size: 20,
                        color: AppColors.success600,
                      ),
                      SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          'Si un compte existe pour cette adresse, un lien vient d\'être envoyé.',
                          style: TextStyle(color: AppColors.success600, fontSize: 13, height: 1.4),
                        ),
                      ),
                    ],
                  ),
                  SizedBox(height: 10),
                  // Le lien part vers le SITE WEB : le backend construit l'URL
                  // depuis FRONTEND_URL. Le dire évite d'attendre un lien dans
                  // l'application sans comprendre pourquoi il ne vient pas.
                  Text(
                    'Le lien s\'ouvre sur le site web Tontine Achat Store : '
                    'c\'est là que le nouveau mot de passe se saisit.',
                    style: TextStyle(color: AppColors.ink600, fontSize: 12, height: 1.4),
                  ),
                ],
              ),
            )
          else ...[
            TextField(
              controller: _email,
              enabled: !_busy,
              keyboardType: TextInputType.emailAddress,
              autocorrect: false,
              textInputAction: TextInputAction.done,
              decoration: const InputDecoration(
                labelText: 'Adresse e-mail',
                prefixIcon: Icon(Icons.mail_outline, size: 20),
              ),
              onSubmitted: (_) => _submit(),
            ),
            const SizedBox(height: 24),
            FilledButton(
              onPressed: _busy ? null : _submit,
              child: _busy
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(
                        strokeWidth: 2.2,
                        valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
                      ),
                    )
                  : const Text('Envoyer le lien'),
            ),
          ],

          const SizedBox(height: 16),
          TextButton(
            onPressed: _busy ? null : () => Navigator.of(context).pop(),
            child: const Text('Revenir à la connexion'),
          ),
        ],
      ),
    );
  }
}
