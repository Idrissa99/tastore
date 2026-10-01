<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminMerchantResource;
use App\Models\Merchant;
use Illuminate\Http\Request;

class MerchantController extends Controller
{
    public function index(Request $request)
    {
        $merchants = Merchant::with('user')
            ->withCount('products', 'reviews')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest()
            ->paginate(20);

        // AdminMerchantResource n'expose que les champs utiles à l'admin : ni
        // le mot de passe, ni les tokens du compte lié.
        return AdminMerchantResource::collection($merchants);
    }

    public function approve(Merchant $merchant)
    {
        $merchant->update(['status' => 'approved']);

        return response()->json(['message' => 'Commerçant approuvé.', 'merchant' => new AdminMerchantResource($merchant)]);
    }

    public function reject(Merchant $merchant)
    {
        $merchant->update(['status' => 'rejected']);

        return response()->json(['message' => 'Commerçant rejeté.', 'merchant' => new AdminMerchantResource($merchant)]);
    }
}
