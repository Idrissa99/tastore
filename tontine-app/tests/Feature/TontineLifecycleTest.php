<?php

namespace Tests\Feature;

use App\Models\Contribution;
use App\Models\Product;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TontineLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * PRIORITÉ 2 — L'ordre de passage est TIRÉ au lancement, il ne vaut plus
     * l'ordre d'arrivée. Ce test suit donc le tirage au lieu de le supposer :
     * le bénéficiaire du round N est le membre tiré en position N.
     */
    public function test_full_tontine_cycle_designates_beneficiaries_in_order_and_completes(): void
    {
        $product = Product::factory()->create();

        $member1 = User::factory()->create(['name' => 'Membre 1']);
        $member2 = User::factory()->create(['name' => 'Membre 2']);
        $member3 = User::factory()->create(['name' => 'Membre 3']);

        $tontine = Tontine::factory()->create([
            'product_id' => $product->id,
            'created_by' => $member1->id,
            'max_members' => 3,
            'contribution_amount' => 1000,
            'status' => 'open',
        ]);

        TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => $member1->id,
            'position' => 1,
            'status' => 'active',
        ]);

        // membre 2 rejoint : la tontine n'est pas encore pleine
        $this->actingAs($member2)->post(route('tontines.join', $tontine));
        $tontine->refresh();
        $this->assertSame('open', $tontine->status);

        // membre 3 rejoint : la tontine est pleine -> activation automatique
        $this->actingAs($member3)->post(route('tontines.join', $tontine));
        $tontine->refresh();

        $this->assertSame('active', $tontine->status);
        $this->assertSame(1, $tontine->current_round);

        // Ordre de passage tel que tiré au lancement : c'est lui qui fait foi,
        // pas l'ordre d'arrivée (membre 1 arrivé en tête).
        $drawnOrder = $tontine->members()->orderBy('position')->pluck('user_id')->all();
        $this->assertEqualsCanonicalizing([$member1->id, $member2->id, $member3->id], $drawnOrder);
        $this->assertSame([1, 2, 3], $tontine->members()->orderBy('position')->pluck('position')->all());

        // Le bénéficiaire du round 1 est le premier de l'ordre TIRÉ.
        $this->assertSame('beneficiary', $this->statusOf($tontine, $drawnOrder[0]));

        // un appel de cotisation "pending" doit exister pour les 3 membres, round 1
        $this->assertSame(3, Contribution::where('round', 1)
            ->whereHas('tontineMember', fn ($q) => $q->where('tontine_id', $tontine->id))
            ->where('status', 'pending')
            ->count());

        // Tous les rounds : tout le monde paie, et chaque round sert le membre
        // suivant dans l'ordre tiré.
        for ($round = 1; $round <= 3; $round++) {
            foreach ([$member1, $member2, $member3] as $user) {
                $memberRecord = TontineMember::where('tontine_id', $tontine->id)->where('user_id', $user->id)->first();
                $contribution = Contribution::where('tontine_member_id', $memberRecord->id)->where('round', $round)->first();

                $this->actingAs($user)->post(route('contributions.pay', $contribution), ['payment_method' => 'orange_money'])->assertSessionDoesntHaveErrors();
            }

            $tontine->refresh();
            $this->assertSame(
                $round,
                $tontine->members()->where('user_id', $drawnOrder[$round - 1])->first()->beneficiary_round,
                "Le membre tiré en position {$round} doit être bénéficiaire du round {$round}."
            );
        }

        // plus personne à désigner -> la tontine est terminée
        $this->assertSame('completed', $tontine->status);

        // Chacun a servi exactement une fois, dans l'ordre tiré.
        $tontine->members()->get()->each(function (TontineMember $member) {
            $this->assertSame('completed', $member->status);
            $this->assertNotNull($member->beneficiary_round);
        });
    }

    private function statusOf(Tontine $tontine, int $userId): string
    {
        return $tontine->members()->where('user_id', $userId)->first()->status;
    }

    public function test_cannot_join_a_full_tontine(): void
    {
        $product = Product::factory()->create();
        $creator = User::factory()->create();

        $tontine = Tontine::factory()->create([
            'product_id' => $product->id,
            'created_by' => $creator->id,
            'max_members' => 1,
        ]);

        TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => $creator->id,
            'position' => 1,
            'status' => 'active',
        ]);

        $tontine->update(['status' => 'active']); // simule une tontine déjà pleine/active

        $latecomer = User::factory()->create();

        $this->actingAs($latecomer)
            ->post(route('tontines.join', $tontine))
            ->assertForbidden();
    }

    public function test_cannot_join_the_same_tontine_twice(): void
    {
        $product = Product::factory()->create();
        $creator = User::factory()->create();

        $tontine = Tontine::factory()->create([
            'product_id' => $product->id,
            'created_by' => $creator->id,
            'max_members' => 5,
        ]);

        TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => $creator->id,
            'position' => 1,
            'status' => 'active',
        ]);

        $this->actingAs($creator)
            ->post(route('tontines.join', $tontine))
            ->assertStatus(409);
    }
}
