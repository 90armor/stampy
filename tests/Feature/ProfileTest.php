<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
    }

    public function test_a_user_with_no_email_can_save_their_profile(): void
    {
        // Many employees have no email at all (users.email is nullable) and sign
        // in with their username instead — the form must not force one on them.
        $user = User::factory()->create(['email' => null]);

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'No Email User',
                'email' => '',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('No Email User', $user->name);
        $this->assertNull($user->email);
    }

    public function test_the_profile_page_offers_no_way_to_delete_the_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/profile')
            ->assertOk()
            ->assertDontSee('Delete Account')
            ->assertDontSee('confirm-user-deletion');
    }

    public function test_a_user_cannot_delete_their_own_account(): void
    {
        $user = User::factory()->create();

        // The URL still exists for GET/PATCH, so a DELETE is refused as a wrong method rather than a missing page.
        $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertStatus(405);

        $this->assertNotNull($user->fresh());
        $this->assertAuthenticatedAs($user);
        $this->assertFalse(Route::has('profile.destroy'));
    }
}
