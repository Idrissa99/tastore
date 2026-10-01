<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cotisation vue par son propriétaire.
 *
 * Cette resource n'est JAMAIS renvoyée à un tiers : `ContributionPolicy`
 * refuse toute accès si `tontine_member.user_id` n'est pas l'utilisateur
 * authentifié. Le `transfer_code` y figure donc en toute sécurité (c'est la
 * valeur que le client a lui-même déclarée) et l'admin, seul habilité à
 * vérifier un virement, la récupère via Admin\PaymentVerificationController.
 */
class ContributionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'round' => $this->round,
            'amount' => $this->amount,
            'commission_amount' => $this->commission_amount,
            'currency' => $this->currency,
            'payment_method' => $this->payment_method,
            'status' => $this->status,
            'verification_status' => $this->verification_status,
            'transaction_reference' => $this->transaction_reference,
            'transfer_code' => $this->transfer_code,
            'payment_failure_reason' => $this->payment_failure_reason,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            // Le chemin pointé `tontineMember.tontine` NE FONCTIONNE PAS dans
            // `whenLoaded` : le test `relationLoaded` sur la chaîne entière
            // renvoie faux alors que les deux relations sont bien chargées, et
            // la clé disparaît purement et simplement de la réponse. Conséquence
            // concrète : le client ne reçoit ni l'identifiant ni le nom de la
            // tontine, et « mes cotisations » comme « mes tontines » ne
            // peuvent plus être affichées.
            //
            // On interroge donc le modèle de l'imbrication, qui sait lui dire
            // si SA relation est chargée.
            'tontine' => $this->when(
                $this->tontineMember !== null
                    && $this->tontineMember->relationLoaded('tontine'),
                fn () => [
                    'id' => $this->tontineMember->tontine->id,
                    'name' => $this->tontineMember->tontine->name,
                    'status' => $this->tontineMember->tontine->status,
                    'type' => $this->tontineMember->tontine->type,
                ],
            ),
            'tontine_member' => $this->whenLoaded('tontineMember', fn () => [
                'id' => $this->tontineMember->id,
                'user_id' => $this->tontineMember->user_id,
                'status' => $this->tontineMember->status,
            ]),
        ];
    }
}
