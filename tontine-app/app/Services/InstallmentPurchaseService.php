<?php

namespace App\Services;

use App\Models\Installment;
use App\Models\InstallmentPurchase;
use App\Models\Product;
use App\Models\Setting;
use App\Notifications\InstallmentPurchaseCompletedNotification;
use App\Services\Payments\PaymentException;

class InstallmentPurchaseService
{
    public function __construct(
        protected TransactionRunner $transactions,
    ) {}

    public function currentCommissionRate(): float
    {
        return (float) Setting::get('commission_rate', config('commissions.rate', 0));
    }

    /**
     * Calcule le montant de chaque tranche. Jamais saisi par l'utilisateur :
     * (prix du produit ÷ nombre de tranches) × (1 + commission).
     */
    public function calculateInstallmentAmount(float $price, int $installmentsCount, ?float $rate = null): float
    {
        $rate ??= $this->currentCommissionRate();

        return round(($price / $installmentsCount) * (1 + $rate), 2);
    }

    public function createPurchase(Product $product, int $userId, int $installmentsCount): InstallmentPurchase
    {
        $rate = $this->currentCommissionRate();
        $installmentAmount = $this->calculateInstallmentAmount((float) $product->price, $installmentsCount, $rate);

        return $this->transactions->run(function () use ($product, $userId, $installmentsCount, $installmentAmount, $rate) {
            $purchase = InstallmentPurchase::create([
                'product_id' => $product->id,
                'user_id' => $userId,
                'installments_count' => $installmentsCount,
                'installment_amount' => $installmentAmount,
                'product_price' => $product->price,
                'status' => 'active',
                'delivery_status' => 'not_applicable',
            ]);

            for ($i = 1; $i <= $installmentsCount; $i++) {
                Installment::create([
                    'installment_purchase_id' => $purchase->id,
                    'installment_number' => $i,
                    'amount' => $installmentAmount,
                    'commission_rate' => $rate,
                    'currency' => config('payments.currency', 'XOF'),
                    'status' => 'pending',
                ]);
            }

            return $purchase;
        });
    }

    public function recordPayment(
        Installment $installment,
        ?string $reference = null,
        ?float $paidAmount = null,
        ?string $currency = null,
    ): Installment {
        return $this->transactions->run(function () use ($installment, $reference, $paidAmount, $currency) {
            $locked = Installment::query()->lockForUpdate()->findOrFail($installment->id);
            $expectedCurrency = strtoupper($locked->currency ?: config('payments.currency', 'XOF'));

            if ($paidAmount !== null && round((float) $paidAmount, 2) !== round((float) $locked->amount, 2)) {
                throw new PaymentException('Le montant reçu ne correspond pas au montant attendu.', 422);
            }

            if ($currency !== null && strtoupper($currency) !== $expectedCurrency) {
                throw new PaymentException('La devise reçue ne correspond pas à la devise attendue.', 422);
            }

            if ($reference !== null && $reference !== '' && $locked->transaction_reference
                && $locked->transaction_reference !== $reference
                && ! str_starts_with($locked->transaction_reference, 'PENDING-')) {
                throw new PaymentException('La transaction ne correspond pas à ce paiement.', 409);
            }

            if ($reference !== null && $reference !== '' && Installment::query()
                ->where('transaction_reference', $reference)
                ->where('id', '!=', $locked->id)
                ->exists()) {
                throw new PaymentException('Cette transaction est déjà associée à un autre paiement.', 409);
            }

            if ($locked->status === 'completed') {
                return $locked;
            }

            if ($locked->status === 'cancelled') {
                throw new PaymentException('Cette tranche a été annulée.', 409);
            }

            if ($locked->isPendingVerification()) {
                throw new PaymentException('Un code de transfert est déjà en attente de vérification.', 409);
            }

            $rate = $locked->commission_rate !== null
                ? (float) $locked->commission_rate
                : $this->currentCommissionRate();

            $updates = [
                'status' => 'completed',
                'paid_at' => now(),
                'commission_amount' => round((float) $locked->amount * $rate, 2),
            ];

            if ($reference !== null && $reference !== '') {
                $updates['transaction_reference'] = $reference;
            }

            $locked->update($updates);

            $this->checkCompletionAndNotify($locked->purchase);

            return $locked->fresh();
        });
    }

    public function recordFailure(Installment $installment, ?string $reason = null): Installment
    {
        return $this->transactions->run(function () use ($installment, $reason) {
            $locked = Installment::query()->lockForUpdate()->findOrFail($installment->id);

            if ($locked->status === 'completed' || $locked->status === 'cancelled') {
                return $locked;
            }

            $locked->update([
                'status' => 'failed',
                'payment_failure_reason' => $reason,
            ]);

            return $locked->fresh();
        });
    }

    private function checkCompletionAndNotify(InstallmentPurchase $purchase): void
    {
        $this->transactions->run(function () use ($purchase) {
            $lockedPurchase = InstallmentPurchase::query()->lockForUpdate()->findOrFail($purchase->id);
            $stillPending = $lockedPurchase->installments()
                ->whereIn('status', ['pending', 'failed'])
                ->exists();

            if ($stillPending || $lockedPurchase->status === 'completed') {
                return;
            }

            $lockedPurchase->update([
                'status' => 'completed',
                'delivery_status' => 'pending',
            ]);

            $lockedPurchase->user->notify(new InstallmentPurchaseCompletedNotification($lockedPurchase));
        });
    }
}
