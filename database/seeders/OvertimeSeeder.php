<?php

namespace Database\Seeders;

use App\Data\PunchRecord;
use App\Enums\AttendanceStatus;
use App\Enums\OvertimeCompensation;
use App\Enums\PunchSource;
use App\Enums\PunchType;
use App\Exceptions\OvertimeValidationException;
use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\Attendance\DailySummaryBuilder;
use App\Services\Attendance\PunchIngestor;
use App\Services\Attendance\SampleAttendanceSource;
use App\Services\Overtime\OvertimeRequestService;
use App\Support\WorkdayCalendar;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * DEMO DATA — overtime requests in every state, at dates relative to today,
 * after LeaveSeeder (DatabaseSeeder). Everything goes through
 * OvertimeRequestService — submit, approve, reject, cancel — so the steps,
 * the rebuild of approved days and the time off in lieu they credit are
 * exactly what the app produces; nothing is written to overtime_requests,
 * approval_steps or leave_adjustments directly. The punches the past cases
 * need go through PunchIngestor, the path a device import takes — or, for the
 * one with no out-punch, the manual void path — and their days are rebuilt
 * the way a punch change rebuilds them (rebuildAround()). Phase 4d added the
 * partly credited, no-out-punch and Sunday cases, and with them a visible
 * time-off remainder.
 *
 * Each case has its own employee (a login and a manager with the manager
 * role), so none collides with another's date. A case whose date can't be
 * found, or that LeaveSeeder's leave happens to block, is skipped with a
 * note, never forced.
 */
class OvertimeSeeder extends DemoSeeder
{
    private OvertimeRequestService $service;

    private User $admin;

    public function run(): void
    {
        $this->service = app(OvertimeRequestService::class);
        $this->admin = User::role('admin')->orderBy('id')->firstOrFail();
        $today = Carbon::today();

        // Aung Kyaw Moe (EMP-0031): a claim for staying until 19:00 on a recent
        // working day, paid — approved by Zaw Zaw Htet, then the admin.
        $aung = $this->employee('EMP-0031');
        if ($day = $this->recentPresentDay($aung)) {
            $this->punch($aung, $day->copy()->setTime(19, 0), PunchType::Out);
            $this->approved($this->claim($aung, $day, '17:00', '19:00', OvertimeCompensation::Pay, 'Month-end invoices'));
        }

        // Yin Yin Htwe (EMP-0034): last Saturday, 08:00–14:00 taken as time
        // off — 5 hours after the break: half a day of time off in lieu, and
        // 1h toward the next (Time off shows it, now that she has a balance).
        $yin = $this->employee('EMP-0034');
        // The most recent Saturday before today: 1–7 days back, inside the claim window.
        $saturday = $today->copy()->previous(CarbonInterface::SATURDAY);
        $this->punch($yin, $saturday->copy()->setTime(8, 0), PunchType::In);
        $this->punch($yin, $saturday->copy()->setTime(14, 0), PunchType::Out);
        $this->approved($this->claim($yin, $saturday, '08:00', '14:00', OvertimeCompensation::TimeOff, 'Stock count'));

        // Nandar Hlaing (EMP-0028): a claim still waiting for Ohnmar Kyaw.
        $nandar = $this->employee('EMP-0028');
        if ($day = $this->recentPresentDay($nandar)) {
            $this->punch($nandar, $day->copy()->setTime(18, 30), PunchType::Out);
            $this->claim($nandar, $day, '17:00', '18:30', OvertimeCompensation::TimeOff, 'Server migration');
        }

        // Phyo Phyo Aye (EMP-0014): planned, crossing 22:00 — two hours, one
        // of them night — waiting for Wai Yan Aung.
        $phyo = $this->employee('EMP-0014');
        $this->planned($phyo, $this->workday($phyo, $today->copy()->addDays(3)), '21:00', '23:00', OvertimeCompensation::Pay, 'Release night');

        // Myo Min Htike (EMP-0022): planned, approved by Aye Aye Mon, waiting for the admin.
        $myo = $this->employee('EMP-0022');
        if ($request = $this->planned($myo, $this->workday($myo, $today->copy()->addDays(5)), '17:00', '19:00', OvertimeCompensation::TimeOff, 'Quarterly report')) {
            $this->service->approve($request, $myo->manager->user, 'Fine by me');
        }

        // Kyaw Kyaw Naing (EMP-0002): planned and rejected, with the reason.
        $kyaw = $this->employee('EMP-0002');
        if ($request = $this->planned($kyaw, $this->workday($kyaw, $today->copy()->addDays(2)), '17:00', '19:00', OvertimeCompensation::Pay, 'Catch up on tickets')) {
            $this->service->reject($request, $kyaw->manager->user, 'Not needed this week — the backlog is under control.');
        }

        // Moe Moe Khaing (EMP-0009): planned, then cancelled by her.
        $moe = $this->employee('EMP-0009');
        if ($request = $this->planned($moe, $this->workday($moe, $today->copy()->addDays(6)), '17:00', '19:00', OvertimeCompensation::Pay, 'Training prep')) {
            $this->service->cancel($request, $moe->user);
        }

        // Su Myat Noe (EMP-0019): time off, approved, but she left at 18:20 —
        // 1h 20m credited of 2h, which leaves 1h 20m toward the next half day.
        $suMyat = $this->employee('EMP-0019');
        if ($day = $this->recentPresentDay($suMyat)) {
            $this->punch($suMyat, $day->copy()->setTime(18, 20), PunchType::Out);
            $this->approved($this->claim($suMyat, $day, '17:00', '19:00', OvertimeCompensation::TimeOff, 'Inventory'));
        }

        // Phyo Phyo Aye (EMP-0014, who also has a planned request ahead):
        // approved, but the day's out-punch turned out to be someone else's and
        // was voided — nothing credited. Someone with no seeded leave: at the
        // year's end LeaveSeeder gives Kaung Kaung Htun a pending leave across
        // New Year, which blocks overtime on those days.
        if ($day = $this->recentPresentDay($phyo)) {
            $this->voidOutPunch($phyo, $day);
            $this->approved($this->claim($phyo, $day, '17:00', '19:00', OvertimeCompensation::Pay, 'Client call'));
        }

        // Hnin Hnin Wai (EMP-0017): last Sunday, the weekly rest day, 09:00–13:00
        // paid — rest-day minutes, less the 12:00 break.
        $hnin = $this->employee('EMP-0017');
        $sunday = $today->copy()->previous(CarbonInterface::SUNDAY);
        $this->punch($hnin, $sunday->copy()->setTime(9, 0), PunchType::In);
        $this->punch($hnin, $sunday->copy()->setTime(13, 0), PunchType::Out);
        $this->approved($this->claim($hnin, $sunday, '09:00', '13:00', OvertimeCompensation::Pay, 'Warehouse move'));

        $this->command?->info('Seeded '.OvertimeRequest::count().' demo overtime requests.');
    }

