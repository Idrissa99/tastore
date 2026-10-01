import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:tontine_achat_store/core/auth/auth_controller.dart';
import 'package:tontine_achat_store/core/di/providers.dart';
import 'package:tontine_achat_store/core/network/api_client.dart';
import 'package:tontine_achat_store/core/network/api_exception.dart';
import 'package:tontine_achat_store/core/storage/token_store.dart';

/// `TokenStore` en mémoire.
///
/// Le vrai s'appuie sur `flutter_secure_storage`, dont les canaux de
/// plateforme n'existent pas sous `flutter test` : sans ce doublure, tout
/// test de la session se heurterait à un `MissingPluginException` avalé en
/// silence par `TokenStore` — et l'échec se déguiserait en « pas de jeton »,
/// donc en « session fermée », ce qui ferait passer n'importe quel test pour
/// une raison fausse.
class _MemoryTokenStore extends TokenStore {
  String? token;

  @override
  Future<String?> read() async => token;

  @override
  Future<void> write(String value) async => token = value;

  @override
  Future<void> clear() async => token = null;
}

/// Requêtes captées, pour vérifier ce que l'app envoie réellement.
class _Call {
  _Call(this.method, this.uri, this.body);

  final String method;
  final Uri uri;
  final Map<String, dynamic>? body;
}

