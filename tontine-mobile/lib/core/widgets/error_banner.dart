import 'package:flutter/material.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';

/// Bandeau d'erreur non bloquant, affiché au-dessus d'un formulaire.
///
/// Distinct d'un `SnackBar` : il reste visible tant que la cause n'est pas
/// corrigée, ce qui est le cas d'une erreur de validation. Les erreurs qui
/// portent sur UN champ ne passent pas par là — elles s'affichent sous le
/// champ concerné, via `ApiException.validationErrors`.
class ErrorBanner extends StatelessWidget {
  const ErrorBanner({super.key, required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.danger50,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: AppColors.danger500),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.error_outline, size: 20, color: AppColors.danger600),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              message,
              style: const TextStyle(color: AppColors.danger600, fontSize: 13),
            ),
          ),
        ],
      ),
    );
  }
}
