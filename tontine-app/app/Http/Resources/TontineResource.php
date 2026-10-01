<?php

namespace App\Http\Resources;

use App\Models\Tontine;
use App\Models\TontineMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TontineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $authUser = $request->user();
        $myMembership = $authUser
            ? $this->members->firstWhere('user_id', $authUser->id)
            : null;

        $round = (int) $this->current_round;
        $summary = $this->roundSummary($round);

        // Droit de modification, calculé ICI et non côté client : le SPA ne
        // doit jamais réinventer une règle d'autorisation, et la décision doit
        // rester la même quelle que soit la surface qui consomme la ressource.
        $isCreator = (bool) $authUser && (int) $this->created_by === (int) $authUser->id;
        $canEdit = (bool) $authUser
            && ($isCreator || $authUser->isAdmin())
            && $this->status === Tontine::STATUS_OPEN;

        // Tant que la tontine n'est pas complète, l'ordre de passage n'est pas
        // tiré : `position` ne porte que l'ordre d'arrivée. Le renvoyer ferait
        // afficher un ordre de passage qui n'existe pas — et c'est public, la
        // route `/tontines/{id}` n'est pas authentifiée. Le tirage a lieu au
        // lancement (TontineService::activateIfFull), donc le Masque tombe
        // exactement quand tout le monde est entré.
        $rotationDrawn = $this->hasRotationOrder();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'product' => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'price' => $this->product->price,
                'image' => $this->product->image,
                'merchant_id' => $this->product->merchant_id,
            ] : null,
            'total_amount' => $this->total_amount,
            'contribution_amount' => $this->contribution_amount,
            // Taux FIGÉ sur la tontine à sa création. Il peut donc différer du
            // taux global affiché ailleurs : l'aperçu d'édition doit rebuild sur
            // celui-ci, pas sur la valeur courante du paramétrage.
            'commission_rate' => $this->commission_rate,
            'frequency' => $this->frequency,
            'max_members' => $this->max_members,
            'current_members' => $this->members->count(),
            'current_round' => $round,
            'status' => $this->status,
            'start_date' => $this->start_date,
            'created_at' => $this->created_at,

            // État financier du round courant : ce qui autorise (ou non) la
            // livraison au bénéficiaire désigné.
            'round' => [
                'number' => $round,
                'expected_members' => $summary['expected_members'],
                'paid_members' => $summary['paid_members'],
                'is_funded' => $summary['is_funded'],
            ],

            // Tontines argent : aucun versement n'est effectué par la plateforme.
            'payout' => [
                'supported' => $this->isPayoutSupported(),
                'status' => $this->payoutStatus(),
            ],

            // liste des membres, triée par ordre de passage — alimente le cercle de rotation
            'members' => $this->members->sortBy('position')->values()->map(fn (TontineMember $member) => [
                'id' => $member->id,
                'user_id' => $member->user_id,
                'user_name' => $member->user->name,
                // null tant que le tirage n'a pas eu lieu : cf. $rotationDrawn.
                'position' => $rotationDrawn ? $member->position : null,
                // état de participation
                'status' => $member->status,
                // état de livraison (indépendant du statut ci-dessus)
                'delivery_status' => $member->delivery_status,
                'beneficiary_round' => $rotationDrawn ? $member->beneficiary_round : null,
                'delivered_at' => $member->delivered_at?->toIso8601String(),
                'is_awaiting_delivery' => $member->isAwaitingDelivery(),
                'is_delivered' => $member->isDelivered(),
            ]),

            // Vrai seulement à partir du lancement : le client doit savoir
            // s'il a le droit d'afficher un ordre de passage, plutôt que de
            // le deviner à un `position` absent.
            'rotation_revealed' => $rotationDrawn,

            'is_member' => (bool) $myMembership,
            'my_status' => $myMembership?->status,
            'my_delivery_status' => $myMembership?->delivery_status,

            // Modification possible uniquement avant le démarrage.
            'is_creator' => $isCreator,
            'can_edit' => $canEdit,
            'edit_locked_reason' => $this->editLockedReason($canEdit, $isCreator),
        ];
    }

    /**
     * Motif du refus, donné au créateur pour qu'il comprenne pourquoi le bouton
     * a disparu — un blocage sans explication serait pris pour un bug.
     */
    private function editLockedReason(bool $canEdit, bool $isCreator): ?string
    {
        if ($canEdit || ! $isCreator) {
            return null;
        }

        return match ($this->status) {
            Tontine::STATUS_ACTIVE => 'Cette tontine a démarré : ses conditions sont figées.',
            Tontine::STATUS_COMPLETED => 'Cette tontine est terminée : elle n\'est plus modifiable.',
            Tontine::STATUS_CANCELLED => 'Cette tontine a été annulée : elle n\'est plus modifiable.',
            default => null,
        };
    }
}
