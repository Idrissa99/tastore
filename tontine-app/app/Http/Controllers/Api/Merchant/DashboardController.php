<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\InstallmentPurchase;
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

        $queue = $this->deliveryService->queueForMerchant($merchant->id);

        $pendingInstallmentDeliveries = InstallmentPurchase::where('delivery_status', 'pending')
            ->whereHas('product', fn ($query) => $query->where('merchant_id', $merchant->id))
            ->with(['user', 'product'])
            ->get();

        return response()->json([
            'merchant' => $merchant,
            'products' => ProductResource::collection($products)->resolve(),
            // Livrables maintenant (round payé + bénéficiaire effectif).
            'pending_deliveries_tontine' => $queue->where('is_eligible', true)->values(),
            // Bénéficiaires désignés dont le round n'est pas encore financé.
            'waiting_for_payment_tontine' => $queue
                ->where('delivery_status', TontineMember::DELIVERY_AWAITING_PAYMENT)
                ->values(),
            'pending_deliveries_installment' => $pendingInstallmentDeliveries,
            'revenue' => $this->reports->merchantRevenue($merchant),
        ]);
    }
}
