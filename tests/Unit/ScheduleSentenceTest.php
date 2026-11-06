<?php

namespace Tests\Unit;

use App\Models\WorkSchedule;
use App\Support\ScheduleSentence;
use App\Support\Tenure;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The profile's plain-words schedule and tenure (Phase 6i). */
class ScheduleSentenceTest extends TestCase
{
    /** @return array<string, array{list<int>, string}> */
    public static function workdays(): array
    {
        return [
            'a working week' => [[1, 2, 3, 4, 5], 'Mon–Fri'],
            'out of order' => [[5, 3, 1, 4, 2], 'Mon–Fri'],
            'six days' => [[1, 2, 3, 4, 5, 6], 'Mon–Sat'],
            'scattered' => [[1, 3, 5], 'Mon, Wed, Fri'],
            'two runs' => [[1, 2, 3, 6, 7], 'Mon–Wed, Sat, Sun'],
            'a pair' => [[2, 3], 'Tue, Wed'],
            'one day' => [[7], 'Sun'],
        ];
    }

    #[DataProvider('workdays')]
    public function test_workdays_read_as_ranges_and_lists(array $days, string $expected): void
    {
        $this->assertSame($expected, ScheduleSentence::days($days));
    }

    public function test_the_sentence_says_days_hours_and_break(): void
    {
        $schedule = new WorkSchedule(['start_time' => '08:00:00', 'end_time' => '17:00:00', 'break_minutes' => 60, 'break_start' => '12:00:00', 'workdays' => [1, 2, 3, 4, 5]]);
        $this->assertSame('Mon–Fri · 8:00 AM – 5:00 PM · Break 12:00 PM', ScheduleSentence::for($schedule));

        $schedule->break_start = null;
        $this->assertSame('Mon–Fri · 8:00 AM – 5:00 PM · Break 1h 00m', ScheduleSentence::for($schedule));

        $schedule->break_minutes = 0;
        $this->assertSame('Mon–Fri · 8:00 AM – 5:00 PM', ScheduleSentence::for($schedule));
    }

    public function test_tenure_in_months_and_years(): void
    {
        $on = Carbon::parse('2026-11-05');

        $this->assertSame('under a month', Tenure::label(Carbon::parse('2026-10-20'), $on));
        $this->assertSame('1 month', Tenure::label(Carbon::parse('2026-10-05'), $on));
        $this->assertSame('5 months', Tenure::label(Carbon::parse('2026-06-01'), $on));
        $this->assertSame('1 year', Tenure::label(Carbon::parse('2025-11-05'), $on));
        $this->assertSame('2 years', Tenure::label(Carbon::parse('2024-11-01'), $on));
        $this->assertSame('2 years 3 months', Tenure::label(Carbon::parse('2024-08-01'), $on));
        $this->assertSame('under a month', Tenure::label(Carbon::parse('2026-12-01'), $on));
    }
}
