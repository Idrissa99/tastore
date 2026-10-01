<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Source de vérité de l'état d'un compte : `is_blocked`.
 *
 * `email_verified_at` reste la seule source de vérité de la vérification
 * email (User::booted synchronise `is_verified`) — ce middleware ne s'en sert
 * pas, pour ne pas créer de deuxième source de vérité.
 *
 * Un compte bloqué :
 *  - ne passe plus aucune route authentifiée ;
 *  - voit sa session web détruite ;
 *  - voit ses tokens Sanctum révoqués sur-le-champ, afin qu'un ancien jeton
 *    ne redevienne pas utilisable si le compte est ensuite débloqué.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if (! $user->is_blocked) {
            return $next($request);
        }

        $this->cutOffAccess($request, $user);

        abort(403, 'Ton compte a été suspendu. Contacte le support pour plus d\'informations.');
    }

    private function cutOffAccess(Request $request, $user): void
    {
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            // Ne révoque que le jeton présenté : révoquer tous ceux du compte
            // ici serait une charge inutile (l'admin le fait au blocage) et
            // casserait les sessions légitimes d'un simple appel.
            $token->delete();
        }
    }
}
