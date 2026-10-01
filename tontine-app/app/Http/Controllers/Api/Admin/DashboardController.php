<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\Merchant;
use App\Models\Tontine;
use App\Models\User;
use App\Services\FinancialReportService;

class DashboardController extends Controller
{
    public function __construct(protected FinancialReportService $reports) {}

    public function index()
    {
        $totals = $this->reports->allTime();

        return response()->json([
            'total_users' => User::count(),
            'total_merchants' => Merchant::count(),
            'pending_merchants' => Merchant::where('status', 'pending')->count(),
            'open_tontines' => Tontine::where('status', Tontine::STATUS_OPEN)->count(),
            'active_tontines' => Tontine::where('status', Tontine::STATUS_ACTIVE)->count(),
            'completed_tontines' => Tontine::where('status', Tontine::STATUS_COMPLETED)->count(),
            'cancelled_tontines' => Tontine::where('status', Tontine::STATUS_CANCELLED)->count(),
            'contributions' => $totals['contributions'],
            'installments' => $totals['installments'],
            'total_collected' => $totals['totals']['collected'],
            'total_commissions' => $totals['totals']['commission'],
            'refunds' => $totals['refunds'],
            'pending_refunds' => $totals['refunds']['pending_count'],
            'pending_refunds_amount' => $totals['refunds']['pending_amount'],
            'open_disputes' => Dispute::where('status', 'open')->count(),
        ]);
    }
}
