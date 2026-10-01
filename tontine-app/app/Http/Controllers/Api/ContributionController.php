<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContributionResource;
use App\Models\Contribution;
use App\Services\PaymentService;
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
            in_array($validated['payment_method'], config('payments.manual_verification_channels')),
            422,
            'Ce moyen de paiement nécessite un code de transfert — utilise /contributions/{id}/soumettre-code.'
        );

        abort_if(
            $contribution->isPendingVerification(),
            409,
            'Un code de transfert est déjà en attente de vérification pour cette cotisation.'
        );

        $this->paymentService->payContribution(
            $contribution,
            $validated['payment_method'],
            $validated['reference'] ?? null,
        );

        // `fresh()` recharge le modèle SANS les relations : sans le `with`
        // explicite, la ressource omet l'extrait de tontine et l'écran perd le
        // nom sous lequel il affichait pourtant la cotisation.
        return response()->json(
            (new ContributionResource($contribution->fresh(['tontineMember.tontine'])))
                ->resolve($request)
        );
    }

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

        // `fresh()` recharge le modèle SANS les relations : sans le `with`
        // explicite, la ressource omet l'extrait de tontine et l'écran perd le
        // nom sous lequel il affichait pourtant la cotisation.
        return response()->json(
            (new ContributionResource($contribution->fresh(['tontineMember.tontine'])))
                ->resolve($request)
        );
    }
}
