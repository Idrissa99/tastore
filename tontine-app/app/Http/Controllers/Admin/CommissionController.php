<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\FinancialReportService;
use Illuminate\Http\Request;

class CommissionController extends Controller
{
    public function __construct(protected FinancialReportService $reports) {}

    public function index()
    {
        // Taux courant : sert uniquement à la saisie d'un NOUVEAU taux.
        // Les montants affichés proviennent tous du taux gelé sur chaque
        // transaction (colonne commission_amount), jamais de celui-ci.
        $currentRate = (float) Setting::get('commission_rate', config('commissions.rate', 0));

        $breakdown = $this->reports->byMerchant();
        $totals = $this->reports->allTime();

        return view('admin.commissions.index', [
            'currentRate' => $currentRate,
            'merchants' => $breakdown['merchants'],
            'cashStats' => (object) $breakdown['cash'],
            'totals' => $totals,
        ]);
    }

    public function updateRate(Request $request)
    {
        $validated = $request->validate([
            'rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        Setting::set('commission_rate', (string) ($validated['rate_percent'] / 100));

        return back()->with('success', 'Taux de commission mis à jour. Il s\'appliquera aux prochains paiements (pas rétroactif).');
    }
}
