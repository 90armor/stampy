<?php

namespace Tests\Feature\Overtime;

use App\Livewire\OvertimeSettings\Edit;
use App\Models\Employee;
use App\Models\LeaveAdjustment;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\OvertimeSettings;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4d — Policies → Overtime: the settings form, the model's rules as
 * field errors, and the TOIL fields locked once time off in lieu is credited.
 */
class OvertimeSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private LeaveType $toil;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->create(['is_default' => true]);
        $this->toil = LeaveType::factory()->earned()->create(['name' => 'Time off in lieu', 'carry_over_cap' => 5]);
        $this->admin = User::factory()->create()->assignRole('admin');
    }

    public function test_only_admins_reach_it(): void
    {
        $this->actingAs($this->admin)->get(route('policies.index'))->assertOk()->assertSee('Overtime');

        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager)->get(route('policies.index'))->assertForbidden();
        Livewire::actingAs($manager)->test(Edit::class)->assertForbidden();
    }

    public function test_an_admin_saves_the_settings(): void
    {
        Livewire::actingAs($this->admin)->test(Edit::class)
            ->assertSet('workday_rate_percent', 150)
            ->assertSet('night_starts', '22:00')
            ->assertSet('weekly_rest_day', 7)
            ->set('night_rate_percent', 175)
            ->set('night_starts', '21:00')
            ->set('weekly_rest_day', 6)
            ->set('claim_window_days', 14)
            ->set('toil_leave_type_id', (string) $this->toil->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Saved the overtime settings.');

        $settings = OvertimeSettings::current();
        $this->assertSame([175, '21:00:00', 6, 14, $this->toil->id], [$settings->night_rate_percent, $settings->night_starts, $settings->weekly_rest_day, $settings->claim_window_days, $settings->toil_leave_type_id]);
    }

    public function test_the_models_rules_land_on_their_fields(): void
    {
        Livewire::actingAs($this->admin)->test(Edit::class)
            ->set('night_rate_percent', 140)
            ->call('save')
            ->assertHasErrors('rates')
            ->assertSee('holiday ≥ rest day ≥ night ≥ workday ≥ 100%');

        Livewire::actingAs($this->admin)->test(Edit::class)
            ->set('toil_block_minutes', 225)
            ->call('save')
            ->assertHasErrors('toil_block_minutes');

        $this->assertSame(200, OvertimeSettings::current()->night_rate_percent);
    }

    public function test_the_toil_fields_lock_once_time_off_in_lieu_is_credited_and_the_rest_stays_editable(): void
    {
        OvertimeSettings::current()->update(['toil_leave_type_id' => $this->toil->id]);
        $request = OvertimeRequest::factory()->for(Employee::factory())->timeOff()->approved()->create();
        LeaveAdjustment::factory()->create(['employee_id' => $request->employee_id, 'leave_type_id' => $this->toil->id, 'overtime_request_id' => $request->id]);

        $html = Livewire::actingAs($this->admin)->test(Edit::class)
            ->assertSee('Locked: time off in lieu has been credited')
            ->set('toil_ratio_percent', 150)
            ->set('workday_rate_percent', 160)
            ->call('save')
            ->assertHasNoErrors()
            ->html();

        $this->assertMatchesRegularExpression('/id="ot_toil_ratio"[^>]*disabled|disabled[^>]*id="ot_toil_ratio"/s', $html);
        $this->assertSame([100, 160], [OvertimeSettings::current()->toil_ratio_percent, OvertimeSettings::current()->workday_rate_percent]);
    }
}
