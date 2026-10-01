<?php

namespace Tests\Feature\TontineCycle;

use App\Models\Contribution;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Services\DeliveryService;
use App\Services\TontineService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concerns\BuildsTontineCycles;
use Tests\TestCase;

/**
 * PRIORITÉ 2 §4 & §5 — Bénéficiaire unique par round et changement de round.
 */
class RoundSwitchTest extends TestCase
{
    use BuildsTontineCycles;
    use RefreshDatabase;

    public function test_a_tontine_never_has_two_active_beneficiaries(): void
    {
        [$tontine, $beneficiary, $members] = $this->makeActiveProductTontine(4);

        $this->assertSame(1, $tontine->members()->where('status', TontineMember::STATUS_BENEFICIARY)->count());

        // Appels répétés : le bénéficiaire existant est renvoyé, pas dupliqué.
        $service = app(TontineService::class);
        for ($i = 0; $i < 5; $i++) {
            $again = $service->designateBeneficiary($tontine->fresh(), 1);
            $this->assertNotNull($again);
            $this->assertSame($beneficiary->id, $again->id);
        }

        $this->assertSame(1, $tontine->fresh()->members()->where('status', TontineMember::STATUS_BENEFICIARY)->count());
    }

    public function test_a_second_beneficiary_cannot_be_inserted_in_the_database(): void
    {
        [$tontine, $beneficiary, $members] = $this->makeActiveProductTontine(3);

        $other = $members->firstWhere('id', '!=', $beneficiary->id);

        // L'index unique partiel protège la règle "un bénéficiaire par tontine",
        // indépendamment de l'application.
        $this->expectException(QueryException::class);

        DB::table('tontine_members')->where('id', $other->id)->update([
            'status' => TontineMember::STATUS_BENEFICIARY,
        ]);
    }

    public function test_round_never_switches_before_being_fully_paid(): void
    {
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(3);
        $contributions = $this->roundContributions($tontine, 1);

        $this->payContribution($contributions[0]);
        $this->payContribution($contributions[1]);

        $this->assertSame(1, $tontine->fresh()->current_round);
        $this->assertSame(
            TontineMember::STATUS_BENEFICIARY,
            $tontine->fresh()->members()->where('id', $beneficiary->id)->first()->status
        );

        // Le dernier paiement débloque le round.
        $this->payContribution($contributions[2]);

        $this->assertSame(2, $tontine->fresh()->current_round);
        $this->assertSame(
            TontineMember::STATUS_COMPLETED,
            $tontine->fresh()->members()->where('id', $beneficiary->id)->first()->status
        );
    }

    public function test_round_switch_is_idempotent(): void
    {
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(2);
        $service = app(TontineService::class);

        $this->payRound($tontine, 1);
        $this->assertSame(2, $tontine->fresh()->current_round);

        $membersAfterFirst = $tontine->fresh()->members()->orderBy('id')
            ->get(['id', 'status', 'beneficiary_round', 'delivery_status'])
            ->toArray();
        $contributionsAfterFirst = Contribution::whereHas(
            'tontineMember',
            fn ($query) => $query->where('tontine_id', $tontine->id)
        )->orderBy('id')->get(['id', 'round', 'status'])->toArray();

        // Rejouer le contrôle 5 fois ne doit rien changer.
        for ($i = 0; $i < 5; $i++) {
            $service->checkRoundCompletionAndAdvance($tontine->fresh());
        }

        $this->assertSame(2, $tontine->fresh()->current_round);
        $this->assertSame(
            $membersAfterFirst,
            $tontine->fresh()->members()->orderBy('id')
                ->get(['id', 'status', 'beneficiary_round', 'delivery_status'])->toArray()
        );
        $this->assertEquals(
            $contributionsAfterFirst,
            Contribution::whereHas(
                'tontineMember',
                fn ($query) => $query->where('tontine_id', $tontine->id)
            )->orderBy('id')->get(['id', 'round', 'status'])->toArray()
        );
    }

    public function test_round_switch_does_not_duplicate_contributions(): void
    {
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(3);
        $service = app(TontineService::class);

        $this->assertSame(3, $this->roundContributions($tontine, 1)->count());

        // Génération répétée sur le même round : rien de neuf.
        $this->assertSame(0, $service->generateContributionCallForRound($tontine->fresh(), 1));
        $this->assertSame(0, $service->generateContributionCallForRound($tontine->fresh(), 1));
        $this->assertSame(3, $this->roundContributions($tontine, 1)->count());

        $this->payRound($tontine, 1);
        $this->assertSame(2, $tontine->fresh()->current_round);
        $this->assertSame(3, $this->roundContributions($tontine->fresh(), 2)->count());

        // Un (member, round) est unique en base : ré-appeler ne duplique rien.
        $this->assertSame(0, $service->generateContributionCallForRound($tontine->fresh(), 2));
        $this->assertSame(3, $this->roundContributions($tontine->fresh(), 2)->count());
    }

