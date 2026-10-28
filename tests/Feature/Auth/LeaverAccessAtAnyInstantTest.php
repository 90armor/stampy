<?php

namespace Tests\Feature\Auth;

use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The leaver rule at whatever "now" is — deliberately unpinned
 * (LeaverAccessTest pins fixed dates), so the pinned-instant check runs it on
 * a leaver's last day and on the day after at every instant, the
 * 23:59:30 and 00:00:30 midnight edges included: a last day of today still
 * signs in and keeps its session; a last day of yesterday is refused and
 * signed out.
 */
class LeaverAccessAtAnyInstantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'employee']);
        WorkSchedule::factory()->create(['is_default' => true]);
    }

    private function leaver(string $leftOn): User
    {
        $user = User::factory()->create(['username' => 'visal', 'password' => 'password'])->assignRole('employee');
        Employee::factory()->create(['user_id' => $user->id, 'join_date' => today()->subYears(2)->format('Y-m-d'), 'status' => 'inactive', 'left_on' => $leftOn]);

        return $user;
    }

    public function test_on_the_last_day_itself_access_continues(): void
    {
        $user = $this->leaver(today()->format('Y-m-d'));

        $this->post('/login', ['email' => 'visal', 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk();
    }

    public function test_on_the_day_after_the_last_day_access_has_ended(): void
    {
        $leftOn = today()->subDay();
        $user = $this->leaver($leftOn->format('Y-m-d'));

        $this->post('/login', ['email' => 'visal', 'password' => 'password'])->assertSessionHasErrors('auth');
        $this->assertGuest();

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/login?ended='.$leftOn->format('Y-m-d'));
        $this->assertGuest();
    }
}
