<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DisputeResource;
use App\Models\Dispute;
use App\Models\Tontine;
use Illuminate\Http\Request;

class DisputeController extends Controller
{
    public function index(Request $request)
    {
        $disputes = Dispute::where('raised_by', $request->user()->id)
            ->with('tontine')
            ->latest()
            ->get();

        return DisputeResource::collection($disputes);
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
            'description' => ['required', 'string', 'max:5000'],
        ]);

        $dispute = Dispute::create([
            'tontine_id' => $tontine->id,
            'raised_by' => $request->user()->id,
            'subject' => $validated['subject'],
            'description' => $validated['description'],
            'status' => 'open',
        ]);

        return new DisputeResource($dispute->load('tontine'));
    }
}
