<?php

namespace App\Http\Controllers;

use App\Models\Installment;
use App\Models\InstallmentPurchase;
use App\Models\Product;
use App\Services\InstallmentPurchaseService;
use App\Services\PaymentService;
use App\Services\Payments\PaymentException;
use Illuminate\Http\Request;

class InstallmentPurchaseController extends Controller
{
    public function __construct(
        protected InstallmentPurchaseService $service,
        protected PaymentService $paymentService,
    ) {
    }

    public function create(Product $product)
    {
        abort_unless($product->status === 'published', 404);

        $commissionRate = $this->service->currentCommissionRate();

        return view('installment-purchases.create', compact('product', 'commissionRate'));
    }

    public function store(Request $request, Product $product)
    {
        abort_unless($product->status === 'published', 404);

        $validated = $request->validate([
            'installments_count' => ['required', 'integer', 'min:2', 'max:24'],
        ]);

        $purchase = $this->service->createPurchase($product, $request->user()->id, $validated['installments_count']);

        return redirect()->route('installment-purchases.show', $purchase)
            ->with('success', 'Achat créé. Tu peux payer tes tranches depuis cette page.');
    }

    public function index(Request $request)
    {
        $purchases = InstallmentPurchase::with('product')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return view('installment-purchases.index', compact('purchases'));
    }

    public function show(Request $request, InstallmentPurchase $purchase)
    {
        $this->authorize('view', $purchase);

        $purchase->load('installments', 'product.merchant');
        $channels = config('payments.channels');

        return view('installment-purchases.show', compact('purchase', 'channels'));
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
            'Ce moyen de paiement nécessite un code de transfert.'
        );

        abort_if($installment->isPendingVerification(), 409, 'Un code est déjà en attente de vérification pour cette tranche.');

        try {
            $this->paymentService->payInstallment(
                $installment,
                $validated['payment_method'],
                $validated['reference'] ?? null,
            );
        } catch (PaymentException $exception) {
            return back()->withErrors(['payment_method' => $exception->getMessage()]);
        }

        return back()->with('success', 'Tranche payée.');
    }
}
