<?php

namespace Tests\Feature\TontineCycle;

use App\Models\Contribution;
use App\Models\Refund;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Services\DeliveryService;
use App\Services\TontineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\BuildsTontineCycles;
use Tests\TestCase;

/**
 * PRIORITÉ 2 — Cycle complet : désignation -> financement -> livraison ->
 * clôture du round -> round suivant -> fin de tontine.
 *
 * Les quatre états (tontine, participation, paiement, livraison) sont vérifiés
 * séparément : "completed" ne signifie jamais "livré".
 */
class TontineCycleTest extends TestCase
{
    use BuildsTontineCycles;
    use RefreshDatabase;

    public function test_designating_a_beneficiary_does_not_make_it_deliverable_yet(): void
    {
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(2);

        $this->assertSame(Tontine::STATUS_ACTIVE, $tontine->status);
        $this->assertSame(1, $tontine->current_round);

        // Participation : bénéficiaire du round 1
        $this->assertSame(TontineMember::STATUS_BENEFICIARY, $beneficiary->status);
        $this->assertSame(1, $beneficiary->beneficiary_round);

        // Livraison : PAS encore livrable, le round n'est pas payé.
        $this->assertSame(TontineMember::DELIVERY_AWAITING_PAYMENT, $beneficiary->delivery_status);
        $this->assertFalse($beneficiary->isAwaitingDelivery());
        $this->assertFalse(app(DeliveryService::class)->isEligible($beneficiary));
        $this->assertContains('round_not_paid', app(DeliveryService::class)->blockers($beneficiary));
    }

    public function test_beneficiary_becomes_deliverable_only_once_the_round_is_funded(): void
    {
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(2);
        $delivery = app(DeliveryService::class);

        $contributions = $this->roundContributions($tontine, 1);
        $this->assertCount(2, $contributions);

        // Un seul des deux membres paie : le round n'est toujours pas financé.
        $this->payContribution($contributions->first());

        $this->assertFalse($tontine->fresh()->roundIsFunded(1));
        $this->assertSame(TontineMember::DELIVERY_AWAITING_PAYMENT, $beneficiary->fresh()->delivery_status);
        $this->assertFalse($delivery->isEligible($beneficiary->fresh()));

        // Le dernier paiement finance le round : le bénéficiaire devient livrable.
        $this->payContribution($contributions->last());

        $this->assertTrue($tontine->fresh()->roundIsFunded(1));
        $this->assertSame(TontineMember::DELIVERY_PENDING, $beneficiary->fresh()->delivery_status);
        $this->assertTrue($delivery->isEligible($beneficiary->fresh()));
        $this->assertSame([], $delivery->blockers($beneficiary->fresh()));
    }

    public function test_completed_participation_does_not_imply_delivered(): void
    {
        [$tontine, $beneficiary, $members] = $this->makeActiveProductTontine(2);

        $this->payRound($tontine, 1);

        $outgoing = $beneficiary->fresh();

        $this->assertSame(TontineMember::STATUS_COMPLETED, $outgoing->status);
        $this->assertFalse($outgoing->isDelivered());
        $this->assertNull($outgoing->delivered_at);
        $this->assertSame(TontineMember::DELIVERY_PENDING, $outgoing->delivery_status);

        // Le round suivant démarre et un nouveau bénéficiaire est désigné.
        $this->assertSame(2, $tontine->fresh()->current_round);
        $incoming = $tontine->fresh()->members()->where('status', TontineMember::STATUS_BENEFICIARY)->first();
        $this->assertNotNull($incoming);
        $this->assertSame(2, $incoming->beneficiary_round);
        $this->assertSame(TontineMember::DELIVERY_AWAITING_PAYMENT, $incoming->delivery_status);

        // L'ancien bénéficiaire reste livrable APRÈS le changement de round.
        $this->assertTrue(app(DeliveryService::class)->isEligible($outgoing));

        // Le nouveau ne l'est pas encore.
        $this->assertFalse(app(DeliveryService::class)->isEligible($incoming));
    }

    public function test_delivery_after_round_switch_keeps_the_original_beneficiary_round(): void
    {
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(2);

        $this->payRound($tontine, 1);

        $outgoing = $beneficiary->fresh();

        $this->assertSame(1, $outgoing->beneficiary_round);
        $this->assertSame(2, $outgoing->tontine->fresh()->current_round);

        $confirmed = app(DeliveryService::class)->confirm($outgoing);

        $this->assertSame(TontineMember::DELIVERY_DELIVERED, $confirmed->delivery_status);
        $this->assertNotNull($confirmed->delivered_at);
        $this->assertSame(TontineMember::STATUS_COMPLETED, $confirmed->status);
    }

