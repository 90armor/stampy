<?php

namespace Tests\Feature;

use App\Livewire\Leave\TimeOff;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6g, F27 — an admin with no employee record: no My attendance in the
 * navigation (the sidebar and the phone drawer are one partial), though the
 * page still opens on its "not linked" state; and Time off says where leave
 * they file for someone shows.
 */
class UnlinkedAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->create(['is_default' => true]);
    }

    public function test_my_attendance_is_in_the_navigation_only_with_an_employee_record(): void
    {
        $unlinked = User::factory()->create()->assignRole('admin');
        $linked = User::factory()->create()->assignRole('admin');
        Employee::factory()->create(['user_id' => $linked->id]);
        $mine = 'href="'.route('attendance.mine').'"';

        // Both copies of the navigation, the sidebar and the drawer.
        $this->assertSame(0, substr_count($this->actingAs($unlinked)->get(route('profile.edit'))->getContent(), $mine));
        $this->assertSame(2, substr_count($this->actingAs($linked)->get(route('profile.edit'))->getContent(), $mine));
    }

    public function test_the_page_itself_still_says_why_it_is_empty(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'))->get(route('attendance.mine'))
            ->assertOk()
            ->assertSee("isn't linked to an employee record");
    }

    public function test_time_off_says_where_filed_leave_appears(): void
    {
        Livewire::actingAs(User::factory()->create()->assignRole('admin'))->test(TimeOff::class)
            ->assertSee("Your account isn't linked to an employee record", false)
            ->assertSee('Leave you file for someone appears on their profile.');

        $linked = User::factory()->create()->assignRole('admin');
        Employee::factory()->create(['user_id' => $linked->id]);
        Livewire::actingAs($linked)->test(TimeOff::class)->assertDontSee('Leave you file for someone appears on their profile.');
    }
}
