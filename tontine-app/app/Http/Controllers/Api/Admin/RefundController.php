<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Refund;
use App\Services\RefundService;
use Illuminate\Http\Request;

class RefundController extends Controller
{
    public function __construct(protected RefundService $refundService) {}

    public function index(Request $request)
    {
        $refunds = Refund::with(['user', 'tontine'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest()
            ->paginate(20);

        return response()->json($refunds);
    }

    /**
     * Traitement idempotent : un remboursement déjà traité est refusé (409).
     * Reste une déclaration manuelle — aucun transfert opérateur n'est simulé.
     */
    public function process(Request $request, Refund $refund)
    {
        $validated = $request->validate([
            'payment_reference' => ['nullable', 'string', 'max:100'],
        ]);

        $processed = $this->refundService->process(
            $refund,
            $request->user(),
            $validated['payment_reference'] ?? null
        );

        return response()->json($processed);
    }
}
