<?php

namespace App\Services;

use App\Models\User;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * PRIORITÉ 3 — Politique de révocation des sessions (tokens Sanctum).
 *
 * Règles :
 *  - un token Sanctum a une durée de vie finie (config('sanctum.expiration'),
 *    pilotée par SANCTUM_TOKEN_EXPIRATION) ;
 *  - changer son mot de passe invalide TOUTES les AUTRES sessions ;
 *  - réinitialiser son mot de passe ou être bloqué par un admin invalide
 *    TOUTES les sessions, y compris la courante ;
 *  - se déconnecter révoque le token utilisé, et rien d'autre.
 *
 * Aucun mot de passe, token ou code de transfert n'est journalisé ici.
 */
class SessionRevocationService
{
    /**
     * @return int nombre de sessions révoquées
     */
    public function revokeAll(User $user): int
    {
        if (! $this->managesTokens($user)) {
            return 0;
        }

        return $user->tokens()->delete();
    }

    /**
     * Conserve la session courante, coupe toutes les autres.
     *
     * @return int nombre de sessions révoquées
     */
    public function revokeOthers(User $user, ?PersonalAccessToken $current = null): int
    {
        if (! $this->managesTokens($user)) {
            return 0;
        }

        return $user->tokens()
            ->when($current, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->delete();
    }

    /**
     * Appelé après un changement de mot de passe réussi.
     *
     * @return int nombre de sessions révoquées
     */
    public function afterPasswordChange(User $user, ?PersonalAccessToken $current = null): int
    {
        if (! config('security.revoke_others_on_password_change', true)) {
            return 0;
        }

        return $this->revokeOthers($user, $current);
    }

    /**
     * Appelé après une réinitialisation de mot de passe : c'est la procédure
     * de récupération d'un compte compromis, donc TOUTES les sessions tombent.
     */
    public function afterPasswordReset(User $user): int
    {
        return $this->revokeAll($user);
    }

    /**
     * Appelé quand un administrateur bloque un compte : les tokens doivent
     * devenir inutilisables immédiatement, pas seulement au prochain login.
     */
    public function afterAccountBlocked(User $user): int
    {
        return $this->revokeAll($user);
    }

    /**
     * Révoque le token de la requête courante s'il y en a un, sans planter
     * quand l'authentification vient d'un cookie de session (jeton transitoire)
     * ou d'un garde `web` (aucun token).
     *
     * @return bool true si un token a effectivement été révoqué
     */
    public function revokeCurrentToken(User $user): bool
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();

            return true;
        }

        return false;
    }

    /**
     * Garde défensive : un modèle sans le trait HasApiTokens n'a pas de
     * relation `tokens()`. Sans ce test, l'erreur remonterait en 500.
     */
    private function managesTokens(User $user): bool
    {
        return in_array(HasApiTokens::class, class_uses_recursive($user), true)
            && method_exists($user, 'tokens');
    }
}
