<?php

namespace Tests\Feature\Leave;

use App\Enums\AttendanceStatus;
use App\Models\DailyAttendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Approval\ApprovalInbox;
use App\Support\WorkdayCalendar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6c (F03) — what the approvals badge and the Approvals page cost: a
 * fixed number of queries however many requests wait, and the same count as
 * before. The clock is Thu 5 Nov 2026 10:00; Fri 30 Oct, Mon 9 and Tue 10
 * Nov are holidays.
 *
 * The requests come in a cycle of six (pending()):
 *  1. leave at step 1 from Wed 11 Nov, submitted yesterday — stuck by its
 *     start only because of the holidays (the second working day before it
 *     is today, Thu 5; without them, Mon 9);
 *  2. an overtime claim at step 1 for Wed 28 Oct, submitted Thu 29 Oct —
 *     stuck: two working days have begun since (Mon 2, Tue 3), the holiday
 *     and the weekend skipped;
 *  3. leave at step 1 from Mon 23 Nov, submitted today — not stuck;
 *  4. planned overtime at step 1 on Mon 16 Nov, submitted today — stuck
 *     because its manager is away until Fri 13 Nov, back Mon 16, after its
 *     deadline (Thu 12);
 *  5. leave at step 2; 6. planned overtime at step 2.
 * So an admin with no employee record waits on 5 of every 6: steps 2 and
 * the three stuck overrides.
 */
class ApprovalsQueryCostTest extends TestCase
{
    use RefreshDatabase;

    private LeaveType $annual;

    private Department $sales;

    private Employee $present;

    private Employee $away;

    private User $admin;

