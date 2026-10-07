<?php

namespace Tests\Feature\Overtime;

use App\Enums\OvertimeCompensation;
use App\Enums\OvertimeStatus;
use App\Livewire\Overtime\Index;
use App\Livewire\Overtime\RequestModal;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\OvertimeSettings;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use App\Services\Overtime\OvertimeRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4d — the Overtime page and its request modal. The clock is Mon 15 Jun
 * 2026 12:00; the schedule is Mon–Fri 08:00–17:00 with a 12:00–13:00 break.
 */
class OvertimePageTest extends TestCase
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

        $this->manager = $this->person('Aye Aye Mon', 'manager');
        $this->employee = $this->person('Kyaw Kyaw', 'employee', $this->manager);
        $this->admin = User::factory()->create(['name' => 'Admin'])->assignRole('admin');
    }

    private function person(string $name, ?string $role, ?Employee $manager = null): Employee
    {
        $user = $role === null ? null : User::factory()->create(['name' => $name])->assignRole($role);

        return Employee::factory()->create(['full_name' => $name, 'user_id' => $user?->id, 'manager_id' => $manager?->id]);
    }

    private function worked(string $date, string $in, ?string $out): void
    {
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => "{$date} {$in}", 'punch_type' => 'in']);
        if ($out !== null) {
            AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => "{$date} {$out}", 'punch_type' => 'out']);
        }
    }

    /** Approved (admin-filed), and its date rebuilt. */
    private function approved(string $date, string $from, string $to, OvertimeCompensation $compensation = OvertimeCompensation::Pay): OvertimeRequest
    {
        $start = Carbon::parse("{$date} {$from}");
        $end = Carbon::parse("{$date} {$to}");

        return app(OvertimeRequestService::class)->submit(
            $this->employee, Carbon::parse($date), $start, $end->lte($start) ? $end->addDay() : $end, $compensation, null, $this->admin,
        )['request'];
    }

    private function modal(?User $user = null)
    {
        return Livewire::actingAs($user ?? $this->employee->user)->test(RequestModal::class)->call('open');
    }

    // ── Access ──────────────────────────────────────────────────────────

    public function test_anyone_with_an_employee_record_and_an_admin_see_the_page_and_its_sidebar_item(): void
    {
        $this->actingAs($this->employee->user)->get(route('overtime.index'))->assertOk()->assertSee(route('overtime.index'));
        $this->actingAs($this->admin)->get(route('overtime.index'))->assertOk()->assertSee('File for an employee');

        // A manager with no employee record has nothing here, and no link to it.
        $unlinked = User::factory()->create()->assignRole('manager');
        $this->actingAs($unlinked)->get(route('overtime.index'))->assertForbidden();
        $this->actingAs($unlinked)->get(route('dashboard'))->assertDontSee(route('overtime.index'));
    }

    // ── This month and the requests ────────────────────────────────────

    public function test_the_month_card_shows_credited_time_by_category_with_rates_paid_and_time_off_apart(): void
    {
        // Mon 8 Jun: 21:00–23:00 paid — 1h workday, 1h night. Tue 9 Jun: 17:00–21:30 as time off.
        $this->worked('2026-06-08', '08:00:00', '23:00:00');
        $this->approved('2026-06-08', '21:00', '23:00');
        $this->worked('2026-06-09', '08:00:00', '21:30:00');
        OvertimeSettings::current()->update(['max_overtime_minutes_per_day' => 300, 'max_work_minutes_per_day' => 800]);
        $this->approved('2026-06-09', '17:00', '21:30', OvertimeCompensation::TimeOff);
        app(OvertimeRequestService::class)->submit($this->employee, Carbon::parse('2026-06-16'), Carbon::parse('2026-06-16 17:00'), Carbon::parse('2026-06-16 18:00'), OvertimeCompensation::Pay, null, $this->employee->user);

        Livewire::actingAs($this->employee->user)->test(Index::class)
            ->assertSee('6h 30m')
            ->assertSee('Workday (150%) 1h 00m · Night (200%) 1h 00m')
            ->assertSee('Workday (150%) 4h 30m')
            ->assertSee('1 request')
            ->assertSee('Time off in lieu')
            ->assertSee('30m toward the next half day');
    }

    public function test_each_request_says_what_it_has_credited(): void
    {
        $this->worked('2026-06-08', '08:00:00', '19:00:00');
        $this->approved('2026-06-08', '17:00', '19:00');
        $this->worked('2026-06-09', '08:00:00', '18:20:00');
        $this->approved('2026-06-09', '17:00', '19:00');
        $this->worked('2026-06-10', '08:00:00', null);
        $this->approved('2026-06-10', '17:00', '19:00');
        $this->approved('2026-06-19', '17:00', '19:00');
        $this->approved('2026-06-11', '23:00', '01:00');

        Livewire::actingAs($this->employee->user)->test(Index::class)
            ->assertSee('2h 00m credited')
            ->assertSee('1h 20m credited of 2h 00m approved')
            ->assertSee('Nothing credited — no out-punch on Wed 10 Jun')
            ->assertSee('Not yet — Fri 19 Jun')
            ->assertSee('11:00 PM – 1:00 AM (+1)')
            // Kind and compensation are plain text; the badge is the status.
            ->assertSee('Claim')
            ->assertSee('Pay');
    }

    public function test_decisions_since_the_last_visit_are_new_and_opening_the_page_marks_them_seen(): void
    {
        $request = app(OvertimeRequestService::class)->submit($this->employee, Carbon::parse('2026-06-16'), Carbon::parse('2026-06-16 17:00'), Carbon::parse('2026-06-16 19:00'), OvertimeCompensation::Pay, null, $this->employee->user)['request'];

        // First visit: nothing is new yet, and it's now "seen".
        Livewire::actingAs($this->employee->user)->test(Index::class)->assertSet('newIds', []);
        $this->assertNotNull($this->employee->user->fresh()->overtime_seen_at);

        $this->travel(1)->minutes();
        app(OvertimeRequestService::class)->reject($request, $this->manager->user, 'Not this week');
        $this->travel(1)->minutes();

        Livewire::actingAs($this->employee->user->fresh())->test(Index::class)
            ->assertSet('newIds', [$request->id])
            ->assertSee('New')
            ->assertSee('“Not this week” — Aye Aye Mon');

        Livewire::actingAs($this->employee->user->fresh())->test(Index::class)->assertSet('newIds', []);
    }

    public function test_the_requester_cancels_behind_a_keep_or_cancel_confirmation(): void
    {
        $request = app(OvertimeRequestService::class)->submit($this->employee, Carbon::parse('2026-06-16'), Carbon::parse('2026-06-16 17:00'), Carbon::parse('2026-06-16 19:00'), OvertimeCompensation::Pay, null, $this->employee->user)['request'];

        Livewire::actingAs($this->employee->user)->test(Index::class)
            ->assertSeeHtml("cancelText: 'Keep request'")
            ->assertSeeHtml("confirmText: 'Cancel request'")
            ->call('cancel', $request->id)
            ->assertSee('Cancelled your overtime request for Tue 16 Jun.');

        $this->assertSame(OvertimeStatus::Cancelled, $request->fresh()->status);
    }

    // ── The request modal ──────────────────────────────────────────────

    public function test_the_review_explains_what_counts_and_what_it_earns(): void
    {
        $this->modal()
            ->set('date', '2026-06-16')->set('start_time', '16:00')->set('end_time', '19:00')->set('compensation', 'time_off')
            ->call('review')
            ->assertHasNoErrors()
            ->assertSet('step', 'review')
            ->assertSee('This is planned: it hasn\'t started yet.')
            ->assertSee('Only 5:00 PM – 7:00 PM counts. 4:00 PM – 5:00 PM is normal working hours.')
            ->assertSee('Workday (150%) 2h 00m')
            ->assertSee('Adds 2h 00m toward time off in lieu.')
            ->assertSee('Aye Aye Mon reviews it first, then an admin.')
            ->call('submit')
            ->assertDispatched('overtime-saved');

        $this->assertSame(OvertimeStatus::Pending, OvertimeRequest::sole()->status);
    }

    public function test_the_review_splits_a_window_crossing_22_00_with_the_rates(): void
    {
        $this->modal()
            ->set('date', '2026-06-16')->set('start_time', '21:00')->set('end_time', '23:00')
            ->call('review')
            ->assertSee('Workday (150%) 1h 00m · Night (200%) 1h 00m');
    }

    public function test_a_claim_shows_the_punches_on_record_and_the_time_saved(): void
    {
        $this->worked('2026-06-12', '08:02:00', '19:05:00');
        app(DailySummaryBuilder::class)->rebuildAround($this->employee, Carbon::parse('2026-06-12'));

        $this->modal()
            ->set('date', '2026-06-12')->set('start_time', '17:00')->set('end_time', '19:00')->set('compensation', 'time_off')
            ->call('review')
            ->assertSee('This is a claim: the time has already started.')
            ->assertSee('You punched in at 8:02 AM and out at 7:05 PM.');
    }

    public function test_an_overnight_end_is_the_next_day(): void
    {
        $this->modal()
            ->set('date', '2026-06-16')->set('start_time', '22:00')->set('end_time', '00:00')
            ->call('review')
            ->assertSee('Tue 16 Jun, 10:00 PM – 12:00 AM (+1)')
            ->assertSee('Night (200%) 2h 00m');
    }

    public function test_errors_land_on_their_fields(): void
    {
        $this->modal()
            ->call('review')
            ->assertHasErrors(['date', 'start_time', 'end_time']);

        $this->modal()
            ->set('date', '2026-06-16')->set('start_time', '09:00')->set('end_time', '11:00')
            ->call('review')
            ->assertHasErrors('starts_at')
            ->assertSee('This is within normal working hours (8:00 AM – 5:00 PM).')
            ->assertSet('step', 'form');

        // An employee over a limit is refused on the form.
        $this->modal()
            ->set('date', '2026-06-16')->set('start_time', '17:00')->set('end_time', '20:00')
            ->call('review')
            ->assertHasErrors('overtime')
            ->assertSee('This is 3h 00m of overtime; the limit is 2h 00m a day.');
    }

    public function test_time_off_is_offered_only_with_a_toil_type(): void
    {
        $this->modal()->assertSee('Time off in lieu');

        // Before anything is credited the TOIL type can still be unset.
        OvertimeSettings::current()->update(['toil_leave_type_id' => null]);

        $this->modal()->assertDontSee('Time off in lieu');
    }

    public function test_an_admin_files_for_an_employee_and_goes_over_a_limit_with_a_reason(): void
    {
        $modal = Livewire::actingAs($this->admin)->test(RequestModal::class)
            ->call('openForOthers')
            ->set('employeeSearch', 'Kyaw')
            ->assertSee('Kyaw Kyaw')
            ->call('selectEmployee', $this->employee->id)
            ->set('date', '2026-06-16')->set('start_time', '17:00')->set('end_time', '20:00')
            ->call('review')
            ->assertSet('step', 'review')
            ->assertSee('Over the daily limit')
            ->assertSee('This is 3h 00m of overtime; the limit is 2h 00m a day.')
            ->assertSee('Reason to go over the limit')
            ->assertSee('approved immediately');

        $modal->call('submit')->assertHasErrors('limit_override_reason')->assertSet('step', 'review');

        $modal->set('override_reason', 'Month-end close')->call('submit')->assertDispatched('overtime-saved');

        $request = OvertimeRequest::sole();
        $this->assertSame([OvertimeStatus::Approved, 'Month-end close'], [$request->status, $request->limit_override_reason]);
    }
}
