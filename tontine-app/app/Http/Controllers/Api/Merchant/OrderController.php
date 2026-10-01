<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Http\Controllers\Controller;
use App\Models\TontineMember;
use App\Services\DeliveryService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(protected DeliveryService $deliveryService) {}

    public function index(Request $request)
    {
        $merchant = $request->user()->merchant;

        return response()->json($this->deliveryService->queueForMerchant($merchant->id));
    }

    /**
     * Contrôle backend strict : round financé, bénéficiaire effectif, produit
     * disponible. Masquer le bouton côté React ne protège rien.
     */
    public function validateDelivery(Request $request, TontineMember $tontineMember)
    {
        $merchant = $request->user()->merchant;

        abort_unless(
            $tontineMember->tontine->product?->merchant_id === $merchant->id,
            403,
            'Ce produit n\'appartient pas à votre boutique.'
        );

        $confirmed = $this->deliveryService->confirm($tontineMember);

        return response()->json([
            'message' => 'Livraison confirmée.',
            'member_id' => $confirmed->id,
            'delivery_status' => $confirmed->delivery_status,
            'delivered_at' => $confirmed->delivered_at?->toIso8601String(),
        ]);
    }
}
