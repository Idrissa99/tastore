<?php

namespace Tests\Feature\Security;

use App\Models\Contribution;
use App\Models\Dispute;
use App\Models\InstallmentPurchase;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\TestCase;

/**
 * PRIORITÉ 3 §10 & §11 — Mass assignment et vie privée des réponses API.
 *
 * Objectif : prouver qu'aucun champ sensible (role, is_blocked, is_verified,
 * commission_*, status de paiement/livraison, user_id, merchant_id...) ne peut
 * être positionné depuis une requête HTTP.
 */
class MassAssignmentAndPrivacyTest extends TestCase
{
    use InteractsWithApiTokens;
    use RefreshDatabase;

    public function test_user_model_does_not_mass_assign_sensitive_fields(): void
    {
        $user = new User;

        foreach (['is_verified', 'is_blocked', 'avatar_path', 'email_verified_at'] as $field) {
            $this->assertNotContains($field, $user->getFillable(), "$field ne doit pas être fillable.");
        }

        // `role` reste fillable mais RegisterRequest le borne à client|merchant.
        $this->assertContains('role', $user->getFillable());
    }

    public function test_profile_update_ignores_every_sensitive_field(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $token = $this->issueTokenFor($user);

        $this->putAs($token, '/api/profil', [
            'name' => 'Nom Autorisé',
            'phone' => '91112222',
            'role' => 'admin',
            'is_blocked' => true,
            'is_verified' => true,
            'avatar_path' => '../../etc/passwd',
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('Nom Autorisé', $fresh->name);
        $this->assertSame('client', $fresh->role);
        $this->assertFalse($fresh->is_blocked);
        $this->assertNull($fresh->avatar_path);
    }

    public function test_contribution_cannot_have_its_financial_fields_forced_via_http(): void
    {
        $owner = User::factory()->create();
        $contribution = $this->makeContribution($owner);
        $token = $this->issueTokenFor($owner);

        $this->postAs($token, "/api/contributions/{$contribution->id}/pay", [
            'payment_method' => 'orange_money',
            'amount' => 1,
            'commission_amount' => 0,
            'commission_rate' => 0.99,
            'status' => 'completed',
            'paid_at' => now()->toDateTimeString(),
            'tontine_member_id' => 9999,
        ])->assertOk();

        $fresh = $contribution->fresh();
        $this->assertEquals(5000, $fresh->amount);
        $this->assertEquals(350, $fresh->commission_amount);
        $this->assertEquals(0.07, (float) $fresh->commission_rate);
    }

    public function test_transfer_code_endpoint_cannot_force_payment_fields(): void
    {
        $owner = User::factory()->create();
        $contribution = $this->makeContribution($owner);
        $token = $this->issueTokenFor($owner);

        $this->postAs($token, "/api/contributions/{$contribution->id}/soumettre-code", [
            'payment_method' => 'amana',
            'transfer_code' => 'CODE-1',
            'status' => 'completed',
            'commission_amount' => 0,
        ])->assertOk();

        $fresh = $contribution->fresh();
        $this->assertSame('pending', $fresh->status);
        // La commission n'est calculée qu'au paiement : elle reste à 0, et le
        // taux gelé n'a pas bougé malgré les champs envoyés.
        $this->assertEquals(0, $fresh->commission_amount);
        $this->assertEquals(0.07, (float) $fresh->commission_rate);
        $this->assertSame('pending', $fresh->verification_status);
        $this->assertSame('CODE-1', $fresh->transfer_code);
    }

    public function test_tontine_creation_ignores_client_supplied_financial_fields(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create(['merchant_id' => $merchant->id]);
        $token = $this->issueTokenFor($merchant->user);

        $this->postAs($token, '/api/tontines', [
            'type' => 'product',
            'product_id' => $product->id,
            'name' => 'Tontine test',
            'frequency' => 'monthly',
            'max_members' => 4,
            'status' => 'active',
            'current_round' => 99,
            'commission_rate' => 0.5,
            'contribution_amount' => 1,
            'created_by' => 9999,
        ])->assertCreated();

        $tontine = Tontine::latest('id')->first();

        $this->assertSame('open', $tontine->status);
        $this->assertSame(1, $tontine->current_round);
        $this->assertSame($merchant->user->id, $tontine->created_by);
        // Le montant de cotisation reste calculé par le serveur, jamais fourni.
        $this->assertGreaterThan(0, (float) $tontine->contribution_amount);
        $this->assertNotEquals(1, (float) $tontine->contribution_amount);
    }

    public function test_installment_purchase_owner_and_amount_cannot_be_forced(): void
    {
        $buyer = User::factory()->create();
        $other = User::factory()->create();
        $product = Product::factory()->create();
        $token = $this->issueTokenFor($buyer);

        $this->postAs($token, "/api/produits/{$product->id}/tranches", [
            'installments_count' => 3,
            'user_id' => $other->id,
            'installment_amount' => 1,
            'product_price' => 1,
            'status' => 'completed',
        ])->assertCreated();

        $purchase = InstallmentPurchase::latest('id')->first();

        $this->assertSame($buyer->id, $purchase->user_id);
        $this->assertSame('active', $purchase->status);
        $this->assertNotEquals(1, (float) $purchase->installment_amount);
    }

    public function test_merchant_cannot_self_approve_or_attach_a_product_to_another_merchant(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'pending']);
        $other = Merchant::factory()->create(['status' => 'approved']);
        $token = $this->issueTokenFor($merchant->user);

        $this->postAs($token, '/api/merchant/products', [
            'name' => 'Produit',
            'price' => 1000,
            'stock' => 1,
            'status' => 'published',
            'merchant_id' => $other->id,
        ])->assertForbidden();

        $this->assertSame('pending', $merchant->fresh()->status);
        $this->assertSame(0, Product::count());
    }

