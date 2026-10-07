<?php

namespace Tests\Feature\Overtime;

use App\Enums\OvertimeCompensation;
use App\Livewire\Attendance\Index;
use App\Livewire\Attendance\Show;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Overtime\OvertimeRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4d — credited overtime in the attendance views: "OT 2h 00m" as an
 * annotation in the calendar cell and the tables, never a status colour; an
 * Overtime filter on Daily attendance; and the day modal's Overtime section.
 * The clock is Mon 15 Jun 2026 12:00.
 */
class OvertimeAttendanceViewsTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->withBreakStart()->create([
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'break_minutes' => 60, 'break_start' => '12:00:00',
            'workdays' => [1, 2, 3, 4, 5], 'is_default' => true,
        ]);
        $this->employee = Employee::factory()->create(['full_name' => 'Kyaw Kyaw', 'user_id' => User::factory()->create()->assignRole('employee')->id]);
        $this->admin = User::factory()->create()->assignRole('admin');
    }

    private function overtime(string $date, ?string $out, string $from = '17:00', string $to = '19:00'): void
    {
        AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => "{$date} 08:00:00", 'punch_type' => 'in']);
        if ($out !== null) {
            AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => "{$date} {$out}", 'punch_type' => 'out']);
        }

        app(OvertimeRequestService::class)->submit(
            $this->employee, Carbon::parse($date), Carbon::parse("{$date} {$from}"), Carbon::parse("{$date} {$to}"), OvertimeCompensation::Pay, null, $this->admin,
        );
    }

    public function test_the_tables_annotate_credited_overtime_and_daily_attendance_filters_by_it(): void
    {
        $this->overtime('2026-06-10', '19:00:00');
        $this->overtime('2026-06-11', '17:00:00', '17:30', '19:00');

        $page = Livewire::actingAs($this->admin)->test(Index::class)
            ->set('fromDate', '2026-06-10')->set('toDate', '2026-06-11')
            ->assertSee('OT 2h 00m');

        $page->call('toggleOvertime')
            ->assertSet('overtime', true)
            ->assertSee('Wed 10 Jun')
            ->assertDontSee('Thu 11 Jun');

        // The per-employee table view says the same.
        Livewire::actingAs($this->admin)->test(Show::class, ['employee' => $this->employee])
            ->set('month', '2026-06')->set('view', 'table')
            ->assertSee('OT 2h 00m');
    }

    public function test_the_calendar_cell_carries_the_annotation_not_a_colour(): void
    {
        $this->overtime('2026-06-10', '19:00:00');

        Livewire::actingAs($this->admin)->test(Show::class, ['employee' => $this->employee])
            ->set('month', '2026-06')
            ->assertSee('OT 2h 00m')
            ->assertSeeHtml('Wednesday, 10 June 2026, Present, overtime 2h 00m');
    }

    public function test_the_day_modal_shows_the_request_and_what_it_credited_or_why_not(): void
    {
        $this->overtime('2026-06-10', '19:00:00', '21:00', '23:00');
        $this->overtime('2026-06-11', null);

        // 10 Jun: out at 19:00, before the 21:00 window.
        Livewire::actingAs($this->admin)->test(Show::class, ['employee' => $this->employee])
            ->set('month', '2026-06')
            ->call('openDay', '2026-06-10')
            ->assertSee('9:00 PM – 11:00 PM')
            ->assertSee('Claim · Pay')
            ->assertSee('Nothing credited — out at 7:00 PM, before the window')
            ->assertSee('View on profile');

        Livewire::actingAs($this->employee->user)->test(Show::class, ['employee' => $this->employee])
            ->set('month', '2026-06')
            ->call('openDay', '2026-06-11')
            ->assertSee('Nothing credited — no out-punch on Thu 11 Jun')
            ->assertSee('View in Overtime');
    }

    /**
     * The day modal opens on Add punch for an admin and on Close for anyone
     * else (docs/DESIGN_SYSTEM.md, Overlays), never on the Overtime or Leave
     * section's link above them: both are marked autofocus, and <x-modal>
     * takes the first visible one.
     */
    public function test_the_day_modal_still_opens_on_add_punch_or_close_with_an_overtime_link_above(): void
    {
        $this->overtime('2026-06-10', '19:00:00');
        $autofocused = function (string $html): array {
            preg_match_all('/<(a|button)\b([^>]*)>(.*?)<\/\1>/s', $html, $controls, PREG_SET_ORDER);

            return collect($controls)
                ->filter(fn ($control) => preg_match('/\sautofocus(=|\s|$)/', $control[2]))
                ->map(fn ($control) => trim(strip_tags($control[3])))
                ->values()->all();
        };

        $admin = Livewire::actingAs($this->admin)->test(Show::class, ['employee' => $this->employee])
            ->set('month', '2026-06')->call('openDay', '2026-06-10')
            ->assertSee('View on profile');
        $this->assertSame(['Add punch', 'Close'], $autofocused($admin->html()));

        $own = Livewire::actingAs($this->employee->user)->test(Show::class, ['employee' => $this->employee])
            ->set('month', '2026-06')->call('openDay', '2026-06-10')
            ->assertSee('View in Overtime');
        $this->assertSame(['Close'], $autofocused($own->html()));
    }

    public function test_a_credited_pay_day_shows_its_categories_in_the_modal(): void
    {
        $this->overtime('2026-06-12', '23:00:00', '21:00', '23:00');

        Livewire::actingAs($this->admin)->test(Show::class, ['employee' => $this->employee])
            ->set('month', '2026-06')
            ->call('openDay', '2026-06-12')
            ->assertSee('2h 00m credited')
            ->assertSee('Workday (150%) 1h 00m · Night (200%) 1h 00m');
    }
}
