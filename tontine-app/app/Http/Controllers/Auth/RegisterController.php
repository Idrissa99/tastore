<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class RegisterController extends Controller
{
    public function create()
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request)
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
        ]);

        // si l'utilisateur s'inscrit en tant que commerçant, on crée son profil (en attente de validation)
        if ($user->role === 'merchant') {
            $user->merchant()->create([
                'business_name' => $validated['name'],
                'status' => 'pending',
            ]);
        }

        Auth::login($user);
        $user->sendEmailVerificationNotification();

        return redirect()->route('tontines.index')
            ->with('success', 'Bienvenue ! Ton compte a été créé. Vérifie ton email pour l\'activer complètement.');
    }
}
