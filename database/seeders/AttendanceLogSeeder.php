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
use Random\Engine\Mt19937;
use Random\Randomizer;

class AttendanceLogSeeder extends Seeder
{
    /**
     * Fixed so sample data is reproducible across runs. Every draw below
     * comes from this seeder's own engine ($random), never PHP's global
     * mt_rand() state: anything may reseed that mid-run — Faker's
     * Generator::__destruct() calls mt_srand() with a random seed, and
     * inside the test suite a generator left by an earlier test was
     * garbage-collected partway through this loop, so the seed differed
     * with test order. Mt19937 with the same seed gives the sequence the
     * global mt_rand() gave, so the seeded data is unchanged.
     */
    private const RANDOM_SEED = 20260914;

    private Randomizer $random;

    public const DAYS = 60;

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
        $this->random = new Randomizer(new Mt19937(self::RANDOM_SEED));

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
                // The employee's position in the list (1, 2, 3…), never their
                // id: ids depend on what the database saw before — inside the
                // test suite, rolled-back inserts still advance AUTO_INCREMENT
                // — and a rule on an id's parity once seeded different data
                // there than when run alone. On a fresh database the two agree,
                // so dev data is unchanged.
                foreach ($employees->values() as $index => $employee) {
                    array_push($records, ...$this->generateDay($employee, $index + 1, $schedule, $date));
                }
            }

            $date = $date->copy()->addDay();
        }

        // Never write a punch that hasn't happened yet. Every day is still
        // generated in full first, so the random sequence (and therefore
        // every past punch) is identical whenever this runs; only punches
        // later than now are dropped. Seeding during working hours therefore
        // gives a real "today so far": some employees punched in, some not
        // yet, nobody punched out in the future.
        $now = Carbon::now();
        $records = array_values(array_filter(
            $records,
            fn (PunchRecord $record) => $record->punchedAt->lte($now),
        ));

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
    private function generateDay(Employee $employee, int $position, WorkSchedule $schedule, Carbon $date): array
    {
        $start = Carbon::parse($date->format('Y-m-d').' '.$schedule->start_time);
        $end = Carbon::parse($date->format('Y-m-d').' '.$schedule->end_time);

        $outcome = $this->rollOutcome();

        // Each entry: ['time' => Carbon, 'type' => PunchType].
        $punches = match ($outcome) {
            'normal', 'duplicate' => [
                ['time' => $start->copy()->subMinutes($this->random->getInt(1, 10)), 'type' => PunchType::In],
                ['time' => $end->copy()->addMinutes($this->random->getInt(-5, 15)), 'type' => PunchType::Out],
            ],
            'late' => [
                ['time' => $start->copy()->addMinutes($this->random->getInt(10, 90)), 'type' => PunchType::In],
                ['time' => $end->copy()->addMinutes($this->random->getInt(-5, 15)), 'type' => PunchType::Out],
            ],
            'early_leave' => [
                ['time' => $start->copy()->subMinutes($this->random->getInt(1, 10)), 'type' => PunchType::In],
                ['time' => $end->copy()->subMinutes($this->random->getInt(30, 120)), 'type' => PunchType::Out],
            ],
            // About half of the in-only days punch in late, so dev data has
            // late incomplete days (Phase 2.6 records late from the in-punch).
            // Chosen by a fixed rule, and still exactly one random draw
            // reused for the minutes, so every other seeded punch is unchanged:
            // 15 + 6..60 minutes after start, which stays past the 10-minute
            // grace even after the ±8 minute jitter below.
            'missing_out' => [
                ['time' => (function () use ($start, $position, $date) {
                    $offset = $this->random->getInt(1, 10);

                    return ($position + $date->day) % 2 === 1
                        ? $start->copy()->addMinutes(15 + $offset * 6)
                        : $start->copy()->subMinutes($offset);
                })(), 'type' => PunchType::In],
            ],
            'absent' => [],
            'missing_in' => [
                ['time' => $end->copy()->addMinutes($this->random->getInt(-5, 15)), 'type' => PunchType::Out],
            ],
            'overnight' => [
                ['time' => $start->copy()->subMinutes($this->random->getInt(1, 10)), 'type' => PunchType::In],
                // Capped at 90 (not 120, originally 180) so the gap from a
                // ~08:00 check-in sits safely under DailySummaryBuilder's 18h
                // pairing window with margin — 120 still landed exactly on
                // the boundary (18h00m), which is fine today but would
                // silently flip to incomplete if that arithmetic ever changed
                // by even a minute. The boundary itself is covered by
                // DailySummaryBuilderTest, not depended on here.
                ['time' => $date->copy()->addDay()->startOfDay()->addMinutes($this->random->getInt(1, 90)), 'type' => PunchType::Out],
            ],
        };

        // Universal jitter so nothing lands exactly on the hour, applied to
        // every "real" punch before anything derives a position from it.
        foreach ($punches as &$punch) {
            $jitter = $this->random->getInt(1, 8) * ($this->random->getInt(0, 1) === 0 ? 1 : -1);
            $punch['time'] = $punch['time']->copy()->addMinutes($jitter);
        }
        unset($punch);

        // "Duplicate" extras are derived from the already-jittered real punch
        // so they land within the intended 5-60s window of it — deriving them
        // pre-jitter would let the ±1-8min jitter above push the real punch
        // away from its own duplicate.
        if ($outcome === 'duplicate' && $punches !== []) {
            for ($i = 0, $extra = $this->random->getInt(1, 2); $i < $extra; $i++) {
                $base = $punches[$this->random->pickArrayKeys($punches, 1)[0]];
                $offsetSeconds = $this->random->getInt(5, 60) * ($this->random->getInt(0, 1) === 0 ? 1 : -1);

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
        $roll = $this->random->getInt(1, 100);
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
