<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\PageTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\HtmlString;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6d, F05: every page's <title> comes from what the topbar shows
 * (App\Support\PageTitle in layouts/app.blade.php), so no view writes its own
 * and browser tabs and history can be told apart.
 */
class PageTitleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $appName;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
        WorkSchedule::factory()->create(['is_default' => true]);
        $this->admin = User::factory()->create()->assignRole('admin');
        $this->appName = config('app.name');
    }

    private function assertTitle(string $expected, string $url): void
    {
        $this->assertSame($expected, $this->titleOf($this->get($url)->getContent()), $url);
    }

    private function titleOf(string $html): string
    {
        preg_match('/<title[^>]*>(.*?)<\/title>/s', $html, $match);

        return html_entity_decode($match[1], ENT_QUOTES);
    }

    public function test_a_page_is_titled_by_its_topbar_header(): void
    {
        $this->actingAs($this->admin);

        foreach ([
            '/dashboard' => 'Dashboard',
            '/employees' => 'Employees',
            '/attendance' => 'Attendance',
            '/my-attendance' => 'My attendance',
            '/time-off' => 'Time off',
            '/approvals' => 'Approvals',
            '/overtime' => 'Overtime',
            '/profile' => 'Profile',
        ] as $url => $page) {
            $this->assertTitle("$page – {$this->appName}", $url);
        }
    }

    public function test_a_page_about_one_person_leads_with_their_name(): void
    {
        $employee = Employee::factory()->create(['full_name' => "Kyaw O'Naing"]);
        $this->actingAs($this->admin);

        $this->assertTitle("Kyaw O'Naing · Attendance – {$this->appName}", "/attendance/{$employee->id}");
        $this->assertTitle("Kyaw O'Naing · Employees – {$this->appName}", "/employees/{$employee->id}");
    }

    public function test_a_page_with_section_tabs_names_the_open_tab(): void
    {
        $this->actingAs($this->admin);

        foreach ([
            '/reports?tab=attendance' => 'Reports · Attendance',
            '/reports?tab=leave' => 'Reports · Leave',
            '/reports?tab=overtime' => 'Reports · Overtime',
            '/reports' => 'Reports · Attendance',
            '/reports?tab=nonsense' => 'Reports · Attendance',
            '/policies' => 'Policies · Schedules',
            '/policies?tab=schedules' => 'Policies · Schedules',
            '/policies?tab=holidays' => 'Policies · Holidays',
            '/policies?tab=leave-types' => 'Policies · Leave types',
            '/policies?tab=overtime' => 'Policies · Overtime',
            '/organization' => 'Organization · Departments',
            '/organization?tab=positions' => 'Organization · Positions',
        ] as $url => $title) {
            $this->assertTitle("$title – {$this->appName}", $url);
        }
    }

    public function test_the_title_follows_a_tab_switch_without_a_reload(): void
    {
        $this->actingAs($this->admin);

        $this->get('/reports?tab=leave')->assertSee('data-tab-title="Reports · :tab – '.$this->appName.'"', false);
        $this->get('/policies')->assertSee('data-tab-title="Policies · :tab – '.$this->appName.'"', false);
        $this->get('/organization')->assertSee('data-tab-title="Organization · :tab – '.$this->appName.'"', false);
        $this->get('/time-off')->assertDontSee('data-tab-title', false);

        $js = File::get(resource_path('js/app.js'));
        $this->assertStringContainsString('title[data-tab-title]', $js);
        $this->assertStringContainsString('this.retitle()', $js);
    }

    public function test_an_error_page_is_titled_by_its_header_signed_in_or_not(): void
    {
        $this->get('/no-such-page')->assertNotFound()->assertSee('>Page not found – '.$this->appName.'</title>', false);

        $this->actingAs($this->admin);
        $this->get('/no-such-page')->assertNotFound()->assertSee('>Page not found – '.$this->appName.'</title>', false);

        $employee = User::factory()->create()->assignRole('employee');
        $this->actingAs($employee)->get('/employees')->assertForbidden()->assertSee('>Access denied – '.$this->appName.'</title>', false);
    }

    public function test_a_guest_page_keeps_its_own(): void
    {
        $this->assertTitle("Sign in – {$this->appName}", '/login');
    }

    public function test_a_slot_header_is_not_escaped_twice(): void
    {
        $this->assertSame("Tom's page – {$this->appName}", PageTitle::for(new HtmlString(' Tom&#039;s page ')));
    }

    public function test_no_view_writes_its_own_title(): void
    {
        $offenders = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (str_contains($file->getContents(), '<title') && ! in_array($file->getFilename(), ['app.blade.php', 'guest.blade.php', 'welcome.blade.php'], true)) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'Page titles come from the layout (App\Support\PageTitle).');
    }
}
