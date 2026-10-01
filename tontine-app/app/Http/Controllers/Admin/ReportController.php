<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\FinancialReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(protected FinancialReportService $reports) {}

    public function index(Request $request)
    {
        [$from, $to] = $this->resolvePeriod($request);

        $stats = $this->reports->forPeriod($from, $to);
        $stats['from'] = $from->toDateString();
        $stats['to'] = $to->toDateString();

        return view('admin.reports.index', compact('stats'));
    }

    /**
     * Export CSV : cotisations ET tranches, chaque ligne portant sa source et
     * son taux de commission gelé, pour qu'aucun montant ne soit confondu.
     */
    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->resolvePeriod($request);

        $rows = $this->reports->exportRows($from, $to);

        $filename = "rapport-{$from->toDateString()}-au-{$to->toDateString()}.csv";

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Source',
                'Date',
                'Utilisateur',
                'Reference',
                'Detail',
                'Montant',
                'Commission',
                'Taux commission',
                'Moyen de paiement',
            ], ';');

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['source'],
                    $row['date'],
                    $row['utilisateur'],
                    $row['reference'],
                    $row['detail'],
                    $row['montant'],
                    $row['commission'],
                    $row['taux_commission'],
                    $row['moyen_de_paiement'],
                ], ';');
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function resolvePeriod(Request $request): array
    {
        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : now()->startOfMonth();

        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))->endOfDay()
            : now()->endOfDay();

        return [$from, $to];
    }
}
