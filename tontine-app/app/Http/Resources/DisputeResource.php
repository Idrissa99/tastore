<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Litige exposé à l'administrateur.
 *
 * N'expose PAS la liste complète des `User` des membres de la tontine : seule
 * l'identité du déposant et un résumé de la tontine sont renvoyés, pour éviter
 * de diffuser des données de comptes tiers dans une réponse d'API.
 */
class DisputeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tontine_id' => $this->tontine_id,
            'subject' => $this->subject,
            'description' => $this->description,
            'status' => $this->status,
            'resolution_note' => $this->resolution_note,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at,
            'raised_by' => $this->whenLoaded('raisedBy', fn () => $this->raisedBy ? [
                'id' => $this->raisedBy->id,
                'name' => $this->raisedBy->name,
            ] : null),
            'resolved_by' => $this->whenLoaded('resolvedBy', fn () => $this->resolvedBy ? [
                'id' => $this->resolvedBy->id,
                'name' => $this->resolvedBy->name,
            ] : null),
            'tontine' => $this->whenLoaded('tontine', fn () => [
                'id' => $this->tontine->id,
                'name' => $this->tontine->name,
                'type' => $this->tontine->type,
                'status' => $this->tontine->status,
                'current_round' => $this->tontine->current_round,
                'members_count' => $this->tontine->members->count(),
            ]),
        ];
    }
}
