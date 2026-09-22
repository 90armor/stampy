<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Every employee is now assigned a schedule at creation, which needs a default to exist.
        WorkSchedule::factory()->create(['is_default' => true]);
    }

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

    public function test_the_profile_page_shows_a_linked_users_name_read_only_from_the_employee_record(): void
    {
        // A mismatch shouldn't occur once this is the only path that sets
        // users.name for a linked user — deliberately set up here anyway, so
        // the assertion is about which value the FORM FIELD shows, not just
        // that the employee's name appears somewhere on the page (the topbar
        // separately renders auth()->user()->name and is out of scope here).
        $user = User::factory()->create(['name' => 'Stale Account Name']);
        Employee::factory()->create(['user_id' => $user->id, 'full_name' => 'Real Employee Name']);

        $response = $this->actingAs($user)->get('/profile');

        $response->assertOk();
        $response->assertSee('managed by an admin');
        $response->assertSee('value="Real Employee Name"', false);
        $response->assertDontSee('value="Stale Account Name"', false);
    }

    public function test_a_linked_user_cannot_change_their_name_through_the_profile_even_with_a_crafted_request(): void
    {
        $user = User::factory()->create(['name' => 'Real Name']);
        Employee::factory()->create(['user_id' => $user->id, 'full_name' => 'Real Name']);

        // The form renders this field read-only; this simulates a request that
        // ignores that and submits a changed value anyway.
        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Tampered Name',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertSame('Real Name', $user->fresh()->name);
    }

    public function test_an_unlinked_user_can_still_change_their_own_name(): void
    {
        // No linked Employee record — same as test_profile_information_can_be_updated,
        // asserted explicitly here as the counterpart to the linked case above.
        $user = User::factory()->create(['name' => 'Old Name']);

        $this->actingAs($user)
            ->patch('/profile', ['name' => 'New Name', 'email' => $user->email])
            ->assertSessionHasNoErrors();

        $this->assertSame('New Name', $user->fresh()->name);
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
