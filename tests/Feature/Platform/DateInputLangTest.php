<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Finder\Finder;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * Lead request 2026-09-17 (Jimmy): the native date pickers read "yyyy/mm/日". Chromium localises every
 * <input type="date|datetime-local|month|time|week"> from the page language, and the page is <html lang="zh">;
 * it honours a lang attribute on the input itself, so every date / time input carries lang="en-AU" (dd/mm/yyyy,
 * the business locale) while the page stays Chinese. Firefox / Safari follow the browser language instead.
 * The scan fails with file:line for any date / time input that lacks the attribute, and the layout's
 * <html lang> is guarded so the page-level language stays the app locale.
 */
class DateInputLangTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const TYPES = 'date|datetime-local|month|time|week';

    public function test_every_date_and_time_input_carries_lang_en_au(): void
    {
        $offenders = [];
        $found = 0;
        foreach ($this->sources() as $path) {
            // Blade expressions are opaque to the tag scan ("->" inside {{ }} must not end the tag); newlines are kept for line numbers.
            $source = (string) preg_replace_callback('/\{\{.*?\}\}|\{!!.*?!!\}/s', fn (array $m) => str_repeat("\n", substr_count($m[0], "\n")).'…', (string) file_get_contents($path));
            preg_match_all('/<input\b[^>]*\btype=["\']?(?:'.self::TYPES.')["\']?[^>]*>/i', $source, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$tag, $offset]) {
                $found++;
                if (! preg_match('/\slang="en-AU"[\s>\/]/', $tag)) {
                    $offenders[] = str_replace(base_path().'/', '', $path).':'.(substr_count($source, "\n", 0, $offset) + 1);
                }
            }
        }

        $this->assertGreaterThan(30, $found, 'the scan should see the date / time inputs of every module');
        $this->assertSame([], $offenders, "date / time inputs without lang=\"en-AU\" (the picker would read yyyy/mm/日 under <html lang=\"zh\">):\n".implode("\n", $offenders));
    }

    public function test_the_page_stays_chinese_while_the_rendered_inputs_are_english_australian(): void
    {
        $this->assertStringContainsString('<html lang="{{ str_replace(\'_\', \'-\', app()->getLocale()) }}">', (string) file_get_contents(resource_path('views/layouts/app.blade.php')), 'the layout must keep the app locale on <html lang>');
        $this->assertSame('zh', app()->getLocale());
        $this->get('/login')->assertOk()->assertSee('<html lang="zh">', false);

        $this->actingAs($this->staff('warehouse_supervisor'))->get(route('warehouse.receipts.index'))->assertOk()
            ->assertSee('<html lang="zh">', false)
            ->assertSee('<input type="date" lang="en-AU" name="date_from"', false)
            ->assertSee('<input type="date" lang="en-AU" name="date_to"', false);
    }

    /** @return list<string> every module view, the shared layouts and every PHP source that could print an input from a string */
    private function sources(): array
    {
        $files = [];
        foreach (Finder::create()->files()->in([base_path('app'), resource_path('views')])->name('*.php') as $file) {
            $files[] = $file->getRealPath();
        }
        sort($files);

        return $files;
    }
}
