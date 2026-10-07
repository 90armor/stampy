<?php

namespace Database\Seeders;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveHalf;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveGranter;
use App\Services\Leave\LeaveRequestService;
use App\Support\WorkdayCalendar;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;

/**
 * DEMO DATA — leave requests in every state the Time off and Approvals pages
 * show, at dates relative to today (like HolidaySeeder's demo slots), so
 * whenever migrate:fresh --seed runs there is something on each screen.
 *
 * One pending request starts on the next working day, so an admin's
 * Approvals page has a stuck override (requests submitted at seed time are
 * otherwise too new to be stuck).
 *
 * Everything goes through LeaveRequestService — submit, approve, reject,
 * cancel — so the approval steps, balances and the rebuild of approved days
 * are exactly what the app itself would produce; nothing is written to
 * leaves or approval_steps directly. Runs after attendance:build-daily
 * (DatabaseSeeder), because a past leave is placed by what that day was:
 * full-day leave on a day the employee was absent (so it reads as leave, not
 * as worked on leave), the one worked-on-leave day on a day they punched.
 *
 * The named employees joined in 2022–2024, so they hold an Annual balance.
 * A scenario whose day can't be found (a seed run on a weekend with no
 * punches today, say) is skipped with a note, never forced.
 */
class LeaveSeeder extends Seeder
{
    private LeaveRequestService $service;

    private User $admin;

    private LeaveType $annual;

