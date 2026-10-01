<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DisputeResource;
use App\Models\Dispute;
use App\Notifications\DisputeResolvedNotification;
use Illuminate\Http\Request;

class DisputeController extends Controller
{
    public function index(Request $request)
    {
        $disputes = Dispute::with(['tontine.members', 'raisedBy', 'resolvedBy'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest()
            ->paginate(20);

        return DisputeResource::collection($disputes);
    }

    public function show(Dispute $dispute)
    {
        // On charge les membres pour le COMPTE (members_count) mais la resource
        // ne renvoie que des agrégats : aucun User de membre n'est exposé.
        $dispute->load(['tontine.members', 'raisedBy', 'resolvedBy']);

        return new DisputeResource($dispute);
    }

    public function resolve(Request $request, Dispute $dispute)
    {
        abort_unless($dispute->isOpen(), 409, 'Ce litige a déjà été traité.');

        $validated = $request->validate([
            'status' => ['required', 'in:resolved,rejected'],
            'resolution_note' => ['required', 'string', 'max:2000'],
        ]);

        $dispute->update([
            'status' => $validated['status'],
            'resolution_note' => $validated['resolution_note'],
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        $dispute->raisedBy->notify(new DisputeResolvedNotification($dispute));

        return new DisputeResource($dispute->fresh(['tontine.members', 'raisedBy', 'resolvedBy']));
    }
}
