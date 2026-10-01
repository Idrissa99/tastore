<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMerchantReviewRequest;
use App\Models\Merchant;
use App\Models\MerchantReview;
use App\Models\Tontine;
use Illuminate\Http\Request;

class MerchantController extends Controller
{
    public function show(Merchant $merchant)
    {
        $merchant->load(['reviews.user']);
        $products = $merchant->products()->where('status', 'published')->get();

        return view('merchants.show', compact('merchant', 'products'));
    }

    /**
     * Un avis ne peut être laissé que par un membre d'une tontine TERMINÉE
     * portant sur un produit de ce commerçant — pas d'avis "à froid".
     */
    public function storeReview(StoreMerchantReviewRequest $request, Tontine $tontine)
    {
        $user = $request->user();
        $merchant = $tontine->product?->merchant;

        abort_if(! $merchant, 404, 'Cette tontine n\'est pas liée à un commerçant (tontine argent).');
        abort_unless($tontine->status === 'completed', 409, 'Tu ne peux évaluer le commerçant qu\'une fois la tontine terminée.');
        abort_unless(
            $tontine->members()->where('user_id', $user->id)->exists(),
            403,
            'Tu dois avoir participé à cette tontine pour laisser un avis.'
        );

        $validated = $request->validated();

        MerchantReview::updateOrCreate(
            ['user_id' => $user->id, 'tontine_id' => $tontine->id],
            [
                'merchant_id' => $merchant->id,
                'rating' => $validated['rating'],
                'comment' => $validated['comment'] ?? null,
            ]
        );

        $merchant->recalculateRating();

        return redirect()->route('tontines.show', $tontine)->with('success', 'Merci pour ton avis !');
    }
}
