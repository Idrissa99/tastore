<?php

namespace App\Policies;

use App\Models\Contribution;
use App\Models\User;

/**
 * Une cotisation n'est accessible qu'à la personne qui l'apayée
 * (user -> tontine member -> tontine -> contribution). Cette règle était
 * dupliquée dans 4 contrôleurs : elle est centralisée ici.
 */
class ContributionPolicy
{
    public function view(User $user, Contribution $contribution): bool
    {
        return $this->owns($user, $contribution);
    }

    public function pay(User $user, Contribution $contribution): bool
    {
        return $this->owns($user, $contribution);
    }

    public function submitTransferCode(User $user, Contribution $contribution): bool
    {
        return $this->owns($user, $contribution);
    }

    public function initiate(User $user, Contribution $contribution): bool
    {
        return $this->owns($user, $contribution);
    }

    private function owns(User $user, Contribution $contribution): bool
    {
        return $contribution->tontineMember !== null
            && $contribution->tontineMember->user_id === $user->id;
    }
}
