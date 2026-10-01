<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| Origines autorisées à appeler l'API. Elles sont pilotées par le .env :
|   FRONTEND_URL   -> origine principale (obligatoire)
|   FRONTEND_URLS  -> origines supplémentaires, séparées par des virgules
|
| Jamais de "*" : un "*" en production exposerait l'API à n'importe quel site.
| Voir App\Providers\AppServiceProvider::configureCors() qui refuse explicitement
| une configuration wildcard quand APP_ENV=production.
|
*/

$frontendUrl = rtrim((string) env('FRONTEND_URL', ''), '/');
$extraUrls = array_filter(array_map(
    fn ($url) => rtrim(trim($url), '/'),
    explode(',', (string) env('FRONTEND_URLS', ''))
));

// `app()` est interdit ici : le fichier de configuration est chargé pendant
// le bootstrap, avant que le dépôt de configuration ne soit résolvable.
// On lit donc APP_ENV directement.
$isLocal = in_array((string) env('APP_ENV', 'production'), ['local', 'testing'], true);

$origins = array_values(array_unique(array_filter(array_merge(
    // Développement : Vite tourne tantôt sur localhost, tantôt sur 127.0.0.1.
    $isLocal ? ['http://localhost:5173', 'http://127.0.0.1:5173'] : [],
    [$frontendUrl],
    $extraUrls
))));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => (int) env('CORS_MAX_AGE', 0),

    // false : l'API utilise des tokens Bearer, pas des cookies de session.
    'supports_credentials' => false,
];
