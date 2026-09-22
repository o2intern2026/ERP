<?php

namespace Tests\Feature\Transport;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Platform\Models\Document;
use App\Modules\Platform\Models\ExceptionRecord;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Transport\Http\TransportValidation;
use App\Modules\Transport\Models\CarrierInvoice;
use App\Modules\Transport\Models\CarrierService;
use App\Modules\Transport\Models\Pod;
use App\Modules\Transport\Models\RunStop;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\ShipmentExtraCharge;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Services\ConsignmentNotePdf;
use App\Modules\Transport\Services\DeliveryRunService;
use App\Modules\Transport\Services\PackageManifest;
use App\Modules\Transport\Services\ShipmentQuoteRequestFactory;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * Audit 2026-09-22 Part B, Transport (CHANGE_REQUESTS #135, lead approval 2026-09-22): TMS-03 / CS-19 money in dollars, TMS-11
 * extra-charge records, TMS-07 phone photo compression + kept input, TMS-09 failed-stop state, TMS-10 redelivery once and
 * numbered, TMS-12 carrier POD time + images, GAP-05 consignment note parties. Seat C edited the X2 zone as integrator.
 */
class AuditPartBTransportTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    private const CJK = '/[\x{3000}-\x{303F}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{FF00}-\x{FFEF}]/u';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-22 09:30:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** TMS-03 / CS-19: the four money forms take dollars (step 0.01, "(AUD)"), the controllers store cents, the flash echoes the parsed amount. */
    public function test_transport_money_fields_take_dollars_store_cents_and_echo_the_amount(): void
    {
        $dispatcher = $this->staff('dispatcher');
        $shipment = $this->shipment('manual', ['status' => 'quoting'], selected: false);
        $service = CarrierService::query()->create(['carrier_id' => $shipment->carrier_id, 'source' => 'manual', 'service_level' => 'standard', 'default_eta_days' => 1, 'active' => true]);
        $this->mock(ShipmentQuoteRequestFactory::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->andReturn([
                'client_id' => 1,
                'sender' => ['address' => '1 Start St', 'suburb' => 'Melbourne', 'state' => 'VIC', 'postcode' => '3000', 'type' => 'business'],
                'receiver' => ['address' => '2 End St', 'suburb' => 'Sydney', 'state' => 'NSW', 'postcode' => '2000', 'type' => 'business'],
                'items' => [['description' => 'Carton', 'qty' => 1, 'weight_kg' => 10, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250]],
                'declared_value_cents' => 10000, 'tailgate_pickup' => false, 'tailgate_delivery' => false, 'requested_date' => null, 'zone' => 'metro',
            ]);
        });

        // The form: dollars with two decimals and an (AUD) label — no cents box anywhere on the page.
        $page = $this->actingAs($dispatcher)->get(route('transport.shipments.show', $shipment))->assertOk();
        $page->assertSee('name="cost" min="0.01" step="0.01"', false)
            ->assertSee('name="customer_price" min="0.01" step="0.01"', false)
            ->assertSee(__('transport.manual_quote.cost'))
            ->assertSee(__('transport.manual_quote.customer_price'))
            ->assertSee(__('transport.money_hint'))
            ->assertDontSee('name="cost_cents"', false)
            ->assertDontSee('（分）');

        // $125.00 / $185.50 typed as the dispatcher reads them → 12500 / 18550 cents stored, echoed in the flash.
        $this->actingAs($dispatcher)->from(route('transport.shipments.show', $shipment))
            ->post(route('transport.shipments.quotes.manual', $shipment), [
                'carrier_service_id' => $service->id, 'quote_stage' => 'final', 'cost' => '125.00', 'customer_price' => '185.50', 'eta_days' => 2,
            ])
            ->assertRedirect(route('transport.shipments.show', $shipment))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('transport.manual_quote.saved_amounts', ['cost' => '$125.00', 'price' => '$185.50']));
        $quote = TransportQuote::query()->where('shipment_id', $shipment->id)->sole();
        $this->assertSame([12500, 18550], [$quote->cost_cents, $quote->customer_price_cents]);
        $this->assertSame(48.4, (float) $quote->markup_percent);

        // A third decimal is refused in Chinese, naming the field in dollars.
        $this->actingAs($dispatcher)->from(route('transport.shipments.show', $shipment))
            ->post(route('transport.shipments.quotes.manual', $shipment), [
                'carrier_service_id' => $service->id, 'quote_stage' => 'final', 'cost' => '125.005', 'customer_price' => '185.50', 'eta_days' => 2,
            ])
            ->assertSessionHasErrors(['cost' => __('transport.validation.messages.decimal', ['attribute' => __('transport.validation.attributes.cost')])]);
        $this->assertSame(1, TransportQuote::query()->where('shipment_id', $shipment->id)->count());

        // Own-fleet actual cost: $83.50 → 8350 cents, echoed.
        $ownFleet = $this->shipment('own_fleet', ['status' => 'booked']);
        $this->actingAs($this->staff('finance'))->get(route('transport.shipments.show', $ownFleet))->assertOk()
            ->assertSee('name="actual_cost" min="0" step="0.01"', false)
            ->assertSee(__('transport.costs.actual_cost'));
        $this->actingAs($this->staff('finance'))->post(route('transport.shipments.own-fleet-cost.store', $ownFleet), ['actual_cost' => '83.50', 'note' => 'Fuel'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('transport.costs.saved_amount', ['amount' => '$83.50']));
        $this->assertDatabaseHas('carrier_costs', ['shipment_id' => $ownFleet->id, 'actual_cost_cents' => 8350]);
        // The saved cost is shown back in dollars in the form.
        $this->actingAs($this->staff('finance'))->get(route('transport.shipments.show', $ownFleet))->assertOk()->assertSee('value="83.50"', false);

        // Carrier invoice total: $280.00 → 28000 cents, echoed.
        $finance = $this->staff('finance');
        $carrier = Carrier::query()->create(['code' => 'B-INV-'.str()->upper(str()->random(6)), 'name' => 'Invoice Carrier', 'status' => 'active']);
        $this->actingAs($finance)->get(route('transport.carrier-invoices.index'))->assertOk()
            ->assertSee('name="total" min="0" step="0.01"', false)
            ->assertSee(__('transport.reconciliation.total_input'))
            ->assertDontSee('name="total_cents"', false);
        $this->actingAs($finance)->post(route('transport.carrier-invoices.store'), [
            'carrier_id' => $carrier->id, 'invoice_no' => 'INV-B-1', 'period_from' => '2026-09-01', 'period_to' => '2026-09-15', 'total' => '280.00',
            'statement' => UploadedFile::fake()->createWithContent('statement.csv', "tracking_number,billed_cents\nTRK-B-1,28000"),
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('transport.reconciliation.imported_total', ['amount' => '$280.00']));
        $this->assertSame(28000, CarrierInvoice::query()->sole()->total_cents);
    }

    /** TMS-11: a report is recorded with its event, listed on the page, refused before booking, and a repeat of the same type needs the tick. */
    public function test_extra_charge_reports_are_recorded_listed_refused_before_booking_and_repeats_need_confirmation(): void
    {
        $dispatcher = $this->staff('dispatcher', ['name' => 'Dispatcher Dee']);
        $shipment = $this->shipment('own_fleet', ['status' => 'booked']);

        $this->actingAs($dispatcher)->from(route('transport.shipments.show', $shipment))
            ->post(route('transport.shipments.extra-charges.store', $shipment), [
                'charge_type' => 'waiting', 'qty' => 1.5, 'uom' => 'man_hour', 'carrier_cost' => '45.00', 'note' => 'Dock was busy',
            ])->assertRedirect(route('transport.shipments.show', $shipment))->assertSessionHasNoErrors();

        $record = ShipmentExtraCharge::query()->sole();
        $event = OutboxEvent::query()->where('event_name', 'delivery.extra_charge')->sole();
        $this->assertSame([$shipment->id, 'waiting', '1.50', 'man_hour', 4500, 'Dock was busy', $dispatcher->id], [
            $record->shipment_id, $record->charge_type, $record->qty, $record->uom, $record->cost_cents, $record->note, $record->reported_by,
        ]);
        $this->assertSame($event->event_id, $record->event_id, 'the record points at the event published in the same transaction');
        $this->assertSame('2026-09-22T09:30:00+10:00', $record->reported_at->toIso8601String());
        $this->assertSame(4500, $event->payload['cost_cents']);

        // Listed under the form: type, qty, cost, note, who, when.
        $page = $this->actingAs($dispatcher)->get(route('transport.shipments.show', $shipment))->assertOk();
        $page->assertSee(__('transport.extra_charges.reported_list'))
            ->assertSee(__('transport.extra_charges.types.waiting'))
            ->assertSee('1.5 '.__('transport.extra_charges.uoms.man_hour'))
            ->assertSee('$45.00')
            ->assertSee('Dock was busy')
            ->assertSee('Dispatcher Dee')
            ->assertSee('2026-09-22 09:30')
            ->assertSee('name="confirm_repeat"', false)
            ->assertSee('id="extra-charges"', false);

        // The same type again without the tick: refused in Chinese, nothing written, nothing published.
        $this->actingAs($dispatcher)->from(route('transport.shipments.show', $shipment))
            ->post(route('transport.shipments.extra-charges.store', $shipment), [
                'charge_type' => 'waiting', 'qty' => 1, 'uom' => 'man_hour', 'note' => 'Again',
            ])->assertSessionHasErrors(['extra_charge' => __('transport.extra_charges.already_reported', ['type' => __('transport.extra_charges.types.waiting'), 'time' => '2026-09-22 09:30'])]);
        $this->assertSame(1, ShipmentExtraCharge::query()->count());
        $this->assertSame(1, OutboxEvent::query()->where('event_name', 'delivery.extra_charge')->count());

        // With 确认再次上报 it goes through; a different type never needed it.
        $this->actingAs($dispatcher)->post(route('transport.shipments.extra-charges.store', $shipment), [
            'charge_type' => 'waiting', 'qty' => 1, 'uom' => 'man_hour', 'note' => 'Again', 'confirm_repeat' => '1',
        ])->assertSessionHasNoErrors();
        $this->actingAs($dispatcher)->post(route('transport.shipments.extra-charges.store', $shipment), [
            'charge_type' => 'failed', 'qty' => 1, 'uom' => 'delivery', 'note' => 'Nobody home',
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('transport.extra_charges.reported_detail', ['type' => __('transport.extra_charges.types.failed'), 'qty' => '1', 'uom' => __('transport.extra_charges.uoms.delivery'), 'cost' => '']));
        $this->assertSame(3, ShipmentExtraCharge::query()->count());
        $this->assertSame(3, OutboxEvent::query()->where('event_name', 'delivery.extra_charge')->count());

        // Before booking: no form (the page says why) and the server refuses.
        $unbooked = $this->shipment('own_fleet', ['status' => 'quote_confirmed']);
        $this->actingAs($dispatcher)->get(route('transport.shipments.show', $unbooked))->assertOk()
            ->assertDontSee(route('transport.shipments.extra-charges.store', $unbooked))
            ->assertSee(__('transport.extra_charges.not_booked_hint'))
            ->assertSee(__('transport.extra_charges.none_reported'));
        $this->actingAs($dispatcher)->from(route('transport.shipments.show', $unbooked))
            ->post(route('transport.shipments.extra-charges.store', $unbooked), ['charge_type' => 'waiting', 'qty' => 1, 'uom' => 'delivery', 'note' => 'x'])
            ->assertSessionHasErrors(['extra_charge' => __('transport.extra_charges.not_booked')]);
        $this->assertSame(0, ShipmentExtraCharge::query()->where('shipment_id', $unbooked->id)->count());
        $this->assertSame(3, OutboxEvent::query()->where('event_name', 'delivery.extra_charge')->count());
    }

    /** TMS-07: the phone downscales photos before the post; a plain post still works; a rejected post comes back with the signature and name kept for that stop only. */
    public function test_driver_page_compresses_photos_before_the_post_and_keeps_the_signature_on_rejection(): void
    {
        Storage::fake('local');
        $driver = $this->staff('transport_operator');
        $first = $this->stop($driver->id, $this->shipment('own_fleet'));
        $second = $this->stop($driver->id, $this->shipment('own_fleet'));

        $page = $this->actingAs($driver)->get(route('transport.driver'))->assertOk();
        $html = $page->getContent();
        foreach (['createImageBitmap(file)', 'MAX_EDGE = 1600', 'QUALITY = 0.8', "canvas.toBlob(resolve, 'image/jpeg', QUALITY)", 'new DataTransfer()', 'input.files = transfer.files', 'data-photos', 'data-photo-form'] as $needle) {
            $this->assertStringContainsString($needle, $html, "compression script: $needle");
        }
        $this->assertStringNotContainsString('capture="environment"', $html, 'the OS offers camera or library');
        $page->assertSee(__('transport.driver.photos_hint'))->assertSee(__('transport.driver.failure_note'))->assertSee(__('transport.driver.failure_photos'));

        // The server does not depend on the script: an uncompressed photo posts as before.
        $this->actingAs($driver)->post(route('transport.driver.stops.deliver', $first), [
            'stop_id' => $first->id, 'recipient_name' => 'Plain Post', 'signature_data' => $this->signatureData(),
            'photos' => [UploadedFile::fake()->image('full-size.jpg', 2400, 1800)],
        ])->assertRedirect(route('transport.driver'))->assertSessionHasNoErrors();
        $this->assertSame('delivered', $first->fresh()->status);

        // A rejected post (bad signature) comes back with the name and the signature data for THAT stop; the other stop's form stays empty.
        $this->actingAs($driver)->from(route('transport.driver'))->post(route('transport.driver.stops.deliver', $second), [
            'stop_id' => $second->id, 'recipient_name' => 'Kept Person', 'signature_data' => 'data:image/png;base64,not-a-png',
            'photos' => [UploadedFile::fake()->image('gate.jpg')],
        ])->assertRedirect(route('transport.driver'))->assertSessionHasErrors('pod');
        $again = $this->actingAs($driver)->get(route('transport.driver'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($again, 'value="Kept Person"'), 'old() is scoped to the stop that was posted');
        $this->assertStringContainsString('name="signature_data" data-signature-data value="data:image/png;base64,not-a-png"', $again);
        $this->assertStringContainsString('name="stop_id" value="'.$second->id.'"', $again);
    }

    /** TMS-09: a failed stop shows its state (badge, reason, time, note), offers no form, and refuses a repeat failure or a delivery server side. */
    public function test_a_failed_stop_shows_its_state_and_refuses_a_repeat_failure_or_a_delivery(): void
    {
        Storage::fake('local');
        $driver = $this->staff('transport_operator');
        // Two stops on one run: the audit's window — the run stays open (dispatched) after the first stop fails.
        $stop = $this->stop($driver->id, $this->shipment('own_fleet'));
        $other = app(DeliveryRunService::class)->addShipment($stop->deliveryRun, $this->shipment('own_fleet'), '2026-09-22 11:30:00');
        $shipment = $stop->shipment;

        // "Other" needs a note.
        $this->actingAs($driver)->from(route('transport.driver'))
            ->post(route('transport.driver.stops.fail', $stop), ['stop_id' => $stop->id, 'failure_reason' => 'other'])
            ->assertSessionHasErrors(['failure_reason' => __('transport.driver.failure_note_required')]);
        $this->assertDatabaseCount('pods', 0);

        // A failure with a note and a photo of the closed gate.
        $this->actingAs($driver)->post(route('transport.driver.stops.fail', $stop), [
            'stop_id' => $stop->id, 'failure_reason' => 'access_blocked', 'failure_note' => 'Gate locked, no answer on the intercom',
            'photos' => [UploadedFile::fake()->image('gate.jpg')],
        ])->assertRedirect(route('transport.driver'))->assertSessionHas('status', __('transport.driver.failed'));
        $pod = Pod::query()->sole();
        $this->assertSame(['access_blocked', 'Gate locked, no answer on the intercom', 1], [$pod->failure_reason, $pod->failure_note, count($pod->photo_document_ids)]);
        $photo = Document::query()->findOrFail($pod->photo_document_ids[0]);
        $this->assertSame(['photo', 'shipment', $shipment->id, $shipment->shipment_no.'-failure-photo-1.jpg'], [$photo->type, $photo->related_type, $photo->related_id, $photo->original_name]);
        Storage::disk('local')->assertExists($photo->storage_path);
        $this->assertStringStartsWith("transport/shipments/{$shipment->id}/pod/", $photo->storage_path, 'the same upload path as POD photos');
        $exception = ExceptionRecord::query()->withoutGlobalScopes()->where('type', 'delivery_failed')->sole();
        $this->assertStringContainsString('Gate locked, no answer on the intercom', $exception->message);
        $this->assertSame(['failed', 'failed', 'dispatched', 'pending'], [$shipment->fresh()->status, $stop->fresh()->status, $stop->deliveryRun->fresh()->status, $other->fresh()->status]);
        $failedEvent = OutboxEvent::query()->where('event_name', 'delivery.failed')->sole();
        $this->assertArrayNotHasKey('failure_note', $failedEvent->payload, 'the contract payload is untouched');

        // The stop card: failed badge, reason, time, note — and neither form.
        $page = $this->actingAs($driver)->get(route('transport.driver'))->assertOk();
        $page->assertSee(__('transport.stop_statuses.failed'))
            ->assertSee(__('transport.driver.failed_state', ['reason' => __('transport.driver.failure_reasons.access_blocked'), 'time' => '2026-09-22 09:30']))
            ->assertSee('Gate locked, no answer on the intercom')
            ->assertSee(__('transport.driver.failed_photos_label', ['count' => 1]))
            ->assertDontSee(route('transport.driver.stops.deliver', $stop))
            ->assertDontSee(route('transport.driver.stops.fail', $stop))
            ->assertSee(route('transport.driver.stops.deliver', $other)) // the pending stop of the same run keeps its forms
            ->assertSee(__('transport.stop_statuses.pending'));

        // A second failure and a delivery after the failure are refused; one pod row, one delivery.failed, the shipment stays failed.
        $refusal = __('transport.driver.already_failed', ['reason' => __('transport.driver.failure_reasons.access_blocked'), 'time' => '2026-09-22 09:30']);
        $this->actingAs($driver)->from(route('transport.driver'))
            ->post(route('transport.driver.stops.fail', $stop), ['failure_reason' => 'recipient_unavailable'])
            ->assertSessionHasErrors(['failure_reason' => $refusal]);
        $this->actingAs($driver)->from(route('transport.driver'))
            ->post(route('transport.driver.stops.deliver', $stop), [
                'recipient_name' => 'Late Signer', 'signature_data' => $this->signatureData(), 'photos' => [UploadedFile::fake()->image('late.jpg')],
            ])->assertSessionHasErrors(['pod' => $refusal]);
        $this->assertDatabaseCount('pods', 1);
        $this->assertSame(1, OutboxEvent::query()->where('event_name', 'delivery.failed')->count());
        $this->assertSame(0, OutboxEvent::query()->where('event_name', 'delivery.pod_captured')->count());
        $this->assertSame('failed', $shipment->fresh()->status);
        $this->assertMatchesRegularExpression(self::CJK, $refusal);
    }

    /** TMS-10: one redelivery per failed shipment, numbered <original>-R1 / -R2, linked both ways, with the TR-REDELIVERY reminder. */
    public function test_redelivery_is_created_once_numbered_r1_then_r2_linked_both_ways_and_reminds_the_fee(): void
    {
        $driver = $this->staff('transport_operator');
        $dispatcher = $this->staff('dispatcher');
        $original = $this->shipment('own_fleet', ['shipment_no' => 'SHP-ORD-20260921-0009']);
        $stop = $this->stop($driver->id, $original);
        $this->actingAs($driver)->post(route('transport.driver.stops.fail', $stop), ['failure_reason' => 'recipient_unavailable'])->assertSessionHasNoErrors();

        $response = $this->actingAs($dispatcher)->post(route('transport.shipments.redelivery.store', $original));
        $redelivery = Shipment::query()->where('redelivery_of_shipment_id', $original->id)->sole();
        $this->assertSame('SHP-ORD-20260921-0009-R1', $redelivery->shipment_no, 'the last digit of the original is kept');
        $this->assertSame($original->id, $redelivery->selectedQuote->raw_response['_redelivery_of']);
        $response->assertRedirect(route('transport.shipments.show', $redelivery))
            ->assertSessionHas('status', __('transport.redelivery.created'))
            ->assertSessionHas('redelivery_hint', $original->id);

        // The new page: the origin link and the fee reminder linking the ORIGINAL's extra-charge form (the new one is not booked yet).
        $this->actingAs($dispatcher)->get(route('transport.shipments.show', $redelivery))->assertOk()
            ->assertSee(__('transport.redelivery.origin'))
            ->assertSee('href="'.route('transport.shipments.show', $original).'"', false)
            ->assertSee(__('transport.redelivery.charge_hint', ['shipment' => $original->shipment_no]))
            ->assertSee('href="'.route('transport.shipments.show', $original).'#extra-charges"', false);

        // The original page: the link replaces the button; a second click is refused and creates nothing.
        $this->actingAs($dispatcher)->get(route('transport.shipments.show', $original))->assertOk()
            ->assertSee(__('transport.redelivery.existing'))
            ->assertSee('href="'.route('transport.shipments.show', $redelivery).'"', false)
            ->assertDontSee(route('transport.shipments.redelivery.store', $original));
        $this->actingAs($dispatcher)->from(route('transport.shipments.show', $original))
            ->post(route('transport.shipments.redelivery.store', $original))
            ->assertSessionHasErrors(['redelivery' => __('transport.redelivery.already_exists', ['shipment' => 'SHP-ORD-20260921-0009-R1'])]);
        $this->assertSame(2, Shipment::query()->where('order_id', $original->order_id)->count());

        // R1 fails too → the next attempt is -R2 (from R1, not from the original).
        $stop2 = app(DeliveryRunService::class)->addShipment(app(DeliveryRunService::class)->create('2026-09-22', $driver->id, 'VAN-R2'), $redelivery->refresh(), '2026-09-22 11:00:00');
        $this->actingAs($driver)->post(route('transport.driver.stops.fail', $stop2), ['failure_reason' => 'address_issue'])->assertSessionHasNoErrors();
        $this->actingAs($dispatcher)->post(route('transport.shipments.redelivery.store', $redelivery))->assertSessionHasNoErrors();
        $this->assertSame('SHP-ORD-20260921-0009-R2', Shipment::query()->where('redelivery_of_shipment_id', $redelivery->id)->sole()->shipment_no);
        $this->assertSame(3, Shipment::query()->where('order_id', $original->order_id)->count());
    }

    /** TMS-12: the carrier's own signing time becomes delivered_at and the event time; JPG / PNG are archived as the POD next to PDF. */
    public function test_carrier_pod_uses_the_reported_delivery_time_and_accepts_images(): void
    {
        Storage::fake('local');
        $dispatcher = $this->staff('dispatcher');
        $shipment = $this->shipment('transdirect', ['status' => 'booked']);
        Shipment::query()->whereKey($shipment->id)->update(['created_at' => '2026-09-19 08:00:00']); // booked last week

        $this->actingAs($dispatcher)->get(route('transport.shipments.show', $shipment))->assertOk()
            ->assertSee(__('transport.carrier_pod.delivered_at'))
            ->assertSee('name="delivered_at" value="2026-09-22T09:30"', false) // default now, through <x-date-field time>
            ->assertSee('accept="application/pdf,image/jpeg,image/png"', false);

        // A future time is refused; a time before the shipment existed is refused; a PDF-named file that is not a PDF / image is refused.
        $this->actingAs($dispatcher)->from(route('transport.shipments.show', $shipment))->post(route('transport.shipments.pod.store', $shipment), [
            'recipient_name' => 'Carrier Recipient', 'delivered_at' => '2026-09-23T08:00',
            'pod_file' => UploadedFile::fake()->createWithContent('pod.pdf', "%PDF-1.4\nx"),
        ])->assertSessionHasErrors(['delivered_at' => TransportValidation::messages()['delivered_at.before_or_equal']]);
        $this->actingAs($dispatcher)->from(route('transport.shipments.show', $shipment))->post(route('transport.shipments.pod.store', $shipment), [
            'recipient_name' => 'Carrier Recipient', 'delivered_at' => '2026-09-18T08:00',
            'pod_file' => UploadedFile::fake()->createWithContent('pod.pdf', "%PDF-1.4\nx"),
        ])->assertSessionHasErrors(['pod_file' => __('transport.carrier_pod.delivered_before_shipment', ['time' => '2026-09-19 08:00'])]);
        $this->actingAs($dispatcher)->from(route('transport.shipments.show', $shipment))->post(route('transport.shipments.pod.store', $shipment), [
            'recipient_name' => 'Carrier Recipient', 'delivered_at' => '2026-09-21T16:20',
            'pod_file' => UploadedFile::fake()->createWithContent('pod.pdf', 'not really a pdf'),
        ])->assertSessionHasErrors('pod_file');
        $this->assertDatabaseCount('pods', 0);

        // Monday's phone photo uploaded on Tuesday: delivered Monday 16:20 everywhere.
        $this->actingAs($dispatcher)->post(route('transport.shipments.pod.store', $shipment), [
            'recipient_name' => 'Carrier Recipient', 'delivered_at' => '2026-09-21T16:20',
            'pod_file' => UploadedFile::fake()->image('carrier-pod.jpg', 640, 480),
        ])->assertSessionHasNoErrors()->assertSessionHas('status', __('transport.carrier_pod.saved'));
        $pod = Pod::query()->sole();
        $this->assertSame('2026-09-21T16:20:00+10:00', $pod->delivered_at->toIso8601String());
        $this->assertSame('2026-09-21T16:20:00+10:00', $shipment->fresh()->delivered_at->toIso8601String());
        $document = Document::query()->findOrFail($pod->pod_document_id);
        $this->assertSame(['pod', 'image/jpeg', $shipment->shipment_no.'-carrier-pod.jpg'], [$document->type, $document->mime, $document->original_name]);
        Storage::disk('local')->assertExists($document->storage_path);
        $event = OutboxEvent::query()->where('event_name', 'delivery.pod_captured')->sole();
        $this->assertSame('2026-09-21T16:20:00+10:00', $event->payload['delivered_at']);
        $this->assertSame('carrier_api', $event->payload['captured_by_type']);
        $this->actingAs($dispatcher)->get(route('transport.shipments.show', $shipment))->assertOk()
            ->assertSee(__('transport.carrier_pod.available', ['recipient' => 'Carrier Recipient', 'time' => '2026-09-21 16:20']));
    }

    /** GAP-05: every consignment note prints From / Deliver to, the order number, the tracking number and a signing box — all from lang/en/pdf.php. */
    public function test_consignment_note_prints_parties_order_number_tracking_and_a_receipt_box_for_delivery_shipments(): void
    {
        $dispatcher = $this->staff('dispatcher');
        $this->actingAs($dispatcher);
        $warehouse = $this->warehouse(); // MEL DC, 1 Depot Road, Dandenong South VIC 3175
        $client = $this->client(['name' => 'Edward Logistics']);
        $job = app(JobService::class)->create($client->id, 'loose');
        $order = app(OrderCreationService::class)->create([
            'client_id' => $client->id, 'job_id' => $job['job_id'], 'order_type' => 'from_stock', 'external_ref' => 'GAP05-'.uniqid(),
            'deliver_to_name' => 'Consignee Pty Ltd', 'deliver_to_phone' => '0400 123 456', 'deliver_to_address' => '88 Harbour St', 'deliver_to_suburb' => 'Sydney',
            'deliver_to_state' => 'NSW', 'deliver_to_postcode' => '2000', 'deliver_to_address_type' => 'business', 'requested_date' => today()->addDay()->toDateString(),
            'service_level' => 'standard', 'lines' => [['description_en' => 'Goods', 'package_type' => 'carton', 'carton_qty' => 3]],
        ], null, 'manual');
        Order::query()->withoutGlobalScopes()->whereKey($order->id)->update(['delivery_instructions' => 'Leave at loading dock B, call first', 'deliver_to_phone' => '0400 123 456']);

        $shipment = $this->shipment('own_fleet', [
            'client_id' => $client->id, 'job_id' => $job['job_id'], 'order_id' => $order->id, 'fulfilment_id' => null,
            'tracking_number' => 'TRK-GAP05-1', 'tailgate_required' => true, 'status' => 'booked',
        ], receiverOnly: true);

        $html = view('transport::shipments.consignment-note', app(ConsignmentNotePdf::class)->data($shipment, app(PackageManifest::class)->forShipment($shipment)))->render();
        foreach ([
            __('pdf.consignment_note.from'), $warehouse->name, '1 Depot Road', 'Dandenong South VIC 3175',
            __('pdf.consignment_note.deliver_to'), 'Consignee Pty Ltd', '0400 123 456', '88 Harbour St', 'Sydney NSW 2000',
            __('pdf.consignment_note.instructions'), 'Leave at loading dock B, call first',
            __('pdf.consignment_note.tailgate_flag'),
            '<td>'.$order->order_no.'</td>', __('pdf.consignment_note.tracking'), 'TRK-GAP05-1',
            __('pdf.consignment_note.received_title'), __('pdf.consignment_note.name'), __('pdf.consignment_note.signature'), __('pdf.consignment_note.date'),
        ] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('<td>'.$order->id.'</td>', $html, 'the Order box prints the order number, not the database id');
        $this->assertStringNotContainsString('pdf.', $html);
        $this->assertDoesNotMatchRegularExpression(self::CJK, $html);

        $response = $this->actingAs($dispatcher)->get(route('transport.shipments.consignment-note', $shipment));
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    private function shipment(string $source, array $attributes = [], bool $selected = true, bool $receiverOnly = false): Shipment
    {
        $client = isset($attributes['client_id']) ? null : $this->client();
        $job = isset($attributes['job_id']) ? null : app(JobService::class)->create($client->id, 'transport_only');
        $carrier = Carrier::query()->create([
            'code' => 'B-'.str()->upper(str()->random(8)),
            'name' => $source === 'own_fleet' ? 'ERP Own Fleet' : 'Carrier '.$source,
            'status' => 'active',
        ]);
        $shipment = Shipment::query()->create($attributes + [
            'shipment_no' => 'SHP-B-'.str()->upper(str()->random(8)),
            'job_id' => $job['job_id'] ?? null,
            'client_id' => $client?->id,
            'order_id' => random_int(10000, 99999),
            'fulfilment_id' => random_int(10000, 99999),
            'shipment_type' => 'outbound',
            'status' => 'quote_confirmed',
            'carrier_id' => $carrier->id,
            'service_level' => 'standard',
            'tailgate_required' => false,
        ]);
        if (! $selected) {
            return $shipment->refresh();
        }

        $request = [
            'receiver' => ['name' => 'Receiving Team', 'phone' => '0400 000 111', 'address' => '88 Test Street', 'suburb' => 'Sydney', 'state' => 'NSW', 'postcode' => '2000', 'type' => 'business'],
            'items' => [['description' => 'Carton', 'qty' => 1, 'weight_kg' => 10, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250]],
            'zone' => '2000',
        ];
        if (! $receiverOnly) {
            $request['sender'] = ['name' => 'MEL DC', 'address' => '1 Depot Road', 'suburb' => 'Dandenong South', 'state' => 'VIC', 'postcode' => '3175', 'type' => 'business'];
        }
        $quote = TransportQuote::query()->create([
            'shipment_id' => $shipment->id,
            'carrier_id' => $carrier->id,
            'source' => $source,
            'service_level' => 'standard',
            'cost_cents' => 10000,
            'customer_price_cents' => 12000,
            'markup_percent' => 20,
            'eta_days' => 1,
            'quote_stage' => 'final',
            'status' => 'selected',
            'selected_by' => 'system',
            'quoted_at' => now(),
            'expires_at' => now()->addDay(),
            'raw_response' => ['_booking' => ['service_code' => $source.'.standard', 'quote_ref' => '', 'pickup_dates' => []], '_quote_request' => $request],
        ]);
        $shipment->update(['selected_quote_id' => $quote->id]);

        return $shipment->refresh();
    }

    private function stop(int $driverId, Shipment $shipment, string $runDate = '2026-09-22'): RunStop
    {
        $run = app(DeliveryRunService::class)->create($runDate, $driverId, 'VAN-B-'.str()->upper(str()->random(4)));

        return app(DeliveryRunService::class)->addShipment($run, $shipment, $runDate.' 10:30:00');
    }

    private function signatureData(): string
    {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL+WQAAAABJRU5ErkJggg==';
    }
}
