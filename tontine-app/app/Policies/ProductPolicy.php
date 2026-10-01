<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\User;

/**
 * Un commerçant ne gère que SES produits et SES médias.
 * Règle centralisée (elle était dupliquée dans les contrôleurs web et API).
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isMerchant();
    }

    public function create(User $user): bool
    {
        return $user->isMerchant();
    }

    public function update(User $user, Product $product): bool
    {
        return $this->owns($user, $product);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->owns($user, $product);
    }

    public function updateStock(User $user, Product $product): bool
    {
        return $this->owns($user, $product);
    }

    public function deleteMedia(User $user, ProductMedia $media): bool
    {
        return $media->product !== null
            && $media->product->merchant !== null
            && $media->product->merchant->user_id === $user->id;
    }

    private function owns(User $user, Product $product): bool
    {
        return $product->merchant !== null
            && $product->merchant->user_id === $user->id;
    }
}
