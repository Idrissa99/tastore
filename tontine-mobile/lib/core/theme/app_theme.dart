import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// Thème de l'application.
///
/// Les valeurs sont reprises UNE PAR UNE du design system du SPA web
/// (`tontine-frontend/src/index.css`, bloc `@theme`). Les deux clients doivent
/// afficher la même marque : un administrateur qui passe du catalogue web à
/// l'app mobile ne doit pas avoir l'impression d'une autre application.
///
/// Flutter n'a pas d'équivalent des variables CSS ni des `oklch` : on fige ici
/// les hex, ce qui reste lisible et vérifiable. Si une teinte change côté web,
/// c'est ce fichier qu'il faut mettre à jour.
class AppColors {
  const AppColors._();

  // Marque : vert profond / émeraude.
  static const primary50 = Color(0xFFF0FDFA);
  static const primary100 = Color(0xFFCCFBF1);
  static const primary200 = Color(0xFF99F6E4);
  static const primary300 = Color(0xFF5EEAD4);
  static const primary400 = Color(0xFF2DD4BF);
  static const primary500 = Color(0xFF14B8A6);
  static const primary600 = Color(0xFF0D9488);
  static const primary700 = Color(0xFF0F766E);
  static const primary800 = Color(0xFF115E59);
  static const primary900 = Color(0xFF134E4A);
  static const primary950 = Color(0xFF042F2E);

  // Secondaire : bleu nuit (sécurité / technologie).
  static const ink50 = Color(0xFFF8FAFC);
  static const ink100 = Color(0xFFF1F5F9);
  static const ink200 = Color(0xFFE2E8F0);
  static const ink300 = Color(0xFFCBD5E1);
  static const ink400 = Color(0xFF94A3B8);
  static const ink500 = Color(0xFF64748B);
  static const ink600 = Color(0xFF475569);
  static const ink700 = Color(0xFF334155);
  static const ink800 = Color(0xFF1E293B);
  static const ink900 = Color(0xFF0F172A);
  static const ink950 = Color(0xFF020617);

  // Accent premium : or.
  static const accent50 = Color(0xFFFFFBEB);
  static const accent100 = Color(0xFFFEF3C7);
  static const accent200 = Color(0xFFFDE68A);
  static const accent300 = Color(0xFFFCD34D);
  static const accent400 = Color(0xFFFBBF24);
  static const accent500 = Color(0xFFF59E0B);
  static const accent600 = Color(0xFFD97706);
  static const accent700 = Color(0xFFB45309);

  // Sémantique.
  //
  // La palette `warning` reprend exactement les mêmes hex que l'accent or du
  // web (`--color-warning-*` y est un alias d'`--color-accent-*`) : le backend
  // distingue les deux notions, l'œil ne les distingue pas.
  //
  // Les paliers `200` sont ceux des anneaux de badge côté web
  // (`ring-success-200`, `ring-warning-200`…). Ils n'existaient pas ici parce
  // qu'aucun badge n'était rendu ; ils sont ajoutés avec [AppTone].
  static const success50 = Color(0xFFF0FDF4);
  static const success100 = Color(0xFFDCFCE7);
  static const success200 = Color(0xFFBBF7D0);
  static const success500 = Color(0xFF16A34A);
  static const success600 = Color(0xFF15803D);
  static const success700 = Color(0xFF15803D);

  static const warning50 = Color(0xFFFFFBEB);
  static const warning100 = Color(0xFFFEF3C7);
  static const warning200 = Color(0xFFFDE68A);
  static const warning500 = Color(0xFFF59E0B);
  static const warning600 = Color(0xFFD97706);
  static const warning700 = Color(0xFFB45309);

  static const danger50 = Color(0xFFFEF2F2);
  static const danger100 = Color(0xFFFEE2E2);
  static const danger200 = Color(0xFFFECACA);
  static const danger500 = Color(0xFFDC2626);
  static const danger600 = Color(0xFFB91C1C);
  static const danger700 = Color(0xFF991B1B);

  static const info50 = Color(0xFFEFF6FF);
  static const info100 = Color(0xFFDBEAFE);
  static const info200 = Color(0xFFBFDBFE);
  static const info500 = Color(0xFF3B82F6);
  static const info600 = Color(0xFF2563EB);
  static const info700 = Color(0xFF1D4ED8);
}

/// Teintes de badge, alignées sur `TONES` du SPA web.
///
/// Le trio fond / texte / anneau est figé ici plutôt que recomposé à chaque
/// badge : c'est ce qui fait qu'un statut « En cours » a exactement la même
/// couleur sur le catalogue, sur la fiche et, demain, sur les cotisations.
class AppTone {
  const AppTone(this.background, this.foreground, this.border);

  final Color background;
  final Color foreground;
  final Color border;

  static const success = AppTone(AppColors.success50, AppColors.success700, AppColors.success200);
  static const warning = AppTone(AppColors.warning50, AppColors.warning700, AppColors.warning200);
  static const danger = AppTone(AppColors.danger50, AppColors.danger700, AppColors.danger200);
  static const info = AppTone(AppColors.info50, AppColors.info700, AppColors.info200);
  static const brand = AppTone(AppColors.primary50, AppColors.primary800, AppColors.primary200);
  static const accent = AppTone(AppColors.accent50, AppColors.accent700, AppColors.accent200);
  static const neutral = AppTone(AppColors.ink100, AppColors.ink600, AppColors.ink200);
}

