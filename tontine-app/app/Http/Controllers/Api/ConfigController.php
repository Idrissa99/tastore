<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;

class ConfigController extends Controller
{
    public function index()
    {
        return response()->json([
            'commission_rate' => (float) Setting::get('commission_rate', config('commissions.rate', 0)),
            'payment_channels' => config('payments.channels'),
        ]);
    }
}