void main() {
  late _MemoryTokenStore store;
  late List<_Call> calls;
  late ProviderContainer container;

  /// Monte un conteneur dont l'API répond via [handler].
  ProviderContainer mount(
    Future<http.Response> Function(http.Request request) handler, {
    String? token,
  }) {
    store = _MemoryTokenStore()..token = token;
    calls = [];

    return ProviderContainer(
      overrides: [
        tokenStoreProvider.overrideWithValue(store),
        apiClientProvider.overrideWith((ref) {
          final client = ApiClient(
            httpClient: MockClient((request) {
              calls.add(
                _Call(
                  request.method,
                  request.url,
                  request.body.isEmpty ? null : jsonDecode(request.body),
                ),
              );
              return handler(request);
            }),
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
  }

  http.Response json(Object body, {int status = 200}) => http.Response(
        jsonEncode(body),
        status,
        headers: {'content-type': 'application/json'},
      );

  AuthController controller() => container.read(authControllerProvider.notifier);

  tearDown(() => container.dispose());

  group('inscription', () {
    test('envoie les six champs, persiste le jeton et ouvre la session', () async {
      final response = json(
        {
          'token': 'jeton-1|abcdef',
          'user': {'id': 7, 'name': 'Awa', 'role': 'merchant'},
        },
        status: 201,
      );
      container = mount((_) async => response);

      await controller().register(
        name: '  Awa Sanou  ',
        email: '  awa@exemple.ne ',
        phone: ' +227 90 00 00 00 ',
        password: 'motdepasse',
        passwordConfirmation: 'motdepasse',
        role: 'merchant',
      );

      final call = calls.single;
      expect(call.method, 'POST');
      expect(call.uri.toString(), 'http://api.test/api/register');
      expect(call.body, {
        'name': 'Awa Sanou',
        'email': 'awa@exemple.ne',
        'phone': '+227 90 00 00 00',
        'password': 'motdepasse',
        'password_confirmation': 'motdepasse',
        'role': 'merchant',
      });

      // Le jeton est écrit AVANT l'état : une session ouverte sans jeton
      // persistant planterait au redémarrage suivant.
      expect(store.token, 'jeton-1|abcdef');

      final state = container.read(authControllerProvider);
      expect(state.status, AuthStatus.authenticated);
      expect(state.user?['role'], 'merchant');
    });

    test('les espaces du mot de passe ne sont pas rognés', () async {
      container = mount((_) async => json({'token': 't', 'user': <String, dynamic>{}}));

      await controller().register(
        name: 'Awa',
        email: 'awa@exemple.ne',
        phone: '+22790000000',
        password: '  mot de passe  ',
        passwordConfirmation: '  mot de passe  ',
        role: 'client',
      );

      expect(calls.single.body?['password'], '  mot de passe  ');
    });

    test('un 422 remonte les erreurs par champ, sans ouvrir de session', () async {
      final response = json(
        {
          'message': 'Les données fournies sont invalides.',
          'errors': {
            'email': ['Cet email est déjà utilisé.'],
            'phone': ['Ce numéro de téléphone est déjà utilisé.'],
          },
        },
        status: 422,
      );
      container = mount((_) async => response);

      await expectLater(
        controller().register(
          name: 'Awa',
          email: 'awa@exemple.ne',
          phone: '+22790000000',
          password: 'motdepasse',
          passwordConfirmation: 'motdepasse',
          role: 'client',
        ),
        throwsA(
          isA<ApiException>()
              .having((error) => error.isValidation, 'isValidation', isTrue)
              .having(
                (error) => error.fieldError('email'),
                'email',
                'Cet email est déjà utilisé.',
              )
              .having(
                (error) => error.fieldError('phone'),
                'phone',
                'Ce numéro de téléphone est déjà utilisé.',
              ),
        ),
      );

      expect(store.token, isNull);
      expect(container.read(authControllerProvider).status, AuthStatus.unknown);
    });

    test('un 2xx sans jeton est un échec explicite, pas une session fantôme', () async {
      container = mount((_) async => json({'user': <String, dynamic>{}}, status: 201));

      await expectLater(
        controller().register(
          name: 'Awa',
          email: 'awa@exemple.ne',
          phone: '+22790000000',
          password: 'motdepasse',
          passwordConfirmation: 'motdepasse',
          role: 'client',
        ),
        throwsA(isA<ApiException>().having((error) => error.statusCode, 'statusCode', 500)),
      );

      expect(store.token, isNull);
      expect(container.read(authControllerProvider).status, AuthStatus.unknown);
    });
  });

  group('connexion', () {
    test('rogne les espaces de l\'adresse, garde le mot de passe', () async {
      container = mount((_) async => json({'token': 't', 'user': <String, dynamic>{}}));

      await controller().signIn(email: '  awa@exemple.ne ', password: ' motdepasse ');

      expect(calls.single.body, {'email': 'awa@exemple.ne', 'password': ' motdepasse '});
    });
  });

  group('démarrage', () {
    test('sans jeton, aucune requête n\'est partie', () async {
      container = mount((_) async => fail('le réseau ne doit pas être sollicité'));

      await controller().bootstrap();

      expect(calls, isEmpty);
      expect(container.read(authControllerProvider).status, AuthStatus.unauthenticated);
    });

    test('avec un jeton valide, l\'utilisateur est celui de /me', () async {
      container = mount(
        (_) async => json({'id': 7, 'name': 'Awa', 'role': 'client'}),
        token: 'jeton-1',
      );

      await controller().bootstrap();

      expect(calls.single.uri.path, '/api/me');
      expect(container.read(authControllerProvider).user?['name'], 'Awa');
      expect(container.read(authControllerProvider).status, AuthStatus.authenticated);
    });

    test('un jeton rejeté par l\'API est effacé', () async {
      container = mount(
        (_) async => json({'message': 'Unauthenticated.'}, status: 401),
        token: 'jeton-mort',
      );

      await controller().bootstrap();

      expect(store.token, isNull);
      expect(container.read(authControllerProvider).status, AuthStatus.unauthenticated);
    });

    test('un serveur injoignable ne vaut PAS déconnexion', () async {
      // L'utilisateur garde son jeton : la coupure réseau est temporaire, et
      // le renvoyer vers la connexion lui ferait croire que sa session a pris
      // fin. Il reste sur le splash, qui réessaiera.
      container = mount(
        (_) async => throw const SocketException('réseau coupé'),
        token: 'jeton-1',
      );

      await controller().bootstrap();

      expect(store.token, 'jeton-1');
      expect(container.read(authControllerProvider).status, AuthStatus.unknown);
    });

    test('un délai dépassé vaut aussi un réseau injoignable', () async {
      container = mount((_) async {
        await Future<void>.delayed(const Duration(milliseconds: 50));
        throw TimeoutException('trop long');
      },
      token: 'jeton-1',
      );

      await controller().bootstrap().timeout(const Duration(seconds: 2));

      expect(store.token, 'jeton-1');
      expect(container.read(authControllerProvider).status, AuthStatus.unknown);
    });
  });
}
