<?php

namespace App\Http\Controllers;

use App\Http\Resources\ContributionResource;
use App\Models\Contribution;
use App\Services\PaymentService;
use App\Services\Payments\PaymentException;
use Illuminate\Http\Request;

class ContributionController extends Controller
{
    public function __construct(protected PaymentService $paymentService) {}

    public function index(Request $request)
    {
        $contributions = Contribution::whereHas(
            'tontineMember',
            fn ($q) => $q->where('user_id', $request->user()->id)
        )->with('tontineMember.tontine')->latest()->get();

        return response()->json(
            ContributionResource::collection($contributions)->resolve($request)
        );
    }

    /**
     * Paiement "instantané" : canaux qui ne nécessitent pas de vérification manuelle
     * (stub en attendant une vraie intégration API opérateur).
     */
    public function pay(Request $request, Contribution $contribution)
    {
        $this->authorize('pay', $contribution);

        abort_if($contribution->status === 'completed', 409, 'Cette cotisation est déjà payée.');
        abort_if(
            $contribution->status === 'cancelled',
            409,
            'Cette cotisation a été annulée et ne peut plus être payée.'
        );

        $validated = $request->validate([
            'payment_method' => ['required', 'in:' . implode(',', array_keys(config('payments.channels')))],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        abort_if(
            in_array($validated['payment_method'], config('payments.manual_verification_channels'), true),
            422,
            'Ce moyen de paiement nécessite un code de transfert — utilise /contributions/{id}/soumettre-code.'
        );

        abort_if($contribution->isPendingVerification(), 409, 'Un code de transfert est déjà en attente de vérification pour cette cotisation.');

        try {
            $this->paymentService->payContribution(
                $contribution,
                $validated['payment_method'],
                $validated['reference'] ?? null,
            );
        } catch (PaymentException $exception) {
            return back()->withErrors(['payment_method' => $exception->getMessage()]);
        }

        return response()->json(
            (new ContributionResource($contribution->fresh()))->resolve($request)
        );
    }

    /**
     * MyNita / Amana : le client déclare avoir transféré et fournit le code reçu.
     * Ça NE marque PAS la cotisation payée — juste "en attente de vérification".
     * Un admin devra accepter ou refuser (voir Api\Admin\PaymentVerificationController).
     */
    public function submitTransferCode(Request $request, Contribution $contribution)
    {
        $this->authorize('submitTransferCode', $contribution);

        abort_if($contribution->status === 'completed', 409, 'Cette cotisation est déjà payée.');
        abort_if(
            $contribution->status === 'cancelled',
            409,
            'Cette cotisation a été annulée et ne peut plus être payée.'
        );
        abort_if($contribution->verification_status === 'pending', 409, 'Un code est déjà en attente de vérification pour cette cotisation.');

        $validated = $request->validate([
            'payment_method' => ['required', 'in:' . implode(',', config('payments.manual_verification_channels'))],
            'transfer_code' => ['required', 'string', 'max:100'],
        ]);

        $contribution->update([
            'payment_method' => $validated['payment_method'],
            'transfer_code' => $validated['transfer_code'],
            'verification_status' => 'pending',
            'submitted_at' => now(),
        ]);

        return response()->json(
            (new ContributionResource($contribution->fresh()))->resolve($request)
        );
    }
}