    public function run(): void
    {
        $this->service = app(LeaveRequestService::class);
        $this->admin = User::role('admin')->orderBy('id')->firstOrFail();
        $this->annual = LeaveType::where('name', 'Annual')->firstOrFail();
        $special = LeaveType::where('name', 'Special')->firstOrFail();

        $today = Carbon::today();

        // Late in the year the requests below reach into next year, which
        // needs next year's grants — given early, as `leave:grant --year=`
        // would. The cross-year example grants its own either way.
        if ($today->copy()->addDays(40)->year > $today->year) {
            Employee::query()->activeOn($today)->get()
                ->each(fn (Employee $employee) => app(LeaveGranter::class)->grant($employee, $today->year + 1, $today));
        }

        // Kyaw Kyaw Naing (EMP-0002, the seeded employee login; manager Aye
        // Aye Mon): a full history on Time off.
        $kyaw = $this->employee('EMP-0002');
        if ($day = $this->pastDay($kyaw, AttendanceStatus::Absent)) {
            $this->approved($kyaw, $this->annual, $day, $day, null, 'Doctor’s appointment');
        }
        $this->approved($kyaw, $this->annual, $this->workday($kyaw, $today->copy()->addDays(9)), null, LeaveHalf::Am, 'Bank errand');
        $this->pending($kyaw, $this->annual, $this->workday($kyaw, $today->copy()->addDays(14)), 2, 'Family visit');
        $rejected = $this->submit($kyaw, $this->annual, $this->workday($kyaw, $today->copy()->addDays(5)), null, null, 'Long weekend');
        $this->service->reject($rejected, $this->user('EMP-0001'), 'Release week — could you move it to the week after?');
        $this->cancelled($kyaw, $this->annual, $this->workday($kyaw, $today->copy()->addDays(21)), 1);

        // Moe Moe Khaing (EMP-0009; manager Htet Htet Oo): waiting for admin.
        $moe = $this->employee('EMP-0009');
        $leave = $this->pending($moe, $this->annual, $this->workday($moe, $today->copy()->addDays(10)), 3, 'Trip to Siem Reap');
        $this->service->approve($leave, $this->user('EMP-0006'), 'Covered by Thura');

        // Kaung Kaung Htun (EMP-0012): across New Year, against next year's
        // balance too — granted early, as `leave:grant --year=` would.
        $kaung = $this->employee('EMP-0012');
        app(LeaveGranter::class)->grant($kaung, $today->year + 1, $today);
        // From the last week of December to the first workday from 5 Jan, so
        // both years are charged (1 Jan is a holiday, and the 2nd may be a weekend).
        $this->submit($kaung, $this->annual, $this->workday($kaung, Carbon::create($today->year, 12, 28)), $this->workday($kaung, Carbon::create($today->year + 1, 1, 5)), null, 'New Year with family');

        // Hnin Hnin Wai (EMP-0017): waiting at step 1 for a leave that starts
        // on the next working day — stuck (ApprovalInbox::isStuck()), so it's
        // on an admin's badge and first among the overrides.
        $hnin = $this->employee('EMP-0017');
        $this->submit($hnin, $this->annual, $this->workday($hnin, $today->copy()->addDay()), null, null, 'Child’s school event');

        // Su Myat Noe (EMP-0019): Special, deducted from Annual.
        $suMyat = $this->employee('EMP-0019');
        $this->approved($suMyat, $special, $this->workday($suMyat, $today->copy()->addDays(17)), 1, null, 'Sister’s wedding');

        // Shwe Yi Aung (EMP-0025; manager Ohnmar Kyaw): a morning and an
        // afternoon on the same date, and a past afternoon on a worked day.
        $shwe = $this->employee('EMP-0025');
        $both = $this->workday($shwe, $today->copy()->addDays(12));
        $this->approved($shwe, $this->annual, $both, null, LeaveHalf::Am, 'Moving house');
        $this->approved($shwe, $this->annual, $both, null, LeaveHalf::Pm, 'Moving house');
        if ($day = $this->pastDay($shwe, AttendanceStatus::Present)) {
            $this->approved($shwe, $this->annual, $day, null, LeaveHalf::Pm, 'School meeting');
        }

        // Employees with no login — an admin files for them, approved on
        // submit: a past day of leave on a day one of them was absent.
        foreach (['EMP-0003', 'EMP-0005', 'EMP-0008', 'EMP-0018'] as $code) {
            $noLogin = $this->employee($code);
            if ($day = $this->pastDay($noLogin, AttendanceStatus::Absent)) {
                $this->filed($noLogin, $day, $day, 'Sick child');
                break;
            }
        }

        // Aye Aye Mon (EMP-0001), the only one who can decide step 1 for
        // Kyaw Kyaw Naing, is on leave from today: his pending request is
        // stuck for that reason (ApprovalInbox::awayReason()). She has no
        // manager, so her own request goes straight to the admin.
        $ayeAye = $this->employee('EMP-0001');
        $this->approved($ayeAye, $this->annual, $today, $this->workday($ayeAye, $today->copy()->addDay()), null, 'Conference abroad');

        // On leave today — anyone with no punches yet today, so the dashboard
        // counts them as on leave rather than Not in. Who that is depends on
        // the time of day the seed runs.
        $used = ['EMP-0001', 'EMP-0002', 'EMP-0009', 'EMP-0017', 'EMP-0012', 'EMP-0019', 'EMP-0025', 'EMP-0020', 'EMP-0021', 'EMP-0024', 'EMP-0026'];
        // At least a year's service, so Annual is usable whatever the date;
        // and nobody the seeder has already given a request.
        $codes = Employee::query()->activeOn($today)
            ->whereDate('join_date', '<=', $today->copy()->subYear())
            ->whereNotIn('employee_code', $used)
            ->orderBy('employee_code')
            ->pluck('employee_code')
            ->all();
        if ($away = $this->firstTodayWhere($codes, fn (DailyAttendance $row) => $row->first_in === null && $row->last_out === null)) {
            $tomorrow = $this->workday($away, $today->copy()->addDay());
            $away->user !== null
                ? $this->approved($away, $this->annual, $today, $tomorrow, null, 'Annual leave')
                : $this->filed($away, $today, $tomorrow, 'Annual leave');
        }

        // Worked on approved leave: a day someone punched despite a full-day
        // leave — today when someone has punched, so Needs attention lists
        // it; otherwise their last worked day.
        $worked = $this->firstTodayWhere(['EMP-0020', 'EMP-0021', 'EMP-0024', 'EMP-0026'], fn (DailyAttendance $row) => $row->first_in !== null)
            ?? $this->employee('EMP-0020');
        $workedDay = $worked->dailyAttendances()->whereDate('work_date', $today)->whereNotNull('first_in')->exists()
            ? $today
            : $this->pastDay($worked, AttendanceStatus::Present);
        if ($workedDay) {
            $this->filed($worked, $workedDay, $workedDay, 'Filed late — came in anyway');
        } else {
            $this->command?->warn('LeaveSeeder: no worked day found for the worked-on-leave example; skipped.');
        }

        $this->command?->info('Seeded '.Leave::count().' demo leave requests.');
    }

