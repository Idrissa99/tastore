<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\InstallmentPurchase;
use App\Notifications\InstallmentDeliveryConfirmedNotification;
use Illuminate\Http\Request;

class InstallmentOrderController extends Controller
{
    public function index(Request $request)
    {
        $merchant = $request->user()->merchant;

        $purchases = InstallmentPurchase::whereIn('delivery_status', ['pending', 'delivered'])
            ->whereHas('product', fn ($q) => $q->where('merchant_id', $merchant->id))
            ->with(['user', 'product'])
            ->latest('delivered_at')
            ->get();

        return view('merchant.installment-orders.index', compact('purchases'));
    }

    public function validateDelivery(Request $request, InstallmentPurchase $purchase)
    {
        $merchant = $request->user()->merchant;

        abort_unless($purchase->product->merchant_id === $merchant->id, 403);
        abort_unless($purchase->delivery_status === 'pending', 409, 'Cette livraison n\'est pas en attente.');

        $purchase->markDelivered();
        $purchase->user->notify(new InstallmentDeliveryConfirmedNotification($purchase));

        return back()->with('success', "Livraison confirmée pour {$purchase->user->name}.");
    }
}