/// Rayons : `--radius-*` du design system web (6/8/10/14/18/24 px).
class AppRadius {
  const AppRadius._();

  static const xs = 6.0;
  static const sm = 8.0;
  static const md = 10.0;
  static const lg = 14.0;
  static const xl = 18.0;
  static const xxl = 24.0;
}

class AppTheme {
  const AppTheme._();

  /// Police de l'app.
  ///
  /// Le web utilise Manrope (titres) et Inter (texte). On ne peut pas les
  /// embarquer sans ajouter les fichiers `.ttf` aux assets du projet : on
  /// s'appuie donc sur la police système, correcte nativement (Roboto sous
  /// Android, SF sous iOS). Les polices de marque se brancheront quand les
  /// fichiers seront ajoutés — ce n'est qu'une question d'assets, la
  /// structure du thème est déjà prête.
  static const String fontFallback = 'Roboto';

  static ThemeData get light {
    final scheme = ColorScheme.fromSeed(
      seedColor: AppColors.primary600,
      brightness: Brightness.light,
    ).copyWith(
      primary: AppColors.primary600,
      onPrimary: Colors.white,
      secondary: AppColors.accent500,
      onSecondary: AppColors.ink900,
      surface: Colors.white,
      onSurface: AppColors.ink900,
      error: AppColors.danger600,
      onError: Colors.white,
    );

    final base = ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      scaffoldBackgroundColor: AppColors.ink50,
      fontFamily: fontFallback,
    );

    return base.copyWith(
      appBarTheme: const AppBarTheme(
        backgroundColor: Colors.white,
        foregroundColor: AppColors.ink900,
        elevation: 0,
        scrolledUnderElevation: 1,
        centerTitle: false,
        titleTextStyle: TextStyle(
          fontFamily: fontFallback,
          fontSize: 19,
          fontWeight: FontWeight.w700,
          color: AppColors.ink900,
        ),
        systemOverlayStyle: SystemUiOverlayStyle(
          statusBarColor: Colors.transparent,
          statusBarIconBrightness: Brightness.dark,
          statusBarBrightness: Brightness.light,
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: AppColors.ink50,
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
        border: _inputBorder(AppColors.ink200),
        enabledBorder: _inputBorder(AppColors.ink200),
        focusedBorder: _inputBorder(AppColors.primary600, width: 1.6),
        errorBorder: _inputBorder(AppColors.danger500),
        focusedErrorBorder: _inputBorder(AppColors.danger600, width: 1.6),
        labelStyle: const TextStyle(color: AppColors.ink600),
        hintStyle: const TextStyle(color: AppColors.ink400),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: AppColors.primary600,
          foregroundColor: Colors.white,
          disabledBackgroundColor: AppColors.ink300,
          minimumSize: const Size.fromHeight(52),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(AppRadius.md),
          ),
          textStyle: const TextStyle(
            fontFamily: fontFallback,
            fontSize: 16,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: AppColors.primary700,
          textStyle: const TextStyle(
            fontFamily: fontFallback,
            fontWeight: FontWeight.w600,
          ),
        ),
      ),
      cardTheme: CardThemeData(
        color: Colors.white,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppRadius.lg),
          side: const BorderSide(color: AppColors.ink200),
        ),
      ),
      chipTheme: base.chipTheme.copyWith(
        side: const BorderSide(color: AppColors.ink200),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppRadius.xs),
        ),
        // La couleur est INDISPENSABLE, et son absence n'est pas un détail
        // cosmétique : Material 3 ne retombe sur aucune couleur de repli quand
        // un `labelStyle` est fourni sans `color`. Les puces non sélectionnées
        // s'affichaient alors en blanc sur fond blanc — des rectangles vides,
        // sans libellé, sur la carte de cotisation. La puce sélectionnée, elle,
        // était lisible, ce qui rendait le défaut encore plus trompeur.
        labelStyle: const TextStyle(
          fontFamily: fontFallback,
          fontSize: 12,
          fontWeight: FontWeight.w600,
          color: AppColors.ink900,
        ),
        secondaryLabelStyle: const TextStyle(
          fontFamily: fontFallback,
          fontSize: 12,
          fontWeight: FontWeight.w700,
          color: AppColors.primary800,
        ),
        backgroundColor: Colors.white,
        selectedColor: AppColors.primary100,
        checkmarkColor: AppColors.primary800,
      ),
      dividerTheme: const DividerThemeData(
        color: AppColors.ink200,
        thickness: 1,
        space: 1,
      ),
      progressIndicatorTheme: const ProgressIndicatorThemeData(
        color: AppColors.primary600,
        linearTrackColor: AppColors.ink200,
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        backgroundColor: AppColors.ink900,
        contentTextStyle: const TextStyle(
          fontFamily: fontFallback,
          color: Colors.white,
        ),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppRadius.md),
        ),
      ),
    );
  }

  static OutlineInputBorder _inputBorder(Color color, {double width = 1}) {
    return OutlineInputBorder(
      borderRadius: BorderRadius.circular(AppRadius.md),
      borderSide: BorderSide(color: color, width: width),
    );
  }
}
