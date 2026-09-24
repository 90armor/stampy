<?php

namespace Tests\Feature\Attendance;

use App\Data\PunchRecord;
use App\Enums\PunchSource;
use App\Enums\PunchType;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\WorkSchedule;
use App\Services\Attendance\PunchIngestor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAttendanceSource;
use Tests\TestCase;

class PunchIngestorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Every employee is now assigned a schedule at creation, which needs a default to exist.
        WorkSchedule::factory()->create(['is_default' => true]);
    }

    public function test_punch_type_is_inferred_by_alternating_when_absent(): void
    {
        $employee = Employee::factory()->create(['device_user_id' => '2001']);

        $times = [
            Carbon::parse('2026-02-02 08:00:00'),
            Carbon::parse('2026-02-02 12:00:00'),
            Carbon::parse('2026-02-02 13:00:00'),
            Carbon::parse('2026-02-02 17:00:00'),
        ];

        $records = array_map(
            fn (Carbon $time) => new PunchRecord(deviceUserId: '2001', punchedAt: $time, punchType: null),
            $times,
        );

        $source = new FakeAttendanceSource($records);

        $summary = app(PunchIngestor::class)->ingest(
            $source,
            Carbon::parse('2026-02-01'),
            Carbon::parse('2026-02-03'),
            PunchSource::Device,
        );

        $this->assertSame(4, $summary->imported);

        $types = AttendanceLog::where('employee_id', $employee->id)
            ->orderBy('punched_at')
            ->pluck('punch_type')
            ->map(fn ($type) => $type->value)
            ->all();

        $this->assertSame(['in', 'out', 'in', 'out'], $types);
    }

    public function test_inference_accounts_for_existing_punches_that_day(): void
    {
        $employee = Employee::factory()->create(['device_user_id' => '2002']);

        AttendanceLog::insert([
            'employee_id' => $employee->id,
            'punched_at' => '2026-02-02 08:00:00',
            'punch_type' => 'in',
            'source' => 'device',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $source = new FakeAttendanceSource([
            new PunchRecord(deviceUserId: '2002', punchedAt: Carbon::parse('2026-02-02 17:00:00'), punchType: null),
        ]);

        app(PunchIngestor::class)->ingest(
            $source,
            Carbon::parse('2026-02-01'),
            Carbon::parse('2026-02-03'),
            PunchSource::Device,
        );

        $log = AttendanceLog::where('employee_id', $employee->id)
            ->where('punched_at', '2026-02-02 17:00:00')
            ->firstOrFail();

        $this->assertSame('out', $log->punch_type->value);
    }

    public function test_inference_groups_a_double_tap_instead_of_alternating_per_punch(): void
    {
        $employee = Employee::factory()->create(['device_user_id' => '2005']);

        $source = new FakeAttendanceSource([
            new PunchRecord(deviceUserId: '2005', punchedAt: Carbon::parse('2026-02-02 08:00:00'), punchType: null),
            new PunchRecord(deviceUserId: '2005', punchedAt: Carbon::parse('2026-02-02 08:00:08'), punchType: null),
            new PunchRecord(deviceUserId: '2005', punchedAt: Carbon::parse('2026-02-02 17:00:00'), punchType: null),
        ]);

        app(PunchIngestor::class)->ingest(
            $source,
            Carbon::parse('2026-02-01'),
            Carbon::parse('2026-02-03'),
            PunchSource::Device,
        );

        $types = AttendanceLog::where('employee_id', $employee->id)
            ->orderBy('punched_at')
            ->pluck('punch_type')
            ->map(fn ($type) => $type->value)
            ->all();

        // Without grouping this would infer in, out, in — the double-tap
        // 8 seconds later would flip the alternation for the rest of the day.
        $this->assertSame(['in', 'in', 'out'], $types);
        $this->assertSame(3, AttendanceLog::where('employee_id', $employee->id)->count());
    }

    /**
     * @return list<string>
     */
    private function inferredTypesFor(string $deviceUserId, string $secondPunchAt): array
    {
        config(['attendance.duplicate_window_seconds' => 90]);

        $employee = Employee::factory()->create(['device_user_id' => $deviceUserId]);

        app(PunchIngestor::class)->ingest(
            new FakeAttendanceSource([
                new PunchRecord(deviceUserId: $deviceUserId, punchedAt: Carbon::parse('2026-02-02 08:00:00'), punchType: null),
                new PunchRecord(deviceUserId: $deviceUserId, punchedAt: Carbon::parse($secondPunchAt), punchType: null),
                new PunchRecord(deviceUserId: $deviceUserId, punchedAt: Carbon::parse('2026-02-02 17:00:00'), punchType: null),
            ]),
            Carbon::parse('2026-02-01'),
            Carbon::parse('2026-02-03'),
            PunchSource::Device,
        );

        return AttendanceLog::where('employee_id', $employee->id)
            ->orderBy('punched_at')
            ->pluck('punch_type')
            ->map(fn ($type) => $type->value)
            ->all();
    }

    public function test_punches_exactly_the_window_apart_are_one_double_tap_group(): void
    {
        // 08:00:00 -> 08:01:30 is exactly 90s: same group, so both are "in"
        // and the 17:00 punch is the day's "out".
        $this->assertSame(['in', 'in', 'out'], $this->inferredTypesFor('2006', '2026-02-02 08:01:30'));
    }

    public function test_punches_one_second_past_the_window_are_separate_groups(): void
    {
        // 08:00:00 -> 08:01:31 is 91s: two distinct events, so they
        // alternate in, out — and 17:00 is the next "in".
        $this->assertSame(['in', 'out', 'in'], $this->inferredTypesFor('2007', '2026-02-02 08:01:31'));
    }

    public function test_two_punches_seconds_apart_are_both_stored(): void
    {
        Employee::factory()->create(['device_user_id' => '2003']);

        $source = new FakeAttendanceSource([
            new PunchRecord(deviceUserId: '2003', punchedAt: Carbon::parse('2026-02-02 08:00:00'), punchType: PunchType::In),
            new PunchRecord(deviceUserId: '2003', punchedAt: Carbon::parse('2026-02-02 08:00:45'), punchType: PunchType::In),
        ]);

        $summary = app(PunchIngestor::class)->ingest(
            $source,
            Carbon::parse('2026-02-01'),
            Carbon::parse('2026-02-03'),
            PunchSource::Device,
        );

        $this->assertSame(2, $summary->imported);
        $this->assertSame(0, $summary->skippedDuplicate);
        $this->assertSame(2, AttendanceLog::count());
    }

    public function test_an_exact_duplicate_punch_is_not_stored_twice(): void
    {
        $employee = Employee::factory()->create(['device_user_id' => '2004']);

        $punchedAt = Carbon::parse('2026-02-02 08:00:00');

        $ingest = fn () => app(PunchIngestor::class)->ingest(
            new FakeAttendanceSource([
                new PunchRecord(deviceUserId: '2004', punchedAt: $punchedAt, punchType: PunchType::In),
            ]),
            Carbon::parse('2026-02-01'),
            Carbon::parse('2026-02-03'),
            PunchSource::Device,
        );

        $first = $ingest();
        $second = $ingest();

        $this->assertSame(1, $first->imported);
        $this->assertSame(0, $second->imported);
        $this->assertSame(1, $second->skippedDuplicate);
        $this->assertSame(1, AttendanceLog::where('employee_id', $employee->id)->count());
    }
}
