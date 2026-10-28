<?php

namespace Database\Seeders;

use App\Models\Holiday;
use Carbon\Carbon;

/**
 * DEMO DATA, not a calendar — do not read a date here as authoritative.
 *
 * The company follows the Cambodian public holidays. Two kinds are seeded:
 *
 * - Fixed-date holidays (FIXED) use their real month and day, for the current
 *   and the next year. The government's annual announcement is the authority,
 *   and it can add substitute days that this does not model.
 * - Relative-date slots (DEMO_NAME) stand in for movable holidays (Khmer
 *   calendar, announced each year), whose real dates aren't computable. They
 *   sit at dates relative to today() so that, whenever migrate:fresh --seed
 *   runs, the seeded system has a workday holiday and a weekend holiday inside
 *   the attendance window (AttendanceLogSeeder::DAYS), and a holiday in the
 *   future — which has no daily_attendances row and is what the calendar's
 *   separate holiday layer exists for (see Attendance\Show::holidaysByDate()).
 *   They are deliberately named "Company holiday (demo)", never after a real
 *   holiday: a real name on an invented date reads as authoritative and is
 *   wrong. Real holiday names appear only on their real (fixed) dates.
 *
 * Runs BEFORE attendance:build-daily (see DatabaseSeeder) so the builder sees
 * these already; otherwise those dates would first be built as plain
 * absent/present.
 */
class HolidaySeeder extends DemoSeeder
{
    /** [month, day, name] */
    private const FIXED = [
        [1, 1, "International New Year's Day"],
        [1, 7, 'Victory over Genocide Day'],
        [3, 8, "International Women's Day"],
        [5, 1, 'International Labour Day'],
        [5, 14, "King Norodom Sihamoni's Birthday"],
        [6, 18, "Queen Mother Norodom Monineath Sihanouk's Birthday"],
        [9, 24, 'Constitution Day'],
        [10, 15, 'Commemoration Day of King Father Norodom Sihanouk'],
        [10, 29, "King Norodom Sihamoni's Coronation Day"],
        [11, 9, 'Independence Day'],
    ];

    private const DEMO_NAME = 'Company holiday (demo)';

    private const DEMO_NOTE = 'Demo date — a stand-in for a holiday announced each year; not a real holiday.';

    public function run(): void
    {
        $today = Carbon::today();

        // Demo slots first: on a date clash the real fixed-date name below wins.
        $demo = [
            // A workday holiday inside the built window — resolves to 'holiday' (or 'present' for anyone who worked it anyway).
            [$this->nearestWeekday($today->copy()->subDays(45)), self::DEMO_NAME, self::DEMO_NOTE],
            // A weekend holiday inside the built window — resolves to 'off', with the name still shown on the calendar.
            [$today->copy()->subDays(25)->next(Carbon::SATURDAY), 'Company Anniversary (demo)', self::DEMO_NOTE],
            // A recent workday holiday, close enough to today to spot without paging back.
            [$this->nearestWeekday($today->copy()->subDays(6)), self::DEMO_NAME, self::DEMO_NOTE],
            // In the future: no daily_attendances row exists for it yet.
            [$today->copy()->addDays(12), self::DEMO_NAME, self::DEMO_NOTE],
        ];

        foreach ($demo as [$date, $name, $note]) {
            $this->save($date, $name, $note);
        }

        foreach ([$today->year, $today->year + 1] as $year) {
            foreach (self::FIXED as [$month, $day, $name]) {
                $this->save(Carbon::create($year, $month, $day), $name, null);
            }
        }
    }

    private function save(Carbon $date, string $name, ?string $note): void
    {
        Holiday::query()->updateOrCreate(
            ['date' => $date->format('Y-m-d')],
            ['name' => $name, 'note' => $note],
        );
    }

    private function nearestWeekday(Carbon $date): Carbon
    {
        return match ($date->dayOfWeekIso) {
            6 => $date->subDay(),
            7 => $date->addDay(),
            default => $date,
        };
    }
}
