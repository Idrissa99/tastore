<?php

namespace App\Policies;

use App\Models\InstallmentPurchase;
use App\Models\User;

/**
 * Un achat par tranches n'est visible que par son acheteur.
 */
class InstallmentPurchasePolicy
{
    public function view(User $user, InstallmentPurchase $purchase): bool
    {
        return $purchase->user_id === $user->id;
    }

    public function pay(User $user, InstallmentPurchase $purchase): bool
    {
        return $this->view($user, $purchase);
    }
}
