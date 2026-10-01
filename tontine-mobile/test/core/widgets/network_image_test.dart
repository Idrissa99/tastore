import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tontine_achat_store/core/config/app_config.dart';
import 'package:tontine_achat_store/core/network/media.dart';
import 'package:tontine_achat_store/core/widgets/network_image.dart';

/// RÉGRESSION — l'image à l'écran n'était pas celle que l'application croyait
/// afficher.
///
/// [AppNetworkImage] appelait `mediaUrl(url)` pour décider s'il y avait une
/// image à montrer, puis passait le `url` BRUT au chargeur. Les deux valeurs
/// diffèrent précisément dans les cas qui échouent : un chemin `/storage/…`
/// n'a pas d'hôte, et une URL absolue bâtie sur un `APP_URL` périmé pointe
/// vers une machine qui n'est plus la bonne. Le widget testait donc une URL et
/// chargeait l'autre — et le résultat était le repli « Pas de photo », sans
/// message, sur une photo que le serveur rend en 200.
///
/// Ce test verrouille l'URL RÉSOLUE qui part au chargeur, pas seulement
/// l'absence d'exception.
void main() {
  final origin = AppConfig.backendOrigin;

  /// URL demandée à `CachedNetworkImage`, lue dans l'arbre.
  String loadedUrl(WidgetTester tester) {
    final image = tester.widget<CachedNetworkImage>(find.byType(CachedNetworkImage));

    return image.imageUrl;
  }

  group('AppNetworkImage charge l\'URL résolue', () {
    testWidgets('un chemin relatif reçoit l\'origine de l\'API', (tester) async {
      await tester.pumpWidget(
        const _Host(child: AppNetworkImage(url: '/storage/products/images/a.jpg')),
      );

      expect(loadedUrl(tester), '$origin/storage/products/images/a.jpg');
    });

    testWidgets('une IP privée périmée est réécrite', (tester) async {
      // Le cas réel : le backend renvoie `http://192.168.3.106:8000/…` depuis
      // son `APP_URL`, le téléphone est compilé pour une autre adresse, et le
      // fichier EST pourtant servi. C'est ce test qui a raté avant la
      // correction.
      await tester.pumpWidget(
        const _Host(child: AppNetworkImage(url: 'http://192.168.3.106:8000/storage/a.jpg')),
      );

      expect(loadedUrl(tester), '$origin/storage/a.jpg');
    });

    testWidgets('une URL déjà correcte est laissée telle quelle', (tester) async {
      final url = '$origin/storage/a.jpg';
      await tester.pumpWidget(_Host(child: AppNetworkImage(url: url)));

      expect(loadedUrl(tester), url);
    });

    testWidgets('une image en ligne n\'est pas réécrite', (tester) async {
      const inline = 'data:image/png;base64,AAAA';
      await tester.pumpWidget(const _Host(child: AppNetworkImage(url: inline)));

      expect(loadedUrl(tester), inline);
    });

    testWidgets('une URL absente affiche le repli sans rien charger', (tester) async {
      await tester.pumpWidget(
        const _Host(child: AppNetworkImage(url: null, fallbackLabel: 'Pas de photo')),
      );

      expect(find.byType(CachedNetworkImage), findsNothing);
      expect(find.text('Pas de photo'), findsOneWidget);
    });

    testWidgets('une chaîne vide affiche le repli', (tester) async {
      await tester.pumpWidget(const _Host(child: AppNetworkImage(url: '   ')));

      expect(find.byType(CachedNetworkImage), findsNothing);
    });
  });

  group('mediaUrl est idempotent', () {
    test('appliquer deux fois ne change rien', () {
      // C'est ce qui autorise les appelants à résoudre en amont ET le widget à
      // résoudre de son côté : sans cela, l'une des deux conventions cassait.
      const raw = '/storage/products/images/a.jpg';
      final once = mediaUrl(raw)!;

      expect(mediaUrl(once), once);
    });

    test('sur une IP privée déjà réécrite', () {
      final once = mediaUrl('http://192.168.3.106:8000/storage/a.jpg')!;

      expect(mediaUrl(once), once);
    });
  });
}

/// Enveloppe minimale : [CachedNetworkImage] a besoin d'une direction et d'un
/// MediaQuery, absents d'un test de widget nu.
class _Host extends StatelessWidget {
  const _Host({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      home: Scaffold(
        body: Center(
          child: SizedBox(width: 200, height: 120, child: child),
        ),
      ),
    );
  }
}