<?php

namespace App\Policies;

use App\Models\Installment;
use App\Models\User;

/**
 * Une tranche n'est accessible qu'à l'acheteur
 * (user -> installment purchase -> installment). Centralise une règle qui
 * était répétée dans les contrôleurs web et API.
 */
class InstallmentPolicy
{
    public function view(User $user, Installment $installment): bool
    {
        return $this->owns($user, $installment);
    }

    public function pay(User $user, Installment $installment): bool
    {
        return $this->owns($user, $installment);
    }

    public function submitTransferCode(User $user, Installment $installment): bool
    {
        return $this->owns($user, $installment);
    }

    private function owns(User $user, Installment $installment): bool
    {
        return $installment->purchase !== null
            && $installment->purchase->user_id === $user->id;
    }
}