    private int $made = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-05 10:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->withBreakStart()->create(['is_default' => true]);
        $this->annual = LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18]);
        LeaveType::factory()->deductsFrom($this->annual)->create(['name' => 'Special']);
        $this->sales = Department::factory()->create(['name' => 'Sales']);

        foreach (['2026-10-30', '2026-11-09', '2026-11-10'] as $date) {
            Holiday::factory()->create(['date' => $date]);
        }

        $this->present = $this->person('Present Manager', 'manager');
        $this->away = $this->person('Away Manager', 'manager');
        // Away Thu 5 – Fri 13 Nov: back Mon 16.
        Leave::factory()->approved()->between('2026-11-05', '2026-11-13')->create(['employee_id' => $this->away->id, 'leave_type_id' => $this->annual->id]);
        $this->admin = User::factory()->create(['name' => 'Admin'])->assignRole('admin');
    }

    private function person(string $name, ?string $role, ?Employee $manager = null): Employee
    {
        $user = $role === null ? null : User::factory()->create(['name' => $name])->assignRole($role);

        return Employee::factory()->create(['full_name' => $name, 'user_id' => $user?->id, 'manager_id' => $manager?->id, 'department_id' => $this->sales->id]);
    }

    /** $count more pending requests, continuing the cycle of six in the class comment. */
    private function pending(int $count): void
    {
        for ($i = 0; $i < $count; $i++, $this->made++) {
            $pattern = $this->made % 6;
            $manager = in_array($pattern, [1, 3], true) ? $this->away : $this->present;
            $employee = $this->person("Requester {$this->made}", 'employee', $manager);
            $leave = fn (string $from, string $to, int $step, string $submitted) => Leave::factory()->pending($step)->between($from, $to)
                ->create(['employee_id' => $employee->id, 'leave_type_id' => $this->annual->id, 'requested_by' => $employee->user_id, 'created_at' => $submitted]);
            $overtime = fn ($factory, int $step, string $submitted) => $factory->pending($step)
                ->create(['employee_id' => $employee->id, 'requested_by' => $employee->user_id, 'created_at' => $submitted]);

            match ($pattern) {
                0 => $leave('2026-11-11', '2026-11-12', 1, '2026-11-04 09:00:00'),
                1 => $overtime(OvertimeRequest::factory()->claim()->window('2026-10-28', '17:00', '19:00'), 1, '2026-10-29 09:00:00'),
                2 => $leave('2026-11-23', '2026-11-24', 1, '2026-11-05 08:00:00'),
                3 => $overtime(OvertimeRequest::factory()->planned()->window('2026-11-16', '18:00', '20:00'), 1, '2026-11-05 08:00:00'),
                4 => $leave('2026-11-18', '2026-11-18', 2, '2026-11-02 09:00:00'),
                5 => $overtime(OvertimeRequest::factory()->planned()->window('2026-11-19', '18:00', '20:00'), 2, '2026-11-02 09:00:00'),
            };

            // The claim's day has punches, so the Approvals card computes what they'd credit.
            if ($pattern === 1) {
                DailyAttendance::factory()->for($employee)->create([
                    'work_date' => '2026-10-28', 'status' => AttendanceStatus::Present,
                    'first_in' => '2026-10-28 07:58:00', 'last_out' => '2026-10-28 18:30:00',
                ]);
            }
        }
    }

    /** The queries one page load runs, every memo the earlier loads left cold again. */
    private function queries(User $user, string $url): int
    {
        Employee::forgetSubordinateIds();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get($url)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * The queries $url runs for $user with 3, 6 and 30 requests waiting (the
     * cycle above). From 6 — the smallest set with every kind of request — to
     * 30 the count must be exactly the same. 3 may cost a few less, never
     * more: three requests can't include every kind, and a relation of a
     * kind that isn't there (step 2's, say) isn't loaded at all.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function costAt3And6And30(User $user, string $url): array
    {
        $this->pending(3);
        $this->queries($user, $url);
        $three = $this->queries($user, $url);
        $this->pending(3);
        $six = $this->queries($user, $url);
        $this->pending(24);
        $thirty = $this->queries($user, $url);

        $this->assertLessThanOrEqual($six, $three, "{$url} ran {$three} queries for 3 requests and {$six} for 6.");
        $this->assertSame($six, $thirty, "{$url} ran {$six} queries for 6 requests and {$thirty} for 30.");

        return [$three, $six, $thirty];
    }

    public function test_the_layout_badge_costs_the_same_for_3_or_30_requests(): void
    {
        $this->costAt3And6And30($this->admin, route('profile.edit'));

        // 30 requests: five cycles of six, five of each six waiting on the admin.
        $this->actingAs($this->admin)->get(route('profile.edit'))->assertSeeHtml('aria-label="25 waiting"');
    }

    public function test_the_layout_badge_costs_the_same_for_a_manager(): void
    {
        $this->costAt3And6And30($this->present->user, route('profile.edit'));

        // Their own team's step-1 requests: two of every six.
        $this->actingAs($this->present->user)->get(route('profile.edit'))->assertSeeHtml('aria-label="10 waiting"');
    }

    public function test_the_badge_is_computed_once_per_request(): void
    {
        $this->pending(6);

        DB::flushQueryLog();
        DB::enableQueryLog();
        // The sidebar item, the menu button's dot and the dashboard card all show it.
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertSeeHtml('aria-label="5 waiting"')
            ->assertSee('Open sidebar — 5 approvals waiting');
        DB::disableQueryLog();

        $inboxLoads = collect(DB::getQueryLog())->filter(fn (array $query) => str_starts_with($query['query'], 'select * from `overtime_requests` where `status` = ?'));
        // One inbox: its step-2 query and its overrides query.
        $this->assertCount(2, $inboxLoads);
    }

    public function test_approvals_costs_the_same_for_3_or_30_requests_for_an_admin(): void
    {
        $this->costAt3And6And30($this->admin, route('approvals.index'));
    }

    public function test_approvals_costs_the_same_for_3_or_30_requests_for_a_manager(): void
    {
        $this->costAt3And6And30($this->present->user, route('approvals.index'));
    }

    public function test_the_approvals_page_and_its_badge_show_the_same_count(): void
    {
        $this->pending(12);

        $this->actingAs($this->admin)->get(route('approvals.index'))->assertOk()
            ->assertSeeHtml('aria-label="10 waiting"')
            ->assertSee('Open sidebar — 10 approvals waiting');
        $this->assertSame(10, ApprovalInbox::count($this->admin));
    }

    public function test_badge_values_for_a_fixed_set(): void
    {
        $this->pending(6);

        $this->assertSame(5, ApprovalInbox::count($this->admin));
        // Each manager's own team at step 1: requests 1 and 3 for the present
        // manager, 2 and 4 for the away one — stuck or not.
        $this->assertSame(2, ApprovalInbox::count($this->present->user));
        $this->assertSame(2, ApprovalInbox::count($this->away->user));
        $this->assertSame(0, ApprovalInbox::count(Employee::query()->where('full_name', 'Requester 0')->first()->user));

        // The badge reads the same count, on and off the Approvals page.
        $this->actingAs($this->admin)->get(route('dashboard'))->assertSeeHtml('aria-label="5 waiting"');
        $this->actingAs($this->present->user)->get(route('dashboard'))->assertSeeHtml('aria-label="2 waiting"');
    }

    public function test_stuck_follows_working_days_across_a_weekend_and_a_holiday(): void
    {
        $this->pending(6);
        $overrides = ApprovalInbox::for($this->admin)['overrides'];
        [, $status] = ApprovalInbox::withStatus($this->admin);
        $find = fn (string $name) => $overrides->first(fn ($item) => $item->employee->full_name === $name);

        // Stuck first, each half in date order: the claim (28 Oct), the leave
        // (11 Nov), the planned overtime (16 Nov); then the leave not stuck.
        $this->assertSame(['Requester 1', 'Requester 0', 'Requester 3', 'Requester 2'], $overrides->map(fn ($item) => $item->employee->full_name)->all());
        $this->assertSame([true, true, true, false], $overrides->map(fn ($item) => $status[ApprovalInbox::key($item)]['stuck'])->all());

        // The claim was submitted Thu 29 Oct; Fri 30 is a holiday and then a
        // weekend, so its second working day is Tue 3 Nov.
        $claim = $find('Requester 1');
        $this->assertFalse(ApprovalInbox::isStuck($claim, Carbon::parse('2026-11-02')));
        $this->assertTrue(ApprovalInbox::isStuck($claim, Carbon::parse('2026-11-03')));

        // The leave starting Wed 11 Nov: two working days before it, past Tue
        // 10 and Mon 9 (holidays) and the weekend, is today.
        $leave = $find('Requester 0');
        $this->assertFalse(ApprovalInbox::isStuck($leave, Carbon::parse('2026-11-04')));
        $this->assertTrue(ApprovalInbox::isStuck($leave));

        // Its manager is away, but back (Mon 16) after the planned overtime's deadline.
        $planned = $find('Requester 3');
        $this->assertSame('Away Manager (manager) is on leave until Fri 13 Nov', ApprovalInbox::awayReason($planned));
        $this->assertSame('Away Manager (manager) is on leave until Fri 13 Nov', $status[ApprovalInbox::key($planned)]['awayReason']);
        $this->assertTrue(ApprovalInbox::isStuck($planned));
        $this->assertNull(ApprovalInbox::awayReason($find('Requester 2')));

        // One request on its own and in the batch: the same answer.
        foreach ($overrides as $item) {
            $this->assertSame(ApprovalInbox::isStuck($item), $status[ApprovalInbox::key($item)]['stuck']);
        }

        // Without the holidays, the leave isn't stuck until Mon 9: the walk really reads them.
        Holiday::query()->delete();
        $this->assertFalse(ApprovalInbox::isStuck($leave->fresh()));
    }

    public function test_a_preloaded_holiday_range_still_answers_outside_itself(): void
    {
        $range = WorkdayCalendar::preload(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-07'));

        $this->assertFalse($range->has(Carbon::parse('2026-11-05')));
        // Outside the range: looked up, not assumed a working day.
        $this->assertTrue($range->has(Carbon::parse('2026-11-09')));
        $this->assertTrue($range->has(Carbon::parse('2026-10-30')));
        $this->assertFalse($range->has(Carbon::parse('2026-11-11')));
    }

    public function test_the_approvals_page_loads_the_inbox_once_for_the_list_and_the_badge(): void
    {
        $this->pending(6);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->admin)->get(route('approvals.index'))->assertOk()->assertSeeHtml('aria-label="5 waiting"');
        DB::disableQueryLog();

        // The page counted the list it shows (ApprovalBadge::remember()); the
        // sidebar and the menu button didn't load a second inbox.
        $inboxLoads = collect(DB::getQueryLog())->filter(fn (array $query) => str_starts_with($query['query'], 'select * from `overtime_requests` where `status` = ?'));
        $this->assertCount(2, $inboxLoads);
    }
}
