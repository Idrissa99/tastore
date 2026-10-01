<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsMerchant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user && $user->isMerchant(), 403, 'Réservé aux commerçants.');
        abort_unless($user->merchant && $user->merchant->isApproved(), 403, 'Ton compte commerçant est en attente de validation.');

        return $next($request);
    }
}
