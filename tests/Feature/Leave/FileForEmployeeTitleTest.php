<?php

namespace Tests\Feature\Leave;

use App\Livewire\Leave\RequestModal as LeaveRequestModal;
use App\Livewire\Overtime\RequestModal as OvertimeRequestModal;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6g, F28 — filing for an employee: the modal's title names the person
 * once chosen, and goes back to the generic one on Change; from the first
 * step it says what filing for someone means. Not when the admin chose their
 * own record: that request goes through approval. Choosing focuses the title
 * (6d2's rule — tests/browser/focus.mjs checks it in a browser).
 */
class FileForEmployeeTitleTest extends TestCase
{
    use RefreshDatabase;

    private const LINE = 'Filed by you — approved straight away, no approval steps.';

    private User $admin;

    private Employee $suSu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-06-17 12:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->withBreakStart()->create(['is_default' => true]);
        LeaveType::factory()->create(['name' => 'Annual', 'days_per_year' => 18]);
        $this->admin = User::factory()->create(['name' => 'Administrator'])->assignRole('admin');
        $this->suSu = Employee::factory()->create(['full_name' => 'Su Su Hlaing', 'join_date' => '2020-01-01']);
    }

    public static function modals(): array
    {
        return [
            'leave' => [LeaveRequestModal::class, 'leave'],
            'overtime' => [OvertimeRequestModal::class, 'overtime'],
        ];
    }

    #[DataProvider('modals')]
    public function test_the_title_names_the_chosen_employee_and_the_line_says_what_filing_means(string $modal, string $kind): void
    {
        $page = Livewire::actingAs($this->admin)->test($modal)->call('openForOthers');

        $page->assertSee("File {$kind} for an employee")->assertSee(self::LINE);

        $page->call('selectEmployee', $this->suSu->id)
            ->assertSee("File {$kind} for Su Su Hlaing")
            ->assertDontSee("File {$kind} for an employee")
            ->assertSee(self::LINE);

        $page->call('clearEmployee')->assertSee("File {$kind} for an employee");
    }

    #[DataProvider('modals')]
    public function test_from_a_profile_the_locked_employee_is_named_at_once(string $modal, string $kind): void
    {
        Livewire::actingAs($this->admin)->test($modal)->call('openForOthers', $this->suSu->id, true)
            ->assertSee("File {$kind} for Su Su Hlaing")
            ->assertSee(self::LINE);
    }

    #[DataProvider('modals')]
    public function test_choosing_their_own_record_drops_the_line(string $modal, string $kind): void
    {
        $own = Employee::factory()->create(['full_name' => 'Sophea Chan', 'user_id' => $this->admin->id, 'join_date' => '2020-01-01']);

        Livewire::actingAs($this->admin)->test($modal)->call('openForOthers')
            ->call('selectEmployee', $own->id)
            ->assertSee("File {$kind} for Sophea Chan")
            ->assertDontSee(self::LINE);
    }

    #[DataProvider('modals')]
    public function test_a_request_of_ones_own_has_neither(string $modal, string $kind): void
    {
        $user = User::factory()->create()->assignRole('employee');
        Employee::factory()->create(['user_id' => $user->id, 'join_date' => '2020-01-01']);

        Livewire::actingAs($user)->test($modal)->call('open')
            ->assertSee('Request '.$kind)
            ->assertDontSee(self::LINE);
    }
}
