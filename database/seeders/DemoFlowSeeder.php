<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Billing\Models\ChargeCode;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Billing\Services\RateCardService;
use App\Modules\Billing\Services\StorageBillingService;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderApiTokenService;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Orders\Services\OrderHoldService;
use App\Modules\Orders\Services\OrderStatusService;
use App\Modules\Orders\Services\ReturnRequestService;
use App\Modules\Platform\Models\Approval;
use App\Modules\Platform\Services\ApprovalService;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Modules\Transport\Models\CarrierService;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Services\CarrierPodService;
use App\Modules\Transport\Services\DeliveryRunService;
use App\Modules\Transport\Services\DriverPodService;
use App\Modules\Transport\Services\ManualQuoteService;
use App\Modules\Transport\Services\QuoteSelectionService;
use App\Modules\Transport\Services\RedeliveryService;
use App\Modules\Transport\Services\ShipmentBookingService;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\AsnLine;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\AsnImportService;
use App\Modules\Warehouse\Services\AsnOrderGeneration;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\OutboundService;
use App\Modules\Warehouse\Services\PutawayService;
use App\Modules\Warehouse\Services\ReceivingService;
use App\Modules\Warehouse\Services\ReturnService;
use App\Modules\Warehouse\Services\SnapshotService;
use App\Modules\Warehouse\Services\TaskService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ERP_PLAN §7 end-to-end demo data, built ONLY through the modules' own services (no direct table writes), so the
 * same run doubles as an executable acceptance scenario (tests/Feature/DemoFlowTest.php).
 *
 *   php artisan migrate:fresh --seed && php artisan db:seed --class=DemoFlowSeeder
 *
 * Story: Edward (per_job) — the real 40ft manifest becomes an ASN, is received (one short line, one damaged carton),
 * put away, turned into orders by consignment mark; four orders are confirmed, waved, picked (one pick-short), packed;
 * one goes by own fleet and is signed for by the driver, one fails at the door and is redelivered, one goes by a third
 * party (Karrio when configured, else a manual quote) with a carrier POD, one is held by Finance. Monthly Demo (monthly)
 * ships two loose-truck jobs into the un-invoiced pool; Prepaid Demo has a locked order. A return is received and
 * inspected; daily snapshots roll into a storage invoice; a service invoice is issued and part-paid; an API token is issued.
 * Every step is best-effort: a failing step is reported in the summary instead of aborting the seed.
 */
class DemoFlowSeeder extends Seeder
{
    /** @var list<string> */
    private array $notes = [];

    /** @var array<string, mixed> */
    private array $out = [];

