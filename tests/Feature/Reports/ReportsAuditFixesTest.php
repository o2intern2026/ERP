<?php

namespace Tests\Feature\Reports;

use App\Modules\MasterData\Models\Client;
use App\Modules\Reports\Http\ReportValidation;
use App\Modules\Reports\Rules\MaxPeriodSpan;
use App\Modules\Reports\Services\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** 2026-09-10 audit, lane B package B5 — the confirmed Reports findings (filter form loses its input, English messages, silent 366-day clamp). */
class ReportsAuditFixesTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    /** Finding: a rejected filter bounced to the bare page and every control snapped back to its default; the message was Laravel's English with the raw key. */
    public function test_rejected_filter_keeps_client_and_dates_and_explains_in_chinese(): void
    {
        $cs = $this->staff('customer_service');
        $alpha = $this->client(['name' => 'Alpha Pty Ltd', 'code' => 'ALPHA']);

        $response = $this->actingAs($cs)->from(route('reports.client'))
            ->get(route('reports.client', ['client_id' => $alpha->id, 'from' => '2026-09-10', 'to' => '2026-09-01']));
        $response->assertRedirect(route('reports.client'))->assertSessionHasErrors('to');
        $message = session('errors')->first('to');
        $this->assertSame(ReportValidation::messages()['to.after_or_equal'], $message);
        $this->assertStringNotContainsString('The to field', $message);

        $page = $this->actingAs($cs)->get(route('reports.client'))->assertOk();
        $page->assertSee('<option value="'.$alpha->id.'" selected>', false)
            ->assertSee('name="from" value="2026-09-10"', false)
            ->assertSee('name="to" value="2026-09-01"', false)
            ->assertSee(ReportValidation::messages()['to.after_or_equal']);

        // Same partial on the boss page and in the portal — Chinese message, dates kept.
        $finance = $this->staff('finance');
        $this->actingAs($finance)->from(route('reports.index'))->get(route('reports.index', ['from' => '2026-09-10', 'to' => '2026-09-01']))
            ->assertRedirect(route('reports.index'))->assertSessionHasErrors(['to' => ReportValidation::messages()['to.after_or_equal']]);
        $this->actingAs($finance)->get(route('reports.index'))->assertOk()->assertSee('name="to" value="2026-09-01"', false);

        $portal = $this->clientUser($alpha);
        $this->actingAs($portal)->from(route('portal.reports.index'))->get(route('portal.reports.index', ['from' => '2026-09-10', 'to' => '2026-09-01']))
            ->assertRedirect(route('portal.reports.index'))->assertSessionHasErrors(['to' => ReportValidation::messages()['to.after_or_equal']]);
        $this->actingAs($portal)->get(route('portal.reports.index'))->assertOk()->assertSee('name="from" value="2026-09-10"', false);

        // An unparsable date and a missing client on the export name the field in Chinese, never `from` / `client_id`.
        $this->actingAs($finance)->get(route('reports.index', ['from' => 'not-a-date']))->assertSessionHasErrors('from');
        $this->assertStringContainsString(__('reports.validation.attributes.from'), session('errors')->first('from'));
        $this->actingAs($cs)->get(route('reports.client.export', ['table' => 'orders_by_client']))->assertSessionHasErrors(['client_id' => ReportValidation::messages()['client_id.required']]);
    }

    /** Finding: a range longer than 366 days was silently shortened — page label and CSV covered a period the user never asked for. */
    public function test_range_longer_than_the_cap_is_rejected_instead_of_silently_truncated(): void
    {
        $finance = $this->staff('finance');
        $alpha = $this->client(['name' => 'Alpha Pty Ltd', 'code' => 'ALPHA']);
        $tooLong = ['from' => '2025-01-01', 'to' => '2026-09-10'];
        $expected = __('reports.validation.messages.max_days', ['days' => ReportPeriod::MAX_DAYS, 'from' => '2025-01-01', 'latest' => '2026-01-02']);

        $this->actingAs($finance)->from(route('reports.index'))->get(route('reports.index', $tooLong))
            ->assertRedirect(route('reports.index'))->assertSessionHasErrors(['to' => $expected]);
        $this->actingAs($finance)->get(route('reports.export', ['table' => 'financials'] + $tooLong))->assertSessionHasErrors('to');
        $this->actingAs($this->staff('customer_service'))->get(route('reports.client', ['client_id' => $alpha->id] + $tooLong))->assertSessionHasErrors('to');
        $this->actingAs($this->clientUser($alpha))->get(route('portal.reports.index', $tooLong))->assertSessionHasErrors('to');

        // Exactly the cap is fine; the page states the cap instead of applying it silently.
        $this->actingAs($finance)->get(route('reports.index', ['from' => '2025-01-01', 'to' => '2026-01-02']))->assertOk()
            ->assertSee('2025-01-01 ~ 2026-01-02')
            ->assertSee(__('reports.filters.span_hint', ['days' => ReportPeriod::MAX_DAYS]));
        $this->actingAs($finance)->get(route('reports.index', ['from' => '2025-01-01', 'to' => '2026-01-03']))->assertSessionHasErrors('to');

        // Programmatic callers (mail jobs) still get the defensive clamp.
        $period = ReportPeriod::fromInput($tooLong);
        $this->assertSame('2026-01-02', $period->to->toDateString());
        $this->assertSame(Client::query()->count(), 1);
    }

    /** Residual: with `from` left out of a hand-edited URL the rule used to skip and fromInput() clamped `to` silently against its own default. */
    public function test_cap_applies_when_from_is_omitted_from_the_url(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10 10:00', 'Australia/Melbourne'));
        try {
            $finance = $this->staff('finance');
            $alpha = $this->client(['name' => 'Alpha Pty Ltd', 'code' => 'ALPHA']);
            $expected = __('reports.validation.messages.max_days', ['days' => ReportPeriod::MAX_DAYS, 'from' => '2026-09-01', 'latest' => '2027-09-02']);

            // Page and CSV export, boss / client / portal — same default `from` as fromInput(), so the same rejection.
            $this->actingAs($finance)->from(route('reports.index'))->get(route('reports.index', ['to' => '2028-12-31']))
                ->assertRedirect(route('reports.index'))->assertSessionHasErrors(['to' => $expected]);
            $this->actingAs($finance)->get(route('reports.export', ['table' => 'financials', 'to' => '2028-12-31']))->assertSessionHasErrors(['to' => $expected]);
            $this->actingAs($this->staff('customer_service'))->get(route('reports.client', ['client_id' => $alpha->id, 'to' => '2028-12-31']))->assertSessionHasErrors(['to' => $expected]);
            $this->actingAs($this->clientUser($alpha))->get(route('portal.reports.index', ['to' => '2028-12-31']))->assertSessionHasErrors(['to' => $expected]);

            // Exactly the cap from the default start is accepted and shown as asked; one day more is refused.
            $this->actingAs($finance)->get(route('reports.index', ['to' => '2027-09-02']))->assertOk()->assertSee('2026-09-01 ~ 2027-09-02');
            $this->actingAs($finance)->get(route('reports.index', ['to' => '2027-09-03']))->assertSessionHasErrors('to');
            $this->assertSame('2026-09-01', MaxPeriodSpan::defaultFrom()->toDateString());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
