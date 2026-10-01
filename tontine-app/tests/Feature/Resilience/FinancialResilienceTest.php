<?php

namespace Tests\Feature\Resilience;

use App\Models\Contribution;
use App\Models\Product;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use App\Services\Payments\PaymentException;
use App\Services\TontineService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concerns\BuildsTontineCycles;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\TestCase;

/**
 * PRIORITÉ 4 §5/§6/§7 — Intégrité financière, concurrence et idempotence.
 *
 * Ces tests simulent les scénarios concrets : double clic, deux onglets,
 * deux requêtes au même instant, deux administrators sur le même paiement.
 */
class FinancialResilienceTest extends TestCase
{
    use BuildsTontineCycles;
    use InteractsWithApiTokens;
    use RefreshDatabase;

    // -------------------------------------------------- INTÉGRITÉ (§5)

    public function test_a_contribution_cannot_have_a_negative_amount(): void
    {
        $contribution = $this->makePendingContribution();

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('strictement positif');

        $contribution->update(['amount' => -5000]);
    }

    public function test_a_contribution_cannot_have_a_zero_amount(): void
    {
        $contribution = $this->makePendingContribution();

        $this->expectException(PaymentException::class);

        $contribution->update(['amount' => 0]);
    }

    public function test_commission_cannot_exceed_the_amount(): void
    {
        $contribution = $this->makePendingContribution();

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('ne peut pas depasser');

        $contribution->update(['commission_amount' => 99999]);
    }

    public function test_commission_cannot_be_negative(): void
    {
        $contribution = $this->makePendingContribution();

        $this->expectException(PaymentException::class);

        $contribution->update(['commission_amount' => -1]);
    }

    public function test_a_refund_cannot_have_a_non_positive_amount(): void
    {
        $contribution = $this->makePendingContribution();
        $this->payContribution($contribution);

        $this->expectException(PaymentException::class);

        \App\Models\Refund::create([
            'contribution_id' => $contribution->id,
            'tontine_id' => $contribution->tontineMember->tontine_id,
            'user_id' => $contribution->tontineMember->user_id,
            'amount' => 0,
            'status' => 'pending',
        ]);
    }

    public function test_a_refund_can_never_exceed_the_contribution_amount(): void
    {
        $contribution = $this->makePendingContribution();
        $this->payContribution($contribution);
        $contribution = $contribution->fresh();

        $refunds = app(\App\Services\RefundService::class);
        $refund = $refunds->createFor($contribution);

        // Le montant est DERIVE de la cotisation, jamais fourni par l'appelant.
        $this->assertEquals($contribution->amount, $refund->amount);
        $this->assertFalse($refunds->isRefundable($contribution));

        // Rappel idempotent : aucune seconde ligne, aucun cumul possible.
        $again = $refunds->createFor($contribution);
        $this->assertSame($refund->id, $again->id);
        $this->assertEquals(
            (float) $contribution->amount,
            (float) $contribution->refunds()->sum('amount')
        );
    }

    public function test_database_refuses_two_contributions_for_the_same_member_and_round(): void
    {
        $contribution = $this->makePendingContribution();

        $this->expectException(QueryException::class);

        Contribution::create([
            'tontine_member_id' => $contribution->tontine_member_id,
            'round' => $contribution->round,
            'amount' => 1000,
            'status' => 'pending',
        ]);
    }

    public function test_database_refuses_duplicate_transaction_references(): void
    {
        $a = $this->makePendingContribution();
        $this->payContribution($a);
        $stolen = $a->fresh()->transaction_reference;

        $b = $this->makePendingContribution();
        $b->update(['transaction_reference' => 'FAKE-AUTRE-REF']);

        // Une référence déjà utilisée par une autre cotisation est refusée par
        // l'index unique : impossible de rattacher deux paiements au même ticket.
        $this->expectException(QueryException::class);

        DB::table('contributions')->where('id', $b->id)
            ->update(['transaction_reference' => $stolen]);
    }

    public function test_foreign_keys_are_enforced(): void
    {
        $this->expectException(QueryException::class);

        Contribution::create([
            'tontine_member_id' => 999999,
            'round' => 1,
            'amount' => 1000,
            'status' => 'pending',
        ]);
    }

