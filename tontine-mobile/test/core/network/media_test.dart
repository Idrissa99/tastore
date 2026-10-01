import 'package:flutter_test/flutter_test.dart';
import 'package:tontine_achat_store/core/config/app_config.dart';
import 'package:tontine_achat_store/core/network/media.dart';

/// L'hôte de référence est celui passé à la compilation — c'est le seul que
/// l'application connaît avec certitude. Il est LU de la configuration plutôt
/// qu'écrit en dur : le test doit rester vrai que l'on compile pour un
/// téléphone (`10.0.2.2` sur l'émulateur) ou depuis un bureau.
final _origin = AppConfig.backendOrigin;

void main() {
  group('chemins relatifs', () {
    test('un chemin nu reçoit l\'origine de l\'API', () {
      expect(
        mediaUrl('/storage/products/images/a.jpg'),
        '$_origin/storage/products/images/a.jpg',
      );
    });

    test('un chemin sans barre oblique initiale est traité pareil', () {
      expect(mediaUrl('storage/a.jpg'), '$_origin/storage/a.jpg');
    });

    test('une valeur vide ou nulle ne donne pas d\'URL', () {
      expect(mediaUrl(null), isNull);
      expect(mediaUrl(''), isNull);
      expect(mediaUrl('   '), isNull);
    });

    test('une image inline est laissée intacte', () {
      // Elle ne transite par aucun réseau : réécrire son « origine » la
      // casserait.
      const inline = 'data:image/png;base64,AAAA';
      expect(mediaUrl(inline), inline);
    });
  });

  group('URL absolues', () {
    test('une IP privée périmée est réécrite', () {
      // RÉGRESSION : le backend fabrique ses URLs depuis son `APP_URL`, qui
      // vaut l'adresse Wi-Fi du poste — celle-ci change au renouvellement du
      // bail DHCP. Le fichier est pourtant servi correctement, et l'image
      // disparaissait de l'application sans la moindre erreur : un « Pas de
      // photo » qui ment.
      expect(
        mediaUrl('http://192.168.3.106:8000/storage/products/images/a.jpg'),
        '$_origin/storage/products/images/a.jpg',
      );
    });

    test('les autres réseaux privés sont traités de même', () {
      const path = '/storage/products/images/a.jpg';

      expect(mediaUrl('http://10.0.2.2:8000$path'), '$_origin$path');
      expect(mediaUrl('http://127.0.0.1:8000$path'), '$_origin$path');
      expect(mediaUrl('http://localhost:8000$path'), '$_origin$path');
      expect(mediaUrl('http://172.16.4.9:8000$path'), '$_origin$path');
    });

    test('l\'origine déjà correcte est conservée', () {
      final url = '$_origin/storage/products/images/a.jpg';

      expect(mediaUrl(url), url);
    });

    test('un vrai domaine n\'est JAMAIS réécrit', () {
      // Une image servie par un CDN reste chez son auteur : le réécrire enverrait
      // le téléphone chercher un fichier qui n'existe pas sur le backend.
      const cdn = 'https://cdn.exemple.com/images/a.jpg';

      expect(mediaUrl(cdn), cdn);
      expect(
        mediaUrl('https://mon-app.test/media/a.jpg'),
        'https://mon-app.test/media/a.jpg',
      );
    });

    test('une IP publique reste en place', () {
      // Un hébergeur distant n'est pas le réseau privé du poste : le distinguer
      // du cas précédent est tout l'intérêt de la règle.
      const distant = 'http://93.184.216.34:8000/storage/a.jpg';

      expect(mediaUrl(distant), distant);
    });
  });

  group('produit', () {
    Map<String, dynamic> product(List<Object?>? images, {Object? image}) => {
          'images': images,
          'image': image,
        };

    test('la première image du tableau est utilisée', () {
      final url = productImage(
        product([
          {'id': 1, 'url': 'http://192.168.3.106:8000/storage/a.jpg'},
          {'id': 2, 'url': 'http://192.168.3.106:8000/storage/b.jpg'},
        ]),
      );

      expect(url, '$_origin/storage/a.jpg');
    });

    test('un tableau d\'URL simples est aussi accepté', () {
      expect(
        productImage(product(['/storage/a.jpg'])),
        '$_origin/storage/a.jpg',
      );
    });

    test('le champ historique sert de repli', () {
      // Une tontine n'embarque qu'un extrait de produit, où seule la forme
      // `image` est garantie.
      expect(
        productImage(product(const [], image: '/storage/legacy.jpg')),
        '$_origin/storage/legacy.jpg',
      );
    });

    test('un tableau vide et un champ vide ne donnent rien', () {
      expect(productImage(product(const [])), isNull);
      expect(productImage(null), isNull);
    });

    test('un produit sans photo ne fabrique pas d\'URL', () {
      // Le cas le plus fréquent : il faut distinguer « pas de photo » de
      // « photo invisible », sans quoi l'interface affiche un cadre vide au
      // lieu du repli prévu.
      expect(productImage(product(const [], image: null)), isNull);
    });
  });
}
