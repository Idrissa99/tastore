<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_register_via_web(): void
    {
        $response = $this->post('/register', [
            'name' => 'Aïcha Test',
            'email' => 'aicha@example.com',
            'phone' => '90123456',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'client',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('tontines.index'));

        $this->assertDatabaseHas('users', [
            'email' => 'aicha@example.com',
            'role' => 'client',
        ]);
    }

    public function test_merchant_registration_creates_a_pending_merchant_profile(): void
    {
        $this->post('/register', [
            'name' => 'Boutique Test',
            'email' => 'boutique@example.com',
            'phone' => '90999999',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'merchant',
        ]);

        $user = User::where('email', 'boutique@example.com')->first();

        $this->assertNotNull($user->merchant);
        $this->assertSame('pending', $user->merchant->status);
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'exists@example.com']);

        $response = $this->post('/register', [
            'name' => 'Doublon',
            'email' => 'exists@example.com',
            'phone' => '90111222',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'client',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
