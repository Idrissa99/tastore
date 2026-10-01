import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:tontine_achat_store/core/auth/auth_controller.dart';
import 'package:tontine_achat_store/core/di/providers.dart';
import 'package:tontine_achat_store/core/network/api_client.dart';
import 'package:tontine_achat_store/core/network/api_exception.dart';
import 'package:tontine_achat_store/core/storage/token_store.dart';
import 'package:tontine_achat_store/features/profile/profile_repository.dart';

Map<String, dynamic> userJson({
  int id = 7,
  String name = 'Awa Sanou',
  bool verified = true,
  String phone = '+22790000000',
}) {
  return {
    'id': id,
    'name': name,
    'email': 'awa@exemple.ne',
    'phone': phone,
    'role': 'client',
    'is_verified': verified,
    'email_verified_at': verified ? '2026-09-01T10:00:00.000000Z' : null,
    'avatar_url': null,
    'merchant': null,
    'created_at': '2026-09-01T09:00:00.000000Z',
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

/// `TokenStore` en memoire : le vrai s'appuie sur `flutter_secure_storage`,
/// dont les canaux de plateforme n'existent pas sous `flutter test`.
class _MemoryTokenStore extends TokenStore {
  String? token;

  @override
  Future<String?> read() async => token;

  @override
  Future<void> write(String value) async => token = value;

  @override
  Future<void> clear() async => token = null;
}

void main() {
  group('profil : enveloppe', () {
    test('GET /profil renvoie un objet NU', () async {
      // `ProfileController::show` rend `UserResource::resolve()`, donc sans
      // enveloppe. Une décompression appliquée par réflexe renverrait un objet
      // sans champs — la fiche profil s'afficherait entièrement vide.
      final container = mount((_) async => jsonBody(userJson()));
      addTearDown(container.dispose);

      final user = await container.read(profileRepositoryProvider).read();

      expect(user['name'], 'Awa Sanou');
      expect(user['email'], 'awa@exemple.ne');
    });

    test('PUT /profil renvoie lui aussi un objet nu', () async {
      late http.Request sent;
      final container = mount(
        (request) async {
          sent = request;
          return jsonBody(userJson(name: 'Awa Sanou II'));
        },
      );
      addTearDown(container.dispose);

      final user = await container
          .read(profileRepositoryProvider)
          .update(name: 'Awa Sanou II', phone: '+22790000000');

      expect(sent.method, 'PUT');
      expect(user['name'], 'Awa Sanou II');
    });

    test('changer le mot de passe envoie le trio attendu', () async {
      late http.Request sent;
      final container = mount((request) async {
        sent = request;
        return jsonBody(userJson());
      });
      addTearDown(container.dispose);

      await container.read(profileRepositoryProvider).update(
            name: 'Awa',
            phone: '+22790000000',
            currentPassword: 'ancien-mdp',
            newPassword: 'nouveau-mdp',
          );

      final body = jsonDecode(sent.body) as Map<String, dynamic>;
      // `PUT`, et non `PATCH` : c'est le seul verbe que la route déclare. Un
      // PATCH recevait 405 et l'enregistrement du profil échouait quel que soit
      // le corps envoyé — la fiche profil était simplement inutilisable.
      expect(sent.method, 'PUT');
      expect(body['current_password'], 'ancien-mdp');
      expect(body['new_password'], 'nouveau-mdp');
      // `confirmed` côté validation : sans la confirmation, le serveur renvoie
      // 422 et le mot de passe n'est jamais changé.
      expect(body['new_password_confirmation'], 'nouveau-mdp');
    });

    test('sans changement de mot de passe, aucun champ mot de passe n\'est envoyé', () async {
      late http.Request sent;
      final container = mount((request) async {
        sent = request;
        return jsonBody(userJson());
      });
      addTearDown(container.dispose);

      await container.read(profileRepositoryProvider).update(
            name: 'Awa',
            phone: '+22790000000',
          );

      final body = jsonDecode(sent.body) as Map<String, dynamic>;
      expect(body.containsKey('new_password'), isFalse);
      expect(body['name'], 'Awa');
    });

    test('une erreur de validation remonte le champ concerné', () async {
      final container = mount(
        (_) async => jsonBody(
          {
            'message': 'Les données fournies sont invalides.',
            'errors': {'phone': ['Ce numéro de téléphone est déjà utilisé.']},
          },
          status: 422,
        ),
      );
      addTearDown(container.dispose);

      await expectLater(
        container.read(profileRepositoryProvider).update(name: 'Awa', phone: '+22790000009'),
        throwsA(
          isA<ApiException>()
              .having((error) => error.isValidation, 'isValidation', isTrue)
              .having((error) => error.fieldError('phone'), 'phone', contains('déjà utilisé')),
        ),
      );
    });
  });

  group('ProfileView', () {
    test('lit les champs sans inventer de modèle', () {
      final view = ProfileView.of(userJson(verified: false));

      expect(view.name, 'Awa Sanou');
      expect(view.role, 'client');
      expect(view.isVerified, isFalse);
      expect(view.businessName, isEmpty, reason: 'un client n\'a pas de fiche commerçant');
    });

    test('le nom de commerce n\'est lu que pour un commerçant', () {
      final raw = userJson()..['merchant'] = {'business_name': 'Awa Boutique', 'status': 'pending'};
      final view = ProfileView.of(raw);

      expect(view.businessName, 'Awa Boutique');
      expect(view.merchantStatus, 'pending');
    });
  });

  group('état de session', () {
    AuthState session(Object? user) =>
        user == null ? const AuthState(AuthStatus.unauthenticated) : AuthState(AuthStatus.authenticated, user: user as Map<String, dynamic>);

    test('un e-mail non vérifié est détecté', () {
      expect(session(userJson(verified: false)).isEmailVerified, isFalse);
      expect(session(userJson(verified: true)).isEmailVerified, isTrue);
    });

    test('une session sans le champ est traitée comme vérifiée', () {
      // Administrateur, ou réponse `/login` réduite : afficher un avertissement
      // « e-mail non vérifié » à quelqu'un dont le compte est valide serait
      // une fausse alerte.
      expect(session({'id': 1, 'name': 'Admin'}).isEmailVerified, isTrue);
      expect(session(null).isEmailVerified, isTrue);
    });

    test('`applyUser` met à jour la session sans en changer le statut', () async {
      final store = _MemoryTokenStore()..token = 'jeton';
      final container = ProviderContainer(
        overrides: [
          tokenStoreProvider.overrideWithValue(store),
          apiClientProvider.overrideWith((ref) {
            final client = ApiClient(
              httpClient: MockClient((request) async => jsonBody(userJson(name: 'Ancien Nom'))),
              baseUrl: 'http://api.test/api',
              readToken: store.read,
              writeToken: store.write,
              clearToken: store.clear,
            );
            ref.onDispose(client.dispose);
            return client;
          }),
        ],
      );
      addTearDown(container.dispose);

      final controller = container.read(authControllerProvider.notifier);
      await controller.bootstrap();
      expect(container.read(authControllerProvider).user!['name'], 'Ancien Nom');

      controller.applyUser(userJson(name: 'Nouveau Nom'));

      final state = container.read(authControllerProvider);
      // C'est ce que fait l'écran de profil après un enregistrement : sans
      // cela l'accueil continuerait d'afficher l'ancien nom.
      expect(state.status, AuthStatus.authenticated);
      expect(state.user!['name'], 'Nouveau Nom');
    });

    test('hors session, `applyUser` ne fabrique pas de session', () async {
      final container = ProviderContainer(
        overrides: [tokenStoreProvider.overrideWithValue(_MemoryTokenStore())],
      );
      addTearDown(container.dispose);

      final controller = container.read(authControllerProvider.notifier);
      controller.applyUser(userJson());

      // Ouvrir une session à partir d'un écran de profil n'a pas de sens :
      // l'utilisateur doit d'abord se connecter.
      expect(container.read(authControllerProvider).status, AuthStatus.unknown);
    });
  });
}
