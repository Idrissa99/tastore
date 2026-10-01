import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:tontine_achat_store/core/di/providers.dart';
import 'package:tontine_achat_store/core/network/api_client.dart';
import 'package:tontine_achat_store/features/purchases/purchase_providers.dart';

Map<String, dynamic> productJson({int id = 5, String name = 'Téléphone', String? image}) {
  return {
    'id': id,
    'name': name,
    'description': 'Smartphone 128 Go',
    'category': 'high-tech',
    'price': '300000.00',
    'stock': 3,
    'image': image,
    'images': [
      if (image != null) {'id': 1, 'url': image},
    ],
    'video': null,
    'status': 'published',
    'merchant': {'id': 2, 'business_name': 'Awa Boutique', 'city': 'Cotonou', 'rating': 4.5},
    'created_at': '2026-09-01T10:00:00.000000Z',
  };
}

Map<String, dynamic> purchaseJson({
  int id = 1,
  int installmentsCount = 6,
  int paid = 2,
  bool includeInstallments = true,
}) {
  return {
    'id': id,
    'product': productJson(),
    'installments_count': installmentsCount,
    'installment_amount': '50000.00',
    'product_price': '300000.00',
    'status': 'active',
    'delivery_status': 'pending',
    'delivered_at': null,
    'paid_installments_count': paid,
    'installments': includeInstallments
        ? [
            for (var index = 1; index <= installmentsCount; index++)
              {
                'id': index,
                'installment_number': index,
                'amount': '50000.00',
                'status': index <= paid ? 'completed' : 'pending',
                'payment_method': index <= paid ? 'orange_money' : null,
                'transaction_reference': index <= paid ? 'FAKE-AB$index' : null,
                'verification_status': 'not_applicable',
                'transfer_code': null,
                'submitted_at': null,
                'paid_at': index <= paid ? '2026-09-20T10:00:00.000000Z' : null,
              },
          ]
        : null,
    'created_at': '2026-09-15T10:00:00.000000Z',
  };
}

http.Response jsonBody(Object body, {int status = 200}) => http.Response(
      jsonEncode(body),
      status,
      headers: {'content-type': 'application/json'},
    );

ProviderContainer mount(Future<http.Response> Function(http.Request request) handler) {
  final calls = <http.Request>[];

  return ProviderContainer(
    overrides: [
      apiClientProvider.overrideWith((ref) {
        final client = ApiClient(
          httpClient: MockClient((request) {
            calls.add(request);
            return handler(request);
          }),
          baseUrl: 'http://api.test/api',
          readToken: () async => 'jeton',
          writeToken: (_) async {},
          clearToken: () async {},
        );
        ref.onDispose(client.dispose);
        return client;
      }),
    ],
  );
}

