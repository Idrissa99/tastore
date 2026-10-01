<?php

namespace Tests\Feature\Notifications;

use App\Models\Contribution;
use App\Models\Dispute;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\Tontine;
use App\Models\TontineMember;
use App\Models\User;
use App\Notifications\BecameBeneficiaryNotification;
use App\Notifications\DeliveryConfirmedNotification;
use App\Notifications\DisputeResolvedNotification;
use App\Notifications\TontineUpdatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * PRIORITÉ 2 — Le « c'est ton tour » part au membre TIRÉ en position 1 au
     * lancement, pas au premier arrivé. On interroge donc le bénéficiaire réel
     * après coup au lieu de supposer que c'est $member1.
     */
    public function test_new_beneficiary_is_notified_when_tontine_becomes_full(): void
    {
        Notification::fake();

        $product = Product::factory()->create();
        $member1 = User::factory()->create();
        $member2 = User::factory()->create();

        $tontine = Tontine::factory()->create([
            'product_id' => $product->id,
            'created_by' => $member1->id,
            'max_members' => 2,
        ]);

        TontineMember::create([
            'tontine_id' => $tontine->id, 'user_id' => $member1->id, 'position' => 1, 'status' => 'active',
        ]);

        $this->actingAs($member2)->post(route('tontines.join', $tontine));

        $winner = $tontine->members()
            ->where('status', TontineMember::STATUS_BENEFICIARY)
            ->firstOrFail();

        $this->assertSame(1, (int) $winner->position, 'Le bénéficiaire du round 1 est le membre tiré en position 1.');

        Notification::assertSentTo($winner->user, BecameBeneficiaryNotification::class);
    }

    public function test_beneficiary_is_notified_when_delivery_is_confirmed(): void
    {
        Notification::fake();

        $merchant = Merchant::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create(['merchant_id' => $merchant->id]);

        $beneficiaryUser = User::factory()->create();
        $tontine = Tontine::factory()->create(['product_id' => $product->id, 'created_by' => $beneficiaryUser->id]);

        $member = TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => $beneficiaryUser->id,
            'position' => 1,
            'status' => 'beneficiary',
            'beneficiary_round' => 1,
            'delivery_status' => 'pending',
        ]);

        // Le round doit être financé : le round 1 est bouclé et le round 2 démarre.
        $tontine->update(['current_round' => 2, 'contribution_amount' => 10000]);

        foreach (TontineMember::where('tontine_id', $tontine->id)->get() as $participant) {
            $contribution = Contribution::create([
                'tontine_member_id' => $participant->id,
                'round' => 1,
                'amount' => 10000,
                'commission_rate' => 0.10,
                'status' => 'completed',
                'paid_at' => now(),
            ]);
        }

        $this->assertTrue($tontine->fresh()->roundIsFunded(1));

        $this->actingAs($merchant->user)->post(route('merchant.orders.deliver', $member));

        Notification::assertSentTo($beneficiaryUser, DeliveryConfirmedNotification::class);
    }

    public function test_dispute_author_is_notified_when_resolved(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create();
        $member = User::factory()->create();
        $tontine = Tontine::factory()->create(['product_id' => $product->id, 'created_by' => $member->id]);

        $dispute = Dispute::create([
            'tontine_id' => $tontine->id,
            'raised_by' => $member->id,
            'subject' => 'Test',
            'description' => 'Test',
            'status' => 'open',
        ]);

        $this->actingAs($admin)->post(route('admin.disputes.resolve', $dispute), [
            'status' => 'resolved',
            'resolution_note' => 'Réglé.',
        ]);

        Notification::assertSentTo($member, DisputeResolvedNotification::class);
    }

    public function test_user_can_view_and_mark_notifications_as_read(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $tontine = Tontine::factory()->create(['product_id' => $product->id, 'created_by' => $user->id]);

        $user->notify(new BecameBeneficiaryNotification($tontine));

        $this->actingAs($user)->get(route('notifications.index'))->assertOk();

        $notificationId = $user->notifications()->first()->id;

        $this->actingAs($user)
            ->post(route('notifications.mark-read', $notificationId))
            ->assertRedirect();

        $this->assertNotNull($user->notifications()->first()->read_at);
    }

    /**
     * L'API mobile alimente la pastille rouge de l'accueil : si « tout lire »
     * échoue, la pastille reste allumée sans que rien ne puisse l'éteindre.
     */
    public function test_api_can_mark_every_notification_as_read(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $tontine = Tontine::factory()->create(['product_id' => $product->id, 'created_by' => $user->id]);

        $user->notify(new BecameBeneficiaryNotification($tontine));
        $user->notify(new TontineUpdatedNotification($tontine, ['Nom']));

        $this->assertCount(2, $user->unreadNotifications);

        $this->actingAs($user)
            ->postJson('/api/notifications/mark-all-read')
            ->assertOk();

        $this->assertCount(0, $user->fresh()->unreadNotifications);
    }

    /**
     * Régression : l'application mobile ne marquait comme lu qu'une notification
     * sur laquelle elle pouvait Naviguer. Celles dont le lien ne se traduit pas
     * côté mobile étaient donc impossibles à lire — et la pastille ne
     * descendait jamais à zéro.
     */
    public function test_api_marks_a_single_notification_as_read_by_id(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $tontine = Tontine::factory()->create(['product_id' => $product->id, 'created_by' => $user->id]);

        $user->notify(new BecameBeneficiaryNotification($tontine));

        $id = $user->notifications()->first()->id;

        $this->actingAs($user)
            ->postJson("/api/notifications/{$id}/read")
            ->assertOk();

        $this->assertNotNull($user->notifications()->first()->read_at);
    }

    /**
     * La liste est la source de la pastille : elle doit exposer `read_at`, sans
     * quoi le client ne peut distinguer lue et non lue.
     */
    public function test_api_notification_list_exposes_the_read_state(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $tontine = Tontine::factory()->create(['product_id' => $product->id, 'created_by' => $user->id]);

        $user->notify(new BecameBeneficiaryNotification($tontine));

        $response = $this->actingAs($user)->getJson('/api/notifications')->assertOk();

        $response->assertJsonPath('data.0.read_at', null);
        $response->assertJsonPath('data.0.data.message', fn (string $message) => $message !== '');
    }
}