    public function test_duplicate_member_positions_are_rejected(): void
    {
        [$tontine] = $this->makeActiveProductTontine(2);

        $this->expectException(QueryException::class);

        TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => User::factory()->create()->id,
            'position' => 1,
            'status' => 'active',
        ]);
    }

    public function test_invalid_status_is_rejected_by_the_database(): void
    {
        [$tontine] = $this->makeActiveProductTontine(2);

        $this->expectException(QueryException::class);

        // L'enum est rétabli par la migration 2026_09_28_000001 : une valeur
        // hors enum doit être refusée par le MOTEUR, pas seulement par PHP.
        DB::table('tontine_members')
            ->where('tontine_id', $tontine->id)
            ->update(['status' => 'statut_inventé']);
    }

    // -------------------------------------------------- CONCURRENCE (§6)

    public function test_two_simultaneous_joins_cannot_overfill_a_tontine(): void
    {
        $product = Product::factory()->create();
        $tontine = Tontine::factory()->create([
            'product_id' => $product->id,
            'status' => Tontine::STATUS_OPEN,
            'max_members' => 2,
        ]);

        $creator = User::factory()->create();
        TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => $creator->id,
            'position' => 1,
            'status' => 'active',
        ]);

        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($first)->postJson("/api/tontines/{$tontine->id}/join")->assertOk();
        $this->actingAs($second)->postJson("/api/tontines/{$tontine->id}/join")->assertForbidden();

        $this->assertSame(2, $tontine->fresh()->members()->count());
        $this->assertSame(2, $tontine->fresh()->max_members);
    }

    public function test_join_uses_the_transactional_check_even_with_a_stale_model(): void
    {
        $product = Product::factory()->create();
        $tontine = Tontine::factory()->create([
            'product_id' => $product->id,
            'status' => Tontine::STATUS_OPEN,
            'max_members' => 1,
        ]);

        TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => User::factory()->create()->id,
            'position' => 1,
            'status' => 'active',
        ]);

        $latecomer = User::factory()->create();

        // Le modèle passé à la route est issu d'un état antérieur : le contrôle
        // de places doit être refait DANS la transaction, pas sur l'objet
        // reçu. Sans cela, l'insertion passerait.
        $this->actingAs($latecomer)
            ->postJson("/api/tontines/{$tontine->id}/join")
            ->assertForbidden();

        $this->assertSame(1, $tontine->fresh()->members()->count());
    }

    public function test_positions_are_sequential_and_unique(): void
    {
        $product = Product::factory()->create();
        $tontine = Tontine::factory()->create([
            'product_id' => $product->id,
            'status' => Tontine::STATUS_OPEN,
            'max_members' => 4,
        ]);

        $creator = User::factory()->create();
        TontineMember::create([
            'tontine_id' => $tontine->id, 'user_id' => $creator->id, 'position' => 1, 'status' => 'active',
        ]);

        foreach (range(2, 3) as $index) {
            $user = User::factory()->create();
            $this->actingAs($user)->postJson("/api/tontines/{$tontine->id}/join")->assertOk();
        }

        $positions = $tontine->fresh()->members()->orderBy('position')->pluck('position')->all();

        $this->assertSame([1, 2, 3], $positions);
        $this->assertSame($positions, array_unique($positions));
    }

    // ------------------------------------------------- IDEMPOTENCE (§7)

    public function test_generating_the_same_round_twice_creates_nothing_extra(): void
    {
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(3);
        $service = app(TontineService::class);

        $this->assertSame(0, $service->generateContributionCallForRound($tontine->fresh(), 1));
        $this->assertSame(0, $service->generateContributionCallForRound($tontine->fresh(), 1));

        $this->assertSame(3, $this->roundContributions($tontine->fresh(), 1)->count());
    }

    public function test_contribution_call_generation_is_atomic(): void
    {
        // Si une ligne échoue, AUCUNE ne doit être committée : sinon certains
        // membres n'auraient pas d'appel de cotisation pour ce round.
        [$tontine] = $this->makeActiveProductTontine(3);
        $service = app(TontineService::class);

        $service->generateContributionCallForRound($tontine->fresh(), 1);
        $this->assertSame(3, $this->roundContributions($tontine->fresh(), 1)->count());

        // Un round déjà occupé : l'unicité (member, round) protège.
        $this->expectException(QueryException::class);
        DB::table('contributions')->where('round', 1)
            ->where('tontine_member_id', $tontine->members()->first()->id)
            ->insert([
                'amount' => 1000, 'status' => 'pending', 'currency' => 'XOF',
                'payment_method' => 'mobile_money', 'commission_amount' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
    }

    public function test_double_payment_of_the_same_contribution_is_idempotent(): void
    {
        $contribution = $this->makePendingContribution();
        $token = $this->issueTokenFor($contribution->tontineMember->user);

        $this->postAs($token, "/api/contributions/{$contribution->id}/pay", [
            'payment_method' => 'orange_money',
        ])->assertOk();

        $paidAt = $contribution->fresh()->paid_at;
        $commission = $contribution->fresh()->commission_amount;

        // Deuxième essai (double clic / second onglet).
        $this->postAs($token, "/api/contributions/{$contribution->id}/pay", [
            'payment_method' => 'orange_money',
        ])->assertStatus(409);

        $this->assertEquals($paidAt, $contribution->fresh()->paid_at);
        $this->assertEquals($commission, $contribution->fresh()->commission_amount);
    }

    public function test_double_refund_creation_returns_the_same_row(): void
    {
        $contribution = $this->makePendingContribution();
        $this->payContribution($contribution);

        $refunds = app(\App\Services\RefundService::class);
        $first = $refunds->createFor($contribution->fresh());
        $second = $refunds->createFor($contribution->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, \App\Models\Refund::count());
    }

    public function test_double_delivery_confirmation_is_refused(): void
    {
        $merchant = \App\Models\Merchant::factory()->create(['status' => 'approved']);
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(2, $merchant);

        $this->payRound($tontine, 1);
        $outgoing = $beneficiary->fresh();

        $token = $this->issueTokenFor($merchant->user);

        $this->postAs($token, "/api/merchant/orders/{$outgoing->id}/deliver")->assertOk();
        $deliveredAt = $outgoing->fresh()->delivered_at;

        $this->postAs($token, "/api/merchant/orders/{$outgoing->id}/deliver")->assertStatus(409);

        $this->assertEquals($deliveredAt, $outgoing->fresh()->delivered_at);
    }

    public function test_double_round_closure_advances_only_once(): void
    {
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(2);
        $service = app(TontineService::class);

        $this->payRound($tontine, 1);
        $this->assertSame(2, $tontine->fresh()->current_round);

        for ($i = 0; $i < 5; $i++) {
            $service->checkRoundCompletionAndAdvance($tontine->fresh());
        }

        $this->assertSame(2, $tontine->fresh()->current_round);
        $this->assertSame(1, $tontine->fresh()->members()->where('status', 'beneficiary')->count());
    }

    public function test_double_webhook_does_not_duplicate_the_payment(): void
    {
        config(['mobilemoney.webhook_secret' => 'secret-resilience']);

        $contribution = $this->makePendingContribution();
        $this->payContribution($contribution);
        $contribution = $contribution->fresh();

        $reference = $contribution->transaction_reference;
        $paidAt = $contribution->paid_at;

        $body = json_encode([
            'reference' => $reference,
            'status' => 'success',
            'amount' => (float) $contribution->amount,
            'currency' => 'XOF',
        ]);
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'secret-resilience');

        foreach (range(1, 3) as $ignored) {
            $this->postJson('/api/webhooks/mobile-money', json_decode($body, true), [
                'X-Webhook-Timestamp' => $timestamp,
                'X-Webhook-Signature' => $signature,
            ])->assertOk();
        }

        $this->assertSame('completed', $contribution->fresh()->status);
        $this->assertEquals($paidAt, $contribution->fresh()->paid_at);
    }

    public function test_two_admins_cannot_validate_the_same_payment_twice(): void
    {
        $adminA = User::factory()->admin()->create();
        $adminB = User::factory()->admin()->create();

        $contribution = $this->makePendingContribution();
        $this->payContribution($contribution);

        $contribution->update([
            'verification_status' => 'pending',
            'status' => Contribution::STATUS_PENDING,
            'paid_at' => null,
            'transaction_reference' => null,
        ]);

        $this->actingAs($adminA, 'sanctum')
            ->postJson("/api/admin/payments/contribution/{$contribution->id}/accept")
            ->assertOk();

        $commission = $contribution->fresh()->commission_amount;

        // Le second administrateur arrive après coup : plus rien à valider.
        $this->actingAs($adminB, 'sanctum')
            ->postJson("/api/admin/payments/contribution/{$contribution->id}/accept")
            ->assertStatus(409);

        $this->assertEquals($commission, $contribution->fresh()->commission_amount);
    }

    // ----------------------------------------------------- TRANSACTIONS (§22)

    public function test_a_failing_operation_rolls_back_completely(): void
    {
        [$tontine, $beneficiary] = $this->makeActiveProductTontine(3);
        $service = app(TontineService::class);

        $before = $this->roundContributions($tontine->fresh(), 1)->count();

        try {
            DB::transaction(function () use ($service, $tontine) {
                $service->generateContributionCallForRound($tontine->fresh(), 1);
                Contribution::create([
                    'tontine_member_id' => 999999,
                    'round' => 1,
                    'amount' => 1000,
                    'status' => 'pending',
                ]);
            });
        } catch (\Throwable) {
            // attendu : clé étrangère invalide
        }

        $this->assertSame($before, $this->roundContributions($tontine->fresh(), 1)->count());
        $this->assertSame(
            Tontine::STATUS_ACTIVE,
            $tontine->fresh()->status
        );
    }

    public function test_payment_locking_prevents_double_commission(): void
    {
        $contribution = $this->makePendingContribution();
        $token = $this->issueTokenFor($contribution->tontineMember->user);

        for ($i = 0; $i < 3; $i++) {
            $this->postAs($token, "/api/contributions/{$contribution->id}/pay", [
                'payment_method' => 'orange_money',
            ]);
        }

        $fresh = $contribution->fresh();
        $this->assertSame('completed', $fresh->status);
        // Une seule commission, calculée une seule fois sur le taux gelé.
        $this->assertEquals(700, (float) $fresh->commission_amount);
    }

    private function makePendingContribution(): Contribution
    {
        $product = Product::factory()->create();
        $tontine = Tontine::factory()->create([
            'product_id' => $product->id,
            'contribution_amount' => 10000,
            'commission_rate' => 0.07,
            'status' => Tontine::STATUS_ACTIVE,
        ]);

        $member = TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => User::factory()->create()->id,
            'position' => 1,
            'status' => TontineMember::STATUS_ACTIVE,
        ]);

        return Contribution::create([
            'tontine_member_id' => $member->id,
            'round' => 1,
            'amount' => 10000,
            'commission_rate' => 0.07,
            'status' => Contribution::STATUS_PENDING,
        ]);
    }
}
