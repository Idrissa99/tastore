<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTontineRequest;
use App\Http\Requests\UpdateTontineRequest;
use App\Http\Resources\TontineResource;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use App\Notifications\TontineUpdatedNotification;
use App\Services\TontineService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TontineController extends Controller
{
    public function __construct(protected TontineService $tontineService) {}

    /**
     * Catalogue public des tontines.
     *
     * Les tontines ne sont PLUS filtrées sur le seul statut "open" : une
     * tontine pleine disparaissait du catalogue alors qu'elle existe toujours
     * et que ses membres continuent de la suivre. Une tontine annulée ou
     * terminée reste consultable, avec son statut affiché sans ambiguïté.
     *
     * Ce qui reste inchangé, et c'est essentiel : l'adhésion, elle, demeure
     * réservée aux tontines "open" (voir join()). Afficher n'autorise pas.
     *
     * Tri : de la plus récente à la plus ancienne, quel que soit le statut.
     * Aucune priorité n'est donnée au statut "open" : une tontine créé
     * aujourd'hui s'affiche donc au-dessus d'une tontine rejoignable
     * d'il y a des mois. L'identifiant en dernier critère rend le tri
     * total, donc la pagination stable entre deux requêtes lorsque deux
     * tontines ont la même date de création.
     *
     * @queryParam available bool ne renvoyer que les tontines rejoignables
     */
    public function index(Request $request)
    {
        $tontines = Tontine::with(['product', 'members.user'])
            ->when(
                $request->boolean('available'),
                fn ($query) => $query->where('status', Tontine::STATUS_OPEN),
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(12);

        return TontineResource::collection($tontines);
    }

    public function store(StoreTontineRequest $request)
    {
        $validated = $request->validated();
        $user = $request->user();

        abort_unless(
            $user->isAdmin() || ($user->isMerchant() && $user->merchant?->isApproved()),
            403,
            'Seuls les commerçants approuvés ou les administrateurs peuvent créer une tontine.'
        );

        if ($validated['type'] === 'product') {
            $product = Product::findOrFail($validated['product_id']);

            abort_unless(
                $user->isAdmin() || $product->merchant_id === $user->merchant?->id,
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

        $commissionRate = (float) Setting::get('commission_rate', config('commissions.rate', 0));
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

            TontineMember::create([
                'tontine_id' => $tontine->id,
                'user_id' => $request->user()->id,
                'position' => 1,
                'status' => 'active',
            ]);

            return $tontine;
        });

        return new TontineResource($tontine->load(['product', 'members.user']));
    }

    public function show(Tontine $tontine)
    {
        return new TontineResource($tontine->load(['product', 'members.user']));
    }

    /**
     * Modification d'une tontine qui n'a pas encore démarré.
     *
     * PUT /tontines/{tontine}
     *
     * Une tontine reste modifiable tant qu'elle est "open". Son activation
     * n'a lieu qu'une fois le nombre maximal de membres atteint
     * (TontineService::activateIfFull) : jusque-là AUCUNE cotisation n'a été
     * générée, donc aucun montant n'est engagé et aucune transaction ne
     * dépend des valeurs modifiées.
     *
     * Passée "active", la modification est refusée : les appels de cotisation
     * portent déjà le montant et la commission figés à ce moment-là, et les
     * membres ont commencé à cotiser sur cette base.
     */
    public function update(UpdateTontineRequest $request, Tontine $tontine)
    {
        $user = $request->user();
        $validated = $request->validated();

        // L'administrateur peut administrer toutes les tontines (comme à la
        // création) ; sinon, seule la personne qui l'a créée y touche.
        abort_unless(
            $user->isAdmin() || $tontine->created_by === $user->id,
            403,
            'Seul le créateur de la tontine peut la modifier.'
        );

        abort_if(
            $tontine->status !== Tontine::STATUS_OPEN,
            403,
            'Cette tontine a déjà démarré : ses conditions sont figées. Elle ne peut plus être modifiée.'
        );

        $tontine = DB::transaction(function () use ($tontine, $validated, $user) {
            $locked = Tontine::query()->lockForUpdate()->findOrFail($tontine->id);

            // Contrôle rejoué sous verrou : entre la lecture du statut et cette
            // écriture, un dernier membre a pu rejoindre la tontine et
            // déclencher son activation.
            abort_if(
                $locked->status !== Tontine::STATUS_OPEN,
                409,
                'Cette tontine vient de démarrer : sa modification n\'est plus possible.'
            );

            $memberCount = $locked->members()->count();
            $maxMembers = (int) ($validated['max_members'] ?? $locked->max_members);

            // Cohérence du modèle : on ne peut pas déclarer 4 places alors que
            // 6 personnes sont déjà inscrites. Sans ce garde-fou, isFull()
            // renverrait vrai et la tontine serait « complète » au-dessus de
            // sa propre capacité.
            abort_if(
                $maxMembers < $memberCount,
                422,
                "Impossible de ramener le nombre de membres à {$maxMembers} : {$memberCount} personnes ont déjà rejoint cette tontine.",
            );

            $updates = [];
            $type = $validated['type'] ?? $locked->type;

            if ($type === 'product') {
                $product = Product::findOrFail($validated['product_id'] ?? $locked->product_id);

                abort_unless(
                    $user->isAdmin() || $product->merchant_id === $user->merchant?->id,
                    403,
                    'Tu ne peux retirer que tes propres produits.'
                );

                $updates['type'] = 'product';
                $updates['product_id'] = $product->id;
                $updates['total_amount'] = $product->price;
            } else {
                abort_unless($user->isAdmin(), 403, 'Seul un administrateur peut faire une tontine argent.');

                $updates['type'] = 'cash';
                $updates['product_id'] = null;
                $updates['total_amount'] = $validated['total_amount'] ?? $locked->total_amount;
            }

            foreach (['name', 'frequency', 'start_date'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $updates[$field] = $validated[$field];
                }
            }

            $updates['max_members'] = $maxMembers;

            $updates = $this->withRecalculatedContribution($locked, $updates);

            $changes = $this->describeChanges($locked, $updates);

            $locked->update($updates);
            $locked->load(['product', 'members.user']);

            if ($changes !== []) {
                $this->notifyMembers($locked, $user, $changes);
            }

            return $locked;
        });

        return new TontineResource($tontine);
    }

    /**
     * Recalcule le montant d'un versement quand sa base change.
     *
     * Le taux de commission reste celui figé à la création : les membres ont
     * été informés sur cette base, et le faire glisser parce que le taux
     * global vient de bouger modifierait un engagement déjà annoncé.
     */
    private function withRecalculatedContribution(Tontine $tontine, array $updates): array
    {
        $totalAmount = (float) ($updates['total_amount'] ?? $tontine->total_amount);
        $maxMembers = (int) ($updates['max_members'] ?? $tontine->max_members);
        $commissionRate = (float) $tontine->commission_rate;

        $updates['contribution_amount'] = round(($totalAmount / $maxMembers) * (1 + $commissionRate), 2);

        return $updates;
    }

    /**
     * Traduit l'écart avant/après en libellés lisibles, pour informer les
     * membres de ce qui change réellement pour eux.
     *
     * @return list<string>
     */
    private function describeChanges(Tontine $before, array $updates): array
    {
        $changes = [];
        $productName = $before->product?->name;

        if (array_key_exists('name', $updates) && $updates['name'] !== $before->name) {
            $changes[] = 'nom : '.$before->name.' → '.$updates['name'];
        }

        if (array_key_exists('frequency', $updates) && $updates['frequency'] !== $before->frequency) {
            $changes[] = 'fréquence des versements modifiée';
        }

        if (array_key_exists('start_date', $updates)
            && (string) $updates['start_date'] !== (string) $before->start_date) {
            $changes[] = 'date de démarrage modifiée';
        }

        if (array_key_exists('max_members', $updates)
            && (int) $updates['max_members'] !== (int) $before->max_members) {
            $changes[] = 'nombre de participants : '.$before->max_members.' → '.$updates['max_members'];
        }

        // `product_id` est TOUJOURS présent dans $updates (la branche de type
        // le renseigne), y compris quand il ne change pas : on compare donc
        // les valeurs, sinon chaque sauvegarde déclencherait une notification
        // annonçant un produit « modifié » qui ne l'a pas été.
        if (array_key_exists('product_id', $updates) && (int) $updates['product_id'] !== (int) $before->product_id) {
            $changes[] = $updates['type'] === 'cash'
                ? 'la tontine devient une tontine argent'
                : 'produit financé : '.($productName ?? 'aucun')
                    .' → '.(Product::find($updates['product_id'])?->name ?? 'aucun');
        }

        if (array_key_exists('total_amount', $updates)
            && (float) $updates['total_amount'] !== (float) $before->total_amount) {
            $changes[] = 'montant visé : '.$before->total_amount.' → '.$updates['total_amount'].' FCFA';
        }

        if (array_key_exists('contribution_amount', $updates)
            && (float) $updates['contribution_amount'] !== (float) $before->contribution_amount) {
            $changes[] = 'montant de chaque versement : '.$before->contribution_amount
                .' → '.$updates['contribution_amount'].' FCFA';
        }

        return array_values(array_filter($changes));
    }

    /**
     * Prévient les membres, sauf l'auteur de la modification.
     *
     * Ils se sont inscrits sur des conditions données : si le montant ou le
     * produit visé change, ils doivent l'apprendre, et non le découvrir en
     * ouvrant l'application plus tard.
     */
    private function notifyMembers(Tontine $tontine, User $author, array $changes): void
    {
        $tontine->members
            ->where('user_id', '!=', $author->id)
            ->each(fn (TontineMember $member) => $member->user->notify(
                new TontineUpdatedNotification($tontine, $changes)
            ));
    }

    public function join(Tontine $tontine)
    {
        $user = request()->user();

        abort_if($tontine->status !== 'open', 403, 'Cette tontine n\'accepte plus de nouveaux membres.');
        abort_if($tontine->members()->where('user_id', $user->id)->exists(), 409, 'Tu es déjà membre de cette tontine.');

        // PRIORITÉ 4 §6 : le contrôle de places ET l'insertion doivent partager
        // la même transaction, avec verrou sur la tontine. Sinon deux
        // inscriptions simultanées peuvent toutes deux lire « pas pleine » et
        // franchir max_members, ou prendre la même position — ce qui rendrait
        // l'ordre de passage des bénéficiaires non déterministe.
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

        return new TontineResource($tontine->fresh(['product', 'members.user']));
    }
}
