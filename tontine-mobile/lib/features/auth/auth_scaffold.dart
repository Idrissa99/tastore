import 'package:flutter/material.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/core/widgets/app_logo.dart';

/// Charpente commune aux écrans « hors session » : connexion, inscription.
///
/// Le logo, le titre et le sous-titre étaient recopiés d'un écran à l'autre.
/// Ils sont ici factorisés, avec la largeur maximale de 440 px reprise du
/// formulaire web : sans elle, le logo et le titre s'étirent sur toute la
/// largeur d'un téléphone, ce qui n'a rien d'un formulaire.
class AuthScaffold extends StatelessWidget {
  const AuthScaffold({
    super.key,
    required this.title,
    required this.subtitle,
    required this.child,
  });

  final String title;
  final String subtitle;

  /// Le contenu du formulaire, bandeau d'erreur et bouton compris.
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 32),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 440),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const AppLogo.tinted(),
                  const SizedBox(height: 24),
                  Text(
                    title,
                    style: const TextStyle(
                      fontSize: 26,
                      fontWeight: FontWeight.w800,
                      color: AppColors.ink900,
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    subtitle,
                    style: const TextStyle(color: AppColors.ink500, fontSize: 14),
                  ),
                  const SizedBox(height: 28),
                  child,
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