    // ------------------------------------------------------------------ PRIVACY

    public function test_no_api_response_ever_contains_password_or_token_material(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create(['merchant_id' => $merchant->id]);
        $user = User::factory()->create();
        $tontine = Tontine::factory()->create(['product_id' => $product->id, 'created_by' => $user->id]);
        $member = TontineMember::create([
            'tontine_id' => $tontine->id, 'user_id' => $user->id, 'position' => 1, 'status' => 'beneficiary',
        ]);
        Contribution::create([
            'tontine_member_id' => $member->id, 'round' => 1, 'amount' => 5000,
            'commission_rate' => 0.07, 'status' => 'pending',
        ]);
        $token = $this->issueTokenFor($user);
        $admin = User::factory()->admin()->create();
        $this->issueTokenFor($admin);

        $payloads = [
            $this->getAs($token, '/api/me')->json(),
            $this->getAs($token, '/api/profil')->json(),
            $this->getAs($token, '/api/contributions')->json(),
            $this->getJson('/api/produits')->json(),
            $this->getJson('/api/commercants/'.$merchant->id)->json(),
            $this->getJson('/api/tontines/'.$tontine->id)->json(),
            $this->actingAs($admin, 'sanctum')->getJson('/api/admin/users')->json(),
            $this->actingAs($admin, 'sanctum')->getJson('/api/admin/merchants')->json(),
            $this->actingAs($admin, 'sanctum')->getJson('/api/admin/dashboard')->json(),
        ];

        foreach ($payloads as $index => $payload) {
            $json = json_encode($payload);

            $this->assertStringNotContainsString('password', $json, "payload #$index fuite 'password'");
            $this->assertStringNotContainsString('$2y$', $json, "payload #$index fuite un hash bcrypt");
            $this->assertStringNotContainsString('remember_token', $json, "payload #$index fuite remember_token");
            $this->assertStringNotContainsString($token, $json, "payload #$index fuite le token");
            $this->assertStringNotContainsString('personal_access_token', $json);
            $this->assertStringNotContainsString('webhook_secret', $json);
        }
    }

    public function test_admin_user_list_exposes_no_secret_but_does_expose_block_state(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();
        $this->issueTokenFor($target);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/users')->assertOk()->json();
        $row = collect($response['data'])->firstWhere('id', $target->id);

        $this->assertNotNull($row);
        $this->assertArrayHasKey('is_blocked', $row);
        $this->assertArrayNotHasKey('password', $row);
        $this->assertArrayNotHasKey('remember_token', $row);
    }

    public function test_public_merchant_profile_does_not_leak_owner_email(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        Merchant::factory()->create(['status' => 'pending']);

        $json = json_encode($this->getJson('/api/commercants/'.$merchant->id)->assertOk()->json());

        $this->assertStringNotContainsString($merchant->user->email, $json);
        $this->assertStringNotContainsString('status', $json);
    }

    public function test_admin_dispute_detail_does_not_dump_member_user_records(): void
    {
        $admin = User::factory()->admin()->create();
        $memberUser = User::factory()->create();
        $product = Product::factory()->create();
        $tontine = Tontine::factory()->create(['product_id' => $product->id, 'created_by' => $memberUser->id]);
        TontineMember::create([
            'tontine_id' => $tontine->id, 'user_id' => $memberUser->id, 'position' => 1, 'status' => 'active',
        ]);

        $this->actingAs($memberUser, 'sanctum')
            ->postJson("/api/tontines/{$tontine->id}/disputes", [
                'subject' => 'Sujet', 'description' => 'Description',
            ])->assertCreated();

        $dispute = Dispute::latest('id')->first();

        $json = json_encode(
            $this->actingAs($admin, 'sanctum')->getJson("/api/admin/disputes/{$dispute->id}")->assertOk()->json()
        );

        // L'admin voit un résumé, pas les comptes des membres.
        $this->assertStringNotContainsString($memberUser->email, $json);
        $this->assertStringContainsString('members_count', $json);
    }

    public function test_sensitive_log_context_is_redacted(): void
    {
        // On écrit volontairement dans le fichier du canal par défaut (celui
        // configuré par LOG_CHANNEL) : c'est là que le processor est installé.
        $path = (string) config('logging.channels.single.path');
        $before = is_file($path) ? (int) filesize($path) : 0;

        Log::info('test de redactation', [
            'password' => 'super-secret',
            'token' => 'plain-text-token',
            'transfer_code' => 'MYNI-998877',
            'authorization' => 'Bearer abcdef',
            'safe_field' => 'visible',
        ]);

        $contents = (string) file_get_contents($path);
        $written = substr($contents, $before);

        $this->assertStringNotContainsString('super-secret', $written);
        $this->assertStringNotContainsString('plain-text-token', $written);
        $this->assertStringNotContainsString('MYNI-998877', $written);
        $this->assertStringNotContainsString('Bearer abcdef', $written);
        $this->assertStringContainsString('visible', $written);
        $this->assertStringContainsString('redacted', $written);
    }

    private function makeContribution(User $owner): Contribution
    {
        $product = Product::factory()->create();
        $tontine = Tontine::factory()->create(['product_id' => $product->id, 'created_by' => $owner->id]);
        $member = TontineMember::create([
            'tontine_id' => $tontine->id, 'user_id' => $owner->id, 'position' => 1, 'status' => 'active',
        ]);

        return Contribution::create([
            'tontine_member_id' => $member->id,
            'round' => 1,
            'amount' => 5000,
            'commission_rate' => 0.07,
            'status' => 'pending',
        ]);
    }
}
