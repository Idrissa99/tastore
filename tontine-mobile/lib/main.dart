import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:tontine_achat_store/app.dart';
import 'package:tontine_achat_store/core/config/app_config.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();

  // Portrait uniquement : les écrans de l'app sont des listes et des fiches,
  // et le paysage n'apporte rien ici. La barre de statut est transparente
  // pour que le dégradé de l'écran d'ouverture remonte sous la barre système.
  SystemChrome.setPreferredOrientations([
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
  ]);
  SystemChrome.setSystemUIOverlayStyle(
    const SystemUiOverlayStyle(statusBarColor: Colors.transparent),
  );

  if (AppConfig.looksLikeEmulatorAlias && !_isProduction) {
    debugPrint(
      'URL de l\'API non fournie : l\'app suppose ${AppConfig.apiBaseUrl}. '
      'Sur un téléphone physique, passe par :\n'
      '  flutter run --dart-define=API_BASE_URL=http://<ip-de-ta-machine>:8000/api',
    );
  }

  runApp(const ProviderScope(child: TontineApp()));
}

const bool _isProduction = bool.fromEnvironment('dart.vm.product');
