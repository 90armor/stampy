<?php

namespace Tests\Feature;

use App\Models\Employee;
use Carbon\Carbon;
use Database\Seeders\HolidaySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The demo seed places everything relative to today, so a date can break it
 * (31 Oct 2026 did: LeaveSeeder chose a worked holiday for a half day). This
 * seeds with the clock pinned to a sample of 2027's dates — every holiday the
 * seeder knows, the days either side of each (which between them cover every
 * day of the week), 1 Jan, 31 Dec — plus 29 Feb 2028. Each date runs at one of
 * TIMES, in turn, so the sample also lands either side of the shift and of
 * midnight.
 *
 * The whole year is scripts/seed-sweep.sh (SEED_SWEEP=full): every day of
 * 2027 (weekends, a holiday on a Saturday — 1 May — both ends of the year and
 * Khmer New Year, 14–16 April, which the seeder doesn't know as a holiday)
 * and 29 Feb 2028. About 20 minutes, so not in the suite.
 *
 * In the seed-sample group: the pre-commit hook leaves it out (about two
 * minutes of a commit's run), every verification run includes it. It pins its
 * own clock, so PIN_NOW changes nothing here: scripts/pinned-instants.sh runs
 * the group once, after the instants, rather than 25 times over.
 */
#[Group('seed-sample')]
class SeedAtAnyDateTest extends TestCase
{
    use RefreshDatabase;

    private const YEAR = 2027;

    private const TIMES = ['00:00:30', '07:59:00', '10:30:00', '13:00:00', '17:00:01', '23:59:30'];

    #[DataProvider('instants')]
    public function test_the_demo_seed_runs(string $instant): void
    {
        $this->travelTo(Carbon::parse($instant));

        $this->seed();

        $this->assertGreaterThan(0, Employee::count());
    }

    /** @return iterable<string, array{string}> */
    public static function instants(): iterable
    {
        $dates = getenv('SEED_SWEEP') === 'full' ? self::everyDay() : self::sample();
        $dates[] = Carbon::create(2028, 2, 29);

        foreach (array_values($dates) as $i => $date) {
            $instant = $date->format('Y-m-d').' '.self::TIMES[$i % count(self::TIMES)];

            yield $instant => [$instant];
        }
    }

    /** @return list<Carbon> */
    private static function everyDay(): array
    {
        $dates = [];

        for ($date = Carbon::create(self::YEAR, 1, 1); $date->year === self::YEAR; $date = $date->copy()->addDay()) {
            $dates[] = $date;
        }

        return $dates;
    }

    /** @return list<Carbon> */
    private static function sample(): array
    {
        $dates = [Carbon::create(self::YEAR, 1, 1), Carbon::create(self::YEAR, 12, 31)];

        foreach (HolidaySeeder::FIXED as [$month, $day]) {
            $holiday = Carbon::create(self::YEAR, $month, $day);
            array_push($dates, $holiday->copy()->subDay(), $holiday, $holiday->copy()->addDay());
        }

        $dates = collect($dates)->unique(fn (Carbon $date) => $date->format('Y-m-d'))->sortBy(fn (Carbon $date) => $date->timestamp)->values();

        return $dates->all();
    }
}
