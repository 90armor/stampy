<?php

namespace Tests\Feature\Overtime;

use App\Enums\LeaveHalf;
use App\Models\Leave;
use App\Models\OvertimeRequest;
use App\Models\OvertimeSettings;
use App\Models\WorkSchedule;
use App\Services\Attendance\LeaveDay;
use App\Services\Attendance\OvertimeCalculator;
use App\Services\Attendance\OvertimeCredit;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Phase 4b — credited overtime minutes by category (CLAUDE.md, Phase 4,
 * rules 2, 3, 9–13). The calculator gets every fact from its caller; the
 * builder's own lookups are tested in OvertimeBuilderTest.
 */
class OvertimeCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-02-02';

    private const SATURDAY = '2026-02-07';

    private const SUNDAY = '2026-02-08';

    private WorkSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse('2026-04-15 12:00:00'));

        // 08:00–17:00 Mon–Fri, break 12:00–13:00.
        $this->schedule = WorkSchedule::factory()->withBreakStart()->create([
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'break_minutes' => 60, 'break_start' => '12:00:00',
            'workdays' => [1, 2, 3, 4, 5], 'is_default' => true,
        ]);
    }

    private function request(string $date, string $from, string $to): OvertimeRequest
    {
        return OvertimeRequest::factory()->window($date, $from, $to)->approved()->create();
    }

    /**
     * @param  string|null  $out  "HH:MM:SS", or "+1 HH:MM:SS" for the next day
     */
    private function credit(
        ?OvertimeRequest $request,
        string $date,
        ?string $in,
        ?string $out,
        bool $workday = true,
        bool $holiday = false,
        ?LeaveDay $leaveDay = null,
    ): OvertimeCredit {
        $at = function (?string $time) use ($date): ?Carbon {
            if ($time === null) {
                return null;
            }

            return str_starts_with($time, '+1 ')
                ? Carbon::parse("{$date} ".substr($time, 3))->addDay()
                : Carbon::parse("{$date} {$time}");
        };

        return app(OvertimeCalculator::class)->calculate(
            $request, $at($in), $at($out), $this->schedule, Carbon::parse($date),
            $workday, $holiday, $leaveDay ?? LeaveDay::none(), OvertimeSettings::current(),
        );
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} workday, night, rest day, holiday */
    private function minutes(OvertimeCredit $credit): array
    {
        return [$credit->workday, $credit->night, $credit->restDay, $credit->holiday];
    }

    public function test_workday_overtime_after_hours_is_capped_by_the_approved_window(): void
    {
        $request = $this->request(self::MONDAY, '17:00', '19:00');

        $this->assertSame([120, 0, 0, 0], $this->minutes($this->credit($request, self::MONDAY, '07:58:00', '19:20:45')));
        $this->assertSame([90, 0, 0, 0], $this->minutes($this->credit($request, self::MONDAY, '07:58:00', '18:30:59')));
    }

    public function test_a_window_crossing_22_00_is_split_by_the_minute(): void
    {
        $request = $this->request(self::MONDAY, '21:00', '23:00');

        $this->assertSame([60, 60, 0, 0], $this->minutes($this->credit($request, self::MONDAY, '08:00:00', '23:10:00')));
    }

    public function test_overtime_past_midnight_is_night(): void
    {
        $request = $this->request(self::MONDAY, '22:00', '01:00');

        $this->assertSame([0, 180, 0, 0], $this->minutes($this->credit($request, self::MONDAY, '08:00:00', '+1 01:05:00')));
    }

    public function test_an_early_start_crossing_05_00_is_night_then_workday(): void
    {
        $request = $this->request(self::MONDAY, '04:00', '06:00');

        $this->assertSame([60, 60, 0, 0], $this->minutes($this->credit($request, self::MONDAY, '03:55:00', '17:05:00')));
    }

    public function test_arriving_early_is_overtime_only_inside_an_approved_window(): void
    {
        // An approved window after hours doesn't make a 07:00 arrival count.
        $evening = $this->request(self::MONDAY, '17:00', '18:00');
        $this->assertSame([0, 0, 0, 0], $this->minutes($this->credit($evening, self::MONDAY, '07:00:00', '17:00:00')));

        // Without any approved request, nothing at all.
        $this->assertEquals(OvertimeCredit::none(), $this->credit(null, self::MONDAY, '07:00:00', '17:00:00'));

        // A window covering it: 06:00–08:00 is two hours before the schedule starts.
        $morning = $this->request('2026-02-03', '06:00', '08:00');
        $this->assertSame([120, 0, 0, 0], $this->minutes($this->credit($morning, '2026-02-03', '06:00:00', '17:00:00')));
    }

    /**
     * Only the weekly rest day (Sunday) is the rest-day category (owner,
     * Phase 4c's commit 0); a Saturday has no normal window either, so the
     * whole span less the break counts, but as workday minutes.
     */
    public function test_a_saturday_is_workday_minutes_and_a_sunday_is_rest_day_both_less_the_break(): void
    {
        $saturday = $this->request(self::SATURDAY, '08:00', '17:00');
        $this->assertSame([480, 0, 0, 0], $this->minutes($this->credit($saturday, self::SATURDAY, '07:50:00', '17:10:00', workday: false)));

        $sunday = $this->request(self::SUNDAY, '08:00', '17:00');
        $this->assertSame([0, 0, 480, 0], $this->minutes($this->credit($sunday, self::SUNDAY, '07:50:00', '17:10:00', workday: false)));
    }

    public function test_a_saturday_evening_is_workday_then_night(): void
    {
        $request = $this->request(self::SATURDAY, '20:00', '23:00');

        $this->assertSame([120, 60, 0, 0], $this->minutes($this->credit($request, self::SATURDAY, '19:55:00', '23:05:00', workday: false)));
    }

    public function test_the_weekly_rest_day_is_a_setting(): void
    {
        OvertimeSettings::current()->update(['weekly_rest_day' => 6]);
        $saturday = $this->request(self::SATURDAY, '08:00', '17:00');
        $sunday = $this->request(self::SUNDAY, '08:00', '17:00');

        $this->assertSame([0, 0, 480, 0], $this->minutes($this->credit($saturday, self::SATURDAY, '08:00:00', '17:00:00', workday: false)));
        $this->assertSame([480, 0, 0, 0], $this->minutes($this->credit($sunday, self::SUNDAY, '08:00:00', '17:00:00', workday: false)));
    }

    public function test_a_non_workday_on_a_schedule_without_a_break_start_subtracts_nothing(): void
    {
        $this->schedule = WorkSchedule::factory()->create(['start_time' => '08:00:00', 'end_time' => '17:00:00', 'break_minutes' => 60, 'break_start' => null]);
        $request = $this->request(self::SUNDAY, '08:00', '17:00');

        $this->assertSame([0, 0, 540, 0], $this->minutes($this->credit($request, self::SUNDAY, '08:00:00', '17:00:00', workday: false)));
    }

    public function test_a_holiday_counts_the_whole_span_less_the_break_as_holiday(): void
    {
        $request = $this->request(self::MONDAY, '08:00', '17:00');

        $this->assertSame([0, 0, 0, 480], $this->minutes($this->credit($request, self::MONDAY, '08:00:00', '17:00:00', holiday: true)));
    }

    public function test_a_holiday_on_a_saturday_is_holiday(): void
    {
        $request = $this->request(self::SATURDAY, '08:00', '17:00');

        $this->assertSame([0, 0, 0, 480], $this->minutes($this->credit($request, self::SATURDAY, '08:00:00', '17:00:00', workday: false, holiday: true)));
    }

    public function test_night_minutes_on_a_holiday_or_rest_day_take_the_days_category(): void
    {
        // 20:00–01:00 on a holiday: every minute holiday, the ones after midnight included.
        $holiday = $this->request(self::MONDAY, '20:00', '01:00');
        $this->assertSame([0, 0, 0, 300], $this->minutes($this->credit($holiday, self::MONDAY, '19:55:00', '+1 01:00:00', holiday: true)));

        $restDay = $this->request(self::SUNDAY, '20:00', '01:00');
        $this->assertSame([0, 0, 300, 0], $this->minutes($this->credit($restDay, self::SUNDAY, '19:55:00', '+1 01:00:00', workday: false)));
    }

    public function test_half_day_leave_makes_only_time_outside_the_full_schedule_overtime(): void
    {
        $request = $this->request(self::MONDAY, '17:00', '19:00');
        $leave = Leave::factory()->approved()->between(self::MONDAY, self::MONDAY)->halfDay('am')->create();
        $amLeave = LeaveDay::on(collect([$leave]), Carbon::parse(self::MONDAY));
        $this->assertSame(LeaveHalf::Am, $amLeave->half);

        $this->assertSame([120, 0, 0, 0], $this->minutes($this->credit($request, self::MONDAY, '13:00:00', '19:00:00', leaveDay: $amLeave)));
    }

    public function test_without_an_out_punch_nothing_is_credited_but_the_request_is_named(): void
    {
        $request = $this->request(self::MONDAY, '17:00', '19:00');

        $credit = $this->credit($request, self::MONDAY, '08:00:00', null);

        $this->assertSame($request->id, $credit->requestId);
        $this->assertSame(0, $credit->total());
    }

    public function test_a_full_day_leave_credits_nothing_and_says_so_in_the_log(): void
    {
        Log::spy();
        $request = $this->request(self::MONDAY, '17:00', '19:00');
        $leave = Leave::factory()->approved()->between(self::MONDAY, self::MONDAY)->create();

        $credit = $this->credit($request, self::MONDAY, '08:00:00', '19:00:00', leaveDay: LeaveDay::on(collect([$leave]), Carbon::parse(self::MONDAY)));

        $this->assertSame([$request->id, 0], [$credit->requestId, $credit->total()]);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, "Overtime request #{$request->id}") && str_contains($message, 'full-day leave'));
    }

    /**
     * Owner's decision (Phase 4b): overtime truncates like late and early
     * minutes — one rule for every minute figure in the app, so partial
     * minutes are never credited, even though here truncating doesn't favour
     * the employee. Do not "fix" it to round().
     */
    public function test_overtime_truncates_partial_minutes(): void
    {
        $request = $this->request(self::MONDAY, '18:00', '19:30');

        // 18:00:00–19:20:59 is 80 minutes 59 seconds: 80, not 81.
        $this->assertSame([80, 0, 0, 0], $this->minutes($this->credit($request, self::MONDAY, '08:00:00', '19:20:59')));
        // 59 seconds of overtime is none.
        $this->assertSame([0, 0, 0, 0], $this->minutes($this->credit($request, self::MONDAY, '08:00:00', '18:00:59')));
    }

    public function test_the_categories_always_add_up_to_the_truncated_total_at_a_boundary(): void
    {
        // 21:59:30–22:00:45: 30 s workday + 45 s night = 75 s, one minute in all.
        // Floored apart they'd be 0 + 0; the minute goes to night, the higher precedence.
        $request = $this->request(self::MONDAY, '21:00', '23:00');

        $credit = $this->credit($request, self::MONDAY, '21:59:30', '22:00:45');

        $this->assertSame([0, 1, 0, 0], $this->minutes($credit));
        $this->assertSame(1, $credit->total());
    }
}
