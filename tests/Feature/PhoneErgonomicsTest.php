<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Livewire\Attendance\Index as AttendanceIndex;
use App\Livewire\Employees\Index as EmployeesIndex;
use App\Models\DailyAttendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6f, phone ergonomics: Daily attendance as cards below 640px with
 * its filters behind "Filters (n)" (F06), 44px touch targets on phones and on
 * the auth pages at every width (F23, D3), and the Employees directory's page
 * size and phone pagination (F32). What only a browser can show — that the
 * cards replace the table below sm, that the disclosure opens and survives a
 * re-render — is tests/browser/focus.mjs.
 */
class PhoneErgonomicsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed dates below, so a fixed clock (CLAUDE.md, pinned-instant check).
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));
        WorkSchedule::factory()->create(['is_default' => true]);
        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    /** The phone card list's markup, from its opening tag to its end. */
    private function cards(string $html): string
    {
        $start = strpos($html, '<ul class="border-t border-slate-divider transition-opacity sm:hidden"');
        $this->assertNotFalse($start, 'No card list below sm.');

        return substr($html, $start, strpos($html, '</ul>', $start) - $start);
    }

    // ── F06: Daily attendance on a phone ───────────────────────────────

    public function test_a_card_shows_every_field_the_table_shows(): void
    {
        $engineering = Department::factory()->create(['name' => 'Engineering']);
        $employee = Employee::factory()->create(['full_name' => 'សុខ សុភាព', 'employee_code' => 'EMP-0101', 'department_id' => $engineering->id]);
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-06-12',
            'status' => AttendanceStatus::Present,
            'first_in' => '2026-06-12 08:21:00',
            'last_out' => '2026-06-12 16:40:00',
            'worked_minutes' => 439,
            'late_minutes' => 21,
            'early_leave_minutes' => 20,
        ]);

        $cards = $this->cards(Livewire::actingAs($this->admin())->test(AttendanceIndex::class)
            ->set('fromDate', '2026-06-11')->set('toDate', '2026-06-12')->html());

        foreach (['សុខ សុភាព', 'EMP-0101 · Engineering', 'Present', 'Fri 12 Jun', '>In<', '>Out<', '>Worked<', '>Late<', '>Early leave<', '7h 19m', '21m', '20m'] as $field) {
            $this->assertStringContainsString($field, $cards, "The card has no {$field}.");
        }
        $this->assertStringContainsString('8:21', $cards);
        $this->assertStringContainsString('4:40', $cards);
        // Late and Early in the timing colour, as in the table.
        $this->assertSame(2, substr_count($cards, 'font-medium text-amber-700 dark:text-amber-300'));
        // The chevron is the card's only link, a 44px target.
        $this->assertSame(1, preg_match_all('/<a\b/', $cards));
        $this->assertStringContainsString('aria-label="View attendance for សុខ សុភាព, Fri 12 Jun"', $cards);
        $this->assertStringContainsString('h-11 w-11', $cards);
    }

    public function test_a_one_day_range_leaves_the_date_off_the_cards_and_an_incomplete_day_keeps_its_dashes(): void
    {
        $employee = Employee::factory()->create(['full_name' => 'Aung Kyaw Moe Thandar Htun Myint Myat Phyo Wai Lwin']);
        DailyAttendance::factory()->create([
            'employee_id' => $employee->id,
            'work_date' => '2026-06-12',
            'status' => AttendanceStatus::Incomplete,
            'first_in' => '2026-06-12 08:00:00',
            'last_out' => null,
            'worked_minutes' => 0,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
        ]);

        $cards = $this->cards(Livewire::actingAs($this->admin())->test(AttendanceIndex::class)
            ->set('fromDate', '2026-06-12')->set('toDate', '2026-06-12')->html());

        $this->assertStringNotContainsString('>Date<', $cards);
        $this->assertStringContainsString('Incomplete', $cards);
        // A long name wraps rather than widening the card.
        $this->assertStringContainsString('<p class="break-words text-sm font-medium', $cards);
        // Out, Worked, Late and Early: an em dash each.
        $this->assertSame(4, substr_count($cards, '>—</span>'));
    }

    public function test_the_filters_button_counts_what_it_hides(): void
    {
        $engineering = Department::factory()->create(['name' => 'Engineering']);
        $page = Livewire::actingAs($this->admin())->test(AttendanceIndex::class);

        // Nothing set: no count, and no Reset.
        $page->assertSeeHtml('<span class="whitespace-nowrap">Filters</span>')->assertDontSee('Reset filters');

        // The date range is outside the disclosure: it's never counted.
        $page->set('fromDate', '2026-06-01')->assertSeeHtml('<span class="whitespace-nowrap">Filters</span>');

        $page->set('employeeFilter', 'Kyaw')
            ->set('departmentFilter', (string) $engineering->id)
            ->call('toggleStatus', 'absent')
            ->call('toggleStatus', 'present')
            ->call('toggleStatus', 'off')
            ->call('toggleTimingFilter', 'late')
            ->call('toggleTimingFilter', 'early')
            ->call('toggleWorkedOnLeave')
            ->call('toggleOvertime')
            // Search, department, the status set (once, however many chips),
            // Show off days, each timing chip, worked on leave, overtime.
            ->assertSeeHtml('<span class="whitespace-nowrap">Filters (8)</span>')
            ->assertSee('Reset filters')
            ->call('resetFilters')
            ->assertSeeHtml('<span class="whitespace-nowrap">Filters</span>');
    }

    public function test_the_disclosure_hides_only_below_sm_and_names_what_it_controls(): void
    {
        DailyAttendance::factory()->create(['employee_id' => Employee::factory()->create()->id, 'work_date' => today(), 'status' => AttendanceStatus::Present]);
        $html = Livewire::actingAs($this->admin())->test(AttendanceIndex::class)->html();

        $this->assertStringContainsString('aria-controls="attendance-search-field attendance-department-field attendance-filter-chips"', $html);
        foreach (['attendance-search-field', 'attendance-department-field', 'attendance-filter-chips'] as $id) {
            $this->assertMatchesRegularExpression('/id="'.$id.'" class="[^"]*\bmax-sm:hidden\b[^"]*" :class="\{ \'max-sm:hidden\': ! filtersOpen \}"/', $html);
        }
        // The table is still the view from sm, unchanged.
        $this->assertStringContainsString('<div class="hidden border-t border-slate-divider sm:block">', $html);
    }

    public function test_a_manager_with_no_reports_gets_the_same_empty_state(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        Employee::factory()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)->test(AttendanceIndex::class)
            ->set('employeeFilter', 'nobody at all')
            ->assertSee('No attendance records')
            ->assertDontSeeHtml('aria-label="Attendance records"');
    }

    // ── F23 / D3: 44px touch targets ────────────────────────────────────

    public function test_buttons_are_44px_below_sm_and_control_height_from_sm(): void
    {
        foreach (['<x-button>Save</x-button>', '<x-button href="/">Go</x-button>', '<x-button variant="danger">Reject</x-button>'] as $button) {
            $html = Blade::render($button);
            $this->assertStringContainsString('h-control max-sm:min-h-11', $html);
        }

        foreach (['leave', 'overtime'] as $page) {
            $this->assertStringContainsString('max-sm:min-h-11', File::get(resource_path("views/livewire/{$page}/partials/cancel-button.blade.php")));
        }
    }

    /** Phase 6h, N3 and N4: Approvals' "Show n" and the admin dashboard's quick actions. */
    public function test_show_n_and_the_quick_actions_are_44px_below_sm(): void
    {
        // "{n} more waiting for managers" / "Show {n} …" since Phase 6j.
        $this->assertMatchesRegularExpression("/<button[^>]*wire:click=\"\\\$toggle\\('showOverrides'\\)\"[^>]*max-sm:min-h-11[^>]*>/", File::get(resource_path('views/livewire/leave/approvals.blade.php')));
        $this->assertMatchesRegularExpression('/@foreach \(\$quickActions as \$action\)\s*<a [^>]*class="[^"]*max-sm:min-h-11[^"]*"/', File::get(resource_path('views/dashboard.blade.php')));
    }

    /** D3: the auth pages keep 44px at every width — every field, button and link. */
    public function test_every_auth_page_control_is_44px_at_every_width(): void
    {
        $offenders = [];

        foreach (File::files(resource_path('views/auth')) as $file) {
            preg_match_all('/<(?:x-text-input|x-button|button|a)\b[^>]*>/s', $file->getContents(), $tags);
            foreach ($tags[0] as $tag) {
                if (! preg_match('/class="[^"]*(?<![\w:-])min-h-11\b/', $tag)) {
                    $offenders[] = $file->getFilename().': '.preg_replace('/\s+/', ' ', substr($tag, 0, 90));
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    // ── F32: the Employees directory ───────────────────────────────────

    public function test_the_directory_shows_25_a_page_and_a_page_count_on_a_phone(): void
    {
        Employee::factory()->count(30)->create();

        Livewire::actingAs($this->admin())->test(EmployeesIndex::class)
            ->assertViewHas('employees', fn ($employees) => $employees->count() === 25 && $employees->lastPage() === 2)
            ->assertSeeHtml('Page <span class="font-medium text-slate-900 dark:text-slate-100">1</span>')
            ->assertSeeHtml('of <span class="font-medium text-slate-900 dark:text-slate-100">2</span>');
    }

    public function test_a_long_department_is_one_line_and_a_long_name_wraps(): void
    {
        $department = Department::factory()->create(['name' => 'Human Resources and Administration Department']);
        Employee::factory()->create(['full_name' => 'Aung Kyaw Moe Thandar Htun Myint Myat Phyo Wai Lwin', 'department_id' => $department->id]);

        $html = Livewire::actingAs($this->admin())->test(EmployeesIndex::class)->html();

        // Truncated by CSS only: the full name is still the cell's text.
        $this->assertMatchesRegularExpression('/<td class="truncate [^"]*max-xl:max-w-\[12rem\]">Human Resources and Administration Department<\/td>/', $html);
        $this->assertStringContainsString('<div class="w-max max-w-[14rem]">', $html);
    }
}
