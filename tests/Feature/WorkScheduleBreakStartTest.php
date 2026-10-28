<?php

namespace Tests\Feature;

use App\Exceptions\InvalidWorkScheduleException;
use App\Exceptions\WorkScheduleLockedException;
use App\Livewire\Schedules\Index;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\WorkScheduleBreakStartBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3a — work_schedules.break_start, the morning/afternoon boundary
 * half-day leave will use: its shape rule, its place in the lock (with the
 * one null → value exception), the Schedules form field, and the backfill.
 */
class WorkScheduleBreakStartTest extends TestCase
{
    use RefreshDatabase;

    private function schedule(array $overrides = []): WorkSchedule
    {
        return WorkSchedule::factory()->create(array_merge([
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'break_minutes' => 60,
        ], $overrides));
    }

    /** A schedule something references, so its calculation fields are locked. */
    private function lockedSchedule(array $overrides = []): WorkSchedule
    {
        $schedule = $this->schedule(['is_default' => true, ...$overrides]);
        Employee::factory()->create();

        return $schedule;
    }

    // ── Shape ──────────────────────────────────────────────────────────

    public function test_a_break_that_ends_exactly_at_end_time_is_allowed(): void
    {
        $schedule = $this->schedule(['break_start' => '16:00:00']);

        $this->assertSame('16:00:00', $schedule->fresh()->break_start);
    }

    public function test_a_break_that_runs_past_end_time_is_rejected(): void
    {
        $this->expectException(InvalidWorkScheduleException::class);

        $this->schedule(['break_start' => '16:01:00']);
    }

    public function test_a_break_starting_at_or_before_start_time_is_rejected(): void
    {
        foreach (['08:00:00', '07:30:00'] as $breakStart) {
            try {
                $this->schedule(['break_start' => $breakStart]);
                $this->fail("break_start {$breakStart} should be rejected");
            } catch (InvalidWorkScheduleException $e) {
                $this->assertStringContainsString('after the start time', $e->getMessage());
            }
        }

        $this->assertSame('08:01:00', $this->schedule(['break_start' => '08:01:00'])->fresh()->break_start);
    }

    public function test_a_break_start_needs_a_break_duration(): void
    {
        $this->expectException(InvalidWorkScheduleException::class);
        $this->expectExceptionMessage('needs a break duration');

        $this->schedule(['break_minutes' => 0, 'break_start' => '12:00:00']);
    }

    public function test_no_break_start_is_valid_with_or_without_a_break(): void
    {
        $this->assertNull($this->schedule(['break_minutes' => 0])->fresh()->break_start);
        $this->assertNull($this->schedule()->fresh()->break_start);
    }

    // ── Lock ───────────────────────────────────────────────────────────

    public function test_a_locked_schedule_takes_a_first_break_start(): void
    {
        $schedule = $this->lockedSchedule();

        $schedule->update(['break_start' => '12:00:00']);

        $this->assertSame('12:00:00', $schedule->fresh()->break_start);
    }

    public function test_a_locked_schedules_first_break_start_still_has_to_fit(): void
    {
        $schedule = $this->lockedSchedule();

        $this->expectException(InvalidWorkScheduleException::class);

        $schedule->update(['break_start' => '16:30:00']);
    }

    public function test_a_locked_schedules_break_start_cannot_be_changed_once_set(): void
    {
        $schedule = $this->lockedSchedule(['break_start' => '12:00:00']);

        $this->expectException(WorkScheduleLockedException::class);

        $schedule->update(['break_start' => '12:30:00']);
    }

    public function test_a_locked_schedules_break_start_cannot_be_cleared(): void
    {
        $schedule = $this->lockedSchedule(['break_start' => '12:00:00']);

        $this->expectException(WorkScheduleLockedException::class);

        $schedule->update(['break_start' => null]);
    }

    public function test_the_first_break_start_exception_does_not_unlock_the_other_fields(): void
    {
        $schedule = $this->lockedSchedule();

        $this->expectException(WorkScheduleLockedException::class);

        $schedule->update(['break_start' => '12:00:00', 'break_minutes' => 30]);
    }

