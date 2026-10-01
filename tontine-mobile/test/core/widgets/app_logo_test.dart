import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tontine_achat_store/core/widgets/app_logo.dart';

/// Le bloc-marque.
///
/// La *lisibilité* du glyphe — le trait blanc posé par-dessus l'ombre — est
/// vérifiée à l'œil sur l'appareil : elle tient à l'ordre des passes de
/// peinture, que `flutter test` ne sait pas observer ici, le rendu hors écran
/// ne produisant aucune image dans un environnement sans rasteriseur. Ces tests
/// verrouillent donc ce qui peut l'être : la présence du nom, la parité des
/// deux déclinaisons, et la couleur du trait.
void main() {
  group('bloc-marque', () {
    testWidgets('le nom affiché est TAStore, plus le seul monogramme', (tester) async {
      // « TA » seul ne disait rien : ni la boutique, ni l'épargne.
      await tester.pumpWidget(
        const MaterialApp(home: Scaffold(body: Center(child: AppLogo.tinted()))),
      );

      expect(find.text('TAStore'), findsOneWidget);
      expect(find.byType(TontineMark), findsOneWidget);
    });

    testWidgets('le splash et la connexion partagent le même bloc-marque', (tester) async {
      // Une marque qui diffère entre l'écran d'ouverture et l'écran de
      // connexion se remarque immédiatement : les deux doivent venir du même
      // widget, non de deux dessins voisins.
      for (final logo in <Widget>[const AppLogo.tinted(), const AppLogo.onDark()]) {
        await tester.pumpWidget(MaterialApp(home: Scaffold(body: Center(child: logo))));

        expect(find.text('TAStore'), findsOneWidget);
        expect(find.byType(TontineMark), findsOneWidget);
      }
    });

    testWidgets('le glyphe est peint BLANC dans les deux déclinaisons', (tester) async {
      // Régression : l'ombre était peinte À LA PLACE du trait blanc, ce qui
      // rendait le glyphe presque invisible sur le fond du dégradé. La couleur
      // du trait est donc exposée et vérifiée ici.
      for (final tone in AppLogoTone.values) {
        await tester.pumpWidget(
          MaterialApp(
            home: Scaffold(body: Center(child: TontineMark(size: 120, tone: tone))),
          ),
        );

        expect(TontineMark.glyphColor, Colors.white, reason: tone.name);
      }
    });

    testWidgets('le glyphe reste dans son carreau', (tester) async {
      // Un glyphe plus grand que son fond serait rogné par les coins arrondis.
      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(body: Center(child: TontineMark(size: 90))),
        ),
      );

      final size = tester.getSize(find.byType(TontineMark));
      expect(size.width, 90);
      expect(size.height, 90);
    });
  });
}
