<?php

namespace Tests\Feature\Transport;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Support\Contracts\ExceptionService;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * CHANGE_REQUESTS #160 / #161: 批量确认最终方案 and 批量确认预订 on the transport board go through the single buttons' services;
 * the client's plan beats the recommended quote, refused shipments are named with the reason, planners only.
 */
class TransportBulkActionsTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_ticked_quoted_shipments_get_the_client_plan_or_the_recommended_final_quote_and_the_rest_are_named(): void
    {
        $dispatcher = $this->staff('dispatcher');
        $this->actingAs($dispatcher);
        $client = $this->client();
        $own = $this->carrier('OWN');
        $td = $this->carrier('TD');

        // A: nothing committed to → the recommended final quote.
        $a = $this->shipment($client->id);
        $aOwn = $this->quote($a, $own, ['is_recommended' => true]);
        $this->quote($a, $td, ['source' => 'transdirect', 'service_level' => 'express', 'customer_price_cents' => 12500]);
        // B: the client's selected preliminary plan (Transdirect express) beats the recommended own-fleet option.
        $b = $this->shipment($client->id);
        $this->quote($b, $td, ['source' => 'transdirect', 'service_level' => 'express', 'quote_stage' => 'preliminary', 'status' => 'selected', 'selected_by' => 'system', 'customer_price_cents' => 12000]);
        $bOwn = $this->quote($b, $own, ['is_recommended' => true]);
        $bTd = $this->quote($b, $td, ['source' => 'transdirect', 'service_level' => 'express', 'customer_price_cents' => 13000]);
        // D: only an expired final quote. E: valid finals but neither the client's plan nor recommended. F: already confirmed.
        $d = $this->shipment($client->id);
        $this->quote($d, $own, ['expires_at' => now()->subHour()]);
        $e = $this->shipment($client->id);
        $this->quote($e, $own);
        $f = $this->confirmedShipment($client->id, $own, $dispatcher->id);

        // The board: a confirm box for A and B (with the plan it will confirm), a book box for F, none for D / E; no raw lang key.
        $page = $this->actingAs($dispatcher)->get(route('transport.index', ['per_page' => 300]))->assertOk()
            ->assertSee('id="confirm-bulk"', false)->assertSee('id="book-bulk"', false)
            ->assertSee('name="shipment_ids[]" value="'.$a->id.'" form="confirm-bulk"', false)
            ->assertSee('name="shipment_ids[]" value="'.$b->id.'" form="confirm-bulk"', false)
            ->assertSee('name="shipment_ids[]" value="'.$f->id.'" form="book-bulk"', false)
            ->assertDontSee('value="'.$d->id.'" form="confirm-bulk"', false)
            ->assertDontSee('value="'.$e->id.'" form="confirm-bulk"', false)
            ->assertSee(__('transport.bulk_confirm.select_all', ['count' => 2]))
            ->assertSee(__('transport.bulk_book.select_all', ['count' => 1]))
            ->assertSee(__('transport.bulk_confirm.will_confirm', ['plan' => 'TD · '.__('transport.service_levels.express').' · $130.00']))
            ->assertSee(__('transport.bulk_confirm.client'))->assertSee(__('transport.bulk_confirm.recommended'));
        $this->assertDoesNotMatchRegularExpression('/transport\.(bulk_confirm|bulk_book|filters)\./', $page->getContent());

        // Nothing ticked → refused. All five ticked → A and B confirmed as the coordinator, D / E / F named with the single button's reason.
        $this->actingAs($dispatcher)->post(route('transport.quotes.confirm_bulk'), [])->assertSessionHasErrors('shipment_ids');
        $this->actingAs($dispatcher)->from(route('transport.index'))
            ->post(route('transport.quotes.confirm_bulk'), ['shipment_ids' => [$a->id, $b->id, $d->id, $e->id, $f->id]])
            ->assertRedirect(route('transport.index'))
            ->assertSessionHas('status', __('transport.bulk_confirm.done', ['count' => 2]))
            ->assertSessionHasErrors('shipment_ids');
        $errors = session('errors')->first('shipment_ids');
        foreach ([$d, $e, $f] as $refused) {
            $this->assertStringContainsString($refused->shipment_no, $errors);
        }
        $this->assertStringNotContainsString($a->shipment_no, $errors);
        $this->assertStringContainsString(__('transport.selection.expired'), $errors);
        $this->assertStringContainsString(__('transport.bulk_confirm.no_reference'), $errors);
        $this->assertStringContainsString(__('transport.selection.invalid_status'), $errors);

        $this->assertSame('quote_confirmed', $a->fresh()->status);
        $this->assertSame($aOwn->id, $a->fresh()->selected_quote_id);
        $this->assertSame('quote_confirmed', $b->fresh()->status);
        $this->assertSame($bTd->id, $b->fresh()->selected_quote_id);
        $this->assertSame('selected', $bTd->fresh()->status);
        $this->assertSame('coordinator', $bTd->fresh()->selected_by);
        $this->assertSame($dispatcher->id, $bTd->fresh()->selected_by_user_id);
        $this->assertSame('quoted', $bOwn->fresh()->status, 'the recommended quote stays open when the client plan was confirmed');
        $this->assertSame('quoted', $d->fresh()->status);
        $this->assertSame('quoted', $e->fresh()->status);
        $this->assertSame(2, \DB::table('outbox_events')->where('event_name', 'shipment.quote_confirmed')->whereIn('job_id', [$a->job_id, $b->job_id])->count());

        // Once confirmed, A appears in the book batch.
        $this->actingAs($dispatcher)->get(route('transport.index', ['status' => 'quote_confirmed']))->assertOk()
            ->assertSee('name="shipment_ids[]" value="'.$a->id.'" form="book-bulk"', false)
            ->assertDontSee(route('transport.shipments.show', $d), false); // the flashed refusal names D; its row is filtered out
    }

    public function test_ticked_confirmed_shipments_are_booked_and_manual_held_and_unconfirmed_ones_are_named(): void
    {
        $dispatcher = $this->staff('dispatcher');
        $this->actingAs($dispatcher);
        $client = $this->client();
        $own = $this->carrier('OWN');
        $manual = $this->carrier('MAN');

        $a = $this->confirmedShipment($client->id, $own, $dispatcher->id);
        $m = $this->confirmedShipment($client->id, $manual, $dispatcher->id, ['source' => 'manual', 'raw_response' => ['entered_manually' => true]]);
        $h = $this->confirmedShipment($client->id, $own, $dispatcher->id);
        app(ExceptionService::class)->raise('hold', 'orders', ['client_id' => $client->id, 'hold_type' => 'financial', 'order_id' => $h->order_id, 'message' => 'overdue']);
        $q = $this->shipment($client->id);
        $this->quote($q, $own, ['is_recommended' => true]);

        $this->actingAs($dispatcher)->post(route('transport.book_bulk'), [])->assertSessionHasErrors('shipment_ids');
        $this->actingAs($dispatcher)->from(route('transport.index'))
            ->post(route('transport.book_bulk'), ['shipment_ids' => [$a->id, $m->id, $h->id, $q->id]])
            ->assertRedirect(route('transport.index'))
            ->assertSessionHas('status', __('transport.bulk_book.done', ['count' => 1]))
            ->assertSessionHasErrors('shipment_ids');
        $errors = session('errors')->first('shipment_ids');
        foreach ([$m, $h, $q] as $refused) {
            $this->assertStringContainsString($refused->shipment_no, $errors);
        }
        $this->assertStringNotContainsString($a->shipment_no, $errors);
        $this->assertStringContainsString(__('transport.bulk_book.manual_reference'), $errors);
        $this->assertStringContainsString(__('transport.booking.financial_hold'), $errors);
        $this->assertStringContainsString(__('transport.booking.invalid_status'), $errors);

        $this->assertSame('booked', $a->fresh()->status);
        $this->assertStringStartsWith('OWN-', (string) $a->fresh()->booking_ref);
        $this->assertSame('quote_confirmed', $m->fresh()->status);
        $this->assertSame('quote_confirmed', $h->fresh()->status);
        $this->assertSame('quoted', $q->fresh()->status);
        // The manual shipment was refused before the booking service — no manual_transport exception was raised for it.
        $this->assertDatabaseMissing('exceptions', ['type' => 'manual_transport', 'source_type' => 'shipment', 'source_id' => $m->id]);
        $this->assertSame(1, \DB::table('outbox_events')->where('event_name', 'shipment.booked')->where('job_id', $a->job_id)->count());
    }

    public function test_only_planners_see_the_boxes_and_may_post_and_the_filter_is_validated(): void
    {
        $supervisor = $this->staff('warehouse_supervisor');
        $this->actingAs($this->staff('dispatcher'));
        $client = $this->client();
        $own = $this->carrier('OWN');
        $quoted = $this->shipment($client->id);
        $this->quote($quoted, $own, ['is_recommended' => true]);
        $confirmed = $this->confirmedShipment($client->id, $own, null);

        $this->actingAs($supervisor)->get(route('transport.index'))->assertOk()
            ->assertSee($quoted->shipment_no)->assertDontSee('id="confirm-bulk"', false)->assertDontSee('id="book-bulk"', false)->assertDontSee('name="shipment_ids[]"', false);
        $this->actingAs($supervisor)->post(route('transport.quotes.confirm_bulk'), ['shipment_ids' => [$quoted->id]])->assertForbidden();
        $this->actingAs($supervisor)->post(route('transport.book_bulk'), ['shipment_ids' => [$confirmed->id]])->assertForbidden();
        $this->assertSame('quoted', $quoted->fresh()->status);

        $cs = $this->staff('customer_service');
        $this->actingAs($cs)->get(route('transport.index', ['status' => 'quoted']))->assertOk()
            ->assertSee($quoted->shipment_no)->assertDontSee($confirmed->shipment_no)->assertSee('name="shipment_ids[]" value="'.$quoted->id.'" form="confirm-bulk"', false);
        $this->actingAs($cs)->get(route('transport.index', ['status' => 'nope']))->assertSessionHasErrors('status');
        $this->actingAs($cs)->get(route('transport.index', ['per_page' => 999]))->assertSessionHasErrors('per_page');
    }

    private function shipment(int $clientId, array $attributes = []): Shipment
    {
        $job = app(JobService::class)->create($clientId, 'transport_only');

        return Shipment::query()->create($attributes + [
            'shipment_no' => 'SHP-BLK-'.str()->upper(str()->random(8)),
            'job_id' => $job['job_id'],
            'client_id' => $clientId,
            'order_id' => random_int(10000, 99999),
            'shipment_type' => 'outbound',
            'status' => 'quoted',
            'tailgate_required' => false,
        ]);
    }

    /** A 报价已确认 shipment with its selected own-fleet (or given) final quote, as the single 确认最终方案 button leaves it. */
    private function confirmedShipment(int $clientId, Carrier $carrier, ?int $userId, array $quoteAttributes = []): Shipment
    {
        $shipment = $this->shipment($clientId, ['status' => 'quote_confirmed']);
        $quote = $this->quote($shipment, $carrier, $quoteAttributes + [
            'status' => 'selected', 'selected_by' => $userId === null ? 'system' : 'coordinator', 'selected_by_user_id' => $userId,
        ]);
        $shipment->update(['selected_quote_id' => $quote->id, 'carrier_id' => $carrier->id, 'service_level' => $quote->service_level]);

        return $shipment->fresh();
    }

    private function quote(Shipment $shipment, Carrier $carrier, array $attributes = []): TransportQuote
    {
        return TransportQuote::query()->create($attributes + [
            'shipment_id' => $shipment->id,
            'carrier_id' => $carrier->id,
            'source' => 'own_fleet',
            'service_level' => 'standard',
            'cost_cents' => 7500,
            'customer_price_cents' => 7500,
            'eta_days' => 1,
            'quote_stage' => 'final',
            'status' => 'quoted',
            'quoted_at' => now(),
            'expires_at' => now()->addDay(),
            'raw_response' => ['pricing_mode' => 'fixed', '_quote_request' => ['zone' => 'metro', 'items' => []]],
        ]);
    }

    private function carrier(string $code): Carrier
    {
        return Carrier::query()->create(['code' => $code, 'name' => $code, 'status' => 'active']);
    }
}
