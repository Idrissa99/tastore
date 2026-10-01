<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTontineRequest;
use App\Models\Product;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Services\TontineService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TontineController extends Controller
{
    public function __construct(protected TontineService $tontineService)
    {
    }

    public function index()
    {
        $tontines = Tontine::with(['product', 'members'])
            ->where('status', 'open')
            ->latest()
            ->paginate(12);

        return view('tontines.index', compact('tontines'));
    }

    public function create(Request $request)
    {
        $user = $request->user();

        // Un commerçant ne peut créer une tontine que sur SES produits.
        // Un admin voit tous les produits publiés et peut aussi créer une tontine argent.
        $products = $user->isAdmin()
            ? Product::where('status', 'published')->get()
            : $user->merchant->products()->where('status', 'published')->get();

        $canCreateCash = $user->isAdmin();
        $selectedProductId = $request->integer('product_id') ?: null;
        $commissionRate = (float) \App\Models\Setting::get('commission_rate', config('commissions.rate', 0));

        return view('tontines.create', compact('products', 'selectedProductId', 'commissionRate', 'canCreateCash'));
    }

    public function store(StoreTontineRequest $request)
    {
        $validated = $request->validated();
        $user = $request->user();

        if ($validated['type'] === 'product') {
            $product = Product::findOrFail($validated['product_id']);

            abort_unless(
                $user->isAdmin() || ($user->isMerchant() && $product->merchant_id === $user->merchant?->id),
                403,
                'Tu ne peux créer une tontine que sur tes propres produits.'
            );

            $productId = $product->id;
            $totalAmount = $product->price;
        } else {
            abort_unless($user->isAdmin(), 403, 'Seul un administrateur peut créer une tontine argent.');

            $productId = null;
            $totalAmount = $validated['total_amount'];
        }

        // Le montant de chaque versement n'est jamais saisi par l'utilisateur : il est
        // calculé automatiquement pour que le prix (ou le montant visé) soit intégralement
        // couvert malgré la commission plateforme prélevée à chaque versement.
        $commissionRate = (float) \App\Models\Setting::get('commission_rate', config('commissions.rate', 0));
        $contributionAmount = round(($totalAmount / $validated['max_members']) * (1 + $commissionRate), 2);

        $tontine = DB::transaction(function () use ($validated, $productId, $totalAmount, $contributionAmount, $commissionRate, $request) {
            $tontine = Tontine::create([
                'product_id' => $productId,
                'type' => $validated['type'],
                'created_by' => $request->user()->id,
                'name' => $validated['name'],
                'total_amount' => $totalAmount,
                'contribution_amount' => $contributionAmount,
                'commission_rate' => $commissionRate,
                'frequency' => $validated['frequency'],
                'max_members' => $validated['max_members'],
                'start_date' => $validated['start_date'] ?? null,
                'status' => 'open',
            ]);

            // le créateur rejoint automatiquement sa propre tontine
            TontineMember::create([
                'tontine_id' => $tontine->id,
                'user_id' => $request->user()->id,
                'position' => 1,
                'status' => 'active',
            ]);

            return $tontine;
        });

        return redirect()->route('tontines.show', $tontine)
            ->with('success', 'Tontine créée avec succès.');
    }

    public function show(Tontine $tontine)
    {
        $tontine->load(['product', 'members.user']);

        return view('tontines.show', compact('tontine'));
    }

    public function join(Tontine $tontine)
    {
        $user = request()->user();

        abort_if($tontine->status !== 'open', 403, 'Cette tontine n\'accepte plus de nouveaux membres.');
        abort_if($tontine->members()->where('user_id', $user->id)->exists(), 409, 'Tu es déjà membre de cette tontine.');

        // PRIORITÉ 4 §6 : contrôle de places ET insertion dans la même
        // transaction, avec verrou sur la tontine (sinon dépassement de
        // max_members et positions en doublon sous inscriptions simultanées).
        $tontine = DB::transaction(function () use ($tontine, $user) {
            $locked = Tontine::query()->lockForUpdate()->findOrFail($tontine->id);

            abort_if($locked->status !== 'open', 403, 'Cette tontine n\'accepte plus de nouveaux membres.');
            abort_if($locked->isFull(), 403, 'Cette tontine est déjà complète.');
            abort_if(
                $locked->members()->where('user_id', $user->id)->exists(),
                409,
                'Tu es déjà membre de cette tontine.'
            );

            TontineMember::create([
                'tontine_id' => $locked->id,
                'user_id' => $user->id,
                'position' => (int) $locked->members()->max('position') + 1,
                'status' => TontineMember::STATUS_ACTIVE,
            ]);

            return $locked;
        });

        $this->tontineService->activateIfFull($tontine->fresh());

        return back()->with('success', 'Tu as rejoint la tontine.');
    }
}
