<?php

namespace Database\Seeders;

use App\Models\Holiday;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Runs BEFORE attendance:build-daily (see DatabaseSeeder) so these are
 * already in place when the builder first runs — otherwise the seeded
 * system would come up with these dates already built as absent/present,
 * only correcting to holiday/present-with-no-timing-exception on the next
 * rebuild.
 *
 * Dates are relative to today() rather than fixed calendar dates, so the
 * seeded window (AttendanceLogSeeder::DAYS back from "today", whenever the
 * seeder actually runs) always contains them regardless of when
 * migrate:fresh --seed is run. Names are plausible Myanmar public/company
 * holidays, not tied to their real calendar dates.
 */
class HolidaySeeder extends Seeder
{
    public function run(): void
    {
        $today = Carbon::today();

        $holidays = [
            // A workday holiday inside the built window — resolves to
            // 'holiday' (or 'present' for anyone who worked it anyway).
            ['date' => $this->nearestWeekday($today->copy()->subDays(45)), 'name' => 'Union Day'],
            // A weekend holiday inside the built window — resolves to
            // 'off', with the name still shown on the calendar.
            ['date' => $today->copy()->subDays(25)->next(Carbon::SATURDAY), 'name' => 'Company Anniversary'],
            // A recent workday holiday, close enough to "today" to be easy
            // to spot without paging back through months.
            ['date' => $this->nearestWeekday($today->copy()->subDays(6)), 'name' => 'Full Moon Day of Tabaung'],
            // A future holiday — no daily_attendances row exists for it at
            // all, which is exactly the case the calendar's separate
            // holiday layer exists for (see Attendance\Show::
            // holidaysByDate()'s doc comment).
            ['date' => $today->copy()->addDays(12), 'name' => 'Thingyan Water Festival'],
        ];

        foreach ($holidays as $holiday) {
            Holiday::query()->updateOrCreate(
                ['date' => $holiday['date']->format('Y-m-d')],
                ['name' => $holiday['name']],
            );
        }
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
