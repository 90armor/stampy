<?php

namespace Tests\Feature\Attendance;

use App\Enums\PunchSource;
use App\Livewire\Attendance\Show;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6g, F34 — a punch correction says why: Add punch and Void both need a
 * reason of 1–200 characters, kept on the punch (reason, void_reason) and shown
 * in the day's punch list beside who made the change and when. A correction
 * from before reasons were asked for shows "—". The clock is Wed 15 Apr 2026
 * 12:00; the day is Mon 2 Feb.
 */
class PunchReasonTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-02-02';

    private Employee $employee;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-04-15 12:00:00'));

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        WorkSchedule::factory()->create(['is_default' => true]);
        $this->employee = Employee::factory()->create();
        $this->admin = User::factory()->create(['name' => 'Administrator'])->assignRole('admin');
    }

    private function page()
    {
        return Livewire::actingAs($this->admin)
            ->test(Show::class, ['employee' => $this->employee])
            ->set('month', '2026-02')
            ->call('openDay', self::DAY);
    }

    private function addForm(string $reason)
    {
        return $this->page()
            ->call('startAddingPunch', self::DAY)
            ->set('newPunchDate', self::DAY)
            ->set('newPunchTime', '08:00')
            ->set('newPunchType', 'in')
            ->set('newPunchReason', $reason);
    }

    public function test_adding_a_punch_needs_a_reason(): void
    {
        foreach (['', '   '] as $reason) {
            $page = $this->addForm($reason)->call('addPunch')->assertHasErrors(['newPunchReason']);
            $this->assertSame('Write a reason for this change.', $page->errors()->first('newPunchReason'));
        }

        $page = $this->addForm(str_repeat('a', 201))->call('addPunch')->assertHasErrors(['newPunchReason']);
        $this->assertSame('Keep the reason to 200 characters or fewer.', $page->errors()->first('newPunchReason'));

        $this->assertSame(0, AttendanceLog::query()->count());

        // The field names its error (stampyErrors moves focus to it on a failed submit).
        $this->assertStringContainsString('id="new_punch_reason_'.self::DAY.'"', $this->addForm('')->call('addPunch')->html());
    }

    public function test_an_added_punch_keeps_its_reason_and_the_list_says_who_when_and_why(): void
    {
        $this->addForm('  Forgot to punch in at the gate  ')->call('addPunch')->assertHasNoErrors();

        $punch = AttendanceLog::query()->sole();
        $this->assertSame('Forgot to punch in at the gate', $punch->reason);
        $this->assertNull($punch->void_reason);

        $this->page()
            ->assertSee('by Administrator, Wed 15 Apr 12:00 PM')
            ->assertSee('Reason: Forgot to punch in at the gate');
    }

    public function test_voiding_needs_a_reason_and_keeps_it(): void
    {
        $punch = AttendanceLog::factory()->for($this->employee)->create(['punched_at' => self::DAY.' 08:52:00']);

        $page = $this->page()->call('startVoiding', $punch->id)->set('voidReason', ' ')->call('voidPunch', $punch->id)->assertHasErrors(['voidReason']);
        $this->assertSame('Write a reason for this change.', $page->errors()->first('voidReason'));
        $this->assertNull($punch->fresh()->voided_at);
        // The error names the form's field.
        $this->assertStringContainsString('id="void_reason_'.$punch->id.'"', $page->html());

        $this->page()->call('startVoiding', $punch->id)->set('voidReason', 'Someone else\'s finger')->call('voidPunch', $punch->id)
            ->assertHasNoErrors()
            ->assertSet('voidingPunchId', null)
            ->assertSee('voided by Administrator, Wed 15 Apr 12:00 PM')
            ->assertSee('Void reason: Someone else&#039;s finger', false);

        $this->assertSame('Someone else\'s finger', $punch->fresh()->void_reason);
        $this->assertNotNull($punch->fresh()->voided_at);
    }

    public function test_only_one_correction_form_is_open_at_a_time(): void
    {
        $punch = AttendanceLog::factory()->for($this->employee)->create(['punched_at' => self::DAY.' 08:52:00']);

        $this->page()
            ->call('startAddingPunch', self::DAY)
            ->call('startVoiding', $punch->id)
            ->assertSet('addingPunchFor', null)
            ->call('startAddingPunch', self::DAY)
            ->assertSet('voidingPunchId', null);
    }

    public function test_corrections_from_before_reasons_show_a_dash_and_device_punches_none(): void
    {
        AttendanceLog::factory()->for($this->employee)->create(['punched_at' => self::DAY.' 08:00:00', 'source' => PunchSource::Manual, 'created_by' => $this->admin->id]);
        AttendanceLog::factory()->for($this->employee)->create(['punched_at' => self::DAY.' 17:00:00', 'punch_type' => 'out', 'voided_at' => '2026-02-03 09:00:00', 'voided_by' => $this->admin->id]);
        AttendanceLog::factory()->for($this->employee)->create(['punched_at' => self::DAY.' 18:00:00', 'punch_type' => 'out']);

        $html = $this->page()->html();

        // The manual punch's reason line, the voided punch's void reason line;
        // the live device punch has neither.
        $this->assertSame(1, substr_count($html, 'Reason: '));
        $this->assertSame(1, substr_count($html, 'Reason: —'));
        $this->assertSame(1, substr_count($html, 'Void reason: '));
        $this->assertSame(1, substr_count($html, 'Void reason: —'));
    }

    public function test_re_adding_a_voided_manual_punch_keeps_the_voided_one_with_its_reasons(): void
    {
        $this->addForm('Forgot to punch')->call('addPunch');
        $punch = AttendanceLog::query()->sole();
        $this->page()->call('startVoiding', $punch->id)->set('voidReason', 'Wrong day')->call('voidPunch', $punch->id);

        $this->travelTo(Carbon::parse('2026-04-16 09:30:00'));
        $this->addForm('It was the right day after all')->call('addPunch')->assertHasNoErrors();

        // The voided correction is untouched (Phase 6h); the new one is its own row.
        $punch->refresh();
        $this->assertNotNull($punch->voided_at);
        $this->assertSame('Forgot to punch', $punch->reason);
        $this->assertSame('Wrong day', $punch->void_reason);
        $this->assertSame('2026-04-15 12:00:00', $punch->created_at->format('Y-m-d H:i:s'));

        $added = AttendanceLog::query()->whereNull('voided_at')->sole();
        $this->assertSame('It was the right day after all', $added->reason);
        $this->assertSame('2026-04-16 09:30:00', $added->created_at->format('Y-m-d H:i:s'));

        // Both are in the day's list: the voided one muted and struck through.
        $html = $this->page()->html();
        $this->assertSame(2, substr_count($html, 'Reason: '));
        $this->assertStringContainsString('Reason: Forgot to punch', $html);
        $this->assertStringContainsString('Void reason: Wrong day', $html);
        $this->assertStringContainsString('Reason: It was the right day after all', $html);
        $this->assertStringContainsString('line-through', $html);
    }

    public function test_the_employee_sees_the_reasons_on_their_own_day(): void
    {
        $this->addForm('Forgot to punch in at the gate')->call('addPunch');
        $punch = AttendanceLog::query()->sole();
        $this->page()->call('startVoiding', $punch->id)->set('voidReason', 'Someone else\'s finger')->call('voidPunch', $punch->id);
        $this->addForm('Punched in at the side door')->call('addPunch');

        $user = User::factory()->create()->assignRole('employee');
        $this->employee->update(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test(Show::class)
            ->set('month', '2026-02')
            ->call('openDay', self::DAY)
            ->assertSee('Reason: Forgot to punch in at the gate')
            ->assertSee('Void reason: Someone else&#039;s finger', false)
            ->assertSee('Reason: Punched in at the side door')
            // No correction controls, so no hint either.
            ->assertDontSee('Add punch')
            ->assertDontSee('The employee can see this.');
    }

    public function test_the_reason_fields_say_the_employee_can_see_them(): void
    {
        $punch = AttendanceLog::factory()->for($this->employee)->create(['punched_at' => self::DAY.' 08:52:00']);

        $add = $this->page()->call('startAddingPunch', self::DAY)->html();
        $this->assertStringContainsString('aria-describedby="new_punch_reason_'.self::DAY.'_hint"', $add);
        $this->assertStringContainsString('id="new_punch_reason_'.self::DAY.'_hint" class="mt-1 text-xs text-slate-600 dark:text-slate-400">The employee can see this.</p>', $add);

        $void = $this->page()->call('startVoiding', $punch->id)->html();
        $this->assertStringContainsString('aria-describedby="void_reason_'.$punch->id.'_hint"', $void);
        $this->assertStringContainsString('id="void_reason_'.$punch->id.'_hint" class="mt-1 text-xs text-slate-600 dark:text-slate-400">The employee can see this.</p>', $void);
    }
}
