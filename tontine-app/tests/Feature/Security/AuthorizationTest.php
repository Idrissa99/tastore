<?php

namespace Tests\Feature\Security;

use App\Models\Contribution;
use App\Models\Dispute;
use App\Models\Installment;
use App\Models\InstallmentPurchase;
use App\Models\Merchant;
use App\Models\MerchantReview;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use App\Notifications\BecameBeneficiaryNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\InteractsWithApiTokens;
use Tests\TestCase;

/**
 * PRIORITÉ 3 §7, §8 & §9 — Autorisation, IDOR et élévation de privilèges.
 *
 * Chaque test démontre le couple :
 *   utilisateur A -> SA ressource        = autorisé
 *   utilisateur A -> ressource de B      = refusé (403/404)
 */
class AuthorizationTest extends TestCase
{
    use InteractsWithApiTokens;
    use RefreshDatabase;

    // ---------------------------------------------------------------- RÔLES

    public function test_client_cannot_reach_admin_routes(): void
    {
        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($client, 'sanctum')->getJson('/api/admin/dashboard')->assertForbidden();
        $this->actingAs($client, 'sanctum')->getJson('/api/admin/users')->assertForbidden();
        $this->actingAs($client, 'sanctum')->getJson('/api/admin/reports')->assertForbidden();
        $this->actingAs($client, 'sanctum')->getJson('/api/admin/commissions')->assertForbidden();
        $this->actingAs($client, 'sanctum')->getJson('/api/admin/payments')->assertForbidden();
    }

    public function test_client_cannot_reach_merchant_routes(): void
    {
        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($client, 'sanctum')->getJson('/api/merchant/dashboard')->assertForbidden();
        $this->actingAs($client, 'sanctum')->getJson('/api/merchant/products')->assertForbidden();
        $this->actingAs($client, 'sanctum')->getJson('/api/merchant/orders')->assertForbidden();
    }

    public function test_merchant_cannot_reach_admin_routes(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);

