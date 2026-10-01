<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tontine;
use App\Services\TontineService;
use Illuminate\Http\Request;

class TontineController extends Controller
{
    public function __construct(protected TontineService $tontineService)
    {
    }

    public function index(Request $request)
    {
        $tontines = Tontine::with(['product', 'members'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest()
            ->paginate(20);

        return view('admin.tontines.index', compact('tontines'));
    }

    /**
     * Annulation administrative (litige grave, fraude suspectée, etc.).
     * Génère une obligation de remboursement pour chaque cotisation déjà payée
     * (voir TontineService::cancelTontine) — à traiter ensuite dans /admin/refunds.
     */
    public function cancel(Tontine $tontine)
    {
        abort_if($tontine->status === 'completed', 409, 'Impossible d\'annuler une tontine déjà terminée.');

        $this->tontineService->cancelTontine($tontine);

        return back()->with('success', "Tontine « {$tontine->name} » annulée. Les remboursements dus ont été enregistrés.");
    }

    /**
     * Suppression DÉFINITIVE, distincte de l'annulation : la tontine et tout ce
     * qui s'y rattache disparaissent, en cascade.
     *
     * Refusée si de l'argent a été encaissé ou si un litige existe, sinon les
     * reçus de paiement et la piste d'audit seraient perdus sans retour
     * possible. Le service rejoue ce contrôle sous verrou : ici on ne fait
     * qu'éviter un 409 de course en renvoyant un message lisible.
     */
    public function destroy(Tontine $tontine)
    {
        $blockers = $this->tontineService->deletionBlockers($tontine);

        if ($blockers !== []) {
            return back()->with('error', $this->tontineService->deletionRefusalMessage($blockers));
        }

        $name = $tontine->name;

        $this->tontineService->deleteTontine($tontine);

        return back()->with('success', "Tontine « {$name} » supprimée définitivement.");
    }
}
