<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCanCreateTontine
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $allowed = $user && (
            $user->isAdmin()
            || ($user->isMerchant() && $user->merchant?->isApproved())
        );

        abort_unless($allowed, 403, 'Seuls les commerçants approuvés ou les administrateurs peuvent créer une tontine.');

        return $next($request);
    }
}
