<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\TontineMember;
use App\Services\DeliveryService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(protected DeliveryService $deliveryService) {}

    /**
     * File des livraisons pour les produits du commerçant connecté, avec pour
     * chaque membre la raison exacte lorsqu'une livraison n'est pas encore
     * possible (round non payé, tontine annulée, produit indisponible...).
     */
    public function index(Request $request)
    {
        $merchant = $request->user()->merchant;

        $deliveries = $this->deliveryService->queueForMerchant($merchant->id);

        return view('merchant.orders.index', compact('deliveries'));
    }

    /**
     * Le contrôle d'éligibilité est fait côté backend par DeliveryService :
     * le bouton Blade n'est qu'un confort, jamais la protection.
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

        return back()->with('success', "Livraison confirmée pour {$confirmed->user->name}.");
    }
}
