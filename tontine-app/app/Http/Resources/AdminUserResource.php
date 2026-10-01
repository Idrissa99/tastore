<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vue administrateur d'un utilisateur : ajoute l'état de blocage et les dates
 * de gestion. Aucun secret (ni mot de passe, ni token) n'est exposé.
 */
class AdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'is_blocked' => $this->is_blocked,
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
            'tontines_count' => $this->whenCounted('tontineMemberships'),
            'created_at' => $this->created_at,
        ];
    }
}
