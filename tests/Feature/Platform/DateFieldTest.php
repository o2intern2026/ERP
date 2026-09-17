<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\Finder\Finder;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * Lead request 2026-09-17 (Jimmy): every date box must READ dd/mm/yyyy, never "yyyy/mm/日". Chromium formats a native <input type="date">
 * by the browser's own UI language and ignores lang="…" on the element (f985295 verified ineffective in a real Chromium), so the display
 * is ours: the shared <x-date-field> (resources/views/components/date-field.blade.php) shows dd/mm/yyyy in a text box, posts ISO from
 * its hidden input — the only named one — and keeps the native date input off-screen as the calendar picker behind the button; one
 * script in layouts/app.blade.php wires every field by event delegation. The scan fails with file:line for any raw date input left in a
 * view; the rendered pages prove the markup; the posted ISO value still passes the existing validation.
 */
class DateFieldTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_no_view_renders_a_raw_date_input_and_the_component_is_used_everywhere(): void
    {
        $offenders = [];
        $components = 0;
        foreach ($this->views() as $path) {
            if (str_ends_with($path, '/components/date-field.blade.php')) {
                continue; // the component itself holds the one native <input type="date"> — the picker
            }
            // Blade expressions are opaque to the tag scan ("->" inside {{ }} must not end a tag); newlines are kept for line numbers.
            $source = (string) preg_replace_callback('/\{\{.*?\}\}|\{!!.*?!!\}/s', fn (array $m) => str_repeat("\n", substr_count($m[0], "\n")).'…', (string) file_get_contents($path));
            preg_match_all('/<input\b[^>]*\btype=["\']?(?:date|datetime-local)["\']?[^>]*>/i', $source, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [, $offset]) {
                $offenders[] = str_replace(base_path().'/', '', $path).':'.(substr_count($source, "\n", 0, $offset) + 1);
            }
            if (preg_match('/lang="en-AU"/', $source, $m, PREG_OFFSET_CAPTURE)) {
                $offenders[] = str_replace(base_path().'/', '', $path).':'.(substr_count($source, "\n", 0, $m[0][1]) + 1).' (lang="en-AU" is ineffective — the component formats the date itself)';
            }
            $components += preg_match_all('/<x-date-field\b/', $source);
        }

        $this->assertSame([], $offenders, "raw <input type=\"date|datetime-local\"> in a view — use <x-date-field name=\"…\" :value=\"…\" /> (dd/mm/yyyy display, ISO submit):\n".implode("\n", $offenders));
        $this->assertGreaterThan(30, $components, 'the scan should see the date fields of every module');
        $this->assertStringContainsString("closest('.date-field')", (string) file_get_contents(resource_path('views/layouts/app.blade.php')), 'the layout carries the one shared script that wires every .date-field');
    }

    public function test_the_component_shows_dd_mm_yyyy_and_posts_iso_from_its_only_named_input(): void
    {
        $html = Blade::render('<x-date-field name="expected_date" value="2026-09-17" required min="2026-01-01" max="2027-12-31" aria-label="预计到港" id="expected" data-role="eta" class="wide" />');
        $this->assertStringContainsString('<span class="date-field wide" data-invalid="'.__('platform.date_field.invalid').'">', $html);
        $this->assertStringContainsString('<input type="text" inputmode="numeric" placeholder="dd/mm/yyyy" pattern="\d{2}/\d{2}/\d{4}" class="date-text" autocomplete="off" value="17/09/2026" required="required" aria-label="预计到港">', $html);
        $this->assertStringContainsString('<input type="hidden" name="expected_date" value="2026-09-17" id="expected" data-role="eta">', $html);
        $this->assertStringContainsString('<input type="date" class="date-native" tabindex="-1" aria-hidden="true" min="2026-01-01" max="2027-12-31">', $html);
        $this->assertStringContainsString('<button type="button" class="date-pick secondary outline" aria-label="'.__('platform.date_field.pick').'"', $html);
        $this->assertSame(1, preg_match_all('/\bname="/', $html), 'the hidden ISO input is the only named input');
        $this->assertStringNotContainsString('lang="en-AU"', $html);

        // Carbon, null and a rejected value keep the same contract; aria-invalid null renders nothing, "true" lands on the text box.
        $this->assertStringContainsString('value="01/02/2026"', Blade::render('<x-date-field name="d" :value="$d" />', ['d' => Carbon::parse('2026-02-01 09:30')]));
        $empty = Blade::render('<x-date-field name="d" :value="null" :aria-invalid="$bad ? \'true\' : null" />', ['bad' => false]);
        $this->assertStringContainsString('class="date-text" autocomplete="off" value="">', $empty);
        $this->assertStringContainsString('<input type="hidden" name="d" value="">', $empty);
        $this->assertStringNotContainsString('aria-invalid', $empty);
        $this->assertStringContainsString('value="" aria-invalid="true">', Blade::render('<x-date-field name="d" :value="null" :aria-invalid="$bad ? \'true\' : null" />', ['bad' => true]));

        // The ETA (Transport runs/show): the date box plus a native time box, combined into the one datetime-local value the controller validates.
        $eta = Blade::render('<x-date-field name="eta" value="2026-09-17T14:30" time />');
        $this->assertStringContainsString('class="date-text" autocomplete="off" value="17/09/2026">', $eta);
        $this->assertStringContainsString('<input type="hidden" name="eta" value="2026-09-17T14:30">', $eta);
        $this->assertStringContainsString('<input type="time" class="date-time" value="14:30" aria-label="'.__('platform.date_field.time').'">', $eta);
        $this->assertStringContainsString('<input type="hidden" name="eta" value="2026-09-17">', Blade::render('<x-date-field name="eta" value="2026-09-17" time />'));
    }

    public function test_a_staff_page_and_the_portal_manual_inbound_list_render_the_component(): void
    {
        $this->assertSame('zh', app()->getLocale());
        $page = $this->actingAs($this->staff('warehouse_supervisor'))->get(route('warehouse.receipts.index', ['date_from' => '2026-09-01', 'date_to' => '2026-09-17']))->assertOk();
        $page->assertSee('<html lang="zh">', false)
            ->assertSee('<span class="date-field" data-invalid="'.__('platform.date_field.invalid').'">', false)
            ->assertSee('class="date-text" autocomplete="off" value="01/09/2026" aria-label="'.__('warehouse.receipts.date_from').'">', false)
            ->assertSee('<input type="hidden" name="date_from" value="2026-09-01">', false)
            ->assertSee('class="date-text" autocomplete="off" value="17/09/2026" aria-label="'.__('warehouse.receipts.date_to').'">', false)
            ->assertSee('<input type="hidden" name="date_to" value="2026-09-17">', false)
            ->assertSee('<input type="date" class="date-native" tabindex="-1" aria-hidden="true">', false)
            ->assertSee('aria-label="'.__('platform.date_field.pick').'"', false)
            ->assertSee("closest('.date-field')", false) // the shared script from the layout
            ->assertDontSee('lang="en-AU"', false)
            ->assertDontSee('type="date" name=', false);

        // 手工建立入库清单: the first goods row and the add-row <template> both carry the component, named rows[i][requested_date] on the hidden input.
        $form = $this->actingAs($this->clientUser($this->client()))->get(route('portal.asns.imports.manual.create'))->assertOk();
        $form->assertSee('<input type="hidden" name="rows[0][requested_date]" value="">', false)
            ->assertSee('<input type="hidden" name="rows[__INDEX__][requested_date]" value="">', false)
            ->assertSee('aria-label="'.__('portal.inbound.manual.columns.requested_date').'">', false)
            ->assertSee('<input type="hidden" name="expected_date" value="">', false)
            ->assertSee('<input type="hidden" name="collection_ready_date" value="">', false)
            ->assertDontSee('lang="en-AU"', false);
        $this->assertSame(0, preg_match('/<input type="date" [^>]*name=/', $form->getContent()), 'no raw date input carries a name');
    }

    public function test_the_posted_iso_value_still_validates_while_the_displayed_format_does_not(): void
    {
        $user = $this->clientUser($this->client());
        $payload = [
            'order_type' => 'from_stock',
            'deliver_to_name' => 'Amazon BWU2', 'deliver_to_address' => '1 Distribution Drive', 'deliver_to_suburb' => 'Kemps Creek',
            'deliver_to_state' => 'NSW', 'deliver_to_postcode' => '2178', 'deliver_to_address_type' => 'fba', 'service_level' => 'standard',
            'lines' => [['description_cn' => '纸箱货', 'package_type' => 'carton', 'carton_qty' => 5]],
        ];

        // What the hidden input posts (yyyy-mm-dd) passes 'required|date|after_or_equal:today'.
        $this->actingAs($user)->post(route('portal.orders.preview'), $payload + ['requested_date' => today()->addDays(3)->toDateString()])->assertOk();
        // What the person SEES (dd/mm/yyyy) is never posted; were it posted, the same rule refuses it — the split is what keeps the server untouched.
        $this->actingAs($user)->from(route('portal.orders.create'))->post(route('portal.orders.preview'), $payload + ['requested_date' => today()->addDays(3)->format('d/m/Y')])
            ->assertRedirect(route('portal.orders.create'))->assertSessionHasErrors('requested_date');
    }

    /** @return list<string> every module view and every shared view */
    private function views(): array
    {
        $files = [];
        foreach (Finder::create()->files()->in(array_merge(glob(base_path('app/Modules/*/views')) ?: [], [resource_path('views')]))->name('*.blade.php') as $file) {
            $files[] = $file->getRealPath();
        }
        sort($files);

        return $files;
    }
}
