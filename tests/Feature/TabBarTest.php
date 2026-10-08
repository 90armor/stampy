<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Section tabs are one component (<x-tab-bar>), so the phone-width behaviour —
 * the bar scrolls, the active tab is scrolled into view, an edge fades while
 * more tabs lie beyond it — is fixed once for every page that has tabs.
 */
class TabBarTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_and_policies_render_the_shared_tab_bar(): void
    {
        Role::firstOrCreate(['name' => 'admin']);
        WorkSchedule::factory()->create(['is_default' => true]);
        $admin = User::factory()->create()->assignRole('admin');

        foreach (['organization.index', 'policies.index'] as $route) {
            $this->actingAs($admin)->get(route($route))
                ->assertOk()
                ->assertSee('x-data="tabBar"', false)
                ->assertSee('tab-bar-scroller relative overflow-x-auto', false)
                ->assertSee('scroll-px-10', false);
        }
    }

    public function test_no_page_hand_rolls_its_section_tabs(): void
    {
        $offenders = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            $relative = str_replace(base_path().'/', '', $file->getPathname());
            if ($relative !== 'resources/views/components/tab-bar.blade.php'
                && preg_match('/<nav\b[^>]*aria-label="[^"]*sections"/', $file->getContents())) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, 'Use <x-tab-bar> for a page\'s section tabs.');
    }

    public function test_the_fade_and_the_reveal_are_wired_up(): void
    {
        $css = File::get(resource_path('css/app.css'));
        $this->assertStringContainsString('.tab-bar-more-before', $css);
        $this->assertStringContainsString('.tab-bar-more-after', $css);
        $this->assertStringContainsString('mask-image', $css);

        $js = File::get(resource_path('js/app.js'));
        $this->assertStringContainsString("Alpine.data('tabBar'", $js);
        $this->assertStringContainsString("this.\$watch('tab'", $js);
    }
}