    public function test_full_cycle_ends_when_every_member_has_received(): void
    {
        [$tontine, $beneficiary, $members] = $this->makeActiveProductTontine(2);

        $service = app(TontineService::class);
        $rounds = 0;

        while ($tontine->fresh()->status === Tontine::STATUS_ACTIVE && $rounds < 10) {
            $this->payRound($tontine->fresh(), $tontine->fresh()->current_round);
            $rounds++;
        }

        $tontine->refresh();

        $this->assertSame(Tontine::STATUS_COMPLETED, $tontine->status);
        $this->assertSame(2, $rounds);
        $this->assertSame(
            [TontineMember::STATUS_COMPLETED, TontineMember::STATUS_COMPLETED],
            $tontine->members()->orderBy('position')->pluck('status')->all()
        );

        // Les deux membres ont été bénéficiaires exactement une fois.
        $this->assertSame([1, 2], $tontine->members()->orderBy('position')->pluck('beneficiary_round')->all());
        $this->assertSame($service::class, TontineService::class);
    }

    public function test_payment_after_tontine_completion_does_not_restart_a_round(): void
    {
        [$tontine] = $this->makeActiveProductTontine(1);

        $this->payRound($tontine, 1);

        $tontine->refresh();
        $this->assertSame(Tontine::STATUS_COMPLETED, $tontine->status);
        $this->assertSame(1, $tontine->current_round);

        // Une seconde passe de contrôle ne doit rien changer.
        app(TontineService::class)->checkRoundCompletionAndAdvance($tontine->fresh());

        $this->assertSame(Tontine::STATUS_COMPLETED, $tontine->fresh()->status);
        $this->assertSame(1, $tontine->fresh()->current_round);
        $this->assertSame(1, Contribution::whereHas(
            'tontineMember',
            fn ($query) => $query->where('tontine_id', $tontine->id)
        )->count());
    }

    public function test_round_summary_reports_expected_and_paid_members(): void
    {
        [$tontine] = $this->makeActiveProductTontine(3);

        $contributions = $this->roundContributions($tontine, 1);
        $this->payContribution($contributions->first());

        $summary = $tontine->fresh()->roundSummary(1);
        $this->assertSame(1, $summary['round']);
        $this->assertSame(3, $summary['expected_members']);
        $this->assertSame(1, $summary['paid_members']);
        $this->assertFalse($summary['is_funded']);

        $this->payContribution($contributions[1]);
        $this->payContribution($contributions[2]);

        $summary = $tontine->fresh()->roundSummary(1);
        $this->assertSame(3, $summary['expected_members']);
        $this->assertSame(3, $summary['paid_members']);
        $this->assertTrue($summary['is_funded']);
    }

    public function test_cash_tontine_beneficiary_is_never_deliverable(): void
    {
        [$tontine, $beneficiary] = $this->makeActiveCashTontine(2);

        $this->assertTrue($tontine->isCashTontine());
        $this->assertSame(TontineMember::STATUS_BENEFICIARY, $beneficiary->status);
        $this->assertSame(TontineMember::DELIVERY_NOT_APPLICABLE, $beneficiary->delivery_status);

        $delivery = app(DeliveryService::class);
        $blockers = $delivery->blockers($beneficiary);
        $this->assertContains('cash_tontine', $blockers);
        $this->assertFalse($delivery->isEligible($beneficiary));

        // Même après financement complet du round, toujours pas de livraison.
        $this->payRound($tontine, 1);
        $tontine->refresh();

        $this->assertFalse($delivery->isEligible($beneficiary->fresh()));
        $this->assertSame(
            TontineMember::DELIVERY_NOT_APPLICABLE,
            $beneficiary->fresh()->delivery_status
        );
    }

    public function test_cash_tontine_exposes_explicit_unsupported_payout_status(): void
    {
        [$tontine] = $this->makeActiveCashTontine(2);

        $this->assertSame(Tontine::PAYOUT_NOT_IMPLEMENTED, $tontine->payoutStatus());
        $this->assertFalse($tontine->isPayoutSupported());

        // Aucun versement n'est enregistré : le beneficiary_round reste le seul
        // marqueur du tour, aucun montant n'est crédité.
        $this->assertSame(
            0,
            Refund::where('tontine_id', $tontine->id)->count()
        );
    }
}
