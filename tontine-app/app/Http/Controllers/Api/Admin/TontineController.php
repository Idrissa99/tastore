<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TontineResource;
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
        $tontines = Tontine::with(['product', 'members.user'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest()
            ->paginate(20);

        return TontineResource::collection($tontines);
    }

    public function cancel(Tontine $tontine)
    {
        abort_if($tontine->status === 'completed', 409, 'Impossible d\'annuler une tontine déjà terminée.');

        $this->tontineService->cancelTontine($tontine);

        return response()->json(['message' => 'Tontine annulée, remboursements enregistrés.']);
    }

    /**
     * Suppression DÉFINITIVE, distincte de l'annulation.
     *
     * Refusée dès qu'une somme a été encaissée ou qu'un litige existe : ces
     * lignes sont en CASCADE, donc le DELETE les emporterait sans laisser de
     * trace comptable. Le contrôle est rejoué sous verrou dans le service, il
     * ne s'agit ici que d'un message plus propre qu'un 409 de course.
     */
    public function destroy(Tontine $tontine)
    {
        $blockers = $this->tontineService->deletionBlockers($tontine);

        abort_if($blockers !== [], 409, $this->tontineService->deletionRefusalMessage($blockers));

        $this->tontineService->deleteTontine($tontine);

        return response()->json(['message' => "Tontine « {$tontine->name} » supprimée."]);
    }
}
