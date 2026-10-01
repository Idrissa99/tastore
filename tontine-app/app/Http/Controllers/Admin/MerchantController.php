<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use Illuminate\Http\Request;

class MerchantController extends Controller
{
    public function index()
    {
        $merchants = Merchant::with('user')->latest()->paginate(20);

        return view('admin.merchants.index', compact('merchants'));
    }

    public function approve(Merchant $merchant)
    {
        $merchant->update(['status' => 'approved']);

        return back()->with('success', "Commerçant {$merchant->business_name} approuvé.");
    }

    public function reject(Request $request, Merchant $merchant)
    {
        $merchant->update(['status' => 'rejected']);

        return back()->with('success', "Commerçant {$merchant->business_name} rejeté.");
    }
}
