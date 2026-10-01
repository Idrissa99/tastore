<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfileRequest;
use App\Services\SessionRevocationService;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function __construct(protected SessionRevocationService $sessions) {}

    public function edit()
    {
        return view('profile.edit', ['user' => auth()->user()]);
    }

    public function update(UpdateProfileRequest $request)
    {
        $validated = $request->validated();
        $user = $request->user();

        // Affectation champ par champ : aucun rôle, statut de blocage ou flag
        // de vérification ne peut être envoyé par le client.
        $user->name = $validated['name'];
        $user->phone = $validated['phone'];

        $passwordChanged = ! empty($validated['new_password']);

        if ($passwordChanged) {
            $user->password = Hash::make($validated['new_password']);
        }

        $user->save();

        if ($passwordChanged) {
            // Invalide toutes les sessions sauf celle-ci : un jeton dérobé ne
            // donne plus accès au compte après une rotation de mot de passe.
            $revoked = $this->sessions->afterPasswordChange($user, $user->currentAccessToken());

            // La session web doit aussi tourner : on régénère l'ID de session
            // pour éviter toute fixation de session.
            $request->session()->regenerate();

            return back()->with('success', $revoked > 0
                ? "Profil mis à jour. {$revoked} autre(s) session(s) ont été déconnectées."
                : 'Profil mis à jour.');
        }

        return back()->with('success', 'Profil mis à jour.');
    }
}
