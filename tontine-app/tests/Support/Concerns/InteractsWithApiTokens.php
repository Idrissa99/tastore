<?php

namespace Tests\Support\Concerns;

use App\Models\User;

/**
 * PRIORITÉ 3 — Utilitaires d'authentification pour les tests.
 *
 * Laravel réutilise le conteneur (donc l'instance du garde `sanctum`) d'une
 * requête à l'autre dans un même test. Sans forgetting explicite, un token
 * révoqué continuerait d'être accepté, ce qui rendrait les tests de révocation
 * faux-positifs. Ces helpers revalident réellement le jeton à chaque appel.
 */
trait InteractsWithApiTokens
{
    protected function issueTokenFor($user, string $name = 'spa'): string
    {
        return $user->createToken($name)->plainTextToken;
    }

    /**
     * Requête API avec un Bearer token, gardes réinitialisés au préalable.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function apiWithToken(string $token, string $method, string $uri, array $payload = [], array $headers = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(array_merge(['Authorization' => 'Bearer '.$token], $headers))
            ->json($method, $uri, $payload);
    }

    protected function getAs(string $token, string $uri, array $headers = [])
    {
        return $this->apiWithToken($token, 'GET', $uri, [], $headers);
    }

    protected function postAs(string $token, string $uri, array $payload = [], array $headers = [])
    {
        return $this->apiWithToken($token, 'POST', $uri, $payload, $headers);
    }

    protected function putAs(string $token, string $uri, array $payload = [], array $headers = [])
    {
        return $this->apiWithToken($token, 'PUT', $uri, $payload, $headers);
    }

    /**
     * Connexion réelle via l'API et renvoi du token en clair.
     */
    protected function loginViaApi(User $user, string $password = 'password'): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertOk()->json('token');
    }
}