    public function test_database_refuses_two_contributions_for_the_same_member_and_round(): void
    {
        [$tontine] = $this->makeActiveProductTontine(2);
        $member = $tontine->members()->first();
        $existing = $this->roundContributions($tontine, 1)->first();

        $this->expectException(QueryException::class);

        Contribution::create([
            'tontine_member_id' => $member->id,
            'round' => 1,
            'amount' => 10000,
            'status' => Contribution::STATUS_PENDING,
        ]);
    }

    public function test_beneficiary_rotation_follows_position_and_does_not_repeat_members(): void
    {
        [$tontine, $first, $members] = $this->makeActiveProductTontine(3);

        $order = [$first->position];

        for ($round = 1; $round < 3; $round++) {
            $this->payRound($tontine, $round);
            $tontine->refresh();

            $next = $tontine->members()->where('status', TontineMember::STATUS_BENEFICIARY)->first();
            $this->assertNotNull($next);
            $this->assertSame($round + 1, $next->beneficiary_round);
            $order[] = $next->position;
        }

        $this->assertSame([1, 2, 3], $order);

        $this->payRound($tontine, 3);
        $tontine->refresh();

        $this->assertSame(Tontine::STATUS_COMPLETED, $tontine->status);
        $this->assertSame(
            0,
            $tontine->members()->where('status', TontineMember::STATUS_BENEFICIARY)->count()
        );
        $this->assertSame(
            [1, 2, 3],
            $tontine->members()->orderBy('position')->pluck('beneficiary_round')->all()
        );
    }

    public function test_outgoing_beneficiary_keeps_delivering_after_the_switch(): void
    {
        [$tontine, $first, $members] = $this->makeActiveProductTontine(3);

        $this->payRound($tontine, 1);
        $tontine->refresh();

        $outgoing = $tontine->members()->where('id', $first->id)->first();
        $incoming = $tontine->members()->where('status', TontineMember::STATUS_BENEFICIARY)->first();

        $this->assertSame(TontineMember::STATUS_COMPLETED, $outgoing->status);
        $this->assertSame(1, $outgoing->beneficiary_round);
        $this->assertSame(TontineMember::DELIVERY_PENDING, $outgoing->delivery_status);

        $this->assertSame(2, $incoming->beneficiary_round);
        $this->assertSame(TontineMember::DELIVERY_AWAITING_PAYMENT, $incoming->delivery_status);

        $delivery = app(DeliveryService::class);
        $this->assertTrue($delivery->isEligible($outgoing));
        $this->assertFalse($delivery->isEligible($incoming));
    }

    public function test_a_failed_contribution_blocks_the_round_switch(): void
    {
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(2);
        $contributions = $this->roundContributions($tontine, 1);

        $this->payContribution($contributions[0]);
        $this->forceContributionStatus($contributions[1], Contribution::STATUS_FAILED);

        $this->assertFalse($tontine->fresh()->roundIsFunded(1));
        $this->assertSame(1, $tontine->fresh()->current_round);
        $this->assertSame(
            TontineMember::STATUS_BENEFICIARY,
            $tontine->fresh()->members()->where('id', $beneficiary->id)->first()->status
        );
    }

    public function test_no_round_generation_for_a_cancelled_tontine(): void
    {
        [$tontine] = $this->makeActiveProductTontine(2);
        $service = app(TontineService::class);

        $service->cancelTontine($tontine);

        $this->assertSame(0, $service->generateContributionCallForRound($tontine->fresh(), 2));
        $this->assertSame(0, $this->roundContributions($tontine->fresh(), 2)->count());
    }

    public function test_designating_a_beneficiary_on_a_cancelled_tontine_is_a_no_op(): void
    {
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(2);
        $service = app(TontineService::class);

        $service->cancelTontine($tontine);

        $this->assertNull($service->designateBeneficiary($tontine->fresh(), 2));
        $this->assertSame(
            0,
            $tontine->fresh()->members()->where('status', TontineMember::STATUS_BENEFICIARY)->count()
        );
    }

    public function test_a_full_cycle_produces_exactly_one_delivery_slot_per_member(): void
    {
        [$tontine] = $this->makeActiveProductTontine(3);

        for ($round = 1; $round <= 3; $round++) {
            $this->payRound($tontine, $round);
            $tontine->refresh();
        }

        $this->assertSame(Tontine::STATUS_COMPLETED, $tontine->status);

        $tontine->members()->get()->each(function (TontineMember $member) {
            $this->assertNotNull($member->beneficiary_round);
            $this->assertSame(TontineMember::STATUS_COMPLETED, $member->status);
            $this->assertSame(TontineMember::DELIVERY_PENDING, $member->delivery_status);
        });

        // 3 membres × 1 tour = 3 cotisations par round × 3 rounds.
        $this->assertSame(9, Contribution::whereHas(
            'tontineMember',
            fn ($query) => $query->where('tontine_id', $tontine->id)
        )->count());
    }
}