    public function test_an_unreferenced_schedules_break_start_can_change_freely(): void
    {
        $schedule = $this->schedule(['break_start' => '12:00:00']);

        $schedule->update(['break_start' => '12:30:00']);
        $this->assertSame('12:30:00', $schedule->fresh()->break_start);

        $schedule->update(['break_start' => null]);
        $this->assertNull($schedule->fresh()->break_start);
    }

    // ── Form ───────────────────────────────────────────────────────────

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'admin']);

        return User::factory()->create()->assignRole('admin');
    }

    public function test_the_form_saves_and_clears_a_break_start(): void
    {
        $schedule = $this->schedule();

        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->call('edit', $schedule->id)
            ->assertSet('break_start', '')
            ->set('break_start', '12:00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('12:00:00', $schedule->fresh()->break_start);

        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->call('edit', $schedule->id)
            ->assertSet('break_start', '12:00')
            ->set('break_start', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($schedule->fresh()->break_start);
    }

    public function test_the_form_reports_a_break_that_does_not_fit(): void
    {
        Livewire::actingAs($this->admin())
            ->test(Index::class)
            ->call('create')
            ->set('name', 'Short day')
            ->set('break_minutes', 60)
            ->set('break_start', '16:30')
            ->call('save')
            ->assertHasErrors(['form']);

        $this->assertFalse(WorkSchedule::where('name', 'Short day')->exists());
    }

    public function test_on_a_locked_schedule_the_field_is_editable_until_it_is_first_set(): void
    {
        $schedule = $this->lockedSchedule();
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('edit', $schedule->id)
            ->assertSee('Schedule configuration is locked')
            ->assertSee("timeInput({ model: 'break_start', min: null, max: null, after: 'start_time', capDate: null, nowCap:", false)
            ->assertSee('Can be set once on a locked schedule.')
            ->set('break_start', '12:00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('12:00:00', $schedule->fresh()->break_start);

        $html = Livewire::actingAs($admin)
            ->test(Index::class)
            ->call('edit', $schedule->id)
            ->assertDontSee('Can be set once on a locked schedule.')
            ->html();

        // Shown read-only now, the way the other locked fields render.
        $this->assertMatchesRegularExpression("/timeInput\\(\\{ model: 'break_start'[^)]*disabled: true \\}\\)/", $html);
    }

    // ── Backfill ───────────────────────────────────────────────────────

    public function test_the_backfill_sets_noon_only_where_the_break_fits_there(): void
    {
        $fits = $this->schedule();
        $endsAtEnd = $this->schedule(['start_time' => '06:00:00', 'end_time' => '13:00:00', 'break_minutes' => 60]);
        $noBreak = $this->schedule(['break_minutes' => 0]);
        $afternoonShift = $this->schedule(['start_time' => '13:00:00', 'end_time' => '22:00:00']);
        $startsAtNoon = $this->schedule(['start_time' => '12:00:00', 'end_time' => '20:00:00']);
        $breakTooLong = $this->schedule(['start_time' => '06:00:00', 'end_time' => '12:30:00', 'break_minutes' => 60]);
        $alreadySet = $this->schedule(['break_start' => '11:30:00']);

        // Lock one of them: the backfill must still reach it (query builder, not the model).
        $fits->update(['is_default' => true]);
        Employee::factory()->create();

        $this->assertSame(2, WorkScheduleBreakStartBackfill::run());

        $this->assertSame('12:00:00', $fits->fresh()->break_start);
        $this->assertSame('12:00:00', $endsAtEnd->fresh()->break_start);
        $this->assertNull($noBreak->fresh()->break_start);
        $this->assertNull($afternoonShift->fresh()->break_start);
        $this->assertNull($startsAtNoon->fresh()->break_start);
        $this->assertNull($breakTooLong->fresh()->break_start);
        $this->assertSame('11:30:00', $alreadySet->fresh()->break_start);
        $this->assertSame(2, DB::table('work_schedules')->where('break_start', '12:00:00')->count());
    }
}
