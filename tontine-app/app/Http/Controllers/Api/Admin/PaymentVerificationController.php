<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\Installment;
use App\Notifications\ContributionVerificationUpdatedNotification;
use App\Notifications\InstallmentVerificationUpdatedNotification;
use App\Services\InstallmentPurchaseService;
use App\Services\TontineService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentVerificationController extends Controller
{
    public function __construct(
        protected TontineService $tontineService,
        protected InstallmentPurchaseService $installmentService,
    ) {
    }

    /**
     * Liste unifiée : paiements de cotisations de tontine + paiements de tranches d'achat,
     * tous identifiés par un champ "type" pour que le frontend sache quelle route utiliser.
     */
    public function index(Request $request)
    {
        $status = $request->input('status', 'pending');

        $contributions = Contribution::where('verification_status', $status)
            ->with(['tontineMember.user', 'tontineMember.tontine'])
            ->get()
            ->map(fn (Contribution $c) => [
                'type' => 'contribution',
                'id' => $c->id,
                'client_name' => $c->tontineMember->user->name,
                'label' => $c->tontineMember->tontine->name,
                'sub_label' => 'Tontine — round ' . $c->round,
                'amount' => $c->amount,
                'payment_method' => $c->payment_method,
                'transfer_code' => $c->transfer_code,
                'submitted_at' => $c->submitted_at,
                'verification_status' => $c->verification_status,
            ]);

        $installments = Installment::where('verification_status', $status)
            ->with(['purchase.user', 'purchase.product'])
            ->get()
            ->map(fn (Installment $i) => [
                'type' => 'installment',
                'id' => $i->id,
                'client_name' => $i->purchase->user->name,
                'label' => $i->purchase->product->name,
                'sub_label' => 'Achat par tranches — tranche ' . $i->installment_number,
                'amount' => $i->amount,
                'payment_method' => $i->payment_method,
                'transfer_code' => $i->transfer_code,
                'submitted_at' => $i->submitted_at,
                'verification_status' => $i->verification_status,
            ]);

        $payments = $contributions->concat($installments)->sortByDesc('submitted_at')->values();

        return response()->json($payments);
    }

    public function acceptContribution(Contribution $contribution)
    {
        $accepted = DB::transaction(function () use ($contribution) {
            $locked = Contribution::query()->lockForUpdate()->findOrFail($contribution->id);

            abort_unless($locked->isPendingVerification(), 409, 'Ce paiement n\'est pas en attente de vérification.');
            abort_if(
                $locked->isCancelled(),
                409,
                'Cette cotisation a été annulée : le code de transfert ne peut plus être validé.'
            );

            $locked->update(['verification_status' => 'accepted']);
            $this->tontineService->recordPayment($locked);

            return $locked->fresh();
        });

        $accepted->tontineMember->user->notify(new ContributionVerificationUpdatedNotification($accepted));

        return response()->json(['message' => 'Paiement accepté.']);
    }

    public function rejectContribution(Contribution $contribution)
    {
        $rejected = DB::transaction(function () use ($contribution) {
            $locked = Contribution::query()->lockForUpdate()->findOrFail($contribution->id);

            abort_unless($locked->isPendingVerification(), 409, 'Ce paiement n\'est pas en attente de vérification.');

            $locked->update(['verification_status' => 'rejected']);

            return $locked->fresh();
        });

        $rejected->tontineMember->user->notify(new ContributionVerificationUpdatedNotification($rejected));

        return response()->json(['message' => 'Paiement refusé.']);
    }

    public function acceptInstallment(Installment $installment)
    {
        $accepted = DB::transaction(function () use ($installment) {
            $locked = Installment::query()->lockForUpdate()->findOrFail($installment->id);

            abort_unless($locked->isPendingVerification(), 409, 'Ce paiement n\'est pas en attente de vérification.');

            $locked->update(['verification_status' => 'accepted']);
            $this->installmentService->recordPayment($locked);

            return $locked->fresh();
        });

        $accepted->purchase->user->notify(new InstallmentVerificationUpdatedNotification($accepted));

        return response()->json(['message' => 'Paiement accepté.']);
    }

    public function rejectInstallment(Installment $installment)
    {
        $rejected = DB::transaction(function () use ($installment) {
            $locked = Installment::query()->lockForUpdate()->findOrFail($installment->id);

            abort_unless($locked->isPendingVerification(), 409, 'Ce paiement n\'est pas en attente de vérification.');

            $locked->update(['verification_status' => 'rejected']);

            return $locked->fresh();
        });

        $rejected->purchase->user->notify(new InstallmentVerificationUpdatedNotification($rejected));

        return response()->json(['message' => 'Paiement refusé.']);
    }
}
