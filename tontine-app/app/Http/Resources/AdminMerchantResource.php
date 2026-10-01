<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vue administrateur d'un commerçant.
 *
 * Séparée de MerchantResource (qui est PUBLIC via /commercants/{merchant}) :
 * l'email du propriétaire et le statut de validation ne doivent pas fuiter sur
 * une route publique. Aucun mot de passe ni token n'est renvoyé.
 */
class AdminMerchantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_name' => $this->business_name,
            'address' => $this->address,
            'city' => $this->city,
            'description' => $this->description,
            'rating' => $this->rating,
            'status' => $this->status,
            'products_count' => $this->whenCounted('products'),
            'reviews_count' => $this->whenCounted('reviews'),
            'owner' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
                'is_blocked' => $this->user->is_blocked,
            ] : null),
            'created_at' => $this->created_at,
        ];
    }
}
