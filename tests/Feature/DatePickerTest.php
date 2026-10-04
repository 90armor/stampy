<?php

namespace Tests\Feature;

use App\Livewire\Attendance\Show;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The single-date mode of the date picker (<x-date-picker>), and its rollout
 * to every date field (docs/ATTENDANCE_UI.md, Date picker).
 */
class DatePickerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        WorkSchedule::factory()->create(['is_default' => true]);
    }

    public function test_a_single_date_field_is_the_shared_picker_with_a_native_input_below_sm(): void
    {
        $this->travelTo('2026-03-04 10:00:00');

        $html = (string) $this->blade('<x-date-picker id="holiday_date" model="date" label="Date" min="2026-01-01" max="2026-03-04" />');

        // Single mode, bound to the one property, today from the server, the bounds passed through.
        $this->assertStringContainsString("datePicker({ mode: 'single', model: 'date', today: '2026-03-04', min: '2026-01-01', max: '2026-03-04' })", $html);
        $this->assertMatchesRegularExpression('/<div\s+class="relative hidden sm:block"\s+wire:ignore/', $html);
        // The trigger keeps the field's id, so its <x-input-label for> still points at it, and names the chosen date.
        $this->assertMatchesRegularExpression('/<button\s+type="button"\s+id="holiday_date"\s+x-ref="trigger"/', $html);
        $this->assertStringContainsString('aria-haspopup="dialog"', $html);
        $this->assertStringContainsString(":aria-label=\"'Date' + ', ' + (displayLong || 'no date selected')\"", $html);
        // The shared calendar, in a fixed panel placed against the trigger.
        $this->assertStringContainsString('<div x-ref="picker" @keydown="onKeydown($event)"', $html);
        $this->assertMatchesRegularExpression('/id="holiday_date-panel"\s+x-ref="panel"[^>]*class="fixed /s', $html);
        // Below 640px: a native input on the same property, with the same bounds.
        $this->assertMatchesRegularExpression('/<input\s+type="date"\s+id="holiday_date-native"\s+aria-label="Date"\s+wire:model="date"\s+min="2026-01-01"\s+max="2026-03-04"[^>]*sm:hidden/s', $html);
    }

    public function test_an_unbounded_field_renders_no_min_or_max(): void
    {
        $html = (string) $this->blade('<x-date-picker id="emp_join_date" model="join_date" label="Join date" />');

        $this->assertStringContainsString('min: null, max: null', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*\b(min|max)="/s', $html);
    }

    public function test_today_is_offered_only_when_today_is_inside_the_fields_bounds(): void
    {
        $this->travelTo('2026-03-04 10:00:00');
        $today = '@click="pickToday()"';

        $this->assertStringContainsString($today, (string) $this->blade('<x-date-picker id="d" model="date" label="Date" />'));
        // Today on either edge still counts as inside.
        $this->assertStringContainsString($today, (string) $this->blade('<x-date-picker id="d" model="date" label="Date" min="2026-03-04" max="2026-03-04" />'));
        // A min after today (an employee who joins next month) or a max before it: no Today.
        $this->assertStringNotContainsString($today, (string) $this->blade('<x-date-picker id="d" model="date" label="Date" min="2026-04-01" />'));
        $this->assertStringNotContainsString($today, (string) $this->blade('<x-date-picker id="d" model="date" label="Date" max="2026-03-03" />'));
    }

    public function test_clear_is_only_for_nullable_fields_and_no_current_date_field_is_nullable(): void
    {
        $clear = '@click="clear()"';

        $this->assertStringNotContainsString($clear, (string) $this->blade('<x-date-picker id="d" model="date" label="Date" />'));
        $this->assertStringContainsString($clear, (string) $this->blade('<x-date-picker id="d" model="date" label="Date" clearable />'));

        // Every date field's rule today is `required` (holiday date, join date,
        // both effective dates, punch date), so none of them may offer Clear.
        // When a nullable date field is added, pass `clearable` and add a test
        // that clearing it persists null.
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (str_contains($file->getContents(), '<x-date-picker ')) {
                $this->assertDoesNotMatchRegularExpression('/<x-date-picker\b[^>]*\bclearable\b/', $file->getContents(), $file->getFilename());
            }
        }
    }

    public function test_no_date_field_uses_a_bare_native_date_input(): void
    {
        // Native date inputs live only inside the picker (its below-640px
        // fallback) and in the Daily Attendance range's own fallback.
        $allowed = ['resources/views/components/date-picker.blade.php', 'resources/views/livewire/attendance/index.blade.php'];
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $relative = str_replace(base_path().'/', '', $file->getPathname());
            if (! in_array($relative, $allowed, true) && str_contains($file->getContents(), 'type="date"')) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_the_manual_punch_date_is_bounded_like_its_rule_join_date_to_today(): void
    {
        $this->travelTo('2026-03-04 10:00:00');
        $employee = Employee::factory()->create(['join_date' => '2026-02-10']);

        $html = Livewire::actingAs(User::factory()->create()->assignRole('admin'))
            ->test(Show::class, ['employee' => $employee])
            ->set('month', '2026-03')
            ->call('openDay', '2026-03-02')
            ->call('startAddingPunch', '2026-03-02')
            ->html();

        $this->assertStringContainsString("model: 'newPunchDate', today: '2026-03-04', min: '2026-02-10', max: '2026-03-04'", $html);
    }

    public function test_a_filled_value_inside_a_dark_popover_has_an_inset_edge_and_the_calendar_today_circle_does_not(): void
    {
        // primary-500 is under 3:1 against the dark popover and range band; a primary-400 edge reaches 3:1 on both.
        $edge = 'dark:shadow-[inset_0_0_0_1px_theme(colors.primary.400)]';

        $this->assertStringContainsString($edge, File::get(resource_path('views/components/date-picker/calendar.blade.php')));
        $this->assertStringContainsString($edge, File::get(resource_path('views/components/time-input.blade.php')));
        // The employee calendar sits on the card, where the fill alone is 3.26:1.
        $this->assertStringNotContainsString($edge, File::get(resource_path('views/livewire/attendance/show.blade.php')));
    }

    public function test_opening_moves_focus_into_the_grid_on_the_selected_date_else_today_else_the_first_enabled_day(): void
    {
        $js = File::get(resource_path('js/app.js'));

        // The order the docs promise (docs/ATTENDANCE_UI.md, Date picker, Keyboard).
        $this->assertMatchesRegularExpression('/initialFocus\(\) \{\s*if \(isValidIso\(this\.to\) && !this\.isDisabled\(this\.to\)\) return this\.to;\s*if \(!this\.isDisabled\(this\.today\)\) return this\.today;\s*return this\.min \?\? this\.max;/', $js);
        // openPanel() focuses that cell once the grid has rendered.
        $this->assertMatchesRegularExpression('/this\.focused = this\.initialFocus\(\);[\s\S]{0,600}querySelector\(`\[data-date="\$\{this\.focused\}"\]`\)[\s\S]{0,120}cell\.focus\(\)/', $js);
    }
}