void main() {
  group('produits', () {
    test('le catalogue se lit dans l\'enveloppe du paginateur', () async {
      // `ProductResource::collection()` sur un paginateur : `data` est la liste,
      // et ses éléments ne sont PAS enveloppés individuellement.
      final container = mount(
        (_) async => jsonBody({
          'data': [productJson(id: 1), productJson(id: 2)],
          'meta': {'current_page': 1, 'last_page': 3, 'total': 30},
        }),
      );
      addTearDown(container.dispose);

      final page = await container.read(purchaseRepositoryProvider).products();

      expect(page.items.length, 2);
      expect(page.lastPage, 3);
      expect(page.items.first.name, 'Téléphone');
    });

    test('la fiche produit est enveloppée', () async {
      final container = mount((_) async => jsonBody({'data': productJson()}));
      addTearDown(container.dispose);

      final product = await container.read(purchaseRepositoryProvider).product(5);

      expect(product.id, 5);
      expect(product.price, 300000);
      expect(product.merchantName, 'Awa Boutique');
      expect(product.merchantRating, 4.5);
    });

    test('les photos de `image` et de `images[]` sont toutes reprises', () async {
      // Deux représentations coexistent côté API : n'en lire qu'une ferait
      // disparaître des photos le jour où le backend change.
      final container = mount(
        (_) async => jsonBody({
          'data': {
            ...productJson(),
            'image': '/storage/a.jpg',
            'images': [
              {'id': 1, 'url': '/storage/b.jpg'},
            ],
          },
        }),
      );
      addTearDown(container.dispose);

      final product = await container.read(purchaseRepositoryProvider).product(5);

      expect(product.images.length, 2);
      expect(product.coverUrl, contains('/storage/a.jpg'));
    });

    test('un produit sans photo n\'a pas d\'URL', () async {
      final container = mount((_) async => jsonBody({'data': productJson()}));
      addTearDown(container.dispose);

      final product = await container.read(purchaseRepositoryProvider).product(5);

      expect(product.images, isEmpty);
      expect(product.coverUrl, isNull);
    });
  });

  group('aperçu des tranches', () {
    test('la réponse est NUE, sans enveloppe', () async {
      // Le contrôleur rend un `response()->json()` et non une ressource : c'est
      // le seul point du parcours achats qui ne soit pas enveloppé. Une
      // décompression appliquée par réflexe afficherait zéro partout.
      final container = mount((request) async {
        expect(request.url.path, '/api/produits/5/tranches/apercu');

        return jsonBody({
          'commission_rate': 0.07,
          'installment_amount': 53500.0,
          'total': 321000.0,
        });
      });
      addTearDown(container.dispose);

      final preview = await container.read(purchaseRepositoryProvider).preview(5, installmentsCount: 6);

      expect(preview.installmentAmount, 53500);
      expect(preview.total, 321000);
      expect(preview.commissionRate, closeTo(0.07, 1e-9));
    });

    test('le total inclut la commission, il est donc supérieur au prix', () async {
      final container = mount(
        (_) async => jsonBody({
          'commission_rate': 0.07,
          'installment_amount': 53500.0,
          'total': 321000.0,
        }),
      );
      addTearDown(container.dispose);

      final preview = await container.read(purchaseRepositoryProvider).preview(5, installmentsCount: 6);

      // 300 000 de produit + 7 % : c'est ce que l'utilisateur paiera au total.
      expect(preview.total, greaterThan(300000));
      expect(preview.installmentAmount * 6, preview.total);
    });
  });

  group('achats', () {
    test('la liste est une collection enveloppée, sans paginateur', () async {
      final container = mount(
        (_) async => jsonBody({
          'data': [purchaseJson(id: 1), purchaseJson(id: 2)],
        }),
      );
      addTearDown(container.dispose);

      final all = await container.read(purchaseRepositoryProvider).purchases();

      expect(all.length, 2);
      expect(all.first.installmentAmount, 50000);
    });

    test('la fiche déballe l\'enveloppe et lit les tranches', () async {
      final container = mount((_) async => jsonBody({'data': purchaseJson()}));
      addTearDown(container.dispose);

      final purchase = await container.read(purchaseRepositoryProvider).purchase(1);

      expect(purchase.id, 1);
      expect(purchase.installments.length, 6);
      expect(purchase.paidInstallmentsCount, 2);
      expect(purchase.product!.name, 'Téléphone');
    });

    test('la prochaine tranche est la première non payée', () async {
      final container = mount((_) async => jsonBody({'data': purchaseJson()}));
      addTearDown(container.dispose);

      final purchase = await container.read(purchaseRepositoryProvider).purchase(1);

      expect(purchase.nextInstallment!.number, 3);
      expect(purchase.nextInstallment!.isPayable, isTrue);
    });

    test('une tranche payée n\'est plus proposée', () async {
      final container = mount((_) async => jsonBody({'data': purchaseJson(paid: 6)}));
      addTearDown(container.dispose);

      final purchase = await container.read(purchaseRepositoryProvider).purchase(1);

      expect(purchase.isFullyPaid, isTrue);
      expect(purchase.nextInstallment, isNull);
      expect(purchase.paidRatio, 1.0);
    });

    test('une tranche dont le code est en attente n\'est pas payable', () async {
      final json = purchaseJson(paid: 1);
      (json['installments'] as List)[1]['verification_status'] = 'pending';

      final container = mount((_) async => jsonBody({'data': json}));
      addTearDown(container.dispose);

      final purchase = await container.read(purchaseRepositoryProvider).purchase(1);

      // La 2 est bloquée : le serveur répondrait 409.
      expect(purchase.installments[1].isPayable, isFalse);
      expect(purchase.nextInstallment!.number, 3);
    });

    test('payer une tranche renvoie l\'ACHAT, pas la tranche', () async {
      // C'est ce qui permet de remplacer l'affichage sans second aller-retour,
      // et sans risque de montrer un compte de tranches qui contredit le
      // bouton que l'utilisateur vient de toucher.
      final container = mount((request) async {
        expect(request.url.path, '/api/tranches/3/pay');
        expect(request.body.contains('"payment_method":"orange_money"'), isTrue);

        return jsonBody({'data': purchaseJson(paid: 3)});
      });
      addTearDown(container.dispose);

      final updated = await container
          .read(purchaseRepositoryProvider)
          .payInstallment(3, channel: 'orange_money');

      expect(updated.paidInstallmentsCount, 3);
      expect(updated.nextInstallment!.number, 4);
    });

    test('soumettre le code d\'une tranche renvoie aussi l\'achat', () async {
      final container = mount((request) async {
        expect(request.url.path, '/api/tranches/3/soumettre-code');

        return jsonBody({
          'data': {
            ...purchaseJson(),
            'installments': [
              {
                'id': 3,
                'installment_number': 3,
                'amount': '50000.00',
                'status': 'pending',
                'payment_method': 'mynita',
                'transaction_reference': null,
                'verification_status': 'pending',
                'transfer_code': 'ABC123',
                'submitted_at': '2026-09-20T10:00:00Z',
                'paid_at': null,
              },
            ],
          },
        });
      });
      addTearDown(container.dispose);

      final updated = await container
          .read(purchaseRepositoryProvider)
          .submitInstallmentCode(3, channel: 'mynita', transferCode: 'ABC123');

      expect(updated.installments.single.verificationStatus.awaitsDecision, isTrue);
    });
  });
}
