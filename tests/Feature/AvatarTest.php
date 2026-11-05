<?php

namespace Tests\Feature;

use App\Support\Avatar;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 6h, F10 — one avatar, <x-avatar :name>, whose initial is the name's
 * first grapheme (App\Support\Avatar): a Khmer or Myanmar-script name keeps
 * its whole first letter, marks included, where `substr($name, 0, 1)` showed
 * "�". It replaces six copies of the markup.
 */
class AvatarTest extends TestCase
{
    public static function names(): array
    {
        return [
            'Latin' => ['aye aye mon', 'A'],
            'Latin, accented' => ['émile', 'É'],
            'Khmer, consonant and vowel sign' => ['សុខ សុភាព', 'សុ'],
            'Khmer, subscript consonant' => ['ស្រីនាង', 'ស្រី'],
            'Myanmar, medial and vowel' => ['ကျော်ကျော်နိုင်', 'ကျေ'],
            'Myanmar, vowel sign' => ['သီတာဝင်', 'သီ'],
            'leading spaces' => ['  zaw zaw htet', 'Z'],
            'leading ideographic space' => ["\u{3000}nay", 'N'],
            'empty' => ['', ''],
            'only spaces' => ['   ', ''],
            'null' => [null, ''],
        ];
    }

    #[DataProvider('names')]
    public function test_the_initial_is_the_first_whole_character(?string $name, string $initial): void
    {
        $this->assertSame($initial, Avatar::initial($name));
        $this->assertTrue(mb_check_encoding(Avatar::initial($name), 'UTF-8'));
    }

    public function test_the_component_renders_the_initial_at_the_callers_size(): void
    {
        $html = Blade::render('<x-avatar :name="$name" class="h-9 w-9 text-xs font-semibold" />', ['name' => 'សុខ សុភាព']);

        $this->assertStringContainsString('>សុ</span>', $html);
        $this->assertStringContainsString('h-9 w-9 text-xs font-semibold', $html);
        $this->assertStringContainsString('rounded-full bg-slate-100 text-slate-600 dark:bg-slate-750 dark:text-slate-300', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringNotContainsString('�', $html);
    }

    public function test_no_view_slices_a_name_by_bytes_for_an_initial(): void
    {
        foreach (File::allFiles(resource_path('views')) as $file) {
            $this->assertDoesNotMatchRegularExpression('/strtoupper\(\s*substr\(/', $file->getContents(), $file->getRelativePathname().' builds an initial with substr(): use <x-avatar :name>.');
        }
    }
}
