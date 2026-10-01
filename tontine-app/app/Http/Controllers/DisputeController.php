<?php

namespace App\Http\Controllers;

use App\Models\Dispute;
use App\Models\Tontine;
use Illuminate\Http\Request;

class DisputeController extends Controller
{
    public function create(Tontine $tontine)
    {
        return view('disputes.create', compact('tontine'));
    }

    public function store(Request $request, Tontine $tontine)
    {
        abort_unless(
            $tontine->members()->where('user_id', $request->user()->id)->exists(),
            403,
            'Tu dois être membre de cette tontine pour signaler un litige.'
        );

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
        ]);

        Dispute::create([
            'tontine_id' => $tontine->id,
            'raised_by' => $request->user()->id,
            'subject' => $validated['subject'],
            'description' => $validated['description'],
            'status' => 'open',
        ]);

        return redirect()->route('tontines.show', $tontine)
            ->with('success', 'Ton signalement a été transmis à l\'équipe.');
    }
}