    public function run(): void
    {
        if (Asn::query()->withoutGlobalScopes()->where('unplanned', false)->whereHas('containers', fn ($q) => $q->where('container_no', 'COSU6508115030'))->exists()) {
            $this->command?->warn('Demo flow already seeded — run `php artisan migrate:fresh --seed` first.');

            return;
        }

        $this->step('Edward client rate card (own-fleet delivery, tailgate, fuel) approved by a second person', fn () => $this->edwardRateCard());
        $this->step('Edward 40ft container: manifest import → receiving → putaway → VAS tasks', fn () => $this->edwardContainer());
        $this->step('Edward orders generated from the ASN, confirmed, waved, picked, packed', fn () => $this->edwardOutbound());
        $this->step('Edward transport: own fleet + driver POD, failed delivery + redelivery, third party + carrier POD, financial hold', fn () => $this->edwardTransport());
        $this->step('Return: request → receipt → inspection → financial decision', fn () => $this->returnFlow());
        $this->step('Monthly Demo: two loose-truck jobs into the un-invoiced pool', fn () => $this->monthlyClient());
        $this->step('Prepaid Demo: confirmed order under a financial lock', fn () => $this->prepaidClient());
        $this->step('Daily snapshots for the last 8 days and weekly storage billing', fn () => $this->snapshots());
        $this->step('Invoices: service (issued, part-paid), storage week, monthly consolidated', fn () => $this->invoices());
        $this->step('Order API token for the Edward integration', fn () => $this->apiToken());
        $this->dispatch();

        foreach ($this->notes as $note) {
            $this->command?->line($note);
        }
        $this->command?->line('Summary: '.json_encode(array_diff_key($this->out, ['api_token_plain' => 1, 'storage_week' => 1, 'edward_fulfilments' => 1]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    public function output(): array
    {
        return $this->out;
    }

    private function step(string $title, callable $fn): void
    {
        try {
            $fn();
            $this->dispatch();
            $this->notes[] = "✓ {$title}";
        } catch (Throwable $e) {
            $this->notes[] = "✗ {$title}: ".get_class($e).' — '.$e->getMessage();
        }
    }

    private function dispatch(int $rounds = 4): void
    {
        for ($i = 0; $i < $rounds; $i++) {
            app(OutboxDispatcher::class)->dispatchDue();
        }
    }

    private function user(string $role): User
    {
        return User::query()->where('email', str_replace('_', '-', $role).'@erp.local')->firstOrFail();
    }

    private function client(string $code): Client
    {
        return Client::query()->where('code', $code)->firstOrFail();
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::query()->where('code', 'MEL')->firstOrFail();
    }

    private function location(string $type, int $i = 0): Location
    {
        $list = Location::query()->where('warehouse_id', $this->warehouse()->id)->where('type', $type)->where('active', true)->orderBy('full_code')->get();

        return $list[$i % max(1, $list->count())];
    }

    // ---------------------------------------------------------------- Edward

    private function edwardRateCard(): void
    {
        $edward = $this->client('EDWARD');
        $admin = $this->user('admin');
        $rates = app(RateCardService::class);
        $codes = ChargeCode::query()->pluck('id', 'code');
        $card = $rates->createClientCard($edward, $admin, today(), 'Edward client card — own fleet delivery');
        $rates->addItem($card, ['charge_code_id' => $codes['TR-DELIVERY-BASE'], 'pricing_mode' => 'fixed', 'rate_cents' => 9500, 'notes' => 'own fleet metro delivery']);
        $rates->addItem($card, ['charge_code_id' => $codes['TR-TAILGATE'], 'pricing_mode' => 'fixed', 'rate_cents' => 4500]);
        $rates->addItem($card, ['charge_code_id' => $codes['TR-FUEL'], 'pricing_mode' => 'percent', 'markup_percent' => 10]);
        $rates->requestActivation($card, $admin, 'Own-fleet delivery price agreed with Edward');
        $approval = Approval::query()->where('subject_type', 'rate_card')->where('subject_id', $card->id)->latest('id')->firstOrFail();
        app(ApprovalService::class)->approve($approval, $this->user('finance'), 'checked against the signed agreement');
        $this->out['edward_card_id'] = $rates->activate($card, $admin)->id;
    }

    private function edwardContainer(): void
    {
        $edward = $this->client('EDWARD');
        $warehouse = $this->warehouse();
        $operator = $this->user('warehouse_operator');
        auth()->login($operator);

        $asn = app(AsnService::class)->create([
            'client_id' => $edward->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container', 'reference' => 'COSU6508115030', 'expected_date' => today()->toDateString(),
            'containers' => [['container_no' => 'COSU6508115030', 'size' => '40', 'unpack_mode' => 'loose', 'gross_weight_kg' => 18200]],
        ]);
        $workbook = base_path('data/需派送货物清单.xlsx');
        $tmp = tempnam(sys_get_temp_dir(), 'manifest').'.xlsx';
        copy($workbook, $tmp);
        app(AsnImportService::class)->import($asn, new UploadedFile($tmp, '需派送货物清单.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true), 'COSU6508115030');
        app(AsnService::class)->markArrived($asn);
        $asn->refresh();
        $container = $asn->containers()->firstOrFail();
        $this->out['edward_asn_id'] = $asn->id;
        $this->out['edward_job_id'] = $asn->job_id;

        $tasks = app(TaskService::class);
        $devan = $tasks->create('devanning', ['job_id' => $asn->job_id, 'client_id' => $edward->id, 'warehouse_id' => $warehouse->id, 'source_type' => 'container', 'source_id' => $container->id, 'asn_id' => $asn->id, 'container_id' => $container->id]);
        $tasks->complete($devan, ['billable_qty' => 1, 'billable_uom' => 'container'], $asn->asn_no);

        $receiving = app(ReceivingService::class);
        $putaway = app(PutawayService::class);
        $lines = $asn->lines()->orderBy('id')->get();
        $pallets = 0;
        $sources = ['chep', 'warehouse_plain', 'client_own', 'loscam'];
        foreach ($lines as $i => $line) {
            $expected = (int) $line->expected_cartons;
            $received = $expected;
            $data = ['received_cartons' => $received, 'units' => []];
            if ($i === 3 && $expected > 2) { // §4.7 #1 / #8: short shipped, reason recorded → discrepancy exception
                $received = $expected - 2;
                $data = ['received_cartons' => $received, 'variance_reason' => 'short shipped — 2 cartons missing from container', 'units' => []];
            }
            if ($i === 7 && $received > 1) { // one damaged carton → quarantine, still counted as received
                $received -= 1;
                $data['received_cartons'] = $received;
                $data['damaged_cartons'] = 1;
                $data['variance_reason'] = 'carton crushed';
            }
            if ($received < 1) {
                $data['received_cartons'] = 0;
            }
            if ($received >= 4) {
                $data['units'][] = ['unit_type' => 'pallet', 'carton_qty' => $received, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => $i % 5 === 0 ? 1600 : 1400, 'weight_kg' => max(50.0, (float) ($line->weight_kg ?? 0)), 'pallet_source' => $sources[$pallets % 4]];
                $pallets++;
            } elseif ($received > 0) {
                $data['units'][] = ['unit_type' => 'carton', 'carton_qty' => $received];
            }
            if ($data['units'] === [] && ($data['damaged_cartons'] ?? 0) === 0) {
                continue;
            }
            $units = $receiving->receiveLine($line, $data, $this->location('receiving'));
            foreach ($units as $u => $unit) {
                $target = $unit->condition !== 'good' ? $this->location('quarantine') : ($unit->unit_type === 'pallet' ? $this->location('storage', $pallets + $u) : $this->location('pickface', $i));
                $putaway->putaway($unit, $target);
            }
        }
        $this->out['edward_pallets'] = $pallets;

        $wrap = $tasks->create('wrap', ['job_id' => $asn->job_id, 'client_id' => $edward->id, 'warehouse_id' => $warehouse->id, 'source_type' => 'container', 'source_id' => $container->id, 'asn_id' => $asn->id, 'container_id' => $container->id]);
        $tasks->complete($wrap, ['billable_qty' => 3, 'billable_uom' => 'pallet'], $asn->asn_no);
        $labour = $tasks->create('labour', ['job_id' => $asn->job_id, 'client_id' => $edward->id, 'warehouse_id' => $warehouse->id, 'source_type' => 'asn', 'source_id' => $asn->id, 'asn_id' => $asn->id, 'notes' => 'relabelling FBA cartons']);
        $tasks->complete($labour, ['hours_business' => 2, 'hours_after_hours' => 1], $asn->asn_no);
        $scan = $tasks->create('scanning', ['job_id' => $asn->job_id, 'client_id' => $edward->id, 'warehouse_id' => $warehouse->id, 'source_type' => 'asn', 'source_id' => $asn->id, 'asn_id' => $asn->id]);
        $tasks->complete($scan, ['serials' => ['SN-24001', 'SN-24002', 'SN-24003', 'SN-24004', 'SN-24005']], $asn->asn_no);
        auth()->logout();
    }

    private function edwardOutbound(): void
    {
        $asn = Asn::query()->withoutGlobalScopes()->findOrFail($this->out['edward_asn_id']);
        if ($asn->status !== 'putaway') {
            throw new \RuntimeException("ASN {$asn->asn_no} is '{$asn->status}', not put away — check receiving");
        }
        $cs = $this->user('customer_service');
        $operator = $this->user('warehouse_operator');
        $generated = app(AsnOrderGeneration::class)->generate($asn);
        $this->out['edward_orders_generated'] = count($generated['orders']);
        $this->out['edward_orders_blocked'] = count($generated['blocked']);

        // Confirm four orders: the two lightest FBA ones and two others with a single line each.
        $orders = Order::query()->withoutGlobalScopes()->whereIn('id', array_column($generated['orders'], 'order_id'))->with('lines')->get()
            ->sortBy(fn (Order $o) => $o->lines->sum('carton_qty'))->values();
        // Slot B gets an order that can be picked short (a line with qty > 1, or at least two lines); the rest are the lightest.
        $shortable = $orders->first(fn (Order $o) => $o->lines->max('carton_qty') > 1 || $o->lines->count() >= 2) ?? $orders->first();
        $rest = $orders->reject(fn (Order $o) => $o->is($shortable))->take(3)->values();
        $picked = collect([$rest[0], $shortable, $rest[1], $rest[2]])->filter()->values();
        if ($picked->count() < 4) {
            throw new \RuntimeException('need at least four generated orders, got '.$orders->count());
        }
        $statuses = app(OrderStatusService::class);
        foreach ($picked as $order) {
            $statuses->transitionOperational($order, 'confirmed', $cs->id, 'demo: confirmed by customer service');
        }
        $this->dispatch();
        $this->out['edward_order_ids'] = $picked->pluck('id')->all();

        $outbound = app(OutboundService::class);
        $wave = $outbound->releaseWave($this->warehouse()->id, ['order_ids' => $picked->pluck('id')->all()], $operator->id);
        $this->out['wave_no'] = $wave['wave']->wave_no;
        $fulfilments = [];
        $shorted = false;
        foreach ($wave['tasks'] as $task) {
            $lines = $task->lines->values();
            foreach ($lines as $l => $line) {
                $qty = $line->required_qty;
                if (! $shorted && $task->order_id === $picked[1]->id) { // §4.7 #10: one Pick Short on order B — a partial line, or a whole second line
                    if ($line->required_qty > 1) {
                        $qty = $line->required_qty - 1;
                        $shorted = true;
                    } elseif ($l > 0) {
                        $qty = 0;
                        $shorted = true;
                    }
                }
                $outbound->confirmPick($line, $qty, $operator->id);
            }
            $packages = [];
            foreach ($task->fresh()->lines as $line) {
                if ($line->completed_qty > 0) {
                    $packages[] = ['package_type' => $line->stockUnit?->unit_type === 'pallet' ? 'pallet' : 'carton', 'weight_kg' => round(max(2.0, (float) ($line->stockUnit?->asnLine?->weight_kg ?? 10)), 2), 'length_mm' => 600, 'width_mm' => 400, 'height_mm' => 400];
                }
            }
            $outbound->pack($task->fulfilment_id, $packages, $operator->id);
            $fulfilments[$task->order_id] = $task->fulfilment_id;
        }
        $this->out['edward_fulfilments'] = $fulfilments;
    }

    private function edwardTransport(): void
    {
        $dispatcher = $this->user('dispatcher');
        $driver = $this->user('transport_operator');
        $finance = $this->user('finance');
        $operator = $this->user('warehouse_operator');
        $outbound = app(OutboundService::class);
        $selection = app(QuoteSelectionService::class);
        $orderIds = $this->out['edward_order_ids'];
        $fulfilments = $this->out['edward_fulfilments'];

        $finalShipment = fn (int $orderId) => Shipment::query()->where('order_id', $orderId)->where('shipment_type', 'outbound')->whereNotNull('fulfilment_id')->orderByDesc('id')->firstOrFail();
        $finalQuote = fn (Shipment $s, string $source) => TransportQuote::query()->where('shipment_id', $s->id)->where('quote_stage', 'final')->where('status', 'quoted')->where('source', $source)->orderBy('customer_price_cents')->first();

        // A + B go on today's own-fleet run (both stops planned first — a run stops accepting stops once it is on the road).
        $a = $finalShipment($orderIds[0]);
        $quoteA = $finalQuote($a, 'own_fleet') ?? throw new \RuntimeException('no own-fleet quote for '.$a->shipment_no.' — is the Edward client card active?');
        $selection->select($a->refresh(), $quoteA, 'coordinator', $dispatcher->id);
        $b = $finalShipment($orderIds[1]);
        $quoteB = $finalQuote($b, 'own_fleet') ?? throw new \RuntimeException('no own-fleet quote for '.$b->shipment_no);
        $selection->select($b->refresh(), $quoteB, 'coordinator', $dispatcher->id);
        $runs = app(DeliveryRunService::class);
        $run = $runs->create(today()->toDateString(), $driver->id, 'VAN-01 (Hino 300)');
        $stopA = $runs->addShipment($run, $a->refresh(), today()->setTime(10, 30)->toDateTimeString());   // booking happens here (own fleet)
        $stopB = $runs->addShipment($run->refresh(), $b->refresh(), today()->setTime(13, 0)->toDateTimeString());
        $outbound->dispatch($fulfilments[$orderIds[0]], 0, 'driver', $a->id, $operator->id);
        $outbound->dispatch($fulfilments[$orderIds[1]], 0, 'driver', $b->id, $operator->id);
        $this->dispatch();

        // A: the driver signs on the phone page → delivered, POD e-mail (§7 #5, §5.7 #4).
        app(DriverPodService::class)->deliver($stopA->refresh(), $driver, 'Receiving clerk', $this->signature(), [UploadedFile::fake()->image('delivered.jpg', 320, 240)]);
        $this->out['own_fleet_shipment'] = $a->shipment_no;
        $this->out['run_no'] = $run->run_no;

        // B: consignee unavailable → failed → redelivery shipment created (§5.7 #6).
        app(DriverPodService::class)->fail($stopB->refresh(), $driver, DriverPodService::FAILURE_REASONS[0]);
        $this->out['redelivery_shipment'] = app(RedeliveryService::class)->create($b->refresh(), $dispatcher)->shipment_no;
        $this->dispatch();

        // C: third party — Karrio when the gateway is configured, otherwise a coordinator's manual quote — carrier POD (§5.7 #5).
        $c = $finalShipment($orderIds[2]);
        $quoteC = $finalQuote($c, 'karrio');
        if ($quoteC === null) {
            $manualService = CarrierService::query()->where('source', 'manual')->where('active', true)->firstOrFail();
            $quoteC = app(ManualQuoteService::class)->record($c->refresh(), $manualService, 'final', 11000, 14500, 2);
        }
        $selection->select($c->refresh(), $quoteC, 'client', $this->user('client')->id);
        app(ShipmentBookingService::class)->book($c->refresh(), $quoteC->source === 'manual' ? 'MANUAL-'.$c->shipment_no : null, $quoteC->source === 'manual' ? 'TRK-'.$c->id : null, today()->addDay()->toDateString());
        $outbound->dispatch($fulfilments[$orderIds[2]], 0, 'carrier', $c->id, $operator->id);
        $this->dispatch();
        $pdf = tempnam(sys_get_temp_dir(), 'pod').'.pdf';
        file_put_contents($pdf, "%PDF-1.4\n% demo carrier POD ".$c->shipment_no."\n");
        app(CarrierPodService::class)->capture($c->refresh(), $dispatcher, 'Dock supervisor', new UploadedFile($pdf, 'carrier-pod.pdf', 'application/pdf', null, true));
        $this->out['third_party_shipment'] = $c->shipment_no;
        $this->out['third_party_source'] = $quoteC->source;

        // D: Finance locks the order after packing — the warehouse handover is refused until release (§3.8 #7).
        $d = Order::query()->withoutGlobalScopes()->findOrFail($orderIds[3]);
        $holdId = app(OrderHoldService::class)->place($d, 'financial', 'account 45 days overdue — release on payment', $finance->id);
        try {
            $outbound->dispatch($fulfilments[$orderIds[3]], 0, 'carrier', null, $operator->id);
            $this->notes[] = '✗ financial hold did NOT block the handover';
        } catch (\InvalidArgumentException) {
            $this->out['held_order_no'] = $d->order_no;
            $this->out['hold_exception_id'] = $holdId;
        }
    }

    private function returnFlow(): void
    {
        $cs = $this->user('customer_service');
        $supervisor = $this->user('warehouse_supervisor');
        $finance = $this->user('finance');
        $original = Order::query()->withoutGlobalScopes()->with('lines')->findOrFail($this->out['edward_order_ids'][0]);
        $line = $original->lines->first();
        $return = app(ReturnRequestService::class)->request($original, [['order_line_id' => $line->id, 'qty' => 1]], 'consignee refused one carton — label damaged', $cs->id);
        $this->dispatch();

        $returns = app(ReturnService::class);
        $receipt = $returns->open(['original_order_id' => $original->id, 'return_order_id' => $return->id, 'warehouse_id' => $this->warehouse()->id, 'notes' => 'driver brought it back on the same run']);
        foreach ($receipt->lines as $rl) {
            $returns->receiveLine($rl, $rl->original_order_line_id === $line->id ? 1 : 0, 'good');
        }
        $returns->completeReceiving($receipt->fresh());
        foreach ($receipt->fresh()->lines as $rl) {
            $returns->inspectLine($rl, 'available', $supervisor->id);
        }
        $returns->completeInspection($receipt->fresh(), $supervisor->id);
        $this->dispatch();
        app(ReturnRequestService::class)->recordFinancialDecision($return->fresh(), 'no_credit', 'goods resaleable, freight already charged', $finance->id);
        $this->out['return_order_no'] = $return->order_no;
        $this->out['return_receipt_no'] = $receipt->receipt_no;
    }

    // ------------------------------------------------------- other clients

    private function monthlyClient(): void
    {
        $client = $this->client('MONTHLY');
        $cs = $this->user('customer_service');
        $operator = $this->user('warehouse_operator');
        $dispatcher = $this->user('dispatcher');
        auth()->login($operator);
        $shipments = [];
        foreach ([['MTH-A', 'Office chairs', 6, 180.0], ['MTH-B', 'Desk lamps', 5, 60.0]] as $i => [$mark, $desc, $cartons, $weight]) {
            [$asn, $line, $unit] = $this->stockLooseTruck($client, $mark, $desc, $cartons, $weight, $i);
            $order = app(OrderCreationService::class)->create([
                'client_id' => $client->id, 'job_id' => $asn->job_id, 'order_type' => 'from_stock', 'external_ref' => 'PO-'.$mark, 'consignment_mark' => $mark,
                'deliver_to_name' => 'Monthly Demo store '.($i + 1), 'deliver_to_address' => (10 + $i).' Retail Rd', 'deliver_to_suburb' => 'Geelong', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3220',
                'deliver_to_address_type' => 'business', 'requested_date' => today()->addDays(2)->toDateString(), 'service_level' => 'standard',
                'lines' => [['description_en' => $desc, 'package_type' => 'carton', 'carton_qty' => $cartons - 1, 'asn_line_id' => $line->id, 'actual_weight_kg' => $weight]],
            ], $cs->id, 'manual');
            app(OrderStatusService::class)->transitionOperational($order, 'confirmed', $cs->id);
            $this->dispatch();
            $outbound = app(OutboundService::class);
            $wave = $outbound->releaseWave($this->warehouse()->id, ['order_ids' => [$order->id]], $operator->id);
            $task = $wave['tasks']->first();
            foreach ($task->lines as $l) {
                $outbound->confirmPick($l, $l->required_qty, $operator->id);
            }
            $outbound->pack($task->fulfilment_id, [['package_type' => 'pallet', 'weight_kg' => $weight, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1400]], $operator->id);
            $this->dispatch();
            $shipment = Shipment::query()->where('order_id', $order->id)->whereNotNull('fulfilment_id')->orderByDesc('id')->firstOrFail();
            $quote = TransportQuote::query()->where('shipment_id', $shipment->id)->where('quote_stage', 'final')->where('status', 'quoted')->orderBy('customer_price_cents')->first();
            if ($quote === null) { // no client card → own fleet has no rate; no gateway → Manual Transport Exception, then the coordinator's manual quote
                $manualService = CarrierService::query()->where('source', 'manual')->where('active', true)->firstOrFail();
                $quote = app(ManualQuoteService::class)->record($shipment->refresh(), $manualService, 'final', 16000, 21000, 3);
            }
            app(QuoteSelectionService::class)->select($shipment->refresh(), $quote, 'coordinator', $dispatcher->id);
            app(ShipmentBookingService::class)->book($shipment->refresh(), $quote->source === 'manual' ? 'MANUAL-'.$mark : null, $quote->source === 'manual' ? 'TRK-'.$mark : null, today()->addDays(2)->toDateString());
            $outbound->dispatch($task->fulfilment_id, 1, 'carrier', $shipment->id, $operator->id);
            $this->dispatch();
            $shipments[] = $shipment->shipment_no;
        }
        auth()->logout();
        $this->out['monthly_shipments'] = $shipments;
    }

    private function prepaidClient(): void
    {
        $client = $this->client('PREPAID');
        $cs = $this->user('customer_service');
        $finance = $this->user('finance');
        auth()->login($this->user('warehouse_operator'));
        [$asn, $line] = $this->stockLooseTruck($client, 'PRE-1', 'Solar panels', 8, 420.0, 2);
        auth()->logout();
        $order = app(OrderCreationService::class)->create([
            'client_id' => $client->id, 'job_id' => $asn->job_id, 'order_type' => 'from_stock', 'external_ref' => 'PO-PRE-1', 'consignment_mark' => 'PRE-1',
            'deliver_to_name' => 'Prepaid Demo site', 'deliver_to_address' => '88 Solar Way', 'deliver_to_suburb' => 'Ballarat', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3350',
            'deliver_to_address_type' => 'business', 'requested_date' => today()->addDays(3)->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_en' => 'Solar panels', 'package_type' => 'carton', 'carton_qty' => 4, 'asn_line_id' => $line->id, 'actual_weight_kg' => 420.0]],
        ], $cs->id, 'manual');
        app(OrderStatusService::class)->transitionOperational($order, 'confirmed', $cs->id);
        $this->dispatch();
        app(OrderHoldService::class)->place($order->fresh(), 'financial', 'prepaid account — no funds received yet', $finance->id);
        $this->out['prepaid_order_no'] = $order->order_no;
    }

    /** @return array{0: Asn, 1: AsnLine, 2: StockUnit} */
    private function stockLooseTruck(Client $client, string $mark, string $description, int $cartons, float $weightKg, int $slot): array
    {
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $this->warehouse()->id, 'inbound_type' => 'loose_truck', 'reference' => 'TRUCK-'.$mark, 'expected_date' => today()->toDateString()]);
        [$line] = app(AsnService::class)->addLines($asn, [['consignment_mark' => $mark, 'description' => $description, 'expected_cartons' => $cartons, 'weight_kg' => $weightKg, 'deliver_to_name' => $client->name, 'deliver_to_address' => '1 Demo St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000']]);
        $tasks = app(TaskService::class);
        $unload = $tasks->create('receiving', ['job_id' => $asn->job_id, 'client_id' => $client->id, 'warehouse_id' => $this->warehouse()->id, 'source_type' => 'asn', 'source_id' => $asn->id, 'asn_id' => $asn->id]);
        $tasks->complete($unload, ['billable_qty' => 1, 'billable_uom' => 'pallet'], $asn->asn_no);
        [$unit] = app(ReceivingService::class)->receiveLine($line, ['received_cartons' => $cartons, 'units' => [['unit_type' => 'pallet', 'carton_qty' => $cartons, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1400, 'weight_kg' => $weightKg, 'pallet_source' => 'chep']]], $this->location('receiving'));
        app(PutawayService::class)->putaway($unit, $this->location('storage', 17 + $slot));

        return [$asn->fresh(), $line->fresh(), $unit->fresh()];
    }

    // ------------------------------------------------------ billing / misc

    private function snapshots(): void
    {
        $snapshots = app(SnapshotService::class);
        for ($i = 8; $i >= 0; $i--) {
            $snapshots->take(today()->subDays($i));
        }
        $this->out['storage_week'] = app(StorageBillingService::class)->billWeek(today()->subWeek());
    }

    private function invoices(): void
    {
        $invoices = app(InvoiceService::class);
        $edward = $this->client('EDWARD');
        $service = $invoices->issue($invoices->draftForJob($this->out['edward_job_id'], 'service'));
        $invoices->recordPayment($service, intdiv((int) $service->total_cents, 2), today(), 'bank_transfer', 'EFT-DEMO-'.$service->invoice_no);
        $this->out['service_invoice_no'] = $service->invoice_no;
        try {
            $this->out['storage_invoice_no'] = $invoices->issue($invoices->draftStorageWeek($edward->id, today()->subWeek()))->invoice_no;
        } catch (\InvalidArgumentException $e) {
            $this->notes[] = '… storage invoice skipped: '.$e->getMessage();
        }
        try {
            $this->out['monthly_invoice_no'] = $invoices->issue($invoices->draftMonthly($this->client('MONTHLY')->id, today()->startOfMonth(), today()->endOfMonth()))->invoice_no;
        } catch (\InvalidArgumentException $e) {
            $this->notes[] = '… monthly invoice skipped: '.$e->getMessage();
        }
    }

    private function apiToken(): void
    {
        $issued = app(OrderApiTokenService::class)->issue($this->client('EDWARD')->id, 'Edward WMS integration (demo)', $this->user('admin')->id);
        $this->out['api_token_plain'] = $issued['plain'];
        $this->notes[] = '  Edward API token (shown once, demo only): '.$issued['plain'];
    }

    private function signature(): string
    {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL+WQAAAABJRU5ErkJggg==';
    }
}