    private function claim(Employee $employee, CarbonInterface $date, string $from, string $to, OvertimeCompensation $compensation, string $reason): ?OvertimeRequest
    {
        return $this->submit($employee, $date, $from, $to, $compensation, $reason);
    }

    private function planned(Employee $employee, CarbonInterface $date, string $from, string $to, OvertimeCompensation $compensation, string $reason): ?OvertimeRequest
    {
        return $this->submit($employee, $date, $from, $to, $compensation, $reason);
    }

    /** Filed by the employee; null (with a note) if the service refuses it. */
    private function submit(Employee $employee, CarbonInterface $date, string $from, string $to, OvertimeCompensation $compensation, string $reason): ?OvertimeRequest
    {
        $day = $date->format('Y-m-d');

        try {
            return $this->service->submit($employee, $date, Carbon::parse("{$day} {$from}"), Carbon::parse("{$day} {$to}"), $compensation, $reason, $employee->user)['request'];
        } catch (OvertimeValidationException $e) {
            $this->command?->warn("OvertimeSeeder: {$employee->employee_code} on {$day} skipped — {$e->getMessage()}");

            return null;
        }
    }

    /** Approved by the employee's manager, then the admin. */
    private function approved(?OvertimeRequest $request): void
    {
        if ($request === null) {
            return;
        }

        if ($request->current_step === 1) {
            $this->service->approve($request, $request->employee->manager->user);
        }

        if ($request->fresh()->current_step === 2) {
            $this->service->approve($request->fresh(), $this->admin);
        }
    }

    /** One punch through PunchIngestor, then its days rebuilt as a punch change rebuilds them. */
    private function punch(Employee $employee, Carbon $at, PunchType $type): void
    {
        app(PunchIngestor::class)->ingest(
            new SampleAttendanceSource([new PunchRecord($employee->device_user_id, $at, $type)]),
            $at->copy()->startOfDay(),
            $at->copy()->endOfDay(),
            PunchSource::Device,
        );

        app(DailySummaryBuilder::class)->rebuildAround($employee->fresh(), $at);
    }

    /**
     * The day's out-punches voided the way an admin voids one on the
     * attendance page (Attendance\Show::voidPunch()): append-only, then the
     * days around it rebuilt.
     */
    private function voidOutPunch(Employee $employee, Carbon $day): void
    {
        AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->notVoided()
            ->where('punch_type', PunchType::Out->value)
            ->whereBetween('punched_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->get()
            ->each(fn (AttendanceLog $log) => $log->update(['voided_at' => now(), 'voided_by' => $this->admin->id]));

        app(DailySummaryBuilder::class)->rebuildAround($employee->fresh(), $day);
    }

    /** Their most recent Present day before today, inside the claim window (6 days back, to be safe). */
    private function recentPresentDay(Employee $employee): ?Carbon
    {
        return DailyAttendance::query()
            ->where('employee_id', $employee->id)
            ->where('status', AttendanceStatus::Present->value)
            ->whereDate('work_date', '<', today())
            ->whereDate('work_date', '>=', today()->subDays(6))
            ->orderByDesc('work_date')
            ->first()?->work_date->copy();
    }

    private function employee(string $code): Employee
    {
        return Employee::where('employee_code', $code)->with(['user', 'manager.user'])->firstOrFail();
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
}
