<?php

namespace Tests\Feature\Overtime;

use App\Enums\OvertimeCompensation;
use App\Enums\OvertimeStatus;
use App\Livewire\Leave\Approvals;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\OvertimeSettings;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Approval\ApprovalInbox;
use App\Services\Attendance\DailySummaryBuilder;
use App\Services\Leave\LeaveRequestService;
use App\Services\Overtime\OvertimeRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4d — Approvals shows overtime alongside leave: the page's items are
 * exactly what the badge counts, an overtime item says what the day's punches
 * would credit, and its decision dialog handles compensation and the limits.
 * The clock is Mon 15 Jun 2026 12:00; the schedule is Mon–Fri 08:00–17:00.
 */
class OvertimeApprovalsPageTest extends TestCase
{
    use RefreshDatabase;

    private Employee $manager;

    private Employee $employee;

    private User $admin;

    private LeaveType $annual;

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
        $this->annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18]);
        $toil = LeaveType::factory()->earned()->create(['name' => 'Time off in lieu', 'carry_over_cap' => 5]);
        OvertimeSettings::current()->update(['toil_leave_type_id' => $toil->id]);

        $this->manager = $this->person('Aye Aye Mon', 'manager');
        $this->employee = $this->person('Kyaw Kyaw', 'employee', $this->manager);
        $this->admin = User::factory()->create(['name' => 'Admin'])->assignRole('admin');
    }

    private function person(string $name, ?string $role, ?Employee $manager = null): Employee
    {
        $user = $role === null ? null : User::factory()->create(['name' => $name])->assignRole($role);

        return Employee::factory()->create(['full_name' => $name, 'user_id' => $user?->id, 'manager_id' => $manager?->id]);
    }

    private function overtime(string $date, string $from = '17:00', string $to = '19:00', OvertimeCompensation $compensation = OvertimeCompensation::Pay, ?string $reason = null): OvertimeRequest
    {
        return app(OvertimeRequestService::class)->submit(
            $this->employee, Carbon::parse($date), Carbon::parse("{$date} {$from}"), Carbon::parse("{$date} {$to}"), $compensation, $reason, $this->employee->user,
        )['request'];
    }

    private function leave(string $from, string $to): Leave
    {
        return app(LeaveRequestService::class)->submit($this->employee, $this->annual, Carbon::parse($from), Carbon::parse($to), null, null, $this->employee->user)['leave'];
    }

    /** Items the page shows that wait on the user: step one, step two, and the stuck overrides. */
    private function shownWaitingOn(User $user): int
    {
        $page = Livewire::actingAs($user)->test(Approvals::class);

        return count($page->viewData('stepOne')) + count($page->viewData('stepTwo'))
            + collect($page->viewData('overrides'))->where('stuck', true)->count();
    }

    public function test_both_types_are_listed_together_with_their_type_and_details(): void
    {
        $this->overtime('2026-06-16', '17:00', '19:00', OvertimeCompensation::TimeOff, 'Release night');
        $this->leave('2026-06-24', '2026-06-24');

        Livewire::actingAs($this->manager->user)->test(Approvals::class)
            ->assertSee('Leave and overtime requests waiting for a decision.')
            ->assertSeeInOrder(['Overtime · Tue 16 Jun, 5:00 PM – 7:00 PM', 'Leave · Annual · Wed 24 Jun'])
            ->assertSee('Planned · Time off')
            ->assertSee('“Release night”')
            ->assertSee('Submitted Mon 15 Jun');
    }

    public function test_a_claim_shows_what_the_days_punches_would_credit(): void
    {
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => '2026-06-12 08:02:00', 'punch_type' => 'in']);
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => '2026-06-12 19:05:00', 'punch_type' => 'out']);
        app(DailySummaryBuilder::class)->rebuildAround($this->employee, Carbon::parse('2026-06-12'));
        $this->overtime('2026-06-12');
        $this->overtime('2026-06-11');

        Livewire::actingAs($this->manager->user)->test(Approvals::class)
            ->assertSee('Punched 8:02 AM – 7:05 PM → 2h 00m would be credited (workday)')
            ->assertSee('No punches on record for this day yet: nothing would be credited until they\'re added.')
            ->assertDontSee('Check the punches before approving')
            ->assertSee('Already worked');
    }

    public function test_less_than_requested_is_written_against_the_request_with_a_hint(): void
    {
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => '2026-06-12 08:01:00', 'punch_type' => 'in']);
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => '2026-06-12 17:01:00', 'punch_type' => 'out']);
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => '2026-06-11 08:00:00', 'punch_type' => 'in']);
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => '2026-06-11 16:30:00', 'punch_type' => 'out']);
        app(DailySummaryBuilder::class)->rebuildAround($this->employee, Carbon::parse('2026-06-11'), Carbon::parse('2026-06-12'));
        $this->overtime('2026-06-12', '17:00', '18:00');
        $this->overtime('2026-06-11', '17:00', '18:00');

        Livewire::actingAs($this->manager->user)->test(Approvals::class)
            ->assertSee('Punched 8:01 AM – 5:01 PM → 1m of 1h 00m requested would be credited (workday)')
            ->assertSee('Punched 8:00 AM – 4:30 PM → none of 1h 00m requested would be credited')
            ->assertSee('Check the punches before approving — an out-punch may be missing.');
    }

    public function test_the_page_shows_exactly_what_the_badge_counts(): void
    {
        $this->overtime('2026-06-16');
        $this->overtime('2026-06-24');
        $this->leave('2026-06-25', '2026-06-25');
        $approved = $this->overtime('2026-06-22');
        app(OvertimeRequestService::class)->approve($approved, $this->manager->user);

        $this->assertSame(3, ApprovalInbox::count($this->manager->user));
        $this->assertSame(ApprovalInbox::count($this->manager->user), $this->shownWaitingOn($this->manager->user));
        // The admin: the step-2 request, and the override starting within two working days.
        $this->assertSame(2, ApprovalInbox::count($this->admin));
        $this->assertSame(ApprovalInbox::count($this->admin), $this->shownWaitingOn($this->admin));
    }

    public function test_the_approver_may_change_the_compensation(): void
    {
        $request = $this->overtime('2026-06-16');

        Livewire::actingAs($this->manager->user)->test(Approvals::class)
            ->call('openDecision', $request->id, 'approve', 'overtime')
            ->assertSet('compensation', 'pay')
            ->assertSee('Time off in lieu')
            ->set('compensation', 'time_off')
            ->call('decide')
            ->assertSee('Approved Kyaw Kyaw\'s overtime on Tue 16 Jun — it now waits for an admin.');

        $this->assertSame(OvertimeCompensation::TimeOff, $request->fresh()->compensation);
        $this->assertSame('Compensation changed: pay → time off', $request->approvalSteps()->sole()->note);
    }

    public function test_over_a_limit_a_manager_sees_a_notice_and_the_admin_needs_a_reason(): void
    {
        $request = $this->overtime('2026-06-16');
        OvertimeSettings::current()->update(['max_overtime_minutes_per_day' => 60]);

        Livewire::actingAs($this->manager->user)->test(Approvals::class)
            ->call('openDecision', $request->id, 'approve', 'overtime')
            ->assertSee('This is 2h 00m of overtime; the limit is 1h 00m a day.')
            ->assertSee('the admin decides at the next step')
            ->assertDontSee('Reason to go over the limit')
            ->call('decide');
        $this->assertSame(2, $request->fresh()->current_step);

        $page = Livewire::actingAs($this->admin)->test(Approvals::class)
            ->call('openDecision', $request->id, 'approve', 'overtime')
            ->assertSee('Reason to go over the limit')
            ->call('decide')
            ->assertHasErrors('limit_override_reason')
            ->assertSet('showDecision', true);

        $page->set('override_reason', 'Agreed with the client')->call('decide')->assertHasNoErrors();

        $this->assertSame([OvertimeStatus::Approved, 'Agreed with the client'], [$request->fresh()->status, $request->fresh()->limit_override_reason]);
    }

    public function test_rejecting_overtime_needs_a_note(): void
    {
        $request = $this->overtime('2026-06-16');

        Livewire::actingAs($this->manager->user)->test(Approvals::class)
            ->call('openDecision', $request->id, 'reject', 'overtime')
            ->call('decide')
            ->assertHasErrors('note')
            ->set('note', 'Not budgeted')
            ->call('decide')
            ->assertSee('Rejected Kyaw Kyaw\'s overtime on Tue 16 Jun.');

        $this->assertSame(OvertimeStatus::Rejected, $request->fresh()->status);
    }
}
