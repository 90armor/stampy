<?php

namespace Tests\Feature;

use App\Livewire\Leave\Approvals;
use App\Livewire\Leave\RequestModal as LeaveRequestModal;
use App\Livewire\Overtime\RequestModal as OvertimeRequestModal;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 6d2: what the modal focus rules (docs/DESIGN_SYSTEM.md, Modals and focus)
 * stand on. Focus itself can't be checked from PHP: the rules run in a browser
 * through scripts/audit/playwright/focus-6d2.mjs, and this suite pins the markup
 * they read and the order they follow, so neither can drift unnoticed.
 */
class ModalFocusRulesTest extends TestCase
{
    use RefreshDatabase;

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    public function test_every_modal_has_a_title_or_a_label_for_its_accessible_name(): void
    {
        $offenders = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());
            if (preg_match('/<x-modal\b(?!\.)[^>]*>/s', $source, $tag)
                && ! str_contains($source, '<x-modal.title') && ! str_contains($tag[0], 'label=')) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'Name each modal with <x-modal.title>, or label="…" if it has no visible title.');
    }

    public function test_a_modal_is_named_by_its_title(): void
    {
        $html = Blade::render('<x-modal name="probe"><x-modal.title>Hello</x-modal.title></x-modal>');
        $this->assertStringContainsString('aria-labelledby="probe-title"', $html);
        $this->assertMatchesRegularExpression('/<h2 id="probe-title" tabindex="-1" data-modal-title class="[^"]*focus:outline-none[^"]*">Hello<\/h2>/', $html);

        $html = Blade::render('<x-modal name="probe" label="Quick look"><p>Hello</p></x-modal>');
        $this->assertStringContainsString('aria-label="Quick look"', $html);
        $this->assertStringNotContainsString('aria-labelledby', $html);
    }

    public function test_the_trap_listens_on_window_so_it_holds_with_focus_outside_the_dialog(): void
    {
        $html = Blade::render('<x-modal name="probe"><x-modal.title>Hello</x-modal.title></x-modal>');

        $this->assertStringContainsString('x-on:keydown.tab.window="trap($event)"', $html);
        $this->assertStringContainsString('x-on:focusin.window=', $html);
        $this->assertStringContainsString('new MutationObserver', $html);
        $this->assertStringContainsString('tabindex="-1"', $html);
    }

    public function test_only_multi_step_modals_render_a_step_and_both_request_modals_do(): void
    {
        $this->assertStringNotContainsString('<span hidden data-modal-step', Blade::render('<x-modal name="probe"></x-modal>'));
        $this->assertStringContainsString('<span hidden data-modal-step="review"', Blade::render('<x-modal name="probe" step="review"></x-modal>'));

        foreach (['leave', 'overtime'] as $modal) {
            $this->assertStringContainsString(':step="$step"', File::get(resource_path("views/livewire/{$modal}/request-modal.blade.php")));
        }
    }

    public function test_the_close_order_is_notice_then_trigger_then_heading_then_main(): void
    {
        $js = File::get(resource_path('js/app.js'));
        $resolve = substr($js, strpos($js, 'resolve(context) {'));
        $resolve = substr($resolve, 0, strpos($resolve, 'settle(context)'));

        $order = array_map(fn (string $needle) => strpos($resolve, $needle), [
            'this.freshNotice(context)',
            'context.trigger',
            'this.notices()[0]',
            'context.dialog',
            'context.heading',
            "querySelector('main')",
        ]);

        $this->assertNotContains(false, $order);
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order);
    }

    public function test_the_order_never_looks_for_a_next_control_and_checks_the_trigger_is_still_the_same_one(): void
    {
        $js = File::get(resource_path('js/app.js'));
        $focus = substr($js, strpos($js, 'window.stampyFocus = {'));
        $focus = substr($focus, 0, strpos($focus, "// A date field's <label for>"));

        // The only things it ever focuses: messages, the trigger, headings, a title, main.
        $this->assertStringNotContainsString("querySelector('button", $focus);
        $this->assertStringNotContainsString('nextElementSibling', $focus);
        // A re-render can reuse a removed row's button for the next row.
        $this->assertStringContainsString("element.getAttribute('wire:click')", $focus);
        $this->assertStringContainsString('this.signature(context.trigger) === context.signature', $focus);
    }

    public function test_every_message_a_modals_action_can_show_can_take_focus(): void
    {
        foreach ([
            'leave/approvals' => 3,
            'leave/time-off' => 2,
            'overtime/index' => 2,
            'leave-types/index' => 1,
            'employees/leave-card' => 2,
            'employees/overtime-card' => 2,
            'employees/schedule-assignments' => 1,
        ] as $view => $count) {
            $source = File::get(resource_path("views/livewire/{$view}.blade.php"));
            $this->assertSame($count, preg_match_all('/<div\b[^>]*tabindex="-1" data-focus-notice[^>]*role="status"[^>]*>/', $source), $view);
        }
    }

    public function test_the_heading_for_each_list_a_modal_works_on_can_take_focus(): void
    {
        foreach ([
            'leave/approvals' => 3,
            'leave/time-off' => 1,
            'overtime/index' => 1,
            'holidays/index' => 1,
            'departments/index' => 1,
            'positions/index' => 1,
            'schedules/index' => 1,
            'leave-types/index' => 1,
            'employees/index' => 1,
            'employees/leave-card' => 1,
            'employees/overtime-card' => 1,
            'employees/schedule-assignments' => 1,
        ] as $view => $count) {
            $source = File::get(resource_path("views/livewire/{$view}.blade.php"));
            $this->assertSame($count, preg_match_all('/<h2 tabindex="-1" data-section-heading class="[^"]*focus:outline-none/', $source), $view);
        }
    }

    public function test_approval_rows_are_keyed_so_a_re_render_cannot_hand_one_rows_button_to_the_next(): void
    {
        foreach (['approval-item' => 'approval-{{ $leave->id }}', 'approval-overtime-item' => 'approval-overtime-{{ $request->id }}'] as $partial => $key) {
            $this->assertStringContainsString('<li wire:key="'.$key.'"', File::get(resource_path("views/livewire/leave/partials/{$partial}.blade.php")));
        }
    }

    public function test_after_an_approval_the_notice_is_rendered_ready_for_focus(): void
    {
        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
        WorkSchedule::factory()->create(['is_default' => true]);
        $manager = Employee::factory()->create(['user_id' => User::factory()->create()->assignRole('manager')->id]);
        $employee = Employee::factory()->create(['manager_id' => $manager->id]);
        $type = LeaveType::factory()->withoutBalance()->create(['name' => 'Unpaid']);
        $leave = Leave::factory()->create([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => today()->addDays(7), 'end_date' => today()->addDays(7), 'current_step' => 1,
        ]);

        $component = Livewire::actingAs($manager->user)->test(Approvals::class)
            ->call('openDecision', $leave->id, 'approve', 'leave', 1)
            ->call('decide')
            ->assertSet('showDecision', false);

        $notice = $this->xpath($component->html())->query('//*[@data-focus-notice]')->item(0);
        $this->assertNotNull($notice);
        $this->assertSame('-1', $notice->getAttribute('tabindex'));
        $this->assertSame('status', $notice->getAttribute('role'));
        $this->assertStringContainsString($employee->full_name, $notice->textContent);
    }

    public function test_the_request_modals_titles_carry_the_step_heading(): void
    {
        $employee = $this->employeeWithLogin();

        foreach ([LeaveRequestModal::class, OvertimeRequestModal::class] as $modal) {
            $xpath = $this->xpath(Livewire::actingAs($employee->user)->test($modal)->call('open')->html());
            $title = $xpath->query('//h2[@data-modal-title]')->item(0);
            $this->assertNotNull($title, $modal);
            $this->assertSame('-1', $title->getAttribute('tabindex'));
            $this->assertSame('form', $xpath->query('//*[@data-modal-step]')->item(0)->getAttribute('data-modal-step'));

            // Through a real Livewire render: it can mark an @if in a tag with a
            // comment, which swallowed these attributes while Blade alone looked fine.
            $dialog = $xpath->query('//*[@aria-modal="true"]')->item(0);
            $this->assertSame($title->getAttribute('id'), $dialog->getAttribute('aria-labelledby'));
            $this->assertSame('-1', $dialog->getAttribute('tabindex'));
        }
    }

    private function employeeWithLogin(): Employee
    {
        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
        WorkSchedule::factory()->create(['is_default' => true]);

        return Employee::factory()->create(['user_id' => User::factory()->create()->assignRole('employee')->id]);
    }
}
