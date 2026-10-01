<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\TontineMember;
use App\Services\DeliveryService;
use App\Services\FinancialReportService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        protected DeliveryService $deliveryService,
        protected FinancialReportService $reports,
    ) {}

    public function index(Request $request)
    {
        $merchant = $request->user()->merchant;

        $products = $merchant->products()->withCount('tontines')->get();

        // Seuls les bénéficiaires réellement éligibles sont actionnables ; ceux
        // qui attendent encore le paiement du round sont listés à part.
        $pendingDeliveries = $this->deliveryService->queueForMerchant($merchant->id)
            ->where('is_eligible', true)
            ->values();

        $waitingForPayment = $this->deliveryService->queueForMerchant($merchant->id)
            ->where('delivery_status', TontineMember::DELIVERY_AWAITING_PAYMENT)
            ->values();

        // Cotisations + tranches identifiées séparément, plus leur somme.
        $revenue = $this->reports->merchantRevenue($merchant);

        return view('merchant.dashboard', compact(
            'merchant', 'products', 'pendingDeliveries', 'waitingForPayment', 'revenue'
        ));
    }
}
