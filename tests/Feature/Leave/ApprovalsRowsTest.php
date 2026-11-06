<?php

namespace Tests\Feature\Leave;

use App\Contracts\Approvable;
use App\Enums\AttendanceStatus;
use App\Livewire\Leave\Approvals;
use App\Models\DailyAttendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveAdjustment;
use App\Models\LeaveEntitlement;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Leave\LeaveRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6j — the Approvals rows: each signal's rule and its boundaries, the
 * order, the type filter, the override section's open/closed rule and the
 * step column. The clock is Thu 5 Nov 2026, 10:00 in Phnom Penh.
 */
class ApprovalsRowsTest extends TestCase
{
    use RefreshDatabase;

    private LeaveType $annual;

    private Department $sales;

    private Employee $manager;

    private Employee $employee;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-05 10:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->withBreakStart()->create(['is_default' => true]);
        $this->annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18, 'carry_over_cap' => null]);
        $this->sales = Department::factory()->create(['name' => 'Sales']);

        $this->manager = $this->person('Manager', 'manager');
        $this->employee = $this->person('Employee', 'employee', $this->manager);
        $this->admin = User::factory()->create(['name' => 'Admin'])->assignRole('admin');
    }

    private function person(string $name, ?string $role, ?Employee $manager = null, ?Department $department = null): Employee
    {
        $user = $role === null ? null : User::factory()->create(['name' => $name])->assignRole($role);

        return Employee::factory()->create(['full_name' => $name, 'user_id' => $user?->id, 'manager_id' => $manager?->id, 'department_id' => ($department ?? $this->sales)->id]);
    }

    private function leave(Employee $employee, string $from, string $to, string $state = 'pending', int $step = 1, ?string $submitted = null): Leave
    {
        $factory = Leave::factory()->between($from, $to);
        $factory = match ($state) {
            'pending' => $factory->pending($step),
            'approved' => $factory->approved(),
            'rejected' => $factory->rejected(),
            'cancelled' => $factory->cancelled(),
        };

        return $factory->create(['employee_id' => $employee->id, 'leave_type_id' => $this->annual->id, 'requested_by' => $employee->user_id, 'created_at' => $submitted ?? now()]);
    }

    private function overtime(string $date, string $from, string $to, string $kind = 'planned', ?Employee $employee = null, ?string $submitted = null): OvertimeRequest
    {
        $employee ??= $this->employee;

        return OvertimeRequest::factory()->{$kind}()->window($date, $from, $to)->pending()
            ->create(['employee_id' => $employee->id, 'requested_by' => $employee->user_id, 'created_at' => $submitted ?? now()]);
    }

    private function page(User $user, array $query = []): Testable
    {
        return Livewire::withQueryParams($query)->actingAs($user)->test(Approvals::class);
    }

    /** Every row the page lists (overrides included, shown or not), keyed "leave-3" / "overtime-4", with its section. */
    private function rows(User $user, array $query = []): array
    {
        $page = $this->page($user, $query);
        $rows = [];

        foreach (['stepOne', 'stepTwo', 'stuckOverrides', 'otherOverrides'] as $section) {
            foreach ($page->viewData($section) as $row) {
                $rows[$row['type'].'-'.$row['request']->id] = $row + ['section' => $section];
            }
        }

        return $rows;
    }

    private function row(User $user, Approvable $request): array
    {
        $key = ($request instanceof OvertimeRequest ? 'overtime' : 'leave').'-'.$request->id;
        $rows = $this->rows($user);
        $this->assertArrayHasKey($key, $rows, "{$key} isn't listed.");
        $this->assertAmberFirst($rows[$key]);

        return $rows[$key];
    }

    /** @return list<string> */
    private function signals(array $row, ?string $tone = null): array
    {
        return collect($row['signals'])->when($tone !== null, fn ($signals) => $signals->where('tone', $tone))->pluck('text')->values()->all();
    }

    private function assertAmberFirst(array $row): void
    {
        $tones = array_column($row['signals'], 'tone');
        $firstMuted = array_search('muted', $tones, true);

        if ($firstMuted !== false) {
            $this->assertNotContains('attention', array_slice($tones, $firstMuted), 'An amber signal comes after a muted one: '.implode(', ', $this->signals($row)));
        }
    }

    // ── Others off ───────────────────────────────────────────────────────

    public function test_others_off_counts_overlap_on_the_first_and_last_day_and_only_pending_or_approved_leave(): void
    {
        $request = $this->leave($this->employee, '2026-11-16', '2026-11-19');
        $this->leave($this->person('Ends on the first day', null, $this->manager), '2026-11-12', '2026-11-16', 'approved');
        $this->leave($this->person('Starts on the last day', null, $this->manager), '2026-11-19', '2026-11-20', 'pending');
        $this->leave($this->person('Ends the day before', null, $this->manager), '2026-11-11', '2026-11-13', 'approved');
        $this->leave($this->person('Starts the day after', null, $this->manager), '2026-11-20', '2026-11-20', 'approved');
        $this->leave($this->person('Rejected', null, $this->manager), '2026-11-16', '2026-11-19', 'rejected');
        $this->leave($this->person('Cancelled', null, $this->manager), '2026-11-16', '2026-11-19', 'cancelled');
        $this->leave($this->person('Other department', null, $this->manager, Department::factory()->create()), '2026-11-16', '2026-11-19', 'approved');

        $row = $this->row($this->manager->user, $request);

        $this->assertContains('2 others off', $this->signals($row, 'attention'));
        $alsoOff = collect($row['details'])->firstWhere('label', 'Also off')['lines'];
        $this->assertSame('2 others in Sales during these dates:', $alsoOff[0]);
        $this->assertStringStartsWith('Ends on the first day (Annual, Thu 12 – Mon 16 Nov, approved)', $alsoOff[1]);
        $this->assertStringStartsWith('Starts on the last day (Annual, Thu 19 – Fri 20 Nov, pending)', $alsoOff[2]);
        $this->assertCount(3, $alsoOff);
    }

    public function test_one_other_off_is_singular_and_a_rejected_overlap_alone_is_no_signal(): void
    {
        $request = $this->leave($this->employee, '2026-11-16', '2026-11-16');
        $colleague = $this->person('Colleague', null, $this->manager);
        $rejected = $this->leave($colleague, '2026-11-16', '2026-11-16', 'rejected');

        $this->assertSame([], $this->signals($this->row($this->manager->user, $request), 'attention'));

        $rejected->delete();
        $this->leave($colleague, '2026-11-16', '2026-11-16', 'pending');

        $this->assertContains('1 other off', $this->signals($this->row($this->manager->user, $request), 'attention'));
    }

    public function test_overtime_has_no_others_off(): void
    {
        $request = $this->overtime('2026-11-16', '17:00', '19:00');
        $this->leave($this->person('Colleague', null, $this->manager), '2026-11-16', '2026-11-16', 'approved');

        $row = $this->row($this->manager->user, $request);

        $this->assertSame([], $this->signals($row, 'attention'));
        $this->assertNull(collect($row['details'])->firstWhere('label', 'Also off'));
    }

    // ── Starts today / tomorrow ──────────────────────────────────────────

    public function test_starts_tomorrow_becomes_starts_today_at_phnom_penh_midnight(): void
    {
        $this->assertSame('Asia/Phnom_Penh', config('app.timezone'));
        $leave = $this->leave($this->employee, '2026-11-06', '2026-11-06');
        $overtime = $this->overtime('2026-11-06', '17:00', '19:00');

        $this->travelTo(Carbon::parse('2026-11-05 23:59:59'));
        $this->assertContains('Starts tomorrow', $this->signals($this->row($this->manager->user, $leave), 'attention'));
        $this->assertContains('Starts tomorrow', $this->signals($this->row($this->manager->user, $overtime), 'attention'));

        // 17:00 UTC is midnight in Phnom Penh (UTC+7).
        $this->travelTo(Carbon::parse('2026-11-05 17:00:00', 'UTC'));
        $this->assertContains('Starts today', $this->signals($this->row($this->manager->user, $leave), 'attention'));
        $this->assertContains('Starts today', $this->signals($this->row($this->manager->user, $overtime), 'attention'));
    }

    public function test_a_later_start_has_no_start_chip_and_planned_says_nothing(): void
    {
        $leave = $this->leave($this->employee, '2026-11-09', '2026-11-09');
        $overtime = $this->overtime('2026-11-07', '09:00', '11:00');

        $this->assertSame([], $this->signals($this->row($this->manager->user, $leave), 'attention'));
        $this->assertSame([], $this->signals($this->row($this->manager->user, $overtime)));
    }

    public function test_leave_under_way_says_when_it_started_and_one_already_over_is_taken(): void
    {
        $started = $this->leave($this->employee, '2026-11-04', '2026-11-09');
        $taken = $this->leave($this->person('Taker', 'employee', $this->manager), '2026-11-02', '2026-11-03');

        $this->assertContains('Started Wed 4 Nov', $this->signals($this->row($this->manager->user, $started), 'attention'));
        $row = $this->row($this->manager->user, $taken);
        $this->assertContains('Already taken', $this->signals($row, 'muted'));
        $this->assertSame([], $this->signals($row, 'attention'));
    }

    // ── Over limit ───────────────────────────────────────────────────────

    public function test_over_the_daily_limit_reads_over_limit_for_an_admin_and_override_needed_for_a_manager(): void
    {
        $over = $this->overtime('2026-11-11', '17:00', '20:00');
        $within = $this->overtime('2026-11-12', '17:00', '19:00');

        $this->assertContains('Override needed', $this->signals($this->row($this->manager->user, $over), 'attention'));
        $this->assertNotContains('Over limit', $this->signals($this->row($this->manager->user, $over)));
        $admin = $this->row($this->admin, $over);
        $this->assertContains('Over limit', $this->signals($admin, 'attention'));
        $this->assertSame([
            'This is 3h 00m of overtime; the limit is 2h 00m a day.',
            'With 8h 00m of scheduled work this makes 11h 00m; the limit is 10h 00m a day.',
        ], collect($admin['details'])->firstWhere('label', 'Daily limit')['lines']);

        $this->assertSame([], $this->signals($this->row($this->manager->user, $within), 'attention'));
    }

    // ── Balance ──────────────────────────────────────────────────────────

    public function test_the_balance_left_and_below_zero(): void
    {
        $request = $this->leave($this->employee, '2026-11-16', '2026-11-18');

        $row = $this->row($this->manager->user, $request);
        $this->assertContains('15 days left', $this->signals($row, 'muted'));
        $this->assertNotContains('Balance below 0', $this->signals($row));
        $this->assertSame(['Annual available after this request: 15 days'], collect($row['details'])->firstWhere('label', 'Balance')['lines']);

        LeaveAdjustment::factory()->create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->annual->id, 'days' => -16]);

        $row = $this->row($this->manager->user, $request);
        $this->assertContains('Balance below 0', $this->signals($row, 'attention'));
        $this->assertContains('-1 day left', $this->signals($row, 'muted'));
    }

    public function test_a_leave_across_two_years_shows_what_is_left_in_each(): void
    {
        LeaveEntitlement::factory()->forYear(2027)->create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->annual->id, 'days' => 18]);
        // Mon 28 – Thu 31 Dec 2026: 4 days; Fri 1 – Tue 5 Jan 2027: 3.
        $request = $this->leave($this->employee, '2026-12-28', '2027-01-05');

        $row = $this->row($this->manager->user, $request);

        $this->assertContains('2026: 14 · 2027: 15 left', $this->signals($row, 'muted'));
        $this->assertSame('Mon 28 Dec 2026 – Tue 5 Jan 2027', $row['when']);
        $this->assertSame(['Annual available after this request: 2026: 14 days · 2027: 15 days'], collect($row['details'])->firstWhere('label', 'Balance')['lines']);
    }

    public function test_a_type_without_a_balance_has_no_balance_chip(): void
    {
        $unpaid = LeaveType::factory()->withoutBalance()->create(['name' => 'Unpaid']);
        $request = Leave::factory()->between('2026-11-16', '2026-11-16')->pending()->create(['employee_id' => $this->employee->id, 'leave_type_id' => $unpaid->id]);

        $row = $this->row($this->manager->user, $request);

        $this->assertSame([], $this->signals($row));
        $this->assertSame(['Unpaid has no balance to draw from.'], collect($row['details'])->firstWhere('label', 'Balance')['lines']);
    }

    // ── Punches and kind ─────────────────────────────────────────────────

    public function test_a_claim_says_what_its_punches_show(): void
    {
        $none = $this->overtime('2026-11-03', '17:00', '19:00', 'claim');
        $inOnly = $this->overtime('2026-11-03', '17:00', '19:00', 'claim', $this->person('In only', 'employee', $this->manager));
        $short = $this->overtime('2026-11-03', '17:00', '19:00', 'claim', $this->person('Short', 'employee', $this->manager));
        $full = $this->overtime('2026-11-03', '17:00', '19:00', 'claim', $this->person('Full', 'employee', $this->manager));
        $day = fn (OvertimeRequest $request, ?string $out) => DailyAttendance::factory()->for($request->employee)->create([
            'work_date' => '2026-11-03', 'status' => $out === null ? AttendanceStatus::Incomplete : AttendanceStatus::Present,
            'first_in' => '2026-11-03 08:00:00', 'last_out' => $out,
        ]);
        $day($inOnly, null);
        $day($short, '2026-11-03 18:00:00');
        $day($full, '2026-11-03 19:05:00');

        $this->assertSame(['No punches yet', 'Claim'], $this->signals($this->row($this->manager->user, $none)));
        $this->assertSame(['No out-punch yet', 'Claim'], $this->signals($this->row($this->manager->user, $inOnly)));
        $this->assertSame(['Punches: 1h 00m of 2h 00m', 'Claim'], $this->signals($this->row($this->manager->user, $short)));
        $this->assertSame(['Claim'], $this->signals($this->row($this->manager->user, $full)));
    }

    public function test_a_planned_request_whose_date_has_passed_is_already_worked(): void
    {
        $request = $this->overtime('2026-11-04', '17:00', '19:00', 'planned');

        $this->assertContains('Already worked', $this->signals($this->row($this->manager->user, $request), 'muted'));
    }

    // ── When ─────────────────────────────────────────────────────────────

    public function test_when_names_the_dates_and_the_window_and_how_long(): void
    {
        $leave = $this->leave($this->employee, '2026-11-16', '2026-11-19');
        $half = Leave::factory()->between('2026-11-23', '2026-11-23')->halfDay('am')->pending()->create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->annual->id]);
        $evening = $this->overtime('2026-11-10', '17:00', '19:00');
        $overnight = $this->overtime('2026-11-12', '21:00', '01:00', 'planned', $this->person('Night', 'employee', $this->manager));

        $row = $this->row($this->manager->user, $leave);
        $this->assertSame(['Mon 16 – Thu 19 Nov', null, '4 days · Annual'], [$row['when'], $row['whenTime'], $row['whenSub']]);
        $row = $this->row($this->manager->user, $half);
        $this->assertSame(['Mon 23 Nov', 'Half day AM · Annual'], [$row['when'], $row['whenSub']]);
        $row = $this->row($this->manager->user, $evening);
        $this->assertSame(['Tue 10 Nov', '5:00 – 7:00 PM', '2h 00m · Workday 150% · Pay'], [$row['when'], $row['whenTime'], $row['whenSub']]);
        $row = $this->row($this->manager->user, $overnight);
        $this->assertSame(['Thu 12 Nov', '9:00 PM – 1:00 AM (+1)', '4h 00m · Workday 150% + Night 200% · Pay'], [$row['when'], $row['whenTime'], $row['whenSub']]);

        $this->page($this->manager->user)->assertSeeHtml('<span class="whitespace-nowrap">9:00 PM – 1:00 AM (+1)</span>');
    }

    /** The window and the dates keep the rows' forms off the rows too: details, the dialog's title, the notices. */
    public function test_details_dialog_and_notices_use_the_rows_forms(): void
    {
        $half = Leave::factory()->between('2026-11-23', '2026-11-23')->halfDay('am')->pending()->create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->annual->id]);
        $overnight = $this->overtime('2026-11-12', '21:00', '01:00', 'planned', $this->person('Night', 'employee', $this->manager));

        $this->assertSame('Annual · Mon 23 Nov · AM · 0.5 day', collect($this->row($this->manager->user, $half)['details'])->firstWhere('label', 'Requested')['lines'][0]);
        $this->assertSame('Thu 12 Nov, 9:00 PM – 1:00 AM (+1) · Planned · Pay', collect($this->row($this->manager->user, $overnight)['details'])->firstWhere('label', 'Requested')['lines'][0]);

        $this->page($this->manager->user)
            ->call('openDecision', $overnight->id, 'approve', 'overtime', 1)
            ->assertSee('overtime on Thu 12 Nov, 9:00 PM – 1:00 AM (+1)')
            ->call('decide')
            ->assertSee("Approved Night's overtime on Thu 12 Nov, 9:00 PM – 1:00 AM (+1) — it now waits for an admin.");
    }

    public function test_time_off_has_no_rates(): void
    {
        $request = OvertimeRequest::factory()->planned()->timeOff()->window('2026-11-12', '21:00', '23:00')->pending()->create(['employee_id' => $this->employee->id]);

        $this->assertSame('2h 00m · Time off', $this->row($this->manager->user, $request)['whenSub']);
    }

    // ── Order ────────────────────────────────────────────────────────────

    public function test_soonest_start_first_and_ties_by_submission(): void
    {
        $later = $this->leave($this->employee, '2026-11-16', '2026-11-16', submitted: '2026-11-05 09:00:00');
        $tieFirst = $this->overtime('2026-11-16', '17:00', '19:00', submitted: '2026-11-05 08:00:00');
        $soonest = $this->leave($this->person('Soonest', 'employee', $this->manager), '2026-11-10', '2026-11-10', submitted: '2026-11-05 09:30:00');

        $this->assertSame(
            ['leave-'.$soonest->id, 'overtime-'.$tieFirst->id, 'leave-'.$later->id],
            array_keys($this->rows($this->manager->user)),
        );
    }

    // ── Filter chips ─────────────────────────────────────────────────────

    public function test_the_type_filter_lists_one_type_and_the_badge_still_counts_the_queue(): void
    {
        $leave = $this->leave($this->employee, '2026-11-16', '2026-11-16');
        $this->leave($this->person('Second', 'employee', $this->manager), '2026-11-17', '2026-11-17');
        $overtime = $this->overtime('2026-11-16', '17:00', '19:00');

        $this->assertSame(['overtime-'.$overtime->id], array_keys($this->rows($this->manager->user, ['type' => 'overtime'])));

        $page = $this->page($this->manager->user, ['type' => 'overtime'])
            ->assertSet('typeFilter', 'overtime')
            ->assertViewHas('typeCounts', ['all' => 3, 'leave' => 2, 'overtime' => 1])
            ->assertDispatched('approval-badge', count: 3)
            ->assertSeeHtml('aria-pressed="true"');
        $this->assertCount(1, $page->viewData('stepOne'));

        $page->call('filterBy', 'leave')->assertSet('typeFilter', 'leave')->assertDispatched('approval-badge', count: 3);
        $this->assertSame(['leave-'.$leave->id], array_slice(array_map(fn ($row) => 'leave-'.$row['request']->id, $page->viewData('stepOne')), 0, 1));
        $this->assertCount(2, $page->viewData('stepOne'));

        $page->call('filterBy', 'sideways')->assertSet('typeFilter', 'all');
        $this->assertCount(3, $page->viewData('stepOne'));
    }

    public function test_an_unknown_type_in_the_url_shows_everything(): void
    {
        $this->leave($this->employee, '2026-11-16', '2026-11-16');

        $this->page($this->manager->user, ['type' => 'sideways'])->assertSet('typeFilter', 'all');
        $this->actingAs($this->manager->user)->get(route('approvals.index', ['type' => ['x']]))->assertOk();
    }

    public function test_a_filter_that_leaves_nothing_says_what_the_other_type_has(): void
    {
        $this->leave($this->employee, '2026-11-16', '2026-11-16');
        $this->leave($this->person('Second', 'employee', $this->manager), '2026-11-17', '2026-11-17');

        $this->page($this->manager->user, ['type' => 'overtime'])
            ->assertSee('No overtime requests waiting')
            ->assertSee('2 leave requests are waiting.')
            ->assertSee('Show all')
            ->call('filterBy', 'all')
            ->assertDontSee('No overtime requests waiting');
    }

    // ── You can override ─────────────────────────────────────────────────

    public function test_overrides_are_closed_when_nothing_is_stuck(): void
    {
        $far = $this->leave($this->employee, '2026-12-14', '2026-12-14');

        $page = $this->page($this->admin)
            ->assertSee('You can override')
            ->assertSee('Show 1 waiting for managers')
            ->assertDontSeeHtml('wire:key="approval-leave-'.$far->id.'"');
        $this->assertSame([], $page->viewData('stuckOverrides'));

        $page->set('showOverrides', true)
            ->assertSeeHtml('wire:key="approval-leave-'.$far->id.'"')
            ->assertSee('Hide the 1 waiting for managers');
    }

    public function test_overrides_open_with_stuck_items_first_and_the_rest_behind_n_more(): void
    {
        $far = $this->leave($this->employee, '2026-12-14', '2026-12-14');
        $tomorrow = $this->leave($this->person('Soon', 'employee', $this->manager), '2026-11-06', '2026-11-06');

        $page = $this->page($this->admin)
            ->assertSee('1 stuck · 2 in all')
            ->assertSeeHtml('wire:key="approval-leave-'.$tomorrow->id.'"')
            ->assertDontSeeHtml('wire:key="approval-leave-'.$far->id.'"')
            ->assertSee('1 more waiting for managers');
        $this->assertContains('Stuck', $this->signals($page->viewData('stuckOverrides')[0], 'attention'));

        $page->set('showOverrides', true)
            ->assertSeeHtmlInOrder(['wire:key="approval-leave-'.$tomorrow->id.'"', 'wire:key="approval-leave-'.$far->id.'"']);
    }

    // ── Step column ──────────────────────────────────────────────────────

    public function test_the_step_column_follows_the_section(): void
    {
        $service = app(LeaveRequestService::class);
        $mine = $this->leave($this->employee, '2026-11-16', '2026-11-16');
        $approver = $this->person('Approved', 'employee', $this->manager);
        $approved = $service->submit($approver, $this->annual, Carbon::parse('2026-11-17'), Carbon::parse('2026-11-17'), null, null, $approver->user)['leave'];
        $this->assertSame(1, $approved->current_step);
        $service->approve($approved, $this->manager->user, null);
        $loner = $this->person('Loner', 'employee');
        $skipped = $service->submit($loner, $this->annual, Carbon::parse('2026-11-18'), Carbon::parse('2026-11-18'), null, null, $loner->user)['leave'];
        $this->assertSame(2, $skipped->current_step);

        // A manager's own queue: the section says it, the column is empty.
        $this->assertNull($this->row($this->manager->user, $mine)['step']['text']);
        $this->page($this->manager->user)->assertDontSee('Waiting for manager');

        $admin = $this->rows($this->admin);
        $this->assertSame(['done' => true, 'text' => 'Manager', 'by' => 'Manager'], $admin['leave-'.$approved->id]['step']);
        $this->assertSame('Manager step skipped', $admin['leave-'.$skipped->id]['step']['text']);
        $this->assertSame('Manager: Manager', $admin['leave-'.$mine->id]['step']['text']);

        // The manager away: the override says until when.
        Leave::factory()->approved()->between('2026-11-05', '2026-11-06')->create(['employee_id' => $this->manager->id, 'leave_type_id' => $this->annual->id]);
        $this->assertSame('Manager: Manager · away until Fri 6 Nov', $this->rows($this->admin)['leave-'.$mine->id]['step']['text']);
    }

    public function test_details_keep_the_reason_history_and_submission(): void
    {
        $service = app(LeaveRequestService::class);
        $leave = $service->submit($this->employee, $this->annual, Carbon::parse('2026-11-16'), Carbon::parse('2026-11-17'), null, 'Family wedding', $this->employee->user)['leave'];
        $service->approve($leave, $this->manager->user, 'Fine by me');

        $row = $this->row($this->admin, $leave);
        $details = collect($row['details'])->pluck('lines', 'label');

        $this->assertSame('Family wedding', $row['reason']);
        $this->assertSame(['“Family wedding”'], $details['Reason']);
        $this->assertSame(['Annual · Mon 16 – Tue 17 Nov · 2 days', 'Submitted Thu 5 Nov'], $details['Requested']);
        $this->assertSame(['Step 1: approved by Manager · Thu 5 Nov — “Fine by me”'], $details['History']);

        $this->page($this->admin)
            ->assertSeeHtml('aria-controls="details-leave-'.$leave->id.'"')
            ->assertSeeHtml('id="details-leave-'.$leave->id.'"')
            ->assertSeeHtml('aria-expanded="false"');
    }
}
