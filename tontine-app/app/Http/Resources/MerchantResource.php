<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantResource extends JsonResource
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
            'reviews_count' => $this->whenCounted('reviews', fn () => $this->reviews_count, $this->reviews->count() ?? null),
            'reviews' => $this->whenLoaded('reviews', fn () => $this->reviews->map(fn ($review) => [
                'id' => $review->id,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'user_name' => $review->user->name,
                'created_at' => $review->created_at,
            ])),
            'products' => ProductResource::collection($this->whenLoaded('products')),
        ];
    }
}
