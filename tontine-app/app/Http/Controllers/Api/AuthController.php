<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\SessionRevocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(protected SessionRevocationService $sessions) {}

    public function register(RegisterRequest $request)
    {
        $validated = $request->validated();

        $user = new User;
        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->phone = $validated['phone'];
        $user->password = Hash::make($validated['password']);
        // Seul RegisterRequest décide du rôle, et uniquement client/merchant.
        $user->role = $validated['role'];
        $user->save();

        if ($user->isMerchant()) {
            $user->merchant()->create([
                'business_name' => $validated['name'],
                'status' => 'pending',
            ]);
        }

        $token = $user->createToken($this->tokenName())->plainTextToken;
        $user->sendEmailVerificationNotification();

        return response()->json([
            'user' => (new UserResource($user->load('merchant')))->resolve($request),
            'token' => $token,
        ], 201);
    }

    public function login(LoginRequest $request)
    {
        $credentials = $request->validated();

        if (! Auth::attempt($credentials)) {
            return response()->json([
                'message' => 'Identifiants incorrects.',
            ], 401);
        }

        $user = Auth::user();

        if ($user->is_blocked) {
            // Aucun jeton n'est délivré à un compte bloqué.
            Auth::guard('web')->logout();
            $this->sessions->revokeAll($user);

            return response()->json([
                'message' => 'Ce compte a été suspendu. Contacte le support.',
            ], 403);
        }

        $token = $user->createToken($this->tokenName())->plainTextToken;

        return response()->json([
            'user' => (new UserResource($user->load('merchant')))->resolve($request),
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        // Le token de la requête est révoqué. Si l'authentification venait d'un
        // cookie de session (jeton transitoire) ou du garde `web`, il n'y a
        // rien à révoquer : on déconnecte la session sans erreur.
        $this->sessions->revokeCurrentToken($request->user());

        return response()->json(['message' => 'Déconnecté.']);
    }

    public function me(Request $request)
    {
        // Réponse plate : le contrat de /me est consumed tel quel par le SPA.
        return response()->json(
            (new UserResource($request->user()->load('merchant')))->resolve($request)
        );
    }

    /**
     * Liste les sessions actives de l'utilisateur afin qu'il puisse repérer
     * un token qu'il ne reconnaît pas. Ne renvoie JAMAIS la valeur du token,
     * seulement son nom, sa date de création et sa dernière utilisation.
     */
    public function sessions(Request $request)
    {
        $currentId = $request->user()->currentAccessToken()?->getKey();

        return response()->json(
            $request->user()->tokens()
                ->orderByDesc('last_used_at')
                ->get()
                ->map(fn ($token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'created_at' => $token->created_at?->toIso8601String(),
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'is_current' => $token->id === $currentId,
                ])
        );
    }

    private function tokenName(): string
    {
        return (string) config('sanctum.token_name', 'spa');
    }
}
