<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMerchantReviewRequest;
use App\Http\Resources\MerchantResource;
use App\Models\Merchant;
use App\Models\MerchantReview;
use App\Models\Tontine;

class MerchantController extends Controller
{
    public function show(Merchant $merchant)
    {
        $merchant->load(['reviews.user', 'products' => fn ($q) => $q->where('status', 'published')]);

        return new MerchantResource($merchant);
    }

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

        return response()->json(['message' => 'Avis enregistré.']);
    }
}
