<?php

namespace App\Modules\Platform\Services;

use App\Models\User;
use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderStatusService;
use App\Modules\Platform\Models\ExceptionRecord;
use App\Modules\Platform\Models\Job;
use App\Modules\Transport\Models\CarrierService;
use App\Modules\Transport\Models\DeliveryRun;
use App\Modules\Transport\Models\RunStop;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Services\CarrierPodService;
use App\Modules\Transport\Services\DeliveryRunService;
use App\Modules\Transport\Services\DriverPodService;
use App\Modules\Transport\Services\ManualQuoteService;
use App\Modules\Transport\Services\QuoteSelectionService;
use App\Modules\Transport\Services\ShipmentBookingService;
use App\Modules\Transport\Services\ShipmentLabelService;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\GoodsReceipt;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\Package;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Models\WarehouseTask;
use App\Modules\Warehouse\Models\Wave;
use App\Modules\Warehouse\Services\AsnOrderGeneration;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\GoodsReceiptService;
use App\Modules\Warehouse\Services\OutboundService;
use App\Modules\Warehouse\Services\PutawayService;
use App\Modules\Warehouse\Services\ReceivingService;
use App\Modules\Warehouse\Services\TaskService;
use App\Support\Exceptions\RuleViolation;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * `php artisan demo:run` (lead request 2026-09-11, CHANGE_REQUESTS #113): one compact end-to-end story on the CURRENT
 * database, built only through the modules' public services — the same calls DemoFlowSeeder makes — so it is additive,
 * safe to repeat on the trial server and never truncates or reseeds. Every identifier carries the run tag (container
 * number, consignment marks `<TAG>-M1…`, receiver names, notes) so runs never collide.
 *
 * Stages run in order; each one commits through its own services (no surrounding transaction), the outbox is drained
 * after every stage, and the first failing stage stops the run and is reported with its reason. `--until` stops early on
 * purpose so testers finish the rest by hand.
 */
final class DemoStory
{
    public const STAGES = ['asn', 'received', 'putaway', 'orders', 'confirmed', 'waved', 'picked', 'packed', 'booked', 'dispatched', 'delivered', 'invoiced'];

    /** Role accounts the story acts as (PlatformSeeder: `<role>@erp.local`). */
    public const USERS = ['customer_service', 'warehouse_supervisor', 'dispatcher', 'transport_operator', 'finance', 'admin'];

    /** description, expected cartons, line weight in kg — cycled over --lines. */
    private const GOODS = [
        ['展示架 / Display stand', 12, 180.0],
        ['LED 灯具 / LED light fittings', 8, 96.0],
        ['折叠椅 / Folding chairs', 20, 300.0],
        ['收纳箱 / Storage boxes', 6, 72.0],
        ['滚轮工具车 / Tool trolleys', 15, 225.0],
        ['户外遮阳伞 / Outdoor umbrellas', 10, 150.0],
    ];

    /** Fictitious receivers across VIC / NSW / QLD — one per consignment mark; the tag is appended to the name. */
    private const RECEIVERS = [
        ['Southbank Home Living', '12 Riverside Quay', 'Southbank', 'VIC', '3006'],
        ['Auburn Hardware Depot', '250 Parramatta Rd', 'Auburn', 'NSW', '2144'],
        ['Valley Office Supplies', '1000 Ann St', 'Fortitude Valley', 'QLD', '4006'],
        ['Chadstone Electronics', '1341 Dandenong Rd', 'Chadstone', 'VIC', '3148'],
        ['Penrith Garden Centre', '585 High St', 'Penrith', 'NSW', '2750'],
        ['Robina Furniture Outlet', '19 Robina Town Centre Dr', 'Robina', 'QLD', '4226'],
    ];

    private const PALLET_SOURCES = ['chep', 'warehouse_plain', 'client_own', 'loscam'];

    /** 1×1 transparent PNG — the driver's signature on the phone page. */
    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL+WQAAAABJRU5ErkJggg==';

    private string $tag = '';

    private string $until = 'delivered';

    private int $lines = 6;

    private int $orders = 4;

    private ?Client $client = null;

    private ?Warehouse $warehouse = null;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, mixed> */
    private array $out = [];

    /** @var list<array{ok: bool, stage: string, title: string, detail: string, url: ?string}> */
    private array $steps = [];

    private ?Closure $onStep = null;

    public function __construct(private readonly OutboxDispatcher $outbox) {}

    /**
     * Command options → validated story parameters. Refusals are InvalidArgumentException with a Chinese message.
     *
     * @param  array<string, mixed>  $options
     * @return array{client: string, warehouse: string, lines: int, orders: int, tag: string, until: string}
     */
    public static function normalise(array $options): array
    {
        $until = strtolower(trim((string) ($options['until'] ?? 'delivered')));
        if (! in_array($until, self::STAGES, true)) {
            throw new InvalidArgumentException(__('demo.errors.unknown_stage', ['stage' => $until, 'stages' => implode(' / ', self::STAGES)]));
        }
        $lines = (int) ($options['lines'] ?? 6);
        if ($lines < 2) {
            throw new InvalidArgumentException(__('demo.errors.lines', ['lines' => $lines]));
        }
        $orders = (int) ($options['orders'] ?? 4);
        if ($orders < 1 || $orders > $lines) {
            throw new InvalidArgumentException(__('demo.errors.orders', ['lines' => $lines, 'orders' => $orders]));
        }
        $tag = strtoupper(trim((string) ($options['tag'] ?? '')));
        if ($tag === '') {
            $tag = 'DEMO-'.now()->format('ymd-His');
        }
        if (! preg_match('/^[A-Z0-9-]{3,40}$/', $tag)) {
            throw new InvalidArgumentException(__('demo.errors.tag', ['tag' => $tag]));
        }

        return [
            'client' => trim((string) ($options['client'] ?? 'EDWARD')) ?: 'EDWARD',
            'warehouse' => trim((string) ($options['warehouse'] ?? 'MEL')) ?: 'MEL',
            'lines' => $lines,
            'orders' => $orders,
            'tag' => $tag,
            'until' => $until,
        ];
    }

    /**
     * @param  array{client: string, warehouse: string, lines: int, orders: int, tag: string, until: string}  $options  from normalise()
     * @param  ?Closure(array{ok: bool, stage: string, title: string, detail: string, url: ?string}): void  $onStep  called after every stage
     * @return array{ok: bool, tag: string, until: string, stopped_at: ?string, steps: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function run(array $options, ?Closure $onStep = null): array
    {
        [$this->tag, $this->until, $this->lines, $this->orders] = [$options['tag'], $options['until'], $options['lines'], $options['orders']];
        $this->onStep = $onStep;
        $this->steps = [];
        $this->out = [];
        $this->preflight($options['client'], $options['warehouse']);

        $stopped = null;
        foreach (self::STAGES as $stage) {
            if (! $this->stage($stage)) {
                $stopped = $stage;
                break;
            }
            if ($stage === $this->until) {
                break;
            }
        }

        return ['ok' => $stopped === null, 'tag' => $this->tag, 'until' => $this->until, 'stopped_at' => $stopped, 'steps' => $this->steps, 'summary' => $this->summary($stopped)];
    }

    // ------------------------------------------------------------------ plumbing

    /** Refuse (Chinese InvalidArgumentException) when the client, warehouse, role accounts or location types are missing. */
    private function preflight(string $clientCode, string $warehouseCode): void
    {
        $this->client = Client::query()->withoutGlobalScopes()->where('code', $clientCode)->first()
            ?? throw new InvalidArgumentException(__('demo.errors.client', ['code' => $clientCode]));
        $this->warehouse = Warehouse::query()->where('code', $warehouseCode)->where('active', true)->first()
            ?? throw new InvalidArgumentException(__('demo.errors.warehouse', ['code' => $warehouseCode]));

        $missing = [];
        foreach (self::USERS as $role) {
            $email = str_replace('_', '-', $role).'@erp.local';
            $user = User::query()->where('email', $email)->first();
            if ($user === null) {
                $missing[] = $email;

                continue;
            }
            $this->users[$role] = $user;
        }
        if ($missing !== []) {
            throw new InvalidArgumentException(__('demo.errors.users', ['emails' => implode(', ', $missing)]));
        }

        foreach (['receiving', 'storage', 'pickface', 'quarantine'] as $type) {
            if (! Location::query()->where('warehouse_id', $this->warehouse->id)->where('type', $type)->where('active', true)->exists()) {
                throw new InvalidArgumentException(__('demo.errors.locations', ['code' => $this->warehouse->code, 'type' => $this->label('warehouse.location_types', $type)]));
            }
        }

        $this->out['client'] = ['code' => $this->client->code, 'name' => $this->client->name, 'invoice_mode' => $this->client->invoice_mode];
        $this->out['warehouse'] = $this->warehouse->code;
    }

    /** Run one stage best-effort: record ✓ with its result or ✗ with the reason; the caller stops after a ✗. */
    private function stage(string $stage): bool
    {
        $title = __('demo.stages.'.$stage);
        try {
            $result = $this->runStage($stage);
            $this->dispatch();
            $step = ['ok' => true, 'stage' => $stage, 'title' => $title, 'detail' => $result['detail'], 'url' => $result['url'] ?? null];
        } catch (Throwable $e) {
            $step = ['ok' => false, 'stage' => $stage, 'title' => $title, 'detail' => RuleViolation::display($e), 'url' => null];
        } finally {
            auth()->logout();
        }
        $this->steps[] = $step;
        if ($this->onStep !== null) {
            ($this->onStep)($step);
        }

        return $step['ok'];
    }

    /** @return array{detail: string, url?: ?string} */
    private function runStage(string $stage): array
    {
        return match ($stage) {
            'asn' => $this->stageAsn(),
            'received' => $this->stageReceived(),
            'putaway' => $this->stagePutaway($this->until === 'putaway'),
            'orders' => $this->stageOrders(),
            'confirmed' => $this->stageConfirmed(),
            'waved' => $this->stageWaved(),
            'picked' => $this->stagePicked(),
            'packed' => $this->stagePacked(),
            'booked' => $this->stageBooked(),
            'dispatched' => $this->stageDispatched(),
            'delivered' => $this->stageDelivered(),
            'invoiced' => $this->stageInvoiced(),
        };
    }

    /** Drain the outbox the way cron would — a few rounds so consumers' follow-up events are delivered too. */
    private function dispatch(int $rounds = 4): void
    {
        for ($i = 0; $i < $rounds; $i++) {
            $this->outbox->dispatchDue();
        }
    }

    private function user(string $role): User
    {
        return $this->users[$role];
    }

    private function location(string $type, int $i = 0): Location
    {
        $list = Location::query()->where('warehouse_id', $this->warehouse->id)->where('type', $type)->where('active', true)->orderBy('full_code')->get();

        return $list[$i % max(1, $list->count())];
    }

    private function asn(): Asn
    {
        return Asn::query()->withoutGlobalScopes()->findOrFail($this->out['asn']['id']);
    }

    /** @return Collection<int, Order> */
    private function orders(): Collection
    {
        return Order::query()->withoutGlobalScopes()->whereIn('id', array_column($this->out['orders'] ?? [], 'id'))->orderBy('id')->get();
    }

    /** @return Collection<int, WarehouseTask> */
    private function pickTasks(): Collection
    {
        return WarehouseTask::query()->withoutGlobalScopes()->whereIn('id', array_column($this->out['tasks'] ?? [], 'task_id'))->orderBy('id')->get();
    }

    private function mark(int $k): string
    {
        return $this->tag.'-M'.$k;
    }

    /** containers.container_no is 20 chars: the tag itself when it fits, otherwise a container-style number derived from it. */
    private function containerNo(): string
    {
        return strlen($this->tag) <= 20 ? $this->tag : 'DEMU'.str_pad((string) (crc32($this->tag) % 10_000_000), 7, '0', STR_PAD_LEFT);
    }

    private function label(string $key, ?string $value): string
    {
        return $value !== null && Lang::has($key.'.'.$value) ? (string) __($key.'.'.$value) : (string) $value;
    }

    private function money(int $cents): string
    {
        return 'AUD '.number_format($cents / 100, 2);
    }

    private function finalShipment(int $orderId): ?Shipment
    {
        return Shipment::query()->where('order_id', $orderId)->where('shipment_type', 'outbound')->whereNotNull('fulfilment_id')->orderByDesc('id')->first();
    }

    private function finalQuote(Shipment $shipment, string $source): ?TransportQuote
    {
        return TransportQuote::query()->where('shipment_id', $shipment->id)->where('quote_stage', 'final')->where('status', 'quoted')->where('source', $source)->orderBy('customer_price_cents')->first();
    }

    // ------------------------------------------------------------------- stages

    /** @return array{detail: string, url: ?string} */
    private function stageAsn(): array
    {
        auth()->login($this->user('customer_service'));
        $containerNo = $this->containerNo();
        $gross = 3800.0; // tare + dunnage
        $lines = [];
        for ($i = 0; $i < $this->lines; $i++) {
            $k = $i % $this->orders; // line i belongs to mark k → exactly --orders orders, extra lines join the first marks
            [$description, $cartons, $weight] = self::GOODS[$i % count(self::GOODS)];
            [$name, $address, $suburb, $state, $postcode] = self::RECEIVERS[$k % count(self::RECEIVERS)];
            $gross += $weight;
            $lines[] = [
                'container_no' => $containerNo, 'consignment_mark' => $this->mark($k + 1), 'description' => $description, 'package_type' => 'carton',
                'expected_cartons' => $cartons, 'weight_kg' => $weight, 'length_mm' => 600, 'width_mm' => 400, 'height_mm' => 400, 'cbm' => round(0.096 * $cartons, 3),
                'deliver_to_name' => $name.' ('.$this->tag.')', 'deliver_to_phone' => '03 9000 0000', 'deliver_to_address' => $address,
                'deliver_to_suburb' => $suburb, 'deliver_to_state' => $state, 'deliver_to_postcode' => $postcode,
            ];
        }

        $asns = app(AsnService::class);
        $asn = $asns->create([
            'client_id' => $this->client->id, 'warehouse_id' => $this->warehouse->id, 'inbound_type' => 'container', 'reference' => $this->tag,
            'expected_date' => today()->toDateString(), 'notes' => __('demo.notes.asn', ['tag' => $this->tag]),
            'containers' => [['container_no' => $containerNo, 'size' => '40', 'unpack_mode' => 'loose', 'gross_weight_kg' => round($gross)]],
        ]);
        $asns->addLines($asn, $lines);
        $asns->markArrived($asn);
        $asn->refresh();

        $this->out['job'] = ['id' => $asn->job_id, 'job_no' => Job::query()->withoutGlobalScopes()->whereKey($asn->job_id)->value('job_no'), 'url' => route('platform.jobs.show', ['job' => $asn->job_id])];
        $this->out['asn'] = ['id' => $asn->id, 'asn_no' => $asn->asn_no, 'container_no' => $containerNo, 'lines' => $this->lines, 'marks' => $this->orders, 'url' => route('warehouse.asns.show', ['asn' => $asn->id])];

        return ['detail' => __('demo.steps.asn', ['asn' => $asn->asn_no, 'container' => $containerNo, 'lines' => $this->lines, 'marks' => $this->orders]), 'url' => $this->out['asn']['url']];
    }

    /** @return array{detail: string, url: ?string} */
    private function stageReceived(): array
    {
        $supervisor = $this->user('warehouse_supervisor');
        auth()->login($supervisor);
        $asn = $this->asn();
        $container = $asn->containers()->firstOrFail();

        // The container is opened first (拆柜 → WH-DEVAN-40-LOOSE), then every line is counted into the open 入库单 batch.
        $tasks = app(TaskService::class);
        $devan = $tasks->create('devanning', ['job_id' => $asn->job_id, 'client_id' => $asn->client_id, 'warehouse_id' => $asn->warehouse_id, 'source_type' => 'container', 'source_id' => $container->id, 'asn_id' => $asn->id, 'container_id' => $container->id]);
        $tasks->complete($devan, ['billable_qty' => 1, 'billable_uom' => 'container'], $asn->asn_no);

        $receiving = app(ReceivingService::class);
        $lines = $asn->lines()->orderBy('id')->get();
        $shortIndex = 1 % $lines->count();   // §4.7 #1 / #8: one line short shipped, reason recorded → discrepancy exception
        $damagedIndex = 2 % $lines->count(); // one crushed carton → quarantine, still counted as received
        $received = 0;
        $pallets = 0;
        foreach ($lines as $i => $line) {
            $qty = (int) $line->expected_cartons;
            $data = ['received_cartons' => $qty, 'units' => []];
            if ($i === $shortIndex) {
                $qty--;
                $data['received_cartons'] = $qty;
                $data['variance_reason'] = __('demo.reasons.short');
            }
            if ($i === $damagedIndex) {
                $qty--;
                $data['received_cartons'] = $qty;
                $data['damaged_cartons'] = 1;
                $data['variance_reason'] = __('demo.reasons.damaged');
            }
            if ($qty >= 4) {
                $data['units'][] = ['unit_type' => 'pallet', 'carton_qty' => $qty, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1400, 'weight_kg' => max(50.0, (float) $line->weight_kg), 'pallet_source' => self::PALLET_SOURCES[$pallets % 4]];
                $pallets++;
            } elseif ($qty > 0) {
                $data['units'][] = ['unit_type' => 'carton', 'carton_qty' => $qty];
            }
            $receiving->receiveLine($line, $data, $this->location('receiving'), $supervisor->id);
            $received += $qty;
        }

        // Tester feedback round 3 item 2: the batch ends with 入库完成 → printable 入库单 PDF in the document centre.
        $receipt = GoodsReceipt::query()->withoutGlobalScopes()->where('asn_id', $asn->id)->where('status', 'open')->first()
            ?? throw new RuntimeException(__('demo.errors.no_receipt', ['asn' => $asn->asn_no]));
        $receipt = app(GoodsReceiptService::class)->complete($receipt, $supervisor->id, __('demo.notes.receipt', ['tag' => $this->tag]));
        if ($receipt->pdf_document_id === null) {
            throw new RuntimeException(__('demo.errors.receipt_pdf', ['no' => $receipt->receipt_no]));
        }
        $exceptions = ExceptionRecord::query()->withoutGlobalScopes()->where('job_id', $asn->job_id)->where('type', 'discrepancy')->count();

        $this->out['receipt'] = ['id' => $receipt->id, 'receipt_no' => $receipt->receipt_no, 'received_cartons' => $received, 'status' => $receipt->status, 'url' => route('warehouse.receipts.show', ['receipt' => $receipt->id])];

        return ['detail' => __('demo.steps.received', ['receipt' => $receipt->receipt_no, 'received' => $received, 'exceptions' => $exceptions]), 'url' => $this->out['receipt']['url']];
    }

    /** @return array{detail: string, url: ?string} */
    private function stagePutaway(bool $leaveLast): array
    {
        auth()->login($this->user('warehouse_supervisor'));
        $asn = $this->asn();
        $units = StockUnit::query()->withoutGlobalScopes()->whereIn('asn_line_id', $asn->lines()->select('id'))->where('putaway_completed', false)->orderBy('id')->get()
            ->sortBy(fn (StockUnit $u) => ($u->condition === 'good' ? '1' : '0').'-'.str_pad((string) $u->id, 12, '0', STR_PAD_LEFT))->values();
        $pending = $leaveLast ? $units->last() : null; // --until=putaway: the last good unit stays in receiving for the tester
        $putaway = app(PutawayService::class);
        $offset = crc32($this->tag) % 40; // spread runs over different bins
        $counts = ['pallets' => 0, 'cartons' => 0, 'quarantine' => 0];

        foreach ($units as $unit) {
            if ($pending !== null && $unit->is($pending)) {
                continue;
            }
            if ($unit->condition !== 'good') {
                $target = $this->location('quarantine');
                $counts['quarantine']++;
            } elseif ($unit->unit_type === 'pallet') {
                $target = $this->location('storage', $offset + $counts['pallets']);
                $counts['pallets']++;
            } else {
                $target = $this->location('pickface', $counts['cartons']);
                $counts['cartons']++;
            }
            $putaway->putaway($unit, $target);
        }

        if ($pending !== null) {
            $this->out['pending_unit'] = $pending->label_code;

            return ['detail' => __('demo.steps.putaway_partial', ['done' => $units->count() - 1, 'units' => $units->count(), 'label' => $pending->label_code]), 'url' => $this->out['asn']['url']];
        }

        $asn->refresh();
        if ($asn->status !== 'putaway') {
            throw new RuntimeException(__('demo.errors.asn_not_putaway', ['asn' => $asn->asn_no, 'status' => $this->label('warehouse.asn_statuses', $asn->status)]));
        }

        return ['detail' => __('demo.steps.putaway', ['units' => $units->count()] + $counts), 'url' => $this->out['asn']['url']];
    }

    /** @return array{detail: string, url: ?string} */
    private function stageOrders(): array
    {
        auth()->login($this->user('customer_service'));
        $result = app(AsnOrderGeneration::class)->generate($this->asn()); // 从预报单生成派送订单 — grouped by consignment mark
        $ids = array_map(fn (array $o) => (int) $o['order_id'], $result['orders']);
        $orders = Order::query()->withoutGlobalScopes()->whereIn('id', $ids)->orderBy('id')->get();
        $this->out['orders'] = $orders->map(fn (Order $o) => [
            'id' => $o->id, 'order_no' => $o->order_no, 'mark' => $o->consignment_mark, 'deliver_to' => trim($o->deliver_to_suburb.' '.$o->deliver_to_state),
            'url' => route('orders.show', ['order' => $o->id]),
        ])->values()->all();
        if ($orders->count() !== $this->orders) {
            throw new RuntimeException(__('demo.errors.orders_count', ['got' => $orders->count(), 'want' => $this->orders, 'blocked' => count($result['blocked'])]));
        }

        return ['detail' => __('demo.steps.orders', ['count' => $orders->count(), 'orders' => $orders->map(fn (Order $o) => $o->order_no.'('.$o->consignment_mark.')')->implode('、')]), 'url' => route('orders.index')];
    }

    /** @return array{detail: string, url: ?string} */
    private function stageConfirmed(): array
    {
        $cs = $this->user('customer_service');
        auth()->login($cs);
        $statuses = app(OrderStatusService::class);
        foreach ($this->orders() as $order) {
            $statuses->transitionOperational($order, 'confirmed', $cs->id, __('demo.notes.confirmed', ['tag' => $this->tag]));
        }
        $this->dispatch(); // order.confirmed → Warehouse reserves stock (immediate dispatch) → stock.reserved → OMS fulfilments
        foreach ($this->orders() as $order) {
            if (! $order->fulfilments()->exists()) {
                throw new RuntimeException(__('demo.errors.no_fulfilment', ['order' => $order->order_no]));
            }
        }

        return ['detail' => __('demo.steps.confirmed', ['count' => $this->orders]), 'url' => route('orders.index')];
    }

    /** @return array{detail: string, url: ?string} */
    private function stageWaved(): array
    {
        $supervisor = $this->user('warehouse_supervisor');
        auth()->login($supervisor);
        $wave = app(OutboundService::class)->releaseWave($this->warehouse->id, ['order_ids' => array_column($this->out['orders'], 'id')], $supervisor->id);
        $this->out['wave'] = ['id' => $wave['wave']->id, 'wave_no' => $wave['wave']->wave_no, 'url' => route('warehouse.outbound.index')];
        $this->out['tasks'] = $wave['tasks']->mapWithKeys(fn (WarehouseTask $t) => [$t->order_id => ['task_id' => $t->id, 'fulfilment_id' => $t->fulfilment_id]])->all();
        foreach ($this->out['orders'] as $order) {
            if (! isset($this->out['tasks'][$order['id']])) {
                throw new RuntimeException(__('demo.errors.no_task', ['order' => $order['order_no']]));
            }
        }

        return ['detail' => __('demo.steps.waved', ['wave' => $wave['wave']->wave_no, 'tasks' => $wave['tasks']->count()]), 'url' => $this->out['wave']['url']];
    }

    /** @return array{detail: string, url: ?string} */
    private function stagePicked(): array
    {
        $supervisor = $this->user('warehouse_supervisor');
        auth()->login($supervisor);
        $outbound = app(OutboundService::class);
        $first = $this->out['orders'][0];
        $shorted = false;
        foreach ($this->pickTasks() as $task) {
            foreach ($task->lines()->orderBy('id')->get() as $line) {
                $qty = (int) $line->required_qty;
                if (! $shorted && $task->order_id === $first['id'] && $qty > 1) { // §4.7 #10: one carton short on the first order → Pick Short
                    $qty--;
                    $shorted = true;
                }
                $outbound->confirmPick($line, $qty, $supervisor->id);
            }
        }
        $exception = ExceptionRecord::query()->withoutGlobalScopes()->where('type', 'pick_short')->where('order_id', $first['id'])->latest('id')->first();

        return ['detail' => __('demo.steps.picked', ['tasks' => count($this->out['tasks']), 'order' => $first['order_no'], 'exception' => $exception !== null ? ' #'.$exception->id : '']), 'url' => $this->out['wave']['url']];
    }

    /** @return array{detail: string, url: ?string} */
    private function stagePacked(): array
    {
        $supervisor = $this->user('warehouse_supervisor');
        auth()->login($supervisor);
        $outbound = app(OutboundService::class);
        $packages = 0;
        foreach ($this->pickTasks() as $task) {
            $task->load('lines.stockUnit.asnLine');
            $specs = [];
            foreach ($task->lines as $line) {
                if ((int) $line->completed_qty < 1) {
                    continue;
                }
                $unit = $line->stockUnit;
                if ($unit?->unit_type === 'pallet') {
                    $specs[] = ['package_type' => 'pallet', 'weight_kg' => round((float) ($unit->weight_kg ?? 50.0), 2), 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => (int) ($unit->height_mm ?? 1400)];
                } else {
                    $perCarton = $unit?->asnLine?->weight_kg !== null ? (float) $unit->asnLine->weight_kg / max(1, (int) $unit->asnLine->expected_cartons) : 10.0;
                    $specs[] = ['package_type' => 'carton', 'weight_kg' => round(max(1.0, $perCarton * (int) $line->completed_qty), 2), 'length_mm' => 600, 'width_mm' => 400, 'height_mm' => 400];
                }
            }
            $outbound->pack($task->fulfilment_id, $specs, $supervisor->id);
            $packages += count($specs);
        }
        $this->dispatch(); // outbound.packed → OMS orders packed, Transport requests the final quotes
        foreach ($this->orders() as $order) {
            if ($order->operational_status !== 'packed') {
                throw new RuntimeException(__('demo.errors.not_packed', ['order' => $order->order_no, 'status' => $this->label('orders.statuses.operational', $order->operational_status)]));
            }
        }

        return ['detail' => __('demo.steps.packed', ['count' => $this->orders, 'packages' => $packages]), 'url' => $this->out['wave']['url']];
    }

    /**
     * First two orders. Leg 1 prefers the own fleet when the client has a delivery rate (driver run + signature); any
     * leg falls back to Karrio when the gateway quoted, else the coordinator's manual quote — exactly as DemoFlowSeeder.
     *
     * @return array{detail: string, url: ?string}
     */
    private function stageBooked(): array
    {
        $dispatcher = $this->user('dispatcher');
        $driver = $this->user('transport_operator');
        auth()->login($dispatcher);
        $selection = app(QuoteSelectionService::class);
        $runs = app(DeliveryRunService::class);
        $shipments = [];
        $details = [];

        foreach (array_slice($this->out['orders'], 0, 2) as $i => $order) {
            $shipment = $this->finalShipment($order['id']) ?? throw new RuntimeException(__('demo.errors.no_shipment', ['order' => $order['order_no']]));
            $quote = $i === 0 ? $this->finalQuote($shipment, 'own_fleet') : null;
            $quote ??= $this->finalQuote($shipment, 'karrio');
            if ($quote === null) {
                $service = CarrierService::query()->where('source', 'manual')->where('active', true)->orderBy('id')->first()
                    ?? throw new RuntimeException(__('demo.errors.no_manual_service'));
                $quote = app(ManualQuoteService::class)->record($shipment->refresh(), $service, 'final', 11000 + $i * 1500, 14500 + $i * 2000, 2);
            }
            $selection->select($shipment->refresh(), $quote, 'coordinator', $dispatcher->id); // final quote confirmed → freight charges, per_job invoice draft

            if ($quote->source === 'own_fleet') {
                $run = isset($this->out['run']) ? DeliveryRun::query()->findOrFail($this->out['run']['id']) : $runs->create(today()->toDateString(), $driver->id, __('demo.notes.vehicle', ['tag' => $this->tag]));
                $stop = $runs->addShipment($run->refresh(), $shipment->refresh(), today()->setTime(10 + 3 * $i, 30)->toDateTimeString()); // booking happens here (own fleet)
                $this->out['run'] = ['id' => $run->id, 'run_no' => $run->run_no, 'url' => route('transport.runs.show', ['deliveryRun' => $run->id])];
                $this->out['stops'][$order['id']] = $stop->id;
                $ref = $run->run_no;
            } else {
                $manual = $quote->source === 'manual';
                app(ShipmentBookingService::class)->book($shipment->refresh(), $manual ? 'MANUAL-'.$shipment->shipment_no : null, $manual ? 'TRK-'.$shipment->id : null, today()->addDay()->toDateString());
                $ref = $shipment->refresh()->booking_ref;
            }

            $shipment = $shipment->fresh()->load('selectedQuote');
            $label = app(ShipmentLabelService::class)->available($shipment);
            $shipments[] = [
                'order_id' => $order['id'], 'order_no' => $order['order_no'], 'id' => $shipment->id, 'shipment_no' => $shipment->shipment_no, 'source' => $quote->source,
                'status' => $shipment->status, 'booking_ref' => $shipment->booking_ref, 'tracking_number' => $shipment->tracking_number, 'label' => $label,
                'url' => route('transport.shipments.show', ['shipment' => $shipment->id]),
            ];
            $details[] = __('demo.steps.booked_leg', [
                'shipment' => $shipment->shipment_no, 'source' => $this->label('demo.sources', $quote->source), 'status' => $this->label('transport.statuses', $shipment->status),
                'ref' => $ref ? ' · '.$ref : '', 'label' => __('demo.label.'.($label ? 'yes' : 'no')),
            ]);
        }
        $this->out['shipments'] = $shipments;

        return ['detail' => implode(';', $details), 'url' => $shipments[0]['url']];
    }

    /** @return array{detail: string, url: ?string} */
    private function stageDispatched(): array
    {
        $supervisor = $this->user('warehouse_supervisor');
        auth()->login($supervisor);
        $leg = $this->out['shipments'][0];
        $fulfilmentId = (int) $this->out['tasks'][$leg['order_id']]['fulfilment_id'];
        $pallets = Package::query()->withoutGlobalScopes()->where('fulfilment_id', $fulfilmentId)->where('package_type', 'pallet')->count();
        $packages = Package::query()->withoutGlobalScopes()->where('fulfilment_id', $fulfilmentId)->count();
        $handedTo = $leg['source'] === 'own_fleet' ? 'driver' : 'carrier';
        app(OutboundService::class)->dispatch($fulfilmentId, $pallets, $handedTo, $leg['id'], $supervisor->id);
        $this->dispatch(); // outbound.dispatched → shipment dispatched, order dispatched
        $shipment = Shipment::query()->findOrFail($leg['id']);
        $this->out['shipments'][0]['status'] = $shipment->status;

        return ['detail' => __('demo.steps.dispatched', [
            'order' => $leg['order_no'], 'who' => __('demo.handed_to.'.$handedTo), 'pallets' => $pallets, 'packages' => $packages,
            'shipment' => $shipment->shipment_no, 'status' => $this->label('transport.statuses', $shipment->status),
        ]), 'url' => $leg['url']];
    }

    /** @return array{detail: string, url: ?string} */
    private function stageDelivered(): array
    {
        $leg = $this->out['shipments'][0];
        $shipment = Shipment::query()->findOrFail($leg['id']);
        $recipient = __('demo.notes.recipient', ['tag' => $this->tag]);
        if ($leg['source'] === 'own_fleet') {
            $driver = $this->user('transport_operator');
            auth()->login($driver);
            $stop = RunStop::query()->findOrFail($this->out['stops'][$leg['order_id']]);
            app(DriverPodService::class)->deliver($stop, $driver, $recipient, self::SIGNATURE, []); // the driver signs on the phone page
            $key = 'delivered_driver';
        } else {
            $dispatcher = $this->user('dispatcher');
            auth()->login($dispatcher);
            $pdf = tempnam(sys_get_temp_dir(), 'pod').'.pdf';
            file_put_contents($pdf, "%PDF-1.4\n% demo carrier POD ".$shipment->shipment_no.' '.$this->tag."\n");
            app(CarrierPodService::class)->capture($shipment, $dispatcher, $recipient, new UploadedFile($pdf, 'carrier-pod.pdf', 'application/pdf', null, true));
            $key = 'delivered_carrier';
        }
        $this->dispatch(); // delivery.pod_captured → order delivered, Job actual cost
        $this->out['shipments'][0]['status'] = $shipment->fresh()->status;

        return ['detail' => __('demo.steps.'.$key, ['order' => $leg['order_no'], 'driver' => $this->user('transport_operator')->name, 'shipment' => $shipment->shipment_no]), 'url' => $leg['url']];
    }

    /** @return array{detail: string, url: ?string} */
    private function stageInvoiced(): array
    {
        auth()->login($this->user('finance'));
        $invoices = app(InvoiceService::class);
        $jobId = (int) $this->out['job']['id'];
        // per_job clients already have the draft from the final quote confirmation (PerJobInvoiceConsumer); otherwise draft it now.
        $draft = Invoice::query()->withoutGlobalScopes()->where('status', 'draft')->whereHas('lines', fn ($q) => $q->where('job_id', $jobId))->latest('id')->first()
            ?? $invoices->draftForJob($jobId, 'service');
        $invoice = $invoices->issue($draft);
        $this->dispatch();
        $unbilled = Charge::query()->withoutGlobalScopes()->where('job_id', $jobId)->whereIn('status', ['pending', 'approved'])->whereNull('invoice_line_id')->count();

        $this->out['invoice'] = ['id' => $invoice->id, 'invoice_no' => $invoice->invoice_no, 'status' => $invoice->status, 'total_cents' => (int) $invoice->total_cents, 'url' => route('billing.invoices.show', ['invoice' => $invoice->id])];

        return ['detail' => __('demo.steps.invoiced', ['invoice' => $invoice->invoice_no, 'total' => $this->money((int) $invoice->total_cents), 'gst' => $this->money((int) $invoice->gst_cents), 'unbilled' => $unbilled]), 'url' => $this->out['invoice']['url']];
    }

    // ------------------------------------------------------------------ summary

    /** @return array<string, mixed> */
    private function summary(?string $stopped): array
    {
        $asn = isset($this->out['asn']) ? Asn::query()->withoutGlobalScopes()->find($this->out['asn']['id']) : null;
        $orders = [];
        foreach ($this->out['orders'] ?? [] as $o) {
            $status = Order::query()->withoutGlobalScopes()->whereKey($o['id'])->value('operational_status');
            $orders[] = $o + ['status' => $status, 'status_label' => $this->label('orders.statuses.operational', $status)];
        }
        $shipments = [];
        foreach ($this->out['shipments'] ?? [] as $s) {
            $status = Shipment::query()->whereKey($s['id'])->value('status');
            $shipments[] = array_replace($s, ['status' => $status, 'status_label' => $this->label('transport.statuses', $status), 'source_label' => $this->label('demo.sources', $s['source'])]);
        }
        $wave = null;
        if (isset($this->out['wave'])) {
            $status = Wave::query()->whereKey($this->out['wave']['id'])->value('status');
            $wave = $this->out['wave'] + ['status' => $status, 'status_label' => $this->label('warehouse.wave_statuses', $status)];
        }

        return [
            'tag' => $this->tag,
            'client' => $this->out['client'] ?? null,
            'warehouse' => $this->out['warehouse'] ?? null,
            'until' => $this->until,
            'stopped_at' => $stopped,
            'karrio_configured' => trim((string) config('services.karrio.api_key')) !== '',
            'job' => $this->out['job'] ?? null,
            'asn' => $asn !== null ? $this->out['asn'] + ['status' => $asn->status, 'status_label' => $this->label('warehouse.asn_statuses', $asn->status)] : null,
            'receipt' => isset($this->out['receipt']) ? $this->out['receipt'] + ['status_label' => $this->label('warehouse.receipt_statuses', $this->out['receipt']['status'])] : null,
            'orders' => $orders,
            'wave' => $wave,
            'run' => $this->out['run'] ?? null,
            'shipments' => $shipments,
            'invoice' => isset($this->out['invoice']) ? $this->out['invoice'] + ['status_label' => $this->label('billing.invoices.statuses', $this->out['invoice']['status']), 'total' => $this->money((int) $this->out['invoice']['total_cents'])] : null,
            'pending_unit' => $this->out['pending_unit'] ?? null,
            'notes' => $this->notes($stopped, $orders, $shipments),
            'next' => $this->nextPages($orders, $shipments),
        ];
    }

    /**
     * Where the story deliberately left things for the testers.
     *
     * @param  list<array<string, mixed>>  $orders
     * @param  list<array<string, mixed>>  $shipments
     * @return list<string>
     */
    private function notes(?string $stopped, array $orders, array $shipments): array
    {
        $notes = [];
        if ($stopped === null && $this->until !== 'invoiced') {
            $notes[] = __('demo.summary.stopped', ['stage' => $this->until, 'label' => __('demo.stages.'.$this->until)]);
        }
        if (isset($this->out['pending_unit'])) {
            $notes[] = __('demo.summary.unit_pending', ['label' => $this->out['pending_unit']]);
        }
        $legs = collect($shipments);
        foreach ($legs->where('status', 'booked') as $s) {
            $notes[] = __('demo.summary.order_booked', ['order' => $s['order_no'], 'shipment' => $s['shipment_no']]);
        }
        foreach ($legs->whereIn('status', ['dispatched', 'in_transit']) as $s) {
            $notes[] = __('demo.summary.order_dispatched', ['order' => $s['order_no'], 'shipment' => $s['shipment_no']]);
        }
        $covered = $legs->whereIn('status', ['booked', 'dispatched', 'in_transit', 'delivered'])->pluck('order_id')->all();
        $rest = collect($orders)->reject(fn (array $o) => in_array($o['id'], $covered, true) || $o['status'] === 'delivered');
        foreach ($rest->groupBy('status') as $status => $group) {
            $notes[] = __('demo.summary.order_stopped', ['orders' => $group->pluck('order_no')->implode('、'), 'status' => $this->label('orders.statuses.operational', (string) $status)]);
        }
        if ($legs->isNotEmpty()) {
            $sources = $legs->pluck('source')->unique();
            $configured = trim((string) config('services.karrio.api_key')) !== '';
            $key = ! $configured ? 'karrio_off' : ($sources->contains('karrio') ? 'karrio_on' : 'karrio_no_quote');
            $notes[] = __('demo.summary.'.$key, ['sources' => $sources->map(fn ($s) => $this->label('demo.sources', $s))->implode(' / ')]);
        }

        return $notes;
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     * @param  list<array<string, mixed>>  $shipments
     * @return list<array{label: string, url: string}>
     */
    private function nextPages(array $orders, array $shipments): array
    {
        $pages = [];
        if (isset($this->out['asn'])) {
            $pages[] = ['label' => __('demo.summary.pages.asn'), 'url' => $this->out['asn']['url']];
        }
        if (isset($this->out['receipt'])) {
            $pages[] = ['label' => __('demo.summary.pages.receipt'), 'url' => $this->out['receipt']['url']];
        }
        if ($orders !== []) {
            $pages[] = ['label' => __('demo.summary.pages.orders'), 'url' => route('orders.index')];
        }
        if (isset($this->out['wave'])) {
            $pages[] = ['label' => __('demo.summary.pages.outbound'), 'url' => $this->out['wave']['url']];
        }
        foreach ($shipments as $s) {
            $pages[] = ['label' => __('demo.summary.pages.shipment', ['no' => $s['shipment_no']]), 'url' => $s['url']];
        }
        if (isset($this->out['run'])) {
            $pages[] = ['label' => __('demo.summary.pages.run'), 'url' => $this->out['run']['url']];
        }
        if (isset($this->out['invoice'])) {
            $pages[] = ['label' => __('demo.summary.pages.invoice', ['no' => $this->out['invoice']['invoice_no']]), 'url' => $this->out['invoice']['url']];
        }
        if (isset($this->out['job'])) {
            $pages[] = ['label' => __('demo.summary.pages.job', ['no' => (string) $this->out['job']['job_no']]), 'url' => $this->out['job']['url']];
        }

        return $pages;
    }
}
