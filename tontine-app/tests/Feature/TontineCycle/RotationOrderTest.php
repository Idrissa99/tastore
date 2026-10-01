<?php

namespace Tests\Feature\TontineCycle;

use App\Models\Product;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use App\Services\TontineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PRIORITÉ 2 — L'ordre de passage est TIRÉ au lancement, et il est SECRET
 * jusque-là.
 *
 * Deux exigences distinctes, souvent confondues :
 *
 *  1. « aléatoire, en privilégiant les premiers venus » -> RotationDraw, au
 *     passage `open` -> `active`, c'est-à-dire quand la tontine est pleine ;
 *  2. « chacun apprend son tour APRÈS que la tontine soit complète » ->
 *     tant que la tontine est ouverte, la ressource ne renvoie ni position ni
 *     round de bénéficiaire. La route `/api/tontines/{id}` étant publique,
 *     exposer ces champs avant le tirage serait une fuite, pas un simple
 *     détail d'affichage.
 */
class RotationOrderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tontine ouverte, créateur déjà inscrit, et $joiners membres supplémentaires.
     * Renvoie la tontine et les membres prêts à postuler.
     */
    private function openTontine(int $maxMembers, int $joiners): array
    {
        $creator = User::factory()->create(['name' => 'Créateur']);
        $tontine = Tontine::factory()->create([
            'product_id' => Product::factory()->create()->id,
            'created_by' => $creator->id,
            'max_members' => $maxMembers,
            'status' => Tontine::STATUS_OPEN,
        ]);

        // Position d'arrivée : le créateur arrive en tête.
        TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => $creator->id,
            'position' => 1,
            'status' => TontineMember::STATUS_ACTIVE,
        ]);

        $members = [$creator];

        for ($index = 0; $index < $joiners; $index++) {
            $members[] = User::factory()->create();
        }

        return [$tontine, $members];
    }

    private function fill(array $members, Tontine $tontine, int $from): void
    {
        for ($index = $from; $index < count($members); $index++) {
            $this->actingAs($members[$index])
                ->postJson("/api/tontines/{$tontine->id}/join")
                ->assertOk();
        }
    }

    public function test_the_rotation_stays_hidden_while_the_tontine_is_not_full(): void
    {
        // 4 membres pour 5 places : la tontine reste ouverte, donc
        // l'ordre de passage n'est pas encore tiré.
        [$tontine, $members] = $this->openTontine(maxMembers: 5, joiners: 3);
        $this->fill($members, $tontine, from: 1);

        $this->assertSame(Tontine::STATUS_OPEN, $tontine->fresh()->status);

        // Lecture PUBLIQUE, non authentifiée : c'est bien là que la fuite
        // serait la plus grave.
        $data = $this->getJson("/api/tontines/{$tontine->id}")->assertOk()->json('data');

        $this->assertFalse($data['rotation_revealed']);

        foreach ($data['members'] as $member) {
            $this->assertNull(
                $member['position'],
                "Le tour de {$member['user_name']} ne doit pas être connu avant que la tontine soit complète.",
            );
            $this->assertNull($member['beneficiary_round']);
        }
    }

    public function test_the_rotation_becomes_public_once_the_tontine_is_full(): void
    {
        [$tontine, $members] = $this->openTontine(maxMembers: 3, joiners: 2);
        $this->fill($members, $tontine, from: 1);

        $this->assertSame(Tontine::STATUS_ACTIVE, $tontine->fresh()->status);

        $data = $this->getJson("/api/tontines/{$tontine->id}")->assertOk()->json('data');

        $this->assertTrue($data['rotation_revealed']);

        $positions = array_column($data['members'], 'position');

        $this->assertSame([1, 2, 3], $positions, 'Le tirage doit produire une permutation de 1..N, triée par la ressource.');
        $this->assertCount(3, array_unique($positions), 'Aucun doublon : un membre ne peut pas servir deux fois au même tour.');
    }

    public function test_the_drawn_order_is_a_permutation_of_every_member(): void
    {
        [$tontine, $members] = $this->openTontine(maxMembers: 5, joiners: 4);
        $this->fill($members, $tontine, from: 1);

        $positions = $tontine->members()->orderBy('position')->pluck('position')->all();

        $this->assertSame([1, 2, 3, 4, 5], $positions);
    }

    public function test_the_order_is_drawn_once_and_never_reshuffled(): void
    {
        [$tontine, $members] = $this->openTontine(maxMembers: 3, joiners: 2);
        $this->fill($members, $tontine, from: 1);

        $drawn = $tontine->members()->orderBy('position')->pluck('user_id', 'position')->all();

        // Relancer l'activation ne doit pas redistribuer les tours : les
        // membres ont déjà vu leur position, et la changer en silence
        // reviendrait à leur mentir.
        app(TontineService::class)->activateIfFull($tontine);
        app(TontineService::class)->activateIfFull($tontine);

        $this->assertSame(
            $drawn,
            $tontine->members()->orderBy('position')->pluck('user_id', 'position')->all(),
            'Un second appel ne doit pas remélanger un ordre déjà connu.',
        );
    }

    public function test_an_already_full_tontine_is_never_rebalanced(): void
    {
        [$tontine, $members] = $this->openTontine(maxMembers: 2, joiners: 1);
        $this->fill($members, $tontine, from: 1);

        $before = $tontine->members()->orderBy('position')->pluck('user_id', 'position')->all();

        // Un membre ne peut plus rejoindre : la tontine est active.
        $this->actingAs(User::factory()->create())
            ->postJson("/api/tontines/{$tontine->id}/join")
            ->assertForbidden();

        $this->assertSame($before, $tontine->members()->orderBy('position')->pluck('user_id', 'position')->all());
    }
}
