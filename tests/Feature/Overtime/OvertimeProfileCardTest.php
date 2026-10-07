<?php

namespace Tests\Feature\Overtime;

use App\Enums\OvertimeCompensation;
use App\Livewire\Employees\LeaveCard;
use App\Livewire\Employees\OvertimeCard;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\OvertimeSettings;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Overtime\OvertimeRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4d — the profile's Overtime card: the months' totals (pay by
 * category, time off plain), the requests with their history and result,
 * what time off in lieu posted, and "File overtime" for an admin only.
 * The clock is Mon 15 Jun 2026 12:00.
 */
class OvertimeProfileCardTest extends TestCase
{
    use RefreshDatabase;

    private Employee $manager;

    private Employee $employee;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->withBreakStart()->create([
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'break_minutes' => 60, 'break_start' => '12:00:00',
            'workdays' => [1, 2, 3, 4, 5], 'is_default' => true,
        ]);
        $toil = LeaveType::factory()->earned()->create(['name' => 'Time off in lieu', 'carry_over_cap' => 5]);
        OvertimeSettings::current()->update(['toil_leave_type_id' => $toil->id]);

        $this->manager = Employee::factory()->create(['full_name' => 'Aye Aye Mon', 'user_id' => User::factory()->create()->assignRole('manager')->id]);
        $this->employee = Employee::factory()->create(['full_name' => 'Kyaw Kyaw', 'user_id' => User::factory()->create()->assignRole('employee')->id, 'manager_id' => $this->manager->id]);
        $this->admin = User::factory()->create()->assignRole('admin');

        // 8 Jun: 21:00–23:00 paid. Sat 13 Jun: 08:00–13:00 as time off — half a day.
        $this->filed('2026-06-08', '08:00:00', '23:00:00', '21:00', '23:00', OvertimeCompensation::Pay);
        $this->filed('2026-06-13', '08:00:00', '13:00:00', '08:00', '13:00', OvertimeCompensation::TimeOff);
    }

    private function filed(string $date, string $in, string $out, string $from, string $to, OvertimeCompensation $compensation): void
    {
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => "{$date} {$in}", 'punch_type' => 'in']);
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => "{$date} {$out}", 'punch_type' => 'out']);
        app(OvertimeRequestService::class)->submit($this->employee, Carbon::parse($date), Carbon::parse("{$date} {$from}"), Carbon::parse("{$date} {$to}"), $compensation, 'Release', $this->admin);
    }

    public function test_an_admin_sees_the_totals_requests_and_what_was_posted_and_can_file(): void
    {
        Livewire::actingAs($this->admin)->test(OvertimeCard::class, ['employee' => $this->employee])
            ->assertSee('June 2026')
            ->assertSee('May 2026')
            ->assertSee('6h 00m')
            ->assertSee('Workday (150%) 1h 00m · Night (200%) 1h 00m')
            ->assertSee('As time off 4h 00m')
            ->assertSee('Mon 8 Jun, 9:00 PM – 11:00 PM')
            ->assertSee('2h 00m credited')
            ->assertSee('Step 1: approved by')
            ->assertSee('Time off in lieu posted')
            ->assertSee('Triggered by overtime on Sat 13 Jun')
            ->assertSee('File overtime');

        $this->actingAs($this->admin)->get(route('employees.show', $this->employee))->assertOk()->assertSee('Credited overtime and requests.');
    }

    public function test_a_manager_reads_it_without_filing(): void
    {
        Livewire::actingAs($this->manager->user)->test(OvertimeCard::class, ['employee' => $this->employee])
            ->assertSee('2h 00m credited')
            ->assertDontSee('File overtime');
    }

    public function test_someone_who_cannot_open_the_profile_cannot_open_the_card(): void
    {
        Livewire::actingAs(User::factory()->create()->assignRole('manager'))->test(OvertimeCard::class, ['employee' => $this->employee])->assertForbidden();
    }

    public function test_the_leave_cards_adjustments_are_only_an_admins(): void
    {
        Livewire::actingAs($this->admin)->test(LeaveCard::class, ['employee' => $this->employee])
            ->assertSee('No adjustments.');
    }
}
