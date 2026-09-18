<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Livewire\Attendance\Show;
use App\Models\AttendanceLog;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CalendarViewTest extends TestCase
{
    use RefreshDatabase;

    // 2026-03-01 is a Sunday (zero leading padding, 31 days, 4 trailing).
    private const SUNDAY_START_MONTH = '2026-03';

    // 2026-08-01 is a Saturday (6 leading padding, 31 days, 5 trailing).
    private const SATURDAY_START_MONTH = '2026-08';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->create([
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'grace_minutes' => 10,
            'break_minutes' => 60,
            'workdays' => [1, 2, 3, 4, 5],
            'is_default' => true,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    public function test_calendar_is_the_default_view(): void
    {
        $employee = Employee::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->assertSet('view', 'calendar');
    }

    public function test_the_view_toggle_switches_and_persists_via_url(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('attendance.show', $employee).'?view=table')
            ->assertOk();

        Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('view', 'table')
            ->assertSet('view', 'table');
    }

    public function test_first_column_is_sunday_for_a_month_starting_on_a_sunday(): void
    {
        $employee = Employee::factory()->create();

        $grid = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::SUNDAY_START_MONTH)
            ->instance()
            ->render()
            ->getData()['gridDays'];

        // March 2026 starts and ends within its own week (1st = Sunday,
        // 31 days), so a correct grid needs zero leading padding.
        $this->assertTrue($grid->first()['inMonth']);
        $this->assertSame('2026-03-01', $grid->first()['date']->format('Y-m-d'));
        $this->assertSame('Sunday', $grid->first()['date']->format('l'));

        // 35 cells (5 weeks): 0 leading + 31 days + 4 trailing.
        $this->assertCount(35, $grid);
        $this->assertFalse($grid->last()['inMonth']);
        $this->assertSame('2026-04-04', $grid->last()['date']->format('Y-m-d'));
    }

    public function test_grid_is_correct_for_a_month_starting_on_a_saturday(): void
    {
        $employee = Employee::factory()->create();

        $grid = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::SATURDAY_START_MONTH)
            ->instance()
            ->render()
            ->getData()['gridDays'];

        // August 2026 starts on a Saturday — the classic off-by-one case:
        // 6 leading padding cells (Sun–Fri) before the 1st lands in the
        // grid's 7th (Saturday) column.
        $this->assertSame('Sunday', $grid->first()['date']->format('l'));
        $this->assertSame('2026-07-26', $grid->first()['date']->format('Y-m-d'));
        $this->assertFalse($grid->first()['inMonth']);

        $firstInMonth = $grid->first(fn (array $cell) => $cell['inMonth']);
        $this->assertSame('2026-08-01', $firstInMonth['date']->format('Y-m-d'));
        $this->assertSame('Saturday', $firstInMonth['date']->format('l'));
        $this->assertSame(6, $grid->search(fn (array $cell) => $cell['inMonth']));

        // 42 cells (6 weeks): 6 leading + 31 days + 5 trailing.
        $this->assertCount(42, $grid);
    }

    public function test_leading_and_trailing_cells_are_muted_not_clickable_and_excluded_from_summary(): void
    {
        $employee = Employee::factory()->create();

        // March 2026 has zero leading padding (1st is a Sunday), so the
        // padding cells to check are trailing ones — 2026-04-01 is the
        // first of them. A record that genuinely exists there proves
        // padding cells are excluded by construction (never looked up),
        // not just coincidentally empty.
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-04-01',
            'status' => AttendanceStatus::Present,
        ]);

        $component = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::SUNDAY_START_MONTH);

        $data = $component->instance()->render()->getData();
        $grid = $data['gridDays'];
        $paddingCell = $grid->first(fn (array $cell) => $cell['date']->format('Y-m-d') === '2026-04-01');

        $this->assertNotNull($paddingCell);
        $this->assertFalse($paddingCell['inMonth']);
        $this->assertNull($paddingCell['record']);

        // Not clickable: no openDay() call for that date anywhere in the markup.
        $component->assertDontSeeHtml("openDay('2026-04-01')");

        // Excluded from the summary — March has zero present days configured,
        // so if Feb 28's row leaked in, this would be 1, not 0.
        $this->assertSame(0, $data['summary']['present']);
    }

    public function test_a_day_with_no_record_renders_as_not_calculated_in_the_calendar(): void
    {
        $employee = Employee::factory()->create();

        $component = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::SUNDAY_START_MONTH);

        $grid = $component->instance()->render()->getData()['gridDays'];
        $day = $grid->first(fn (array $cell) => $cell['inMonth'] && $cell['date']->format('Y-m-d') === '2026-03-05');

        $this->assertNull($day['record']);
        $component->assertSee('March 5, 2026, Not calculated');
        // The dash icon's distinguishing path, not the clock/x-mark/etc used
        // by real statuses — "not calculated" must not borrow another
        // status's icon.
        $component->assertSeeHtml('M5 12h14');
    }

    public function test_each_status_renders_its_own_icon(): void
    {
        $employee = Employee::factory()->create();

        $statuses = [
            '2026-03-02' => [AttendanceStatus::Present, 'M4.5 12.75l6 6 9-13.5'],
            '2026-03-03' => [AttendanceStatus::Late, 'M12 6v6h4.5m4.5 0a9'],
            '2026-03-04' => [AttendanceStatus::Incomplete, 'M12 9v3.75m-9.303'],
            '2026-03-05' => [AttendanceStatus::Absent, 'M6 18 18 6M6 6l12 12'],
            '2026-03-01' => [AttendanceStatus::Off, 'M6.75 3v2.25M17.25 3v2.25'],
        ];

        foreach ($statuses as $date => [$status, $iconPath]) {
            DailyAttendance::factory()->create([
                'employee_id' => $employee->id,
                'work_date' => $date,
                'status' => $status,
            ]);
        }

        $component = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::SUNDAY_START_MONTH);

        foreach ($statuses as [$status, $iconPath]) {
            $component->assertSeeHtml($iconPath);
        }
    }

    public function test_every_cell_carries_an_accessible_label(): void
    {
        $employee = Employee::factory()->create();
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-10',
            'status' => AttendanceStatus::Present,
        ]);

        Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::SUNDAY_START_MONTH)
            ->assertSee('March 10, 2026, Present')
            ->assertSee('March 5, 2026, Not calculated');
    }

    public function test_clicking_a_day_opens_the_modal_with_that_days_punches(): void
    {
        $employee = Employee::factory()->create();
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => '2026-03-10 07:55:00',
            'punch_type' => 'in',
        ]);

        Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::SUNDAY_START_MONTH)
            ->call('openDay', '2026-03-10')
            ->assertSet('dayModalOpen', true)
            ->assertSet('viewingDay', '2026-03-10')
            ->assertSee('7:55');
    }

    public function test_admin_can_add_and_void_a_punch_from_the_modal_and_the_summary_updates_immediately(): void
    {
        $employee = Employee::factory()->create();

        $component = Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::SUNDAY_START_MONTH)
            ->call('openDay', '2026-03-02') // a Monday — scheduled workday
            ->assertSet('dayModalOpen', true)
            ->call('startAddingPunch', '2026-03-02')
            ->set('newPunchDate', '2026-03-02')
            ->set('newPunchTime', '07:55')
            ->set('newPunchType', 'in')
            ->call('addPunch')
            ->assertHasNoErrors();

        // The modal is still open (addPunch doesn't close it) and its own
        // data — recomputed on this same render — must already reflect the
        // new punch, not a stale "not calculated" state.
        $data = $component->instance()->render()->getData();
        $record = $data['recordsByDate']->get('2026-03-02');
        $this->assertNotNull($record);
        $this->assertSame(AttendanceStatus::Incomplete, $record->status);
        $this->assertNotNull($record->first_in);

        $punch = AttendanceLog::where('employee_id', $employee->id)->firstOrFail();

        $component->call('voidPunch', $punch->id);

        $dataAfterVoid = $component->instance()->render()->getData();
        $recordAfterVoid = $dataAfterVoid['recordsByDate']->get('2026-03-02');
        $this->assertSame(AttendanceStatus::Absent, $recordAfterVoid->status);
    }

    /**
     * The brief's rebuild rule (D-1, D, D+1) is shared code with the table
     * view (rebuildAround()), but explicitly asked to be re-verified from
     * the modal path specifically, not assumed from the table's own tests.
     */
    public function test_the_modals_add_punch_path_triggers_the_same_three_day_rebuild_as_the_table(): void
    {
        $employee = Employee::factory()->create();

        // An out-punch just after midnight on 2026-03-03, with nothing yet
        // to claim it as an earlier day's overnight tail — the builder
        // reads it as 2026-03-03's own (unclaimed) "forgot to punch in"
        // signal.
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => '2026-03-03 01:00:00',
            'punch_type' => 'out',
        ]);

        $before = app(DailySummaryBuilder::class)->build($employee, Carbon::parse('2026-03-03'));
        $this->assertSame(AttendanceStatus::Incomplete, $before->status);
        $this->assertNotNull($before->last_out);

        Livewire::actingAs($this->admin())
            ->test(Show::class, ['employee' => $employee])
            ->set('month', self::SUNDAY_START_MONTH)
            ->call('openDay', '2026-03-02')
            ->call('startAddingPunch', '2026-03-02')
            ->set('newPunchDate', '2026-03-02')
            ->set('newPunchTime', '20:00')
            ->set('newPunchType', 'in')
            ->call('addPunch')
            ->assertHasNoErrors();

        // 2026-03-02 now pairs as an overnight shift into 2026-03-03...
        $mar2 = DailyAttendance::where('employee_id', $employee->id)->whereDate('work_date', '2026-03-02')->first();
        $this->assertNotNull($mar2->last_out);
        $this->assertTrue($mar2->isOvernightOut());

        // ...and 2026-03-03 — a day the modal was never open on — must have
        // been rebuilt too: its 01:00 out-punch is now claimed by 2026-03-02's
        // shift, so it reverts from Incomplete to Absent rather than staying
        // stale at the "before" state asserted above.
        $mar3 = DailyAttendance::where('employee_id', $employee->id)->whereDate('work_date', '2026-03-03')->first();
        $this->assertNull($mar3->last_out);
        $this->assertSame(AttendanceStatus::Absent, $mar3->status);
    }

    public function test_a_manager_cannot_add_or_void_from_the_modal(): void
    {
        $topManager = Employee::factory()->create();
        $subordinate = Employee::factory()->create(['manager_id' => $topManager->id]);
        $managerUser = User::factory()->create()->assignRole('manager');
        $topManager->update(['user_id' => $managerUser->id]);

        $punch = AttendanceLog::factory()->create([
            'employee_id' => $subordinate->id,
            'punched_at' => '2026-03-02 07:55:00',
            'punch_type' => 'in',
        ]);

        Livewire::actingAs($managerUser)
            ->test(Show::class, ['employee' => $subordinate])
            ->set('month', self::SUNDAY_START_MONTH)
            ->call('openDay', '2026-03-02')
            ->assertSet('dayModalOpen', true) // viewing is allowed
            ->call('addPunch')
            ->assertForbidden();

        Livewire::actingAs($managerUser)
            ->test(Show::class, ['employee' => $subordinate])
            ->set('month', self::SUNDAY_START_MONTH)
            ->call('openDay', '2026-03-02')
            ->call('voidPunch', $punch->id)
            ->assertForbidden();
    }

    public function test_an_employee_on_my_attendance_sees_the_calendar_but_no_punch_controls(): void
    {
        $user = User::factory()->create()->assignRole('employee');
        $employee = Employee::factory()->create(['user_id' => $user->id]);
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'punched_at' => '2026-03-02 07:55:00',
            'punch_type' => 'in',
        ]);
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-03-02',
            'status' => AttendanceStatus::Present,
        ]);

        $this->actingAs($user)
            ->get(route('attendance.mine').'?month='.self::SUNDAY_START_MONTH)
            ->assertOk()
            ->assertSee('Calendar')
            // Initial load shows no day panel for anyone (nothing is open
            // yet) — that alone wouldn't prove the controls are actually
            // gated, so open a day (allowed — this is their own record)
            // and confirm the raw punch shows but no add/void controls do.
            ->assertDontSee('+ Add punch');

        Livewire::actingAs($user)
            ->test(Show::class)
            ->set('month', self::SUNDAY_START_MONTH)
            ->call('openDay', '2026-03-02')
            ->assertSet('dayModalOpen', true)
            // Just the numeric part: <x-time> splits the meridiem into its
            // own span for muted styling, so "7:55 AM" isn't one contiguous
            // string in the raw markup even though it reads that way.
            ->assertSee('7:55')
            ->assertDontSee('+ Add punch')
            ->assertDontSee('Void');
    }
}
