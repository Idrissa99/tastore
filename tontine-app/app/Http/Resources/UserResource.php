<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Représentation publique d'un utilisateur.
 *
 * Volontairement restrictive : ni hash de mot de passe, ni remember_token,
 * ni token Sanctum, ni compteurs internes. Le rôle et l'état de vérification
 * sont exposés car l'interface en a besoin pour adapter son rendu — le backend
 * reste seul juge des droits effectifs.
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'is_verified' => $this->is_verified,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'avatar_url' => $this->avatar_url,
            'merchant' => $this->whenLoaded('merchant', fn () => $this->merchant ? [
                'id' => $this->merchant->id,
                'business_name' => $this->merchant->business_name,
                'city' => $this->merchant->city,
                'status' => $this->merchant->status,
                'rating' => $this->merchant->rating,
            ] : null),
            'created_at' => $this->created_at,
        ];
    }
}
