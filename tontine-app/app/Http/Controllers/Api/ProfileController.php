<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Services\SessionRevocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function __construct(protected SessionRevocationService $sessions) {}

    public function show(Request $request)
    {
        return response()->json(
            (new UserResource($request->user()))->resolve($request)
        );
    }

    public function update(UpdateProfileRequest $request)
    {
        $validated = $request->validated();
        $user = $request->user();

        // Affectation champ par champ : `role`, `is_blocked`, `is_verified` et
        // `email` ne sont pas modifiables depuis le profil.
        $user->name = $validated['name'];
        $user->phone = $validated['phone'];

        $passwordChanged = ! empty($validated['new_password']);

        if ($passwordChanged) {
            $user->password = Hash::make($validated['new_password']);
        }

        if ($request->hasFile('avatar')) {
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }

            $user->avatar_path = $request->file('avatar')->store('avatars', 'public');
        }

        $user->save();

        if ($passwordChanged) {
            // Un token volé ne doit pas survivre à un changement de mot de
            // passe. La session appelante, elle, est conservée.
            $this->sessions->afterPasswordChange($user, $user->currentAccessToken());
        }

        return response()->json(
            (new UserResource($user->fresh()))->resolve($request)
        );
    }
}
