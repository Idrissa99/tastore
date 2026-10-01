<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Notifications\DisputeResolvedNotification;
use Illuminate\Http\Request;

class DisputeController extends Controller
{
    public function index(Request $request)
    {
        $disputes = Dispute::with(['tontine', 'raisedBy'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest()
            ->paginate(20);

        return view('admin.disputes.index', compact('disputes'));
    }

    public function show(Dispute $dispute)
    {
        $dispute->load(['tontine.members.user', 'raisedBy', 'resolvedBy']);

        return view('admin.disputes.show', compact('dispute'));
    }

    public function resolve(Request $request, Dispute $dispute)
    {
        abort_unless($dispute->isOpen(), 409, 'Ce litige a déjà été traité.');

        $validated = $request->validate([
            'status' => ['required', 'in:resolved,rejected'],
            'resolution_note' => ['required', 'string'],
        ]);

        $dispute->update([
            'status' => $validated['status'],
            'resolution_note' => $validated['resolution_note'],
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        $dispute->raisedBy->notify(new DisputeResolvedNotification($dispute));

        return redirect()->route('admin.disputes.index')->with('success', 'Litige traité.');
    }
}
