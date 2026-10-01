<?php

/*
|--------------------------------------------------------------------------
| Réglages de sécurité applicatifs
|--------------------------------------------------------------------------
|
| Regroupe les interrupteurs de sécurité qui ne sont pas portés par un paquet
| tiers. Les valeurs restent pilotables par .env pour que la production
| n'ait rien à coder.
|
*/

return [
    /*
    | Changer son mot de passe invalide-t-il les AUTRES sessions ouvertes ?
    | true (défaut) : un token volé devient inutilisable dès le changement.
    | La session courante survit pour ne pas déconnecter l'utilisateur actif.
    */
    'revoke_others_on_password_change' => filter_var(
        env('SESSION_REVOKE_OTHERS_ON_PASSWORD_CHANGE', true),
        FILTER_VALIDATE_BOOLEAN
    ),

    /*
    | Les tokens Sanctum expirent-ils ?
    | La durée en minutes vit dans config/sanctum.php (SANCTUM_TOKEN_EXPIRATION).
    | Ce commutant permet de tout couper d'un coup sans toucher au .env.
    */
    'enforce_token_expiration' => (bool) env('SANCTUM_TOKEN_EXPIRATION', 43200),

    /*
    | Durée maximale d'une session avant rotation obligatoire, en minutes.
    | 0 = pas de rotation forcée en plus de l'expiration du token.
    */
    'session_idle_timeout' => (int) env('SESSION_IDLE_TIMEOUT', 0),

    /*
    | Proxies de confiance (Priorité 4 §18).
    |
    | Derriere Nginx/Apache/Cloudflare, sans cette liste Laravel voit l'IP du
    | proxy et le schema `http` : redirections HTTPS en HTTP, `Request::ip()`
    | errone (journalisation, rate limiting), generation d'URL faussee.
    |
    | Separes par des virgules. Formats acceptes : `1.2.3.4`, `10.0.0.0/8`,
    | `*` (assume : machine unique derriere un reverse proxy maitrise).
    |
    | NE JAMAIS mettre `*` derriere une IP publique sans raison : cela permet
    | a un client de forger `X-Forwarded-For` et donc de contourner un
    | rate limiting par IP.
    |
    */
    'trusted_proxies' => (string) env('TRUSTED_PROXIES', ''),
];
