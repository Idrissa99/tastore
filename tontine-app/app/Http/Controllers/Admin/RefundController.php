<?php

namespace App\Http\Controllers\Admin;

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

        return view('admin.refunds.index', compact('refunds'));
    }

    /**
     * Déclaration MANUELLE : l'admin confirme avoir rendu l'argent hors
     * plateforme. Aucun remboursement opérateur n'est déclenché par le code,
     * car aucun fournisseur Mobile Money réel n'est connecté.
     */
    public function process(Request $request, Refund $refund)
    {
        $validated = $request->validate([
            'payment_reference' => ['nullable', 'string', 'max:100'],
        ]);

        $this->refundService->process(
            $refund,
            $request->user(),
            $validated['payment_reference'] ?? null
        );

        return back()->with('success', 'Remboursement marqué comme traité (règlement manuel).');
    }
}