        $this->actingAs($merchant->user, 'sanctum')->getJson('/api/admin/dashboard')->assertForbidden();
        $this->actingAs($merchant->user, 'sanctum')->getJson('/api/admin/users')->assertForbidden();
        $this->actingAs($merchant->user, 'sanctum')->getJson('/api/admin/refunds')->assertForbidden();
    }

    public function test_admin_cannot_be_blocked_or_may_not_block_another_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/users/{$other->id}/block")
            ->assertForbidden();

        $this->assertFalse($other->fresh()->is_blocked);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/users/{$admin->id}/block")
            ->assertForbidden();
    }

    // ------------------------------------------------- ÉLÉVATION DE PRIVILÈGE

    public function test_a_user_cannot_change_their_own_role_through_the_profile(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $token = $this->issueTokenFor($user);

        $this->putAs($token, '/api/profil', [
            'name' => $user->name,
            'phone' => $user->phone,
            'role' => 'admin',
            'is_blocked' => false,
            'is_verified' => true,
            'email' => 'pirate@example.com',
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('client', $fresh->role);
        $this->assertFalse($fresh->is_blocked);
        $this->assertSame($user->email, $fresh->email);
    }

    public function test_a_user_cannot_register_themselves_as_admin(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Intrus',
            'email' => 'intruse@example.com',
            'phone' => '90000001',
            'password' => 'motdepasse123',
            'password_confirmation' => 'motdepasse123',
            'role' => 'admin',
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_no_public_endpoint_updates_a_user_role(): void
    {
        $admin = User::factory()->admin()->create();
        $victim = User::factory()->create(['role' => 'client']);

        // Aucun endpoint de mise à jour de rôle n'existe côté admin : la route
        // n'existe pas, donc la requête ne peut pas aboutir.
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/{$victim->id}", ['role' => 'admin'])
            ->assertNotFound();

        $this->assertSame('client', $victim->fresh()->role);
    }

    // ------------------------------------------------------------ IDOR COTISATION

    public function test_user_cannot_pay_or_manage_another_users_contribution(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $contribution = $this->makeContribution($owner);

        $attackerToken = $this->issueTokenFor($attacker);

        $this->postAs($attackerToken, "/api/contributions/{$contribution->id}/pay", [
            'payment_method' => 'orange_money',
        ])->assertForbidden();

        $this->postAs($attackerToken, "/api/contributions/{$contribution->id}/soumettre-code", [
            'payment_method' => 'bank',
            'transfer_code' => 'VOL-123',
        ])->assertForbidden();

        $this->postAs($attackerToken, "/api/contributions/{$contribution->id}/initiate-payment", [
            'payment_method' => 'orange_money',
        ])->assertForbidden();

        $this->assertSame('pending', $contribution->fresh()->status);
        $this->assertNotSame('VOL-123', $contribution->fresh()->transfer_code);

        // En revanche son propriétaire le peut.
        $ownerToken = $this->issueTokenFor($owner);
        $this->postAs($ownerToken, "/api/contributions/{$contribution->id}/pay", [
            'payment_method' => 'orange_money',
        ])->assertOk();
    }

    public function test_contributions_list_only_returns_the_owners_contributions(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $mine = $this->makeContribution($owner);
        $theirs = $this->makeContribution($other);

        $response = $this->getAs($this->issueTokenFor($owner), '/api/contributions')->assertOk()->json();

        $ids = array_column($response, 'id');
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    // ------------------------------------------------------------- IDOR TRANCHE

    public function test_user_cannot_pay_or_read_another_users_installment(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $product = Product::factory()->create();

        $purchase = $this->makePurchase($product, $owner);
        $installment = $purchase->installments->first();

        $attackerToken = $this->issueTokenFor($attacker);

        $this->getAs($attackerToken, "/api/mes-achats/{$purchase->id}")->assertForbidden();
        $this->postAs($attackerToken, "/api/tranches/{$installment->id}/pay", [
            'payment_method' => 'orange_money',
        ])->assertForbidden();
        $this->postAs($attackerToken, "/api/tranches/{$installment->id}/soumettre-code", [
            'payment_method' => 'bank',
            'transfer_code' => 'VOL-456',
        ])->assertForbidden();

        $this->assertSame('pending', $installment->fresh()->status);

        $ownerToken = $this->issueTokenFor($owner);
        $this->getAs($ownerToken, "/api/mes-achats/{$purchase->id}")->assertOk();
    }

    // ------------------------------------------------------------- IDOR PRODUIT

    public function test_merchant_cannot_manage_another_merchants_product(): void
    {
        $owner = Merchant::factory()->create(['status' => 'approved']);
        $intruder = Merchant::factory()->create(['status' => 'approved']);

        $product = Product::factory()->create(['merchant_id' => $owner->id]);

        $token = $this->issueTokenFor($intruder->user);

        $this->putAs($token, "/api/merchant/products/{$product->id}", [
            'name' => 'Volé', 'price' => 1, 'stock' => 1, 'status' => 'published',
        ])->assertForbidden();

        $this->apiWithToken($token, 'DELETE', "/api/merchant/products/{$product->id}")->assertForbidden();

        $this->apiWithToken($token, 'PATCH', "/api/merchant/products/{$product->id}/stock", ['stock' => 999])
            ->assertForbidden();

        $this->assertNotSame('Volé', $product->fresh()->name);
        $this->assertNotSame(999, $product->fresh()->stock);
    }

    public function test_merchant_cannot_delete_another_merchants_media(): void
    {
        $owner = Merchant::factory()->create(['status' => 'approved']);
        $intruder = Merchant::factory()->create(['status' => 'approved']);

        $product = Product::factory()->create(['merchant_id' => $owner->id]);
        $media = ProductMedia::create([
            'product_id' => $product->id,
            'type' => 'image',
            'path' => 'products/x.jpg',
            'url' => '/storage/products/x.jpg',
            'position' => 1,
        ]);

        $this->apiWithToken($this->issueTokenFor($intruder->user), 'DELETE', "/api/merchant/products/media/{$media->id}")
            ->assertForbidden();

        $this->assertNotNull($media->fresh());
    }

    public function test_merchant_can_manage_their_own_product(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create(['merchant_id' => $merchant->id]);

        $token = $this->issueTokenFor($merchant->user);

        $this->apiWithToken($token, 'PATCH', "/api/merchant/products/{$product->id}/stock", ['stock' => 42])->assertOk();
        $this->assertSame(42, $product->fresh()->stock);
    }

    // ------------------------------------------------------------ IDOR LIVRAISON

    public function test_merchant_cannot_deliver_for_another_merchants_tontine_member(): void
    {
        $owner = Merchant::factory()->create(['status' => 'approved']);
        $intruder = Merchant::factory()->create(['status' => 'approved']);

        $product = Product::factory()->create(['merchant_id' => $owner->id]);
        $member = $this->makeFundedBeneficiary($product);

        $this->postAs($this->issueTokenFor($intruder->user), "/api/merchant/orders/{$member->id}/deliver")
            ->assertForbidden();

        $this->assertNotSame('delivered', $member->fresh()->delivery_status);
    }

    public function test_merchant_cannot_deliver_another_merchants_installment_purchase(): void
    {
        $owner = Merchant::factory()->create(['status' => 'approved']);
        $intruder = Merchant::factory()->create(['status' => 'approved']);

        $product = Product::factory()->create(['merchant_id' => $owner->id]);
        $purchase = $this->makePurchase($product, User::factory()->create());
        $purchase->update(['status' => 'completed', 'delivery_status' => 'pending']);

        $this->postAs($this->issueTokenFor($intruder->user), "/api/merchant/installment-orders/{$purchase->id}/deliver")
            ->assertForbidden();

        $this->assertNotSame('delivered', $purchase->fresh()->delivery_status);
    }

    public function test_merchant_can_deliver_their_own_funded_delivery(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create(['merchant_id' => $merchant->id]);
        $member = $this->makeFundedBeneficiary($product);

        $this->postAs($this->issueTokenFor($merchant->user), "/api/merchant/orders/{$member->id}/deliver")
            ->assertOk();

        $this->assertSame('delivered', $member->fresh()->delivery_status);
    }

    // ----------------------------------------------------------- IDOR DIVERS

    public function test_a_user_cannot_read_or_mark_another_users_notification(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $owner->notify(new BecameBeneficiaryNotification(
            Tontine::factory()->create()
        ));
        $notification = $owner->notifications()->latest()->first();

        $token = $this->issueTokenFor($attacker);

        $this->postAs($token, "/api/notifications/{$notification->id}/read")->assertNotFound();

        $listed = $this->getAs($token, '/api/notifications')->assertOk()->json();
        $this->assertNotContains($notification->id, array_column($listed['data'] ?? $listed, 'id'));
    }

    public function test_a_non_member_cannot_raise_a_dispute_on_someone_elses_tontine(): void
    {
        $member = User::factory()->create();
        $outsider = User::factory()->create();

        $product = Product::factory()->create();
        $tontine = Tontine::factory()->create(['product_id' => $product->id, 'created_by' => $member->id]);
        TontineMember::create([
            'tontine_id' => $tontine->id, 'user_id' => $member->id, 'position' => 1, 'status' => 'active',
        ]);

        $this->postAs($this->issueTokenFor($outsider), "/api/tontines/{$tontine->id}/disputes", [
            'subject' => 'Intrusion',
            'description' => 'Je ne suis pas membre.',
        ])->assertForbidden();

        $this->assertSame(0, Dispute::count());
    }

    public function test_a_non_member_cannot_review_a_merchant_on_a_tontine_they_did_not_join(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create(['merchant_id' => $merchant->id]);
        $participant = User::factory()->create();
        $outsider = User::factory()->create();

        $tontine = Tontine::factory()->create([
            'product_id' => $product->id, 'created_by' => $participant->id, 'status' => 'completed',
        ]);
        TontineMember::create([
            'tontine_id' => $tontine->id, 'user_id' => $participant->id, 'position' => 1, 'status' => 'completed',
        ]);

        $this->postAs($this->issueTokenFor($outsider), "/api/tontines/{$tontine->id}/review", [
            'rating' => 1, 'comment' => 'Faux avis',
        ])->assertForbidden();

        $this->assertSame(0, MerchantReview::count());
    }

    public function test_an_unapproved_merchant_cannot_reach_merchant_routes(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'pending']);

        $this->actingAs($merchant->user, 'sanctum')->getJson('/api/merchant/dashboard')->assertForbidden();
        $this->actingAs($merchant->user, 'sanctum')->getJson('/api/merchant/orders')->assertForbidden();
    }

    // --------------------------------------------------------------- UTILITAIRES

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

    private function makePurchase(Product $product, User $buyer): InstallmentPurchase
    {
        $purchase = InstallmentPurchase::create([
            'product_id' => $product->id,
            'user_id' => $buyer->id,
            'installments_count' => 2,
            'installment_amount' => 5000,
            'product_price' => 10000,
            'status' => 'active',
            'delivery_status' => 'not_applicable',
        ]);

        Installment::create([
            'installment_purchase_id' => $purchase->id,
            'installment_number' => 1,
            'amount' => 5000,
            'commission_rate' => 0.07,
            'status' => 'pending',
        ]);

        Installment::create([
            'installment_purchase_id' => $purchase->id,
            'installment_number' => 2,
            'amount' => 5000,
            'commission_rate' => 0.07,
            'status' => 'pending',
        ]);

        return $purchase->fresh();
    }

    private function makeFundedBeneficiary(Product $product): TontineMember
    {
        $beneficiary = User::factory()->create();
        $payer = User::factory()->create();

        $tontine = Tontine::factory()->create([
            'product_id' => $product->id, 'created_by' => $beneficiary->id, 'status' => 'active',
        ]);

        $b = TontineMember::create([
            'tontine_id' => $tontine->id, 'user_id' => $beneficiary->id, 'position' => 1,
            'status' => 'beneficiary', 'beneficiary_round' => 1, 'delivery_status' => 'pending',
        ]);
        TontineMember::create([
            'tontine_id' => $tontine->id, 'user_id' => $payer->id, 'position' => 2, 'status' => 'active',
        ]);

        foreach ([$b, TontineMember::where('tontine_id', $tontine->id)->where('position', 2)->first()] as $member) {
            Contribution::create([
                'tontine_member_id' => $member->id,
                'round' => 1,
                'amount' => 5000,
                'commission_rate' => 0.07,
                'status' => 'completed',
                'paid_at' => now(),
            ]);
        }

        return $b->fresh();
    }
}
