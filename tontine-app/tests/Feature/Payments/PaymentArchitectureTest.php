<?php

namespace Tests\Feature\Payments;

use App\Models\Contribution;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use App\Services\InstallmentPurchaseService;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentGatewayInterface;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\TontineService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_gateway_manager_resolves_the_configured_provider(): void
    {
        $gateway = app(PaymentGatewayManager::class)->forChannel('orange_money');

        $this->assertInstanceOf(PaymentGatewayInterface::class, $gateway);
        $this->assertSame('fake', $gateway->provider());
        $this->assertTrue($gateway->isTestGateway());
    }

    public function test_payment_confirmation_is_idempotent_and_does_not_recreate_commission(): void
    {
        $contribution = $this->createContribution();
        $service = app(TontineService::class);

        $first = $service->recordPayment($contribution, 'IDEMP-1', 1000, 'XOF');
        $paidAt = $first->paid_at;
        $second = $service->recordPayment($contribution, 'IDEMP-1', 1000, 'XOF');

        $this->assertSame('completed', $second->status);
        $this->assertEquals(70, $second->commission_amount);
        $this->assertEquals($paidAt, $second->paid_at);
        $this->assertSame(1, Contribution::where('transaction_reference', 'IDEMP-1')->count());
    }

    public function test_completed_contribution_cannot_be_paid_again(): void
    {
        $contribution = $this->createContribution();
        Sanctum::actingAs($contribution->tontineMember->user);

        $this->postJson("/api/contributions/{$contribution->id}/pay", [
            'payment_method' => 'orange_money',
        ])->assertOk();

        $this->postJson("/api/contributions/{$contribution->id}/pay", [
            'payment_method' => 'orange_money',
        ])->assertStatus(409);

        $this->assertSame('completed', $contribution->fresh()->status);
        $this->assertEquals(70, $contribution->fresh()->commission_amount);
    }

    public function test_a_reference_cannot_be_reused_for_another_contribution(): void
    {
        $first = $this->createContribution();
        $second = $this->createContribution();
        $first->update(['transaction_reference' => 'DUP-APP']);

        $this->expectException(PaymentException::class);

        app(TontineService::class)->recordPayment($second, 'DUP-APP', 1000, 'XOF');
    }

    public function test_transaction_reference_has_a_database_unique_constraint(): void
    {
        $first = $this->createContribution();
        $second = $this->createContribution();
        $first->update(['transaction_reference' => 'DUP-DB']);

        $this->expectException(QueryException::class);

        DB::table('contributions')->where('id', $second->id)->update([
            'transaction_reference' => 'DUP-DB',
        ]);
    }

    public function test_commission_rate_is_frozen_on_the_payment_record(): void
    {
        Setting::set('commission_rate', '0.10');

        $contribution = $this->createContribution(['commission_rate' => 0.07]);
        app(TontineService::class)->recordPayment($contribution, 'RATE-1', 1000, 'XOF');

        $this->assertEquals(70, $contribution->fresh()->commission_amount);
    }

    public function test_installment_payment_is_idempotent_and_uses_its_frozen_rate(): void
    {
        Setting::set('commission_rate', '0.07');

        $product = Product::factory()->create(['price' => 10000, 'status' => 'published']);
        $user = User::factory()->create();
        $purchase = app(InstallmentPurchaseService::class)->createPurchase($product, $user->id, 2);
        $installment = $purchase->installments()->first();

        Setting::set('commission_rate', '0.10');

        $service = app(InstallmentPurchaseService::class);
        $first = $service->recordPayment($installment, 'TRANCHE-RATE-1', (float) $installment->amount, 'XOF');
        $second = $service->recordPayment($installment, 'TRANCHE-RATE-1', (float) $installment->amount, 'XOF');

        $expected = round((float) $installment->amount * 0.07, 2);

        $this->assertEquals($expected, $first->commission_amount);
        $this->assertEquals($expected, $second->commission_amount);
        $this->assertSame('completed', $second->status);
    }

    public function test_payment_initiation_is_idempotent(): void
    {
        $contribution = $this->createContribution();
        Sanctum::actingAs($contribution->tontineMember->user);

        $first = $this->postJson("/api/contributions/{$contribution->id}/initiate-payment")
            ->assertOk()
            ->json('reference');
        $second = $this->postJson("/api/contributions/{$contribution->id}/initiate-payment")
            ->assertOk()
            ->json('reference');

        $this->assertSame($first, $second);
        $this->assertSame('pending', $contribution->fresh()->status);
    }

    public function test_manual_payment_requires_admin_acceptance_and_keeps_transfer_code_separate(): void
    {
        $contribution = $this->createContribution([
            'payment_method' => 'mynita',
            'verification_status' => 'pending',
            'transfer_code' => 'MANUAL-TRANSFER-1',
            'submitted_at' => now(),
        ]);
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/payments/contribution/{$contribution->id}/accept")
            ->assertOk();

        $contribution->refresh();

        $this->assertSame('completed', $contribution->status);
        $this->assertSame('accepted', $contribution->verification_status);
        $this->assertNull($contribution->transaction_reference);
        $this->assertSame('MANUAL-TRANSFER-1', $contribution->transfer_code);
        $this->assertEquals(70, $contribution->commission_amount);
    }

    public function test_refund_cannot_be_created_or_processed_twice(): void
    {
        $contribution = $this->createContribution();
        $contribution->update([
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        $tontineService = app(TontineService::class);
        $tontineService->cancelTontine($contribution->tontineMember->tontine);
        $tontineService->cancelTontine($contribution->tontineMember->tontine);

        $refund = Refund::where('tontine_id', $contribution->tontineMember->tontine_id)->firstOrFail();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->assertSame(1, Refund::where('tontine_id', $contribution->tontineMember->tontine_id)->count());
        $this->postJson("/api/admin/refunds/{$refund->id}/process")->assertOk();
        $this->postJson("/api/admin/refunds/{$refund->id}/process")->assertStatus(409);
    }

    private function createContribution(array $attributes = []): Contribution
    {
        $product = Product::factory()->create();
        $user = User::factory()->create();
        $tontine = Tontine::factory()->create([
            'product_id' => $product->id,
            'created_by' => $user->id,
            'status' => 'active',
        ]);
        $member = TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => $user->id,
            'position' => 1,
            'status' => 'beneficiary',
        ]);

        return Contribution::create(array_merge([
            'tontine_member_id' => $member->id,
            'round' => 1,
            'amount' => 1000,
            'commission_rate' => 0.07,
            'currency' => 'XOF',
            'payment_method' => 'orange_money',
            'status' => 'pending',
        ], $attributes));
    }
}
