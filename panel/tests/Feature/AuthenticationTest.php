<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/buckets')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Sign in');
    }

    public function test_public_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_an_admin_can_sign_in(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse'])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_passwords_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'nope'])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_admin_command_creates_and_updates_users(): void
    {
        $this->artisan('johnny:admin', ['email' => 'ops@example.com', 'password' => 'first-pass'])
            ->expectsOutput('User created.')
            ->assertSuccessful();

        $this->artisan('johnny:admin', ['email' => 'ops@example.com', 'password' => 'second-pass'])
            ->expectsOutput('User updated.')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('second-pass', User::where('email', 'ops@example.com')->value('password')));
    }
}
