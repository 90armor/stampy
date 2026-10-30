<?php

namespace Tests\Feature;

use App\Livewire\Leave\RequestModal as LeaveRequestModal;
use App\Livewire\Overtime\RequestModal as OvertimeRequestModal;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\User;
use App\Models\WorkSchedule;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6d: the structure the accessibility pass settled (docs/DESIGN_SYSTEM.md,
 * Accessibility) — the skip link, one <h1> a page, the stat strip's <dl>,
 * focusable scrolling tables, the employee picker and the date fields' names.
 */
class AccessibilityFoundationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
        WorkSchedule::factory()->create(['is_default' => true]);
        $this->admin = User::factory()->create()->assignRole('admin');
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    public function test_the_skip_link_is_the_first_focusable_element_and_targets_main(): void
    {
        $html = $this->actingAs($this->admin)->get('/dashboard')->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $first = $xpath->query('//body//*[self::a or self::button or self::input or self::select or self::textarea or @tabindex]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $first);
        $this->assertSame('a', $first->tagName);
        $this->assertSame('Skip to content', trim($first->textContent));
        $this->assertSame('#main-content', $first->getAttribute('href'));
        $this->assertStringContainsString('sr-only', $first->getAttribute('class'));
        $this->assertStringContainsString('focus:not-sr-only', $first->getAttribute('class'));
        $this->assertStringContainsString('dark:bg-slate-750', $first->getAttribute('class'));

        $main = $xpath->query('//main')->item(0);
        $this->assertSame('main-content', $main->getAttribute('id'));
        $this->assertSame('-1', $main->getAttribute('tabindex'));
        $this->assertStringContainsString('focus:outline-none', $main->getAttribute('class'));
    }

    public function test_the_skip_link_works_with_the_phone_drawer_open(): void
    {
        $layout = File::get(resource_path('views/layouts/app.blade.php'));

        // It joins the drawer's focus trap, and closing the drawer for it
        // doesn't hand focus back to the menu button.
        $this->assertStringContainsString('[this.$refs.skipLink, ...this.$refs.sidebarPanel', $layout);
        $this->assertStringContainsString('@keydown.tab="if (sidebarOpen) trapSidebarTab($event)"', $layout);
        $this->assertMatchesRegularExpression('/skipToContent\(\) \{\s+this\.sidebarTrigger = null;\s+this\.sidebarOpen = false;/', $layout);
    }

    public function test_every_kind_of_page_has_exactly_one_h1(): void
    {
        $linked = User::factory()->create()->assignRole('employee');
        Employee::factory()->create(['user_id' => $linked->id]);
        $unlinked = User::factory()->create()->assignRole('employee');

        $assertOne = function (User $user, string $url, int $status = 200) {
            $response = $this->actingAs($user)->get($url)->assertStatus($status);
            $this->assertSame(1, substr_count($response->getContent(), '<h1'), $url);
        };

        $assertOne($this->admin, '/dashboard');
        $assertOne($this->admin, '/profile');
        $assertOne($linked, '/profile');
        $assertOne($linked, '/my-attendance');
        $assertOne($unlinked, '/my-attendance');
        $assertOne($this->admin, '/no-such-page', 404);
        $assertOne($linked, '/employees', 403);
    }

    public function test_the_guest_pages_have_one_h1_at_every_width_and_a_main(): void
    {
        foreach (['/login', '/forgot-password', '/no-such-page'] as $url) {
            $html = $this->get($url)->getContent();
            $xpath = $this->xpath($html);

            $headings = $xpath->query('//h1');
            $this->assertSame(1, $headings->length, $url);
            // Not inside the hero, which is hidden below lg.
            $this->assertSame(0, $xpath->query('//*[contains(@class, "hidden lg:flex")]//h1')->length, $url);
            $this->assertSame(1, $xpath->query('//main//h1')->length, $url);
        }
    }

    public function test_the_error_pages_heading_is_the_h1(): void
    {
        foreach (['403', '404', '419', '500', '503'] as $code) {
            $this->assertStringContainsString('errors.partials.page', File::get(resource_path("views/errors/{$code}.blade.php")));
        }

        $this->assertStringContainsString('heading="h1"', File::get(resource_path('views/errors/partials/page.blade.php')));
        $this->assertStringContainsString('<h1 ', Blade::render('<x-empty-state title="T" heading="h1" />'));
        $this->assertStringContainsString('<h3 ', Blade::render('<x-empty-state title="T" />'));
    }

    public function test_a_stat_cell_is_a_div_of_dt_and_dd_with_the_icon_hidden(): void
    {
        $xpath = $this->xpath(Blade::render(<<<'BLADE'
            <dl><x-stat-card icon="users" label="Total" value="3"><x-slot name="subtext">2 late</x-slot></x-stat-card></dl>
            BLADE));

        $cell = $xpath->query('//dl/*')->item(0);
        $this->assertSame('div', $cell->tagName);
        $this->assertSame(1, $xpath->query('//dl/*')->length);
        $this->assertSame(1, $xpath->query('//dl/div/dt')->length);
        $this->assertSame(2, $xpath->query('//dl/div/dd')->length);
        $this->assertSame(0, $xpath->query('//dl/div/div')->length);

        $this->assertSame(1, $xpath->query('//dl/div/span[@aria-hidden="true"]/svg')->length);
        $this->assertSame(0, $xpath->query('//dt//svg | //dd//svg')->length);
    }

    public function test_every_scrolling_table_goes_through_the_focusable_region(): void
    {
        $offenders = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (str_contains($file->getContents(), 'overflow-x-auto')
                && ! in_array($file->getRelativePathname(), ['components/table-scroll.blade.php', 'components/tab-bar.blade.php'], true)) {
                $offenders[] = $file->getRelativePathname();
            }
        }
        $this->assertSame([], $offenders, 'Wrap a scrolling table in <x-table-scroll label="…">.');

        $xpath = $this->xpath(Blade::render('<x-table-scroll label="Overtime report" class="mt-3"><table></table></x-table-scroll>'));
        $wrapper = $xpath->query('//div[table]')->item(0);
        $this->assertSame('0', $wrapper->getAttribute('tabindex'));
        $this->assertSame('region', $wrapper->getAttribute('role'));
        $this->assertSame('Overtime report', $wrapper->getAttribute('aria-label'));
        $this->assertStringContainsString('overflow-x-auto', $wrapper->getAttribute('class'));
        $this->assertStringContainsString('focus-visible:ring-primary-500', $wrapper->getAttribute('class'));
        $this->assertStringContainsString('mt-3', $wrapper->getAttribute('class'));
    }

    public function test_the_employee_picker_is_a_plain_list_that_says_when_it_is_cut_short(): void
    {
        $this->assertStringNotContainsString('role="listbox"', File::get(resource_path('views/livewire/partials/employee-picker.blade.php')));

        Employee::factory()->count(9)->sequence(fn ($sequence) => ['full_name' => 'Findable Person '.$sequence->index])->create();
        Employee::factory()->count(2)->sequence(fn ($sequence) => ['full_name' => 'Rare Person '.$sequence->index])->create();

        foreach ([LeaveRequestModal::class, OvertimeRequestModal::class] as $modal) {
            Livewire::actingAs($this->admin)->test($modal)
                ->call('openForOthers')
                ->set('employeeSearch', 'Findable')
                ->assertSee('Showing 8 of 9 — keep typing')
                ->assertSee('Findable Person 0')
                ->assertDontSee('Findable Person 8')
                ->assertSee('aria-live="polite"', false)
                ->set('employeeSearch', 'Rare')
                ->assertDontSee('keep typing')
                ->assertSee('2 employees found')
                ->set('employeeSearch', 'Nobody by that name')
                ->assertSee('No employee matches');
        }
    }

    public function test_a_date_fields_accessible_name_is_its_visible_label(): void
    {
        $employee = Employee::factory()->create(['user_id' => User::factory()->create()->assignRole('employee')->id]);

        $html = Livewire::actingAs($employee->user)->test(LeaveRequestModal::class)->call('open')->html();

        $xpath = $this->xpath($html);
        $this->assertSame('From', $xpath->query('//input[@id="leave_start-native"]')->item(0)->getAttribute('aria-label'));
        $this->assertSame('To', $xpath->query('//input[@id="leave_end-native"]')->item(0)->getAttribute('aria-label'));
        $this->assertStringContainsString("'From'", $xpath->query('//button[@id="leave_start"]')->item(0)->getAttribute(':aria-label'));
        $this->assertStringContainsString("'To'", $xpath->query('//button[@id="leave_end"]')->item(0)->getAttribute(':aria-label'));
        $this->assertStringNotContainsString('First day', $html);
        $this->assertStringNotContainsString('Last day', $html);

        // "Same day" is the trigger's placeholder; a native date input has none.
        $hint = $xpath->query('//p[@id="leave_end-hint"]')->item(0);
        $this->assertSame('Same day if empty', trim($hint->textContent));
        $this->assertStringContainsString('sm:hidden', $hint->getAttribute('class'));
        $this->assertSame('leave_end-hint', $xpath->query('//input[@id="leave_end-native"]')->item(0)->getAttribute('aria-describedby'));
        $this->assertSame(0, $xpath->query('//p[@id="leave_start-hint"]')->length);
    }

    public function test_every_date_fields_visible_label_matches_its_pickers_label(): void
    {
        foreach (File::allFiles(resource_path('views')) as $file) {
            preg_match_all('/<x-input-label for="([^"]+)" value="([^"]+)"[^>]*>(?:(?!<x-input-label).)*?<x-date-picker id="\1"[^>]*label="([^"]+)"/s', $file->getContents(), $fields, PREG_SET_ORDER);

            foreach ($fields as [, $id, $visible, $label]) {
                $this->assertSame($visible, $label, "{$file->getRelativePathname()}: {$id}");
            }
        }
    }

    public function test_the_calendars_holiday_name_has_no_native_title(): void
    {
        Employee::factory()->create(['user_id' => $this->admin->id]);
        Holiday::create(['date' => today(), 'name' => 'Founders Test Day']);

        $this->actingAs($this->admin)->get('/my-attendance')
            ->assertSee(', Holiday: Founders Test Day"', false)
            ->assertDontSee('title="Founders Test Day"', false);
    }

    public function test_no_element_carries_a_native_title_except_an_abbreviation(): void
    {
        $offenders = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            preg_match_all('/<([a-z][\w-]*)\b[^>]*?\stitle="/s', $file->getContents(), $tags);
            foreach ($tags[1] as $name) {
                if ($name !== 'abbr' && ! str_starts_with($name, 'x-')) {
                    $offenders[] = $file->getRelativePathname().': <'.$name.'>';
                }
            }
        }

        $this->assertSame([], $offenders, 'A native title shows on hover only: docs/DESIGN_SYSTEM.md, Interaction states.');
    }

    public function test_the_shell_has_header_and_nav_landmarks(): void
    {
        $xpath = $this->xpath($this->actingAs($this->admin)->get('/dashboard')->getContent());

        $this->assertGreaterThan(0, $xpath->query('//header')->length);
        $this->assertSame(2, $xpath->query('//aside//nav[@aria-label="Main"]')->length, 'desktop and drawer, only one shown at a time');
    }

    public function test_the_theme_toggle_names_what_it_will_do(): void
    {
        $topbar = File::get(resource_path('views/layouts/partials/topbar.blade.php'));

        $this->assertStringContainsString(":aria-label=\"isDark ? 'Switch to light mode' : 'Switch to dark mode'\"", $topbar);
    }

    public function test_the_guest_pages_are_titled_by_their_heading(): void
    {
        $this->get('/forgot-password')->assertSee('<title>Reset your password – '.config('app.name').'</title>', false);

        foreach ([
            'reset-password' => 'Choose a new password',
            'confirm-password' => 'Confirm your password',
            'force-password-change' => 'Choose a permanent password',
        ] as $view => $heading) {
            $source = File::get(resource_path("views/auth/{$view}.blade.php"));
            $this->assertStringContainsString("<x-slot:pageTitle>{$heading}</x-slot:pageTitle>", $source);
            $this->assertMatchesRegularExpression('/<h1 [^>]*>'.preg_quote($heading, '/').'<\/h1>/', $source);
        }
    }

    public function test_the_employee_pickers_results_show_the_standard_focus_ring(): void
    {
        $this->assertStringContainsString(
            'focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-500',
            File::get(resource_path('views/livewire/partials/employee-picker.blade.php')),
        );
    }

    public function test_a_date_fields_label_focuses_whichever_control_is_showing(): void
    {
        $this->assertStringContainsString('data-date-native', File::get(resource_path('views/components/date-picker.blade.php')));

        $js = File::get(resource_path('js/app.js'));
        $this->assertStringContainsString("hasAttribute('data-date-native')", $js);
        $this->assertStringContainsString('native.getClientRects().length ? native : document.getElementById(label.htmlFor)', $js);
    }

    public function test_pagination_has_no_aria_label_on_its_disabled_spans(): void
    {
        $view = File::get(resource_path('views/vendor/livewire/tailwind.blade.php'));

        $this->assertDoesNotMatchRegularExpression('/<span[^>]*aria-disabled="true"[^>]*aria-label/', $view);
        $this->assertSame(2, preg_match_all('/<span aria-disabled="true">\s+<span class="\{\{ \$item \}\} \{\{ \$disabled \}\}/', $view));
    }

    public function test_read_only_values_and_the_auth_subtitle_use_the_muted_text_rule(): void
    {
        $this->assertStringContainsString('dark:!text-slate-400', File::get(resource_path('views/profile/partials/update-profile-information-form.blade.php')));
        $this->assertStringContainsString('attendance, stamped in seconds', $guest = File::get(resource_path('views/layouts/guest.blade.php')));
        $this->assertMatchesRegularExpression('/class="[^"]*dark:text-slate-400[^"]*">attendance, stamped in seconds/', $guest);
    }
}
