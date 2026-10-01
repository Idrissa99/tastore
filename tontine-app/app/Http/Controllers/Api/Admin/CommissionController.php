<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\FinancialReportService;
use Illuminate\Http\Request;

class CommissionController extends Controller
{
    public function __construct(protected FinancialReportService $reports) {}

    public function index()
    {
        $currentRate = (float) Setting::get('commission_rate', config('commissions.rate', 0));

        $breakdown = $this->reports->byMerchant();
        $totals = $this->reports->allTime();

        return response()->json([
            'current_rate' => $currentRate,
            'merchants' => $breakdown['merchants']->map(fn ($row) => [
                'merchant' => [
                    'id' => $row['merchant']->id,
                    'business_name' => $row['merchant']->business_name,
                ],
                'contributions_count' => $row['contributions_count'],
                'installments_count' => $row['installments_count'],
                'total_collected' => $row['total_collected'],
                'total_commission' => $row['total_commission'],
            ]),
            'cash_stats' => $breakdown['cash'],
            'contributions' => $totals['contributions'],
            'installments' => $totals['installments'],
            'total_commission' => $totals['totals']['commission'],
            'total_collected' => $totals['totals']['collected'],
            'refunds' => $totals['refunds'],
        ]);
    }

    public function updateRate(Request $request)
    {
        $validated = $request->validate([
            'rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        Setting::set('commission_rate', (string) ($validated['rate_percent'] / 100));

        return response()->json(['message' => 'Taux de commission mis à jour.']);
    }
}
