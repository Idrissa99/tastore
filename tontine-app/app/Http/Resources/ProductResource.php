<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'price' => $this->price,
            'stock' => $this->stock,
            'image' => $this->image,
            'images' => $this->media->where('type', 'image')->values()->map(fn ($m) => [
                'id' => $m->id,
                'url' => $m->url,
            ]),
            'video' => optional($this->media->firstWhere('type', 'video'), fn ($v) => ['id' => $v->id, 'url' => $v->url]),
            'status' => $this->status,
            'merchant' => $this->whenLoaded('merchant', fn () => [
                'id' => $this->merchant->id,
                'business_name' => $this->merchant->business_name,
                'city' => $this->merchant->city,
                'rating' => $this->merchant->rating,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
