import 'package:flutter_test/flutter_test.dart';
import 'package:tontine_achat_store/core/config/app_config.dart';
import 'package:tontine_achat_store/core/network/api_client.dart';
import 'package:tontine_achat_store/features/merchant/merchant_models.dart';
import 'package:tontine_achat_store/features/merchant/merchant_repository.dart';

/// RÉGRESSION — l'espace commerçant est né avec deux pièges de sérialisation,
/// tous deux invisibles tant qu'on ne regarde pas ce que le backend RENVOIE
/// vraiment. Les deux ont été trouvés en lisant les contrôleurs Laravel, pas
/// en devinant : l'un est dans les enveloppes, l'autre dans les prix.
void main() {
  final origin = AppConfig.backendOrigin;

  group('ProductStatus', () {
    test('les trois valeurs du serveur sont reconnues', () {
      expect(ProductStatus.fromWire('draft'), ProductStatus.draft);
      expect(ProductStatus.fromWire('published'), ProductStatus.published);
      expect(ProductStatus.fromWire('archived'), ProductStatus.archived);
    });

    test('un statut inconnu ou absent ne devient pas « publié »', () {
      // Le repli est `draft` : un produit qu'on ne sait pas classer ne doit
      // surtout pas être affiché comme en vente. L'inverse mettrait en ligne
      // un produit qu'aucun vendeur n'a jamais voulu publier.
      expect(ProductStatus.fromWire(null), ProductStatus.draft);
      expect(ProductStatus.fromWire(''), ProductStatus.draft);
      expect(ProductStatus.fromWire('quelque-chose'), ProductStatus.draft);
    });

    test('la valeur wire est celle attendue par l\'API', () {
      // Le serveur valide `in:draft,published,archived` : le libellé français
      // ne part JAMAIS sur le réseau.
      expect(ProductStatus.draft.wire, 'draft');
      expect(ProductStatus.published.wire, 'published');
      expect(ProductStatus.archived.wire, 'archived');
    });
  });

  group('MerchantProduct', () {
    Map<String, dynamic> product(Map<String, dynamic> overrides) => {
          'id': 11,
          'name': 'Lunette de soleil',
          'description': 'Verres polarisés.',
          'category': 'Accessoires',
          'price': '15000.00',
          'stock': 4,
          'status': 'published',
          'images': <Object?>[],
          'video': null,
          'created_at': '2026-08-20T08:30:36.000000Z',
          ...overrides,
        };

    test('le prix est lu comme un double malgré sa forme décimale', () {
      // RÉGRESSION : `ProductResource` déclare `price` en `decimal:2`, et
      // Laravel le sérialise donc en CHAÎNE « 15000.00 ». Un `as double` ou un
      // `toDouble()` naïf sur du JSON typé dynamiquement rendrait l'écran
      // entier rouge, et un `(json['price'] as num)` lèverait.
      expect(MerchantProduct.fromJson(product({})).price, 15000.0);
    });

    test('un prix décimal avec une virgule est aussi accepté', () {
      expect(
        MerchantProduct.fromJson(product({'price': '1250,50'})).price,
        1250.5,
      );
    });

    test('les photos gardent leur identifiant ET leur URL résolue', () {
      // L'identifiant est indispensable : la suppression passe par
      // `DELETE /merchant/products/media/{id}`, pas par l'URL.
      final parsed = MerchantProduct.fromJson(
        product({
          'images': [
            {'id': 3, 'url': 'http://192.168.3.106:8000/storage/products/images/a.jpg'},
          ],
        }),
      );

      expect(parsed.images, hasLength(1));
      expect(parsed.images.first.id, 3);
      expect(parsed.images.first.url, '$origin/storage/products/images/a.jpg');
      expect(parsed.coverUrl, '$origin/storage/products/images/a.jpg');
    });

    test('un produit sans photo ne fabrique pas de couverture', () {
      expect(MerchantProduct.fromJson(product({})).coverUrl, isNull);
    });

    test('la vidéo est distinguée des photos', () {
      final parsed = MerchantProduct.fromJson(
        product({
          'video': {'id': 9, 'url': '/storage/products/videos/v.mp4'},
        }),
      );

      expect(parsed.video!.id, 9);
      expect(parsed.video!.isVideo, isTrue);
      expect(parsed.video!.url, '$origin/storage/products/videos/v.mp4');
    });

    test('rupture de stock et publication se lisent sans le serveur', () {
      final out = MerchantProduct.fromJson(product({'stock': 0}));
      expect(out.isOutOfStock, isTrue);
      expect(out.isLive, isTrue);

      final draft = MerchantProduct.fromJson(product({'status': 'draft'}));
      expect(draft.isOutOfStock, isFalse);
      expect(draft.isLive, isFalse);
    });

    test('tontines_count absent vaut zéro, pas « aucune vente » fausse', () {
      // Seul le tableau de bord compte la relation (`withCount`). Ailleurs le
      // produit n'a pas été compté : afficher « 0 tontine » serait une
      // affirmation fausse, et l'interface s'en sert pour interdire la
      // suppression.
      expect(MerchantProduct.fromJson(product({})).tontinesCount, 0);
      expect(MerchantProduct.fromJson(product({'tontines_count': 3})).tontinesCount, 3);
    });
  });

  group('MerchantDashboard', () {
    /// Réponse RÉELLE de `GET /merchant/dashboard`.
    ///
    /// Elle est reproduite ici parce que sa forme est le cœur du piège : les
    /// produits y sont un tableau NU, alors que `/merchant/products` renvoie
    /// `{data: [...]}`. Déballer les deux de la même façon fait afficher un
    /// catalogue vide au vendeur, sans la moindre erreur.
    Map<String, dynamic> dashboard() => {
          'merchant': {
            'id': 2,
            'business_name': 'Boutique Sanou',
            'city': 'Cotonou',
            'status': 'approved',
            'rating': 4.5,
          },
          // Tableau nu : le contrôleur y applique `->resolve()`.
          'products': [
            {
              'id': 11,
              'name': 'Lunette de soleil',
              'price': '15000.00',
              'stock': 4,
              'status': 'published',
              'images': <Object?>[],
              'tontines_count': 2,
            },
            {
              'id': 12,
              'name': 'Pile',
              'price': '2500.00',
              'stock': 0,
              'status': 'draft',
              'images': <Object?>[],
            },
          ],
          'pending_deliveries_tontine': [
            {
              'id': 31,
              'user_name': 'Ibrahim Sawadogo',
              'beneficiary_round': 3,
              'delivery_status': 'pending',
              'is_eligible': true,
              'blockers': <Object?>[],
              'delivered_at': null,
              'tontine': {
                'id': 5,
                'name': 'Tontine Riz',
                'current_round': 3,
                'product': {'id': 11, 'name': 'Lunette de soleil'},
              },
            },
          ],
          'waiting_for_payment_tontine': <Object?>[],
          'pending_deliveries_installment': [
            {
              'id': 44,
              'product_id': 11,
              'installments_count': 6,
              'delivery_status': 'pending',
              'delivered_at': null,
              'user': {'id': 8, 'name': 'Awa Kone'},
              'product': {'id': 11, 'name': 'Lunette de soleil'},
            },
          ],
          'revenue': {'contributions': 120000.0, 'installments': 45000.0, 'total': 165000.0},
        };

    test('les produits sont lus malgré leur tableau déballé', () {
      expect(MerchantDashboard.fromJson(dashboard()).products, hasLength(2));
    });

    test('les compteurs reflètent les statuts et les stocks', () {
      final parsed = MerchantDashboard.fromJson(dashboard());

      expect(parsed.publishedCount, 1);
      expect(parsed.outOfStockCount, 1);
    });

    test('le chiffre d\'affaires est décomposé et totalisé', () {
      final parsed = MerchantDashboard.fromJson(dashboard());

      expect(parsed.revenue.total, 165000.0);
      expect(parsed.revenue.contributions, 120000.0);
      expect(parsed.revenue.installments, 45000.0);
    });

    test('seule la livraison éligible compte comme actionnable', () {
      // C'est le cœur du tableau de bord : une livraison en attente de
      // versement n'est PAS livrable, et la compter comme telle enverrait le
      // commerçant séparer un produit contre un paiement non validé.
      final parsed = MerchantDashboard.fromJson(dashboard());

      expect(parsed.actionableDeliveries, 2); // 1 tontine + 1 tranche
      expect(parsed.pendingTontineDeliveries.single.isEligible, isTrue);
    });

    test('la file des tranches lit `user` et `product` à plat', () {
      // L'endpoint renvoie le MODÈLE Eloquent, sans ressource : le produit et
      // l'utilisateur sont des sous-objets, jamais des chaînes.
      final parsed = MerchantDashboard.fromJson(dashboard());
      final delivery = parsed.pendingInstallmentDeliveries.single;

      expect(delivery.userName, 'Awa Kone');
      expect(delivery.productName, 'Lunette de soleil');
      expect(delivery.installmentsCount, 6);
    });

    test('une réponse sans aucune file ne donne pas d\'erreur', () {
      // Les files sont censées être TOUJOURS présentes, mais un tableau vide
      // manquant ferait planter un écran qui n'a rien à afficher.
      final parsed = MerchantDashboard.fromJson({
        'merchant': {'business_name': 'Boutique', 'status': 'approved'},
        'products': <Object?>[],
      });

      expect(parsed.products, isEmpty);
      expect(parsed.revenue.total, 0);
      expect(parsed.actionableDeliveries, 0);
    });
  });

  group('DeliveryItem', () {
    test('les obstacles sont lus et traduits', () {
      final parsed = DeliveryItem.fromJson({
        'id': 31,
        'user_name': 'Ibrahim',
        'beneficiary_round': 3,
        'delivery_status': 'pending',
        'is_eligible': false,
        'blockers': ['round_not_paid', 'turn_not_active'],
        'delivered_at': null,
        'tontine': {'name': 'Tontine Riz', 'current_round': 3, 'product': {'name': 'Lunette'}},
      });

      expect(parsed.blockers, ['round_not_paid', 'turn_not_active']);
      expect(parsed.isEligible, isFalse);
      expect(deliveryBlockerLabel('round_not_paid'), contains('collecte'));
    });

    test('un code d\'obstacle inconnu reste visible', () {
      // Le masquer ferait croire que l'obstacle a disparu, alors qu'il signale
      // un décalage entre le backend et l'application.
      expect(deliveryBlockerLabel('code_qui_nexiste_pas'), 'code_qui_nexiste_pas');
    });

    test('`is_eligible` absent vaut faux, jamais vrai', () {
      // Mieux vaut ne proposer la confirmation que le serveur confirme que
      // l'inverse : proposer une remise non permise se corrige en 403.
      expect(DeliveryItem.fromJson({'id': 1}).isEligible, isFalse);
    });

    test('une tontine sans produit produit ne casse pas la lecture', () {
      final parsed = DeliveryItem.fromJson({
        'id': 1,
        'is_eligible': true,
        'tontine': {'name': 'Tontine Argent', 'product': null},
      });

      expect(parsed.productName, '');
      expect(parsed.tontineName, 'Tontine Argent');
    });

    test('les statuts de livraison sont distingués', () {
      expect(DeliveryItem.fromJson({'id': 1, 'delivery_status': 'delivered'}).isDelivered, isTrue);
      expect(
        DeliveryItem.fromJson({'id': 1, 'delivery_status': 'awaiting_payment'}).isAwaitingPayment,
        isTrue,
      );
      expect(DeliveryItem.fromJson({'id': 1, 'delivery_status': 'pending'}).isDelivered, isFalse);
    });
  });

  group('ProductDraft', () {
    test('les six champs du serveur sont TOUJOURS envoyés', () {
      // RÉGRESSION : `StoreProductRequest` sert la création ET la mise à jour,
      // donc tous les champs y sont obligatoires. Un formulaire d'édition qui
      // n'enverrait que le nom se ferait refuser en 422 sur `price` — le
      // vendeur verrait «The price field is required» alors qu'il a touché au
      // stock.
      final fields = const ProductDraft(
        name: ' Lunette ',
        description: ' Verres ',
        category: ' Accessoires ',
        price: 15000,
        stock: 4,
        status: ProductStatus.published,
      ).toFields();

      expect(fields.keys.toSet(), {
        'name',
        'description',
        'category',
        'price',
        'stock',
        'status',
      });
      expect(fields['name'], 'Lunette');
      expect(fields['price'], '15000.00');
    });

    test('sans fichier, le corps JSON suffit', () {
      const draft = ProductDraft(
        name: 'Pile',
        description: '',
        category: '',
        price: 2500,
        stock: 0,
        status: ProductStatus.draft,
      );

      expect(draft.hasFiles, isFalse);
      expect(draft.toMultipart().containsKey('images[]'), isFalse);
    });

    test('avec des photos, le multipart porte le champ `images[]`', () {
      // Le nom de champ n'est pas libre : le serveur lit
      // `$request->file('images', [])`, et `FilePart` utilise `images[]` par
      // défaut. Un nom différent se ferait ignorer silencieusement, et le
      // vendeur ne verrait jamais ses photos.
      const draft = ProductDraft(
        name: 'Lunette',
        description: '',
        category: '',
        price: 15000,
        stock: 1,
        status: ProductStatus.published,
        imagePaths: ['/tmp/a.jpg'],
      );

      final body = draft.toMultipart();
      expect(draft.hasFiles, isTrue);
      expect(body['images[]'], isA<List<FilePart>>());
      expect((body['images[]'] as List).single.field, 'images[]');
    });

    test('la vidéo porte son propre nom de champ', () {
      const draft = ProductDraft(
        name: 'Lunette',
        description: '',
        category: '',
        price: 15000,
        stock: 1,
        status: ProductStatus.published,
        videoPath: '/tmp/v.mp4',
      );

      final video = draft.toMultipart()['video'] as FilePart;
      expect(video.field, 'video');
    });

    test('l\'édition repart du produit existant, statut compris', () {
      // Régression de comportement : un produit PUBLIÉ ne doit pas repasser en
      // brouillon parce qu'on a ouvert son formulaire. Cela le retirerait du
      // catalogue sans que personne l'ait demandé.
      final product = MerchantProduct.fromJson({
        'id': 11,
        'name': 'Lunette',
        'description': '',
        'category': '',
        'price': '15000.00',
        'stock': 4,
        'status': 'published',
        'images': <Object?>[],
      });

      expect(ProductDraft.fromProduct(product).status, ProductStatus.published);
    });
  });

  group('bornes d\'upload', () {
    test('elles correspondent aux règles du backend', () {
      // `StoreProductRequest` : `images` max 6, `images.*` max 5120 Ko,
      // `video` max 51200 Ko. proposer 10 Mo par photo ne produirait qu'un
      // 422 après avoir attendu le téléchargement complet.
      expect(maxImagesPerUpload, 6);
      expect(maxImageKilobytes, 5120);
      expect(maxVideoKilobytes, 51200);
    });
  });
}