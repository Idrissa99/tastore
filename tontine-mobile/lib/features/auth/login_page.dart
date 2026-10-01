import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:tontine_achat_store/core/auth/auth_controller.dart';
import 'package:tontine_achat_store/core/network/api_exception.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/core/widgets/error_banner.dart';
import 'package:tontine_achat_store/features/auth/auth_scaffold.dart';
import 'package:tontine_achat_store/routing/app_router.dart';

/// Connexion.
///
/// `POST /login` ne demande que deux champs, `email` et `password`
/// (`LoginRequest`) : l'erreur de validation du backend n'a donc rien à nous
/// apprendre de plus que « identifiants incorrects ». On ne construit pas de
/// formulaire dynamique pour deux règles, on affiche le message du serveur.
class LoginPage extends ConsumerStatefulWidget {
  const LoginPage({super.key});

  @override
  ConsumerState<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends ConsumerState<LoginPage> {
  final _formKey = GlobalKey<FormState>();
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();

  bool _obscure = true;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _emailController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      await ref.read(authControllerProvider.notifier).signIn(
            email: _emailController.text,
            password: _passwordController.text,
          );
      // Pas de navigation manuelle : passer l'état à `authenticated` suffit,
      // le routeur redirige. C'est lui l'unique décideur.
    } on ApiException catch (error) {
      if (mounted) setState(() => _error = error.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AuthScaffold(
      title: 'Connexion',
      subtitle: 'Accède à tes tontines et à tes cotisations.',
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (_error != null) ...[
              ErrorBanner(message: _error!),
              const SizedBox(height: 20),
            ],

            TextFormField(
              controller: _emailController,
              enabled: !_busy,
              keyboardType: TextInputType.emailAddress,
              autocorrect: false,
              textInputAction: TextInputAction.next,
              autofillHints: const [AutofillHints.email],
              decoration: const InputDecoration(
                labelText: 'Adresse e-mail',
                prefixIcon: Icon(Icons.mail_outline, size: 20),
              ),
              validator: (value) {
                final text = (value ?? '').trim();
                if (text.isEmpty) return 'Renseigne ton adresse e-mail.';
                if (!text.contains('@') || !text.contains('.')) {
                  return 'Cette adresse ne semble pas valide.';
                }
                return null;
              },
            ),
            const SizedBox(height: 16),

            TextFormField(
              controller: _passwordController,
              enabled: !_busy,
              obscureText: _obscure,
              textInputAction: TextInputAction.done,
              autofillHints: const [AutofillHints.password],
              onFieldSubmitted: (_) => _submit(),
              decoration: InputDecoration(
                labelText: 'Mot de passe',
                prefixIcon: const Icon(Icons.lock_outline, size: 20),
                suffixIcon: IconButton(
                  onPressed: () => setState(() => _obscure = !_obscure),
                  icon: Icon(
                    _obscure
                        ? Icons.visibility_outlined
                        : Icons.visibility_off_outlined,
                    size: 20,
                  ),
                  tooltip: _obscure ? 'Afficher' : 'Masquer',
                ),
              ),
              validator: (value) =>
                  (value ?? '').isEmpty ? 'Renseigne ton mot de passe.' : null,
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
                  : const Text('Se connecter'),
            ),
            const SizedBox(height: 16),

            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const Text(
                  'Pas encore de compte ?',
                  style: TextStyle(color: AppColors.ink500, fontSize: 13),
                ),
                TextButton(
                  onPressed: _busy ? null : () => context.go(AppRoutes.register),
                  child: const Text('Inscription'),
                ),
              ],
            ),
            Center(
              child: TextButton(
                onPressed: _busy ? null : () => context.push(AppRoutes.forgotPassword),
                child: const Text('Mot de passe oublié ?'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