    private function submit(Employee $employee, LeaveType $type, CarbonInterface $start, ?CarbonInterface $end, ?LeaveHalf $half, ?string $reason): Leave
    {
        return $this->service->submit($employee, $type, $start, $end ?? $start, $half, $reason, $employee->user ?? $this->admin)['leave'];
    }

    /** A request from $start over $workdays workdays, left pending at step 1. */
    private function pending(Employee $employee, LeaveType $type, CarbonInterface $start, int $workdays, ?string $reason): Leave
    {
        return $this->submit($employee, $type, $start, $this->afterWorkdays($employee, $start, $workdays), null, $reason);
    }

    /** Requested by the employee, approved by their manager, then by the admin. */
    private function approved(Employee $employee, LeaveType $type, CarbonInterface $start, CarbonInterface|int|null $end, ?LeaveHalf $half, ?string $reason): Leave
    {
        $end = is_int($end) ? $this->afterWorkdays($employee, $start, $end) : $end;
        $leave = $this->submit($employee, $type, $start, $end, $half, $reason);

        if ($leave->current_step === 1) {
            $this->service->approve($leave, $employee->manager->user);
        }
        if ($leave->fresh()->current_step === 2) {
            $this->service->approve($leave->fresh(), $this->admin);
        }

        return $leave->fresh();
    }

    private function cancelled(Employee $employee, LeaveType $type, CarbonInterface $start, int $workdays): void
    {
        $leave = $this->approved($employee, $type, $start, $workdays, null, 'Conference');
        $this->service->cancel($leave, $employee->user);
    }

    /** Filed by the admin for an employee with no login: approved on submit. */
    private function filed(Employee $employee, CarbonInterface $start, CarbonInterface $end, string $reason): void
    {
        $this->service->submit($employee, $this->annual, $start, $end, null, $reason, $this->admin);
    }

    private function employee(string $code): Employee
    {
        return Employee::where('employee_code', $code)->with(['user', 'manager.user'])->firstOrFail();
    }

    private function user(string $code): User
    {
        return $this->employee($code)->user;
    }

    /** The first workday on or after $date. */
    private function workday(Employee $employee, CarbonInterface $date): Carbon
    {
        $date = Carbon::instance($date)->startOfDay();

        while (! WorkdayCalendar::isWorkday($employee, $date)) {
            $date->addDay();
        }

        return $date;
    }

    /** The date $workdays workdays from $start, $start counting as the first. */
    private function afterWorkdays(Employee $employee, CarbonInterface $start, int $workdays): Carbon
    {
        $date = Carbon::instance($start)->startOfDay();

        for ($counted = 1; $counted < $workdays; $counted++) {
            $date = $this->workday($employee, $date->addDay());
        }

        return $date;
    }

    /**
     * Their most recent past day with $status, inside the retroactive limit
     * and this year — last year has no grant (grants only start from the
     * current year), so a past day there couldn't be paid for.
     */
    private function pastDay(Employee $employee, AttendanceStatus $status): ?Carbon
    {
        $row = DailyAttendance::query()
            ->where('employee_id', $employee->id)
            ->where('status', $status->value)
            ->whereDate('work_date', '<', today())
            ->whereDate('work_date', '>=', today()->subDays(LeaveRequestService::RETROACTIVE_DAYS - 2)->max(today()->startOfYear()))
            ->orderByDesc('work_date')
            ->first();

        return $row?->work_date->copy();
    }

    /**
     * The first of $codes whose row today matches — on a workday only.
     *
     * @param  list<string>  $codes
     */
    private function firstTodayWhere(array $codes, callable $matches): ?Employee
    {
        foreach ($codes as $code) {
            $employee = $this->employee($code);
            $row = $employee->dailyAttendances()->whereDate('work_date', today())->first();

            if ($row !== null && WorkdayCalendar::isWorkday($employee, today()) && $matches($row)) {
                return $employee;
            }
        }

        return null;
    }
}
