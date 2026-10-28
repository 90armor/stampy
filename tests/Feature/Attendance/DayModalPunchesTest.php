<?php

namespace Tests\Feature\Attendance;

use App\Livewire\Attendance\Show;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Attendance\DailySummaryBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The day modal's raw punches say which shift an overnight (+1) out-punch
 * belongs to, on both days and across a month boundary, and the void
 * confirmation opens above the modal.
 */
class DayModalPunchesTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        WorkSchedule::factory()->create([
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'workdays' => [1, 2, 3, 4, 5],
            'is_default' => true,
        ]);
        $this->travelTo(Carbon::parse('2026-04-15 12:00:00'));

        // Tuesday 31 March's shift ends at 00:42 on Wednesday 1 April.
        $this->employee = Employee::factory()->create();
        foreach ([['2026-03-31 07:56:00', 'in'], ['2026-04-01 00:42:00', 'out'], ['2026-04-01 07:51:00', 'in'], ['2026-04-01 15:12:00', 'out']] as [$at, $type]) {
            AttendanceLog::factory()->create(['employee_id' => $this->employee->id, 'punched_at' => $at, 'punch_type' => $type]);
        }
        $builder = app(DailySummaryBuilder::class);
        $builder->build($this->employee, Carbon::parse('2026-03-31'));
        $builder->build($this->employee, Carbon::parse('2026-04-01'));
    }

    private function modal(string $month, string $day): string
    {
        return Livewire::actingAs(User::factory()->create()->assignRole('admin'))
            ->test(Show::class, ['employee' => $this->employee])
            ->set('month', $month)
            ->call('openDay', $day)
            ->html();
    }

    public function test_the_next_day_lists_the_overnight_out_as_the_end_of_the_previous_months_shift(): void
    {
        $html = $this->modal('2026-04', '2026-04-01');

        $this->assertStringContainsString("ends Tue 31 Mar's shift", $html);
        // Only the 00:42 punch carries it; the day's own punches don't.
        $this->assertSame(1, substr_count($html, "'s shift"));
    }

    public function test_the_shifts_own_day_lists_its_overnight_out_marked_plus_one(): void
    {
        $html = $this->modal('2026-03', '2026-03-31');

        $this->assertStringContainsString('recorded Wed 1 Apr', $html);
        $this->assertStringContainsString('Raw punches', $html);
        // In + the (+1) out: two punches, each with its own Void.
        $this->assertSame(2, substr_count($html, 'aria-label="Void the '));
    }

    public function test_the_void_confirmation_is_teleported_above_modals(): void
    {
        // Inspect the rendered markup, never the source: a comment that
        // mentions z-[60] must not be able to satisfy this.
        $dialog = Blade::render('<x-confirm-dialog event="confirm-probe" />');
        $modal = Blade::render('<x-modal name="probe"><p>Body</p></x-modal>');

        $this->assertStringStartsWith('<template x-teleport="body">', trim($dialog));

        // The overlay (the element that becomes visible) sits above every modal.
        $this->assertSame(1, preg_match('/<div\s+x-show="open"[^>]*class="([^"]*)"/', $dialog, $overlay));
        $this->assertSame(1, preg_match('/(?<![\w-])z-\[?(\d+)\]?(?![\w-])/', $overlay[1], $dialogZ));
        $this->assertSame(1, preg_match('/aria-modal="true"[^>]*class="[^"]*(?<![\w-])z-\[?(\d+)\]?(?![\w-])/s', $modal, $modalZ));
        $this->assertGreaterThan((int) $modalZ[1], (int) $dialogZ[1], 'The confirmation must stack above <x-modal>.');

        // Escape closes the confirmation only: caught on window in the capture phase while open.
        $this->assertMatchesRegularExpression('/@keydown\.escape\.window\.capture="if \(open\) \{ \$event\.stopPropagation\(\); close\(\); \}"/', $dialog);
    }
}
