<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SessionRevocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    /**
     * Réponse volontairement identique que l'email existe ou non : sinon la
     * route permet d'énumérer les comptes enregistrés.
     */
    private const NEUTRAL_MESSAGE = 'Si un compte utilise cette adresse, un lien de réinitialisation vient d\'être envoyé.';

    public function __construct(protected SessionRevocationService $sessions) {}

    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        // Aucun statut renvoyé : ni "invalid_user", niDifference de temps
        // observable côté client puisque le travail est fait en interne.
        Password::sendResetLink($request->only('email'));

        return response()->json(['message' => self::NEUTRAL_MESSAGE]);
    }

    public function reset(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            $validated,
            function (User $user) use ($validated) {
                $user->forceFill([
                    'password' => Hash::make($validated['password']),
                    'remember_token' => Str::random(60),
                ])->save();

                // Une réinitialisation est la procédure de récupération d'un
                // compte compromis : toutes les sessions existantes tombent,
                // y compris celle qui a déclenché le reset.
                $this->sessions->afterPasswordReset($user);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            // Message volontairement générique : ne révèle pas si le token
            // était inconnu, expiré ou déjà utilisé.
            return response()->json([
                'message' => 'Ce lien de réinitialisation est invalide ou a expiré.',
            ], 422);
        }

        return response()->json(['message' => 'Mot de passe réinitialisé.']);
    }
}
