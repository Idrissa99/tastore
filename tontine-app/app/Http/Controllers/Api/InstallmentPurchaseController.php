<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InstallmentPurchaseResource;
use App\Models\Installment;
use App\Models\InstallmentPurchase;
use App\Models\Product;
use App\Services\InstallmentPurchaseService;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class InstallmentPurchaseController extends Controller
{
    public function __construct(
        protected InstallmentPurchaseService $service,
        protected PaymentService $paymentService,
    ) {
    }

    public function preview(Request $request, Product $product)
    {
        abort_unless($product->status === 'published', 404);

        $validated = $request->validate([
            'installments_count' => ['required', 'integer', 'min:2', 'max:24'],
        ]);

        $amount = $this->service->calculateInstallmentAmount((float) $product->price, $validated['installments_count']);

        return response()->json([
            'commission_rate' => $this->service->currentCommissionRate(),
            'installment_amount' => $amount,
            'total' => round($amount * $validated['installments_count'], 2),
        ]);
    }

    public function store(Request $request, Product $product)
    {
        abort_unless($product->status === 'published', 404);

        $validated = $request->validate([
            'installments_count' => ['required', 'integer', 'min:2', 'max:24'],
        ]);

        $purchase = $this->service->createPurchase($product, $request->user()->id, $validated['installments_count']);

        return (new InstallmentPurchaseResource($purchase->load('product', 'installments')))
            ->response()
            ->setStatusCode(201);
    }

    public function index(Request $request)
    {
        $purchases = InstallmentPurchase::with('product')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return InstallmentPurchaseResource::collection($purchases);
    }

    public function show(Request $request, InstallmentPurchase $purchase)
    {
        $this->authorize('view', $purchase);

        return new InstallmentPurchaseResource($purchase->load('product', 'installments'));
    }

    public function pay(Request $request, Installment $installment)
    {
        $this->authorize('pay', $installment);

        $purchase = $installment->purchase;
        abort_if($installment->status === 'completed', 409, 'Cette tranche est déjà payée.');

        $validated = $request->validate([
            'payment_method' => ['required', 'in:' . implode(',', array_keys(config('payments.channels')))],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        abort_if(
            in_array($validated['payment_method'], config('payments.manual_verification_channels'), true),
            422,
            'Ce moyen de paiement nécessite un code de transfert — utilise /tranches/{id}/soumettre-code.'
        );

        abort_if($installment->isPendingVerification(), 409, 'Un code est déjà en attente de vérification pour cette tranche.');

        $this->paymentService->payInstallment(
            $installment,
            $validated['payment_method'],
            $validated['reference'] ?? null,
        );

        return new InstallmentPurchaseResource($purchase->fresh(['product', 'installments']));
    }

    /**
     * MyNita / Amana : l'acheteur déclare avoir transféré et fournit le code reçu.
     * Ne marque PAS la tranche payée — juste "en attente de vérification" par un admin.
     */
    public function submitTransferCode(Request $request, Installment $installment)
    {
        $this->authorize('submitTransferCode', $installment);

        $purchase = $installment->purchase;
        abort_if($installment->status === 'completed', 409, 'Cette tranche est déjà payée.');
        abort_if($installment->verification_status === 'pending', 409, 'Un code est déjà en attente de vérification pour cette tranche.');

        $validated = $request->validate([
            'payment_method' => ['required', 'in:' . implode(',', config('payments.manual_verification_channels'))],
            'transfer_code' => ['required', 'string', 'max:100'],
        ]);

        $installment->update([
            'payment_method' => $validated['payment_method'],
            'transfer_code' => $validated['transfer_code'],
            'verification_status' => 'pending',
            'submitted_at' => now(),
        ]);

        return new InstallmentPurchaseResource($purchase->fresh(['product', 'installments']));
    }
}
