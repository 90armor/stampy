<?php

namespace Tests\Feature\Attendance;

use App\Data\PunchRecord;
use App\Enums\PunchSource;
use App\Enums\PunchType;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Services\Attendance\PunchIngestor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAttendanceSource;
use Tests\TestCase;

class PunchIngestorTest extends TestCase
{
    use RefreshDatabase;

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
