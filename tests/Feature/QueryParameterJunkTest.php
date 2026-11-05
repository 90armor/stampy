<?php

namespace Tests\Feature;

use App\Livewire\Attendance\Index as AttendanceIndex;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 6h — a query parameter can never break a page. Every list page's
 * parameters take a scalar or an array, ignore values they don't know, and
 * never fail on a wrong type: `/attendance?timing=late` (a scalar where the
 * filter is a list) used to be a 500. Each parameter of each page is sent
 * alone with every junk value below, then all of them at once; the page
 * answers 200 or a normal redirect, never a 500.
 */
class QueryParameterJunkTest extends TestCase
{
    use RefreshDatabase;

    /** Every page and the parameters it reads. */
    private const PAGES = [
        '/attendance' => ['from', 'to', 'q', 'department', 'status', 'timing', 'worked-on-leave', 'overtime', 'page'],
        '/attendance/{employee}' => ['month', 'view'],
        '/my-attendance' => ['month', 'view'],
        '/employees' => ['q', 'department', 'status', 'page'],
        '/reports' => ['tab', 'month', 'department', 'year', 'type', 'leave-department'],
        '/reports/overtime' => ['month'],
        '/policies' => ['tab'],
        '/organization' => ['tab'],
        '/approvals' => ['type', 'tab', 'page'],
        '/time-off' => ['page'],
        '/overtime' => ['page'],
        '/dashboard' => ['page'],
    ];

    /** A wrong type, an unknown value, an impossible date, an empty one. */
    private static function junk(): array
    {
        return [
            'scalar' => 'late',
            'empty' => '',
            'blank' => ' ',
            'list' => ['junk'],
            'nested' => ['a' => ['b' => 'c']],
            'json object' => '{"a":1}',
            'json null' => 'null',
            'boolean word' => 'true',
            'negative' => '-1',
            'huge number' => '99999999999999999999',
            'impossible date' => '2026-02-30',
            'month 13' => '2026-13',
            'script' => '<script>alert(1)</script>',
        ];
    }

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
        $this->seed([RoleSeeder::class, LeaveTypeSeeder::class]);
        WorkSchedule::factory()->create(['is_default' => true]);

        // A linked admin, so My attendance has a record to show.
        $this->admin = User::factory()->create()->assignRole('admin');
        Employee::factory()->create(['user_id' => $this->admin->id, 'department_id' => Department::factory()]);
    }

    private function path(string $page): string
    {
        return str_replace('{employee}', (string) $this->admin->employee->id, $page);
    }

    private function assertNormal(string $url): ?string
    {
        $status = $this->actingAs($this->admin)->get($url)->getStatusCode();

        return in_array($status, [200, 302], true) ? null : "{$status} {$url}";
    }

    public function test_no_parameter_of_any_page_breaks_it(): void
    {
        $failures = [];

        foreach (self::PAGES as $page => $parameters) {
            foreach ($parameters as $parameter) {
                foreach (self::junk() as $value) {
                    $failures[] = $this->assertNormal($this->path($page).'?'.http_build_query([$parameter => $value]));
                }

                // Present with no value at all (`?timing`).
                $failures[] = $this->assertNormal($this->path($page).'?'.$parameter);
            }

            foreach (self::junk() as $value) {
                $failures[] = $this->assertNormal($this->path($page).'?'.http_build_query(array_fill_keys($parameters, $value)));
            }
        }

        $this->assertSame([], array_values(array_filter($failures)));
    }

    public function test_a_scalar_where_a_list_is_expected_filters_as_a_one_item_list(): void
    {
        $this->actingAs($this->admin)->get('/attendance?timing=late&status=absent')->assertOk();

        Livewire::withQueryParams(['timing' => 'late', 'status' => 'absent'])
            ->actingAs($this->admin)
            ->test(AttendanceIndex::class)
            ->assertSet('timingFilters', ['late'])
            ->assertSet('statuses', ['absent']);
    }

    public function test_unknown_values_are_dropped(): void
    {
        Livewire::withQueryParams(['timing' => ['late', 'sideways'], 'status' => ['absent', 'asleep'], 'from' => '2026-02-30', 'department' => ['x']])
            ->actingAs($this->admin)
            ->test(AttendanceIndex::class)
            ->assertSet('timingFilters', ['late'])
            ->assertSet('statuses', ['absent'])
            ->assertSet('fromDate', '2026-10-21')
            ->assertSet('departmentFilter', '');
    }

    public function test_every_query_string_property_uses_query_param_not_livewires_url(): void
    {
        foreach (File::allFiles(app_path('Livewire')) as $file) {
            if ($file->getRelativePath() === 'Attributes') {
                continue;
            }

            $this->assertStringNotContainsString('use Livewire\\Attributes\\Url;', $file->getContents(), $file->getRelativePathname().' binds a query parameter with Livewire\'s #[Url]: use #[QueryParam].');
        }
    }
}
