<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\Installment;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentIntent;
use App\Services\Payments\PaymentResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

class PaymentService
{
    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected TontineService $tontineService,
        protected InstallmentPurchaseService $installmentPurchaseService,
        protected TransactionRunner $transactions,
    ) {}

    public function payContribution(Contribution $contribution, string $channel, ?string $clientReference = null): PaymentResult
    {
        $this->assertAutomaticChannel($channel);

        $reservation = $this->reserveContribution($contribution, $channel);

        try {
            $gateway = $this->gateways->forChannel($channel);
            $result = $reservation['created']
                ? $gateway->initiate($this->contributionIntent($contribution, $channel, $clientReference, true))
                : $gateway->verify($reservation['reference']);
        } catch (Throwable $exception) {
            if ($reservation['created']) {
                $this->releaseReference($contribution, $reservation['reference']);
            }

            throw $exception;
        }

        $this->applyContributionResult($contribution->fresh(), $result);

        return $result;
    }

    public function initiateContribution(Contribution $contribution, string $channel): PaymentResult
    {
        $this->assertAutomaticChannel($channel);

        $reservation = $this->reserveContribution($contribution, $channel);

        try {
            $gateway = $this->gateways->forChannel($channel);
            $result = $reservation['created']
                ? $gateway->initiate($this->contributionIntent($contribution, $channel, null, false))
                : $gateway->verify($reservation['reference']);
        } catch (Throwable $exception) {
            if ($reservation['created']) {
                $this->releaseReference($contribution, $reservation['reference']);
            }

            throw $exception;
        }

        $this->applyContributionResult($contribution->fresh(), $result);

        return $result;
    }

    public function payInstallment(Installment $installment, string $channel, ?string $clientReference = null): PaymentResult
    {
        $this->assertAutomaticChannel($channel);

        $reservation = $this->reserveInstallment($installment, $channel);

        try {
            $gateway = $this->gateways->forChannel($channel);
            $result = $reservation['created']
                ? $gateway->initiate($this->installmentIntent($installment, $channel, $clientReference, true))
                : $gateway->verify($reservation['reference']);
        } catch (Throwable $exception) {
            if ($reservation['created']) {
                $this->releaseReference($installment, $reservation['reference']);
            }

            throw $exception;
        }

        $this->applyInstallmentResult($installment->fresh(), $result);

        return $result;
    }

    public function processWebhook(PaymentResult $result): Model
    {
        $contribution = Contribution::where('transaction_reference', $result->externalReference)->first();
        $installment = Installment::where('transaction_reference', $result->externalReference)->first();

        if ($contribution && $installment) {
            throw new PaymentException('La référence de transaction est ambiguë.', 409);
        }

        if ($contribution) {
            return $this->applyContributionResult($contribution, $result);
        }

        if ($installment) {
            return $this->applyInstallmentResult($installment, $result);
        }

        throw new PaymentException('Référence inconnue.', 404);
    }

    public function isManualChannel(string $channel): bool
    {
        return in_array($channel, config('payments.manual_verification_channels', []), true);
    }

    private function assertAutomaticChannel(string $channel): void
    {
        if ($this->isManualChannel($channel)) {
            throw new PaymentException('Ce moyen de paiement nécessite une vérification administrative.', 422);
        }
    }

    private function reserveContribution(Contribution $contribution, string $channel): array
    {
        return $this->transactions->run(function () use ($contribution, $channel) {
            $locked = Contribution::query()->lockForUpdate()->findOrFail($contribution->id);
            $this->assertContributionPayable($locked);

            $locked->update(['payment_method' => $channel]);

            if ($locked->transaction_reference) {
                return ['reference' => $locked->transaction_reference, 'created' => false];
            }

            $reference = 'PENDING-'.strtoupper(Str::uuid());
            $locked->update(['transaction_reference' => $reference]);

            return ['reference' => $reference, 'created' => true];
        });
    }

    private function reserveInstallment(Installment $installment, string $channel): array
    {
        return $this->transactions->run(function () use ($installment, $channel) {
            $locked = Installment::query()->lockForUpdate()->findOrFail($installment->id);
            $this->assertInstallmentPayable($locked);

            $locked->update(['payment_method' => $channel]);

            if ($locked->transaction_reference) {
                return ['reference' => $locked->transaction_reference, 'created' => false];
            }

            $reference = 'PENDING-'.strtoupper(Str::uuid());
            $locked->update(['transaction_reference' => $reference]);

            return ['reference' => $reference, 'created' => true];
        });
    }

    private function assertContributionPayable(Contribution $contribution): void
    {
        if ($contribution->status === 'completed') {
            throw new PaymentException('Cette cotisation est déjà payée.', 409);
        }

        if ($contribution->status === 'cancelled') {
            throw new PaymentException('Cette cotisation a été annulée.', 409);
        }

        if ($contribution->isPendingVerification()) {
            throw new PaymentException('Un code de transfert est déjà en attente de vérification.', 409);
        }

        if ($contribution->tontineMember->tontine->status === 'cancelled') {
            throw new PaymentException('Cette tontine a été annulée.', 409);
        }
    }

    private function assertInstallmentPayable(Installment $installment): void
    {
        if ($installment->status === 'completed') {
            throw new PaymentException('Cette tranche est déjà payée.', 409);
        }

        if ($installment->status === 'cancelled') {
            throw new PaymentException('Cette tranche a été annulée.', 409);
        }

        if ($installment->isPendingVerification()) {
            throw new PaymentException('Un code de transfert est déjà en attente de vérification.', 409);
        }

        if ($installment->purchase->status === 'cancelled') {
            throw new PaymentException('Cet achat a été annulé.', 409);
        }
    }

    private function contributionIntent(
        Contribution $contribution,
        string $channel,
        ?string $clientReference,
        bool $simulateSuccess,
    ): PaymentIntent {
        return new PaymentIntent(
            paymentKey: 'contribution:'.$contribution->id,
            amount: (float) $contribution->amount,
            currency: $contribution->currency ?: config('payments.currency', 'XOF'),
            description: 'Cotisation de tontine',
            metadata: [
                'simulate_success' => $simulateSuccess,
                'client_reference' => $clientReference,
            ],
        );
    }

    private function installmentIntent(
        Installment $installment,
        string $channel,
        ?string $clientReference,
        bool $simulateSuccess,
    ): PaymentIntent {
        return new PaymentIntent(
            paymentKey: 'installment:'.$installment->id,
            amount: (float) $installment->amount,
            currency: $installment->currency ?: config('payments.currency', 'XOF'),
            description: 'Tranche d\'achat',
            metadata: [
                'simulate_success' => $simulateSuccess,
                'client_reference' => $clientReference,
            ],
        );
    }

    private function applyContributionResult(Contribution $contribution, PaymentResult $result): Contribution
    {
        $this->storeReference($contribution, $result->externalReference);

        if ($result->isSuccessful()) {
            return $this->tontineService->recordPayment(
                $contribution->fresh(),
                $result->externalReference,
                $result->amount,
                $result->currency,
            );
        }

        if ($result->isFailure()) {
            return $this->tontineService->recordFailure($contribution->fresh(), $result->reason);
        }

        return $contribution->fresh();
    }

    private function applyInstallmentResult(Installment $installment, PaymentResult $result): Installment
    {
        $this->storeReference($installment, $result->externalReference);

        if ($result->isSuccessful()) {
            return $this->installmentPurchaseService->recordPayment(
                $installment->fresh(),
                $result->externalReference,
                $result->amount,
                $result->currency,
            );
        }

        if ($result->isFailure()) {
            return $this->installmentPurchaseService->recordFailure($installment->fresh(), $result->reason);
        }

        return $installment->fresh();
    }

    private function storeReference(Model $record, string $reference): void
    {
        if ($reference === '') {
            return;
        }

        $this->transactions->run(function () use ($record, $reference) {
            $locked = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());
            $current = $locked->transaction_reference;

            if ($current && $current !== $reference && ! str_starts_with($current, 'PENDING-')) {
                throw new PaymentException('Une autre transaction est déjà associée à ce paiement.', 409);
            }

            if ($record->newQuery()
                ->where('transaction_reference', $reference)
                ->where($record->getKeyName(), '!=', $record->getKey())
                ->exists()) {
                throw new PaymentException('Cette transaction est déjà associée à un autre paiement.', 409);
            }

            $locked->update(['transaction_reference' => $reference]);
        });
    }

    private function releaseReference(Model $record, string $reference): void
    {
        $this->transactions->run(function () use ($record, $reference) {
            $locked = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());

            if ($locked->transaction_reference === $reference && $locked->status !== 'completed') {
                $locked->update(['transaction_reference' => null]);
            }
        });
    }
}
