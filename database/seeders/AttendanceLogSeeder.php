<?php

namespace Database\Seeders;

use App\Data\PunchRecord;
use App\Enums\PunchSource;
use App\Enums\PunchType;
use App\Models\Employee;
use App\Models\WorkSchedule;
use App\Services\Attendance\PunchIngestor;
use App\Services\Attendance\SampleAttendanceSource;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class AttendanceLogSeeder extends Seeder
{
    /**
     * Fixed so sample data is reproducible across runs — mt_rand() (not
     * random_int(), which isn't seedable) is used everywhere below.
     */
    private const RANDOM_SEED = 20260914;

    private const DAYS = 60;

    /**
     * Weighted day outcomes; must sum to 100.
     */
    private const WEIGHTS = [
        'normal' => 70,
        'late' => 10,
        'early_leave' => 5,
        'missing_out' => 5,
        'absent' => 5,
        'missing_in' => 2,
        'duplicate' => 2,
        'overnight' => 1,
    ];

    public function run(PunchIngestor $ingestor): void
    {
        mt_srand(self::RANDOM_SEED);

        $schedule = WorkSchedule::default();

        if (! $schedule) {
            $this->command?->warn('No default WorkSchedule found — skipping attendance log seeding.');

            return;
        }

        $employees = Employee::where('status', 'active')->orderBy('id')->get();
        $this->assignMissingDeviceUserIds($employees);

        $to = Carbon::today();
        $from = $to->copy()->subDays(self::DAYS - 1);

        $records = [];
        $date = $from->copy();

        while ($date->lte($to)) {
            if (in_array($date->dayOfWeekIso, $schedule->workdays, true)) {
                foreach ($employees as $employee) {
                    array_push($records, ...$this->generateDay($employee, $schedule, $date));
                }
            }

            $date = $date->copy()->addDay();
        }

        $source = new SampleAttendanceSource($records);

        // +1 day on the range end to catch overnight punches from the last day.
        $summary = $ingestor->ingest(
            $source,
            $from->copy()->startOfDay(),
            $to->copy()->addDay()->endOfDay(),
            PunchSource::Device,
        );

        $this->command?->info(
            "AttendanceLogSeeder: imported {$summary->imported}, ".
            "skipped {$summary->skippedDuplicate} duplicate, ".
            "{$summary->skippedUnknown} unknown device id."
        );
    }

    /**
     * Real devices need a device_user_id; nothing sets one for employees
     * created without it, so backfill sequential IDs starting at 1001.
     */
    private function assignMissingDeviceUserIds(Collection $employees): void
    {
        $taken = Employee::whereNotNull('device_user_id')->pluck('device_user_id')->all();
        $next = 1001;

        foreach ($employees as $employee) {
            if (! empty($employee->device_user_id)) {
                continue;
            }

            while (in_array((string) $next, $taken, true)) {
                $next++;
            }

            $employee->update(['device_user_id' => (string) $next]);
            $taken[] = (string) $next;
            $next++;
        }
    }

    /**
     * @return PunchRecord[]
     */
    private function generateDay(Employee $employee, WorkSchedule $schedule, Carbon $date): array
    {
        $start = Carbon::parse($date->format('Y-m-d').' '.$schedule->start_time);
        $end = Carbon::parse($date->format('Y-m-d').' '.$schedule->end_time);

        $outcome = $this->rollOutcome();

        // Each entry: ['time' => Carbon, 'type' => PunchType].
        $punches = match ($outcome) {
            'normal', 'duplicate' => [
                ['time' => $start->copy()->subMinutes(mt_rand(1, 10)), 'type' => PunchType::In],
                ['time' => $end->copy()->addMinutes(mt_rand(-5, 15)), 'type' => PunchType::Out],
            ],
            'late' => [
                ['time' => $start->copy()->addMinutes(mt_rand(10, 90)), 'type' => PunchType::In],
                ['time' => $end->copy()->addMinutes(mt_rand(-5, 15)), 'type' => PunchType::Out],
            ],
            'early_leave' => [
                ['time' => $start->copy()->subMinutes(mt_rand(1, 10)), 'type' => PunchType::In],
                ['time' => $end->copy()->subMinutes(mt_rand(30, 120)), 'type' => PunchType::Out],
            ],
            'missing_out' => [
                ['time' => $start->copy()->subMinutes(mt_rand(1, 10)), 'type' => PunchType::In],
            ],
            'absent' => [],
            'missing_in' => [
                ['time' => $end->copy()->addMinutes(mt_rand(-5, 15)), 'type' => PunchType::Out],
            ],
            'overnight' => [
                ['time' => $start->copy()->subMinutes(mt_rand(1, 10)), 'type' => PunchType::In],
                ['time' => $date->copy()->addDay()->startOfDay()->addMinutes(mt_rand(1, 180)), 'type' => PunchType::Out],
            ],
        };

        // Universal jitter so nothing lands exactly on the hour, applied to
        // every "real" punch before anything derives a position from it.
        foreach ($punches as &$punch) {
            $jitter = mt_rand(1, 8) * (mt_rand(0, 1) === 0 ? 1 : -1);
            $punch['time'] = $punch['time']->copy()->addMinutes($jitter);
        }
        unset($punch);

        // "Duplicate" extras are derived from the already-jittered real punch
        // so they land within the intended 5-60s window of it — deriving them
        // pre-jitter would let the ±1-8min jitter above push the real punch
        // away from its own duplicate.
        if ($outcome === 'duplicate' && $punches !== []) {
            for ($i = 0, $extra = mt_rand(1, 2); $i < $extra; $i++) {
                $base = $punches[array_rand($punches)];
                $offsetSeconds = mt_rand(5, 60) * (mt_rand(0, 1) === 0 ? 1 : -1);

                $punches[] = [
                    'time' => $base['time']->copy()->addSeconds($offsetSeconds),
                    'type' => $base['type'],
                ];
            }
        }

        return array_map(
            fn (array $punch) => new PunchRecord(
                deviceUserId: $employee->device_user_id,
                punchedAt: $punch['time'],
                punchType: $punch['type'],
                raw: ['seeded_outcome' => $outcome],
            ),
            $punches,
        );
    }

    private function rollOutcome(): string
    {
        $roll = mt_rand(1, 100);
        $cumulative = 0;

        foreach (self::WEIGHTS as $outcome => $weight) {
            $cumulative += $weight;

            if ($roll <= $cumulative) {
                return $outcome;
            }
        }

        return 'normal';
    }
}
