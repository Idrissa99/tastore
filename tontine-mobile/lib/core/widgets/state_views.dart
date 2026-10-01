import 'package:flutter/material.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';

/// Chargement centré, avec le libellé de ce qui charge.
///
/// Le libellé n'est pas décoratif : « Chargement des tontines… » rassure sur ce
/// que l'application fait, alors qu'un spinner muet laisse croire à un blocage.
class LoadingView extends StatelessWidget {
  const LoadingView({super.key, this.label = 'Chargement…'});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const SizedBox(
              width: 26,
              height: 26,
              child: CircularProgressIndicator(strokeWidth: 2.4),
            ),
            const SizedBox(height: 16),
            Text(
              label,
              textAlign: TextAlign.center,
              style: const TextStyle(color: AppColors.ink500, fontSize: 13),
            ),
          ],
        ),
      ),
    );
  }
}

/// Écran vide, avec une action de sortie quand l'utilisateur en a une.
///
/// Le texte distingue « il n'y a rien » de « tes filtres ne donnent rien » :
/// la seconde phrase propose de les enlever, ce qui est la seule action utile.
class EmptyView extends StatelessWidget {
  const EmptyView({
    super.key,
    required this.title,
    required this.message,
    this.icon = Icons.inbox_outlined,
    this.actionLabel,
    this.onAction,
  });

  final String title;
  final String message;
  final IconData icon;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    final action = actionLabel;

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 40, color: AppColors.ink300),
            const SizedBox(height: 14),
            Text(
              title,
              textAlign: TextAlign.center,
              style: const TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.w800,
                color: AppColors.ink900,
              ),
            ),
            const SizedBox(height: 6),
            Text(
              message,
              textAlign: TextAlign.center,
              style: const TextStyle(color: AppColors.ink500, fontSize: 13, height: 1.4),
            ),
            if (action != null && onAction != null) ...[
              const SizedBox(height: 20),
              OutlinedButton.icon(
                onPressed: onAction,
                icon: const Icon(Icons.close, size: 18),
                label: Text(action),
                style: OutlinedButton.styleFrom(
                  foregroundColor: AppColors.ink700,
                  side: const BorderSide(color: AppColors.ink200),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// Erreur bloquante, avec réessai.
///
/// `onRetry` est obligatoire : sans action, un serveur injoignable ne laisse
/// à l'utilisateur que la fermeture de l'application.
class ErrorView extends StatelessWidget {
  const ErrorView({
    super.key,
    required this.message,
    required this.onRetry,
    this.icon = Icons.cloud_off_outlined,
  });

  final String message;
  final VoidCallback onRetry;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 40, color: AppColors.danger500),
            const SizedBox(height: 14),
            Text(
              message,
              textAlign: TextAlign.center,
              style: const TextStyle(color: AppColors.ink600, fontSize: 14, height: 1.4),
            ),
            const SizedBox(height: 20),
            OutlinedButton.icon(
              onPressed: onRetry,
              icon: const Icon(Icons.refresh, size: 18),
              label: const Text('Réessayer'),
              style: OutlinedButton.styleFrom(
                foregroundColor: AppColors.primary700,
                side: const BorderSide(color: AppColors.primary200),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
