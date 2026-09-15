<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Events\AsnCollectionCancelled;
use App\Modules\Warehouse\Events\AsnCollectionRequested;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\AsnLine;
use App\Support\Contracts\InboundService;
use App\Support\Contracts\JobService;
use App\Support\Enums;
use App\Support\Exceptions\RuleViolation;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** B2: ASN creation (planned or unplanned), containers (basic fields), goods lines; B2b import writes lines through addLines(). */
final class AsnService implements InboundService
{
    /** Keys of a collection pickup party (asns.collection_address); `type` is business | residential. */
    private const ADDRESS_KEYS = ['name', 'phone', 'address', 'suburb', 'state', 'postcode', 'type'];

    /** Keys of one declared package row (asns.collection_packages[]). */
    private const PACKAGE_KEYS = ['package_type', 'qty', 'weight_kg', 'length_mm', 'width_mm', 'height_mm'];

    /**
     * Keys kept of the client's chosen collection plan (asns.collection_preference, CHANGE_REQUESTS #125): the portal's customer
     * snapshot (Portal PortalTransportEstimate::SNAPSHOT) plus who chose it and when — never cost or markup.
     */
    public const PREFERENCE_KEYS = ['carrier_id', 'carrier_name', 'source', 'service_level', 'customer_price_cents', 'eta_days', 'is_recommended', 'is_cheapest', 'is_fastest', 'chosen_at', 'chosen_by'];

    public function __construct(private readonly JobService $jobs, private readonly OutboxPublisher $outbox) {}

    /**
     * @param  array{client_id:int, warehouse_id:int, inbound_type:string, expected_date?:?string, job_id?:?int, job_type?:string, reference?:?string, created_by_type?:string, unplanned?:bool, notes?:?string, containers?:list<array{container_no:string, size:string, unpack_mode:string, gross_weight_kg?:?float}>}  $data
     */
    public function create(array $data): Asn
    {
        return DB::transaction(function () use ($data): Asn {
            $jobId = $data['job_id'] ?? null;
            if ($jobId === null) {
                $jobType = $data['job_type'] ?? ($data['inbound_type'] === 'container' ? 'container' : 'loose');
                $jobId = $this->jobs->create((int) $data['client_id'], $jobType, ['reference' => $data['reference'] ?? null])['job_id'];
            }

            $asn = Asn::query()->create([
                'asn_no' => DocumentNumbers::next(Asn::query()->withoutGlobalScopes(), 'asn_no', 'ASN'),
                'job_id' => $jobId,
                'client_id' => $data['client_id'],
                'warehouse_id' => $data['warehouse_id'],
                'expected_date' => $data['expected_date'] ?? null,
                'inbound_type' => $data['inbound_type'],
                'status' => 'booked',
                'created_by_type' => $data['created_by_type'] ?? 'coordinator',
                'created_by' => auth()->id(),
                'unplanned' => (bool) ($data['unplanned'] ?? false),
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['containers'] ?? [] as $container) {
                $asn->containers()->create($container + ['job_id' => $jobId]);
            }

            return $asn;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines  asn_lines columns; `container_no` may be given instead of container_id
     * @return list<AsnLine>
     */
    public function addLines(Asn $asn, array $lines): array
    {
        return DB::transaction(function () use ($asn, $lines): array {
            $containers = $asn->containers()->get()->keyBy('container_no');
            $created = [];

            foreach ($lines as $line) {
                if (isset($line['container_no'])) {
                    $line['container_id'] = $containers->get($line['container_no'])?->id;
                    unset($line['container_no']);
                }
                $created[] = $asn->lines()->create($line);
            }

            foreach ($asn->containers as $container) {
                $container->update(['line_count' => $container->lines()->count()]);
            }

            return $created;
        });
    }

    public function markArrived(Asn $asn): void
    {
        $asn->update(['status' => 'arrived', 'arrived_at' => now()]);
    }

    /**
     * 到仓方式 = 我方上门提货 (CHANGE_REQUESTS #124): the coordinator asks Transport to collect the goods at the client's pickup
     * address and bring them here. Stores the request on the ASN, bumps `collection_version` and publishes
     * `asn.collection_requested` in the same transaction; Transport opens (or re-quotes) the inbound collection shipment. A
     * re-request before booking supersedes the previous one; once Transport has booked the collection (booked / collected /
     * delivered) the request is the dispatcher's and this refuses.
     *
     * @param  array{address:array<string, mixed>, ready_date:string, packages?:list<array<string, mixed>>, notes?:?string}  $data
     */
    public function setCollection(Asn $asn, array $data, ?int $userId): Asn
    {
        return DB::transaction(function () use ($asn, $data, $userId): Asn {
            $locked = Asn::query()->withoutGlobalScopes()->with(['lines', 'warehouse'])->lockForUpdate()->findOrFail($asn->id);
            if ($locked->collectionLocked()) {
                throw new RuleViolation("ASN {$locked->asn_no}: the collection is already booked; the dispatcher manages it on the shipment.", 'warehouse.asns.collection.errors.locked', ['no' => $locked->asn_no]);
            }
            if ($locked->status !== 'booked') {
                throw new RuleViolation("ASN {$locked->asn_no} has already arrived; a collection cannot be requested.", 'warehouse.asns.collection.errors.asn_not_booked', ['no' => $locked->asn_no]);
            }

            $address = $this->collectionAddress($data['address'] ?? []);
            if (! $this->completeParty($address)) {
                throw new RuleViolation("ASN {$locked->asn_no}: the pickup address is incomplete.", 'warehouse.asns.collection.errors.address_incomplete');
            }
            $readyDate = Carbon::parse((string) ($data['ready_date'] ?? ''))->startOfDay();
            if ($readyDate->lt(today())) {
                throw new RuleViolation("ASN {$locked->asn_no}: the ready date is in the past.", 'warehouse.asns.collection.errors.ready_date_past');
            }
            $packages = $this->collectionPackages($data['packages'] ?? []);
            $lines = $this->collectionLines($locked);
            if ($packages === [] && $lines === []) {
                throw new RuleViolation("ASN {$locked->asn_no}: no packages declared and no goods line carries weight and dimensions.", 'warehouse.asns.collection.errors.no_packages');
            }
            $warehouse = $locked->warehouse;
            if ($warehouse === null || trim((string) $warehouse->address) === '' || trim((string) $warehouse->state) === '') {
                throw new RuleViolation("ASN {$locked->asn_no}: the warehouse has no address to deliver to.", 'warehouse.asns.collection.errors.warehouse_address');
            }

            $version = (int) $locked->collection_version + 1;
            $requestedAt = now();
            $locked->update([
                'inbound_transport' => 'we_collect',
                'collection_address' => $address,
                'collection_ready_date' => $readyDate->toDateString(),
                'collection_packages' => $packages,
                'collection_notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
                'collection_requested_at' => $requestedAt,
                'collection_requested_by' => $userId,
                'collection_version' => $version,
                'collection_status' => 'requested',
                'collection_plan' => null,
            ] + $this->collectionOrigin($locked, $data));

            $this->outbox->publish(new AsnCollectionRequested([
                'asn_id' => $locked->id,
                'asn_no' => $locked->asn_no,
                'client_id' => (int) $locked->client_id,
                'job_id' => (int) $locked->job_id,
                'warehouse_id' => (int) $locked->warehouse_id,
                'warehouse' => [
                    'name' => (string) $warehouse->name,
                    'phone' => null,
                    'address' => (string) $warehouse->address,
                    'suburb' => (string) ($warehouse->suburb ?? ''),
                    'state' => (string) $warehouse->state,
                    'postcode' => (string) ($warehouse->postcode ?? ''),
                    'type' => 'business',
                ],
                'collection_address' => $address,
                'ready_date' => $readyDate->toDateString(),
                'service_level' => 'standard',
                'packages' => $packages,
                'lines' => $lines,
                'notes' => $locked->collection_notes,
                'requested_by' => $userId,
                'requested_at' => $requestedAt->toIso8601String(),
                'activity_version' => $version,
                // CHANGE_REQUESTS #125: the plan the client ticked in the portal (customer fields only) and who asked — Transport confirms that option within tolerance.
                'client_preference' => $locked->collection_preference,
                'requested_via' => $locked->collection_requested_via,
            ], jobId: (int) $locked->job_id, clientId: (int) $locked->client_id, correlationId: $locked->job?->job_no ?? $locked->asn_no));

            return $locked->refresh();
        });
    }

    /**
     * InboundService (CHANGE_REQUESTS #125): 待建预报 opened this ASN for a client's portal collection request — same rules and event as
     * the ASN page, inside the caller's transaction, so a refusal rolls the whole generation back.
     */
    public function requestCollection(int $asnId, array $data, ?int $actorId): array
    {
        $asn = $this->setCollection(Asn::query()->withoutGlobalScopes()->findOrFail($asnId), $data, $actorId);

        return ['asn_id' => (int) $asn->id, 'collection_version' => (int) $asn->collection_version];
    }

    /**
     * Who asked and what the client chose (CHANGE_REQUESTS #125). `client_preference` present → whitelisted to PREFERENCE_KEYS (null
     * clears it); absent (a staff edit on the ASN page) → the stored preference stays, so a re-quote still confirms the client's
     * option within tolerance. `requested_via` / `import_id` follow the same rule; a request without any origin is staff's.
     *
     * @return array{collection_requested_via:string, collection_preference?:?array<string, mixed>, collection_import_id?:?int}
     */
    private function collectionOrigin(Asn $asn, array $data): array
    {
        $via = array_key_exists('requested_via', $data) ? $data['requested_via'] : $asn->collection_requested_via;
        $origin = ['collection_requested_via' => in_array($via, Enums::ASN_COLLECTION_REQUESTED_VIA, true) ? $via : 'staff'];
        if (array_key_exists('client_preference', $data)) {
            $kept = is_array($data['client_preference']) ? Arr::only($data['client_preference'], self::PREFERENCE_KEYS) : [];
            $origin['collection_preference'] = $kept === [] ? null : $kept;
        }
        if (array_key_exists('import_id', $data)) {
            $origin['collection_import_id'] = filled($data['import_id']) ? (int) $data['import_id'] : null;
        }

        return $origin;
    }

    /**
     * 改为客户自送: back to client_delivers. Only before Transport has booked the collection — afterwards the dispatcher cancels
     * on the shipment page. Publishes `asn.collection_cancelled` so the unbooked shipment is cancelled.
     */
    public function clearCollection(Asn $asn, ?int $userId, ?string $reason = null): Asn
    {
        return DB::transaction(function () use ($asn, $userId, $reason): Asn {
            $locked = Asn::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($asn->id);
            if (! $locked->isCollection()) {
                return $locked;
            }
            if ($locked->collectionLocked()) {
                throw new RuleViolation("ASN {$locked->asn_no}: the collection is already booked; cancel it on the shipment.", 'warehouse.asns.collection.errors.locked', ['no' => $locked->asn_no]);
            }

            $shipmentId = $locked->collection_shipment_id;
            $locked->update(['inbound_transport' => 'client_delivers', 'collection_status' => null, 'collection_shipment_id' => null, 'collection_plan' => null,
                'collection_preference' => null, 'collection_requested_via' => null, 'collection_import_id' => null]); // #125: the client's request ends with it

            $this->outbox->publish(new AsnCollectionCancelled([
                'asn_id' => $locked->id,
                'asn_no' => $locked->asn_no,
                'client_id' => (int) $locked->client_id,
                'job_id' => (int) $locked->job_id,
                'shipment_id' => $shipmentId === null ? null : (int) $shipmentId,
                'cancelled_by' => $userId,
                'reason' => $reason ?? 'client_delivers',
                'cancelled_at' => now()->toIso8601String(),
            ], jobId: (int) $locked->job_id, clientId: (int) $locked->client_id, correlationId: $locked->job?->job_no ?? $locked->asn_no));

            return $locked->refresh();
        });
    }

    /** Transport's events copied back for display (consumers): status, shipment id and — once confirmed — the plan / client price. */
    public function recordCollectionProgress(Asn $asn, string $status, ?int $shipmentId = null, ?array $plan = null): void
    {
        $update = ['collection_status' => $status];
        if ($shipmentId !== null) {
            $update['collection_shipment_id'] = $shipmentId;
        }
        if ($plan !== null) {
            $update['collection_plan'] = $plan;
        }
        $asn->update($update);
    }

    /** Same rule as Transport's ShipmentQuoteRequestFactory::completeParty (a carrier can price it): address, suburb, state, postcode, type. */
    private function completeParty(array $party): bool
    {
        foreach (['address', 'suburb', 'state', 'postcode', 'type'] as $key) {
            if (trim((string) ($party[$key] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    private function collectionAddress(array $raw): array
    {
        $address = [];
        foreach (self::ADDRESS_KEYS as $key) {
            $address[$key] = trim((string) ($raw[$key] ?? ''));
        }
        $address['type'] = $address['type'] === '' ? 'business' : $address['type'];
        $address['state'] = strtoupper($address['state']);

        return $address;
    }

    /** Declared package rows, blank rows dropped, numbers normalised. @return list<array<string, mixed>> */
    private function collectionPackages(array $raw): array
    {
        $packages = [];
        foreach ($raw as $row) {
            if (! is_array($row) || (int) ($row['qty'] ?? 0) < 1) {
                continue;
            }
            $packages[] = [
                'package_type' => (string) ($row['package_type'] ?? 'carton'),
                'qty' => (int) $row['qty'],
                'weight_kg' => round((float) ($row['weight_kg'] ?? 0), 3),
                'length_mm' => (int) ($row['length_mm'] ?? 0),
                'width_mm' => (int) ($row['width_mm'] ?? 0),
                'height_mm' => (int) ($row['height_mm'] ?? 0),
            ];
        }

        return $packages;
    }

    /**
     * The goods lines Transport can price when no packages were declared: cartons + line weight + all three dimensions
     * (weight_kg is the line total, like an order line's actual_weight_kg).
     *
     * @return list<array{asn_line_id:int, description:string, expected_cartons:int, weight_kg:float, length_mm:int, width_mm:int, height_mm:int}>
     */
    private function collectionLines(Asn $asn): array
    {
        return $asn->lines
            ->filter(fn (AsnLine $l) => (int) $l->expected_cartons > 0 && (float) $l->weight_kg > 0 && (int) $l->length_mm > 0 && (int) $l->width_mm > 0 && (int) $l->height_mm > 0)
            ->map(fn (AsnLine $l): array => [
                'asn_line_id' => $l->id,
                'description' => (string) $l->description,
                'expected_cartons' => (int) $l->expected_cartons,
                'weight_kg' => (float) $l->weight_kg,
                'length_mm' => (int) $l->length_mm,
                'width_mm' => (int) $l->width_mm,
                'height_mm' => (int) $l->height_mm,
            ])->values()->all();
    }

    /** Unplanned arrivals must be confirmed by a coordinator before putaway (ERP_PLAN §4.2 asns.unplanned). */
    public function confirmUnplanned(Asn $asn): void
    {
        $asn->update(['unplanned_confirmed' => true]);
    }

    /**
     * 编辑收件信息 (CHANGE_REQUESTS #115): the consignee fields and mark of one goods line, optionally copied to the ASN's other
     * lines under the same mark — one mark becomes one 派送订单, so OMS needs them to agree. Refused once the line sits on an
     * order (order_line_id): from then on the order carries the address and is edited there.
     *
     * @param  array<string, mixed>  $data  consignment_mark, deliver_to_*, fba_reference (other keys ignored)
     * @return int lines updated
     */
    public function updateLineDelivery(AsnLine $line, array $data, bool $applyToMark = false): int
    {
        if ($line->order_line_id !== null) {
            throw new RuleViolation("ASN line {$line->id} is already on an order; edit the address on the order.", 'warehouse.asns.errors.delivery_locked', ['id' => $line->id]);
        }
        $fields = Arr::only($data, ['consignment_mark', 'deliver_to_name', 'deliver_to_phone', 'deliver_to_address', 'deliver_to_suburb', 'deliver_to_state', 'deliver_to_postcode', 'fba_reference']);

        return DB::transaction(function () use ($line, $fields, $applyToMark): int {
            $line->update($fields);
            $mark = trim((string) $line->consignment_mark);
            if (! $applyToMark || $mark === '') {
                return 1;
            }

            return 1 + AsnLine::query()->where('asn_id', $line->asn_id)->whereKeyNot($line->id)->where('consignment_mark', $line->consignment_mark)
                ->whereNull('order_line_id')->update(Arr::except($fields, ['consignment_mark']));
        });
    }

    /**
     * 确认客户预报 (CHANGE_REQUESTS #116): customer service has checked a client-submitted ASN (container, ETA, goods lines).
     * A flag for the screens and the portal only — receiving and putaway never wait for it.
     */
    public function confirmClientSubmission(Asn $asn, ?int $userId): void
    {
        if (! $asn->isClientSubmitted()) {
            throw new RuleViolation("ASN {$asn->asn_no} was not submitted by the client.", 'warehouse.asns.errors.not_client_submitted', ['no' => $asn->asn_no]);
        }
        if ($asn->client_confirmed_at !== null) {
            throw new RuleViolation("ASN {$asn->asn_no} is already confirmed.", 'warehouse.asns.errors.already_confirmed', ['no' => $asn->asn_no]);
        }
        $asn->update(['client_confirmed_at' => now(), 'client_confirmed_by' => $userId]);
    }

    /**
     * The client re-uploads its packing list while the submission is still a draft: drop the lines nobody has touched so the new
     * list replaces them instead of doubling up. Only for a booked, unconfirmed client submission whose lines have no receipt,
     * stock unit or order behind them — otherwise the list is history and staff amend it by hand.
     *
     * @return int lines removed
     */
    public function clearDraftLines(Asn $asn): int
    {
        if (! $asn->isPendingClientConfirmation() || $asn->status !== 'booked') {
            throw new RuleViolation("ASN {$asn->asn_no} is no longer a draft submission.", 'warehouse.asns.errors.draft_lines_locked', ['no' => $asn->asn_no]);
        }

        return DB::transaction(function () use ($asn): int {
            $lines = $asn->lines()->with(['stockUnits', 'receiptLine'])->lockForUpdate()->get();
            if ($lines->contains(fn (AsnLine $l) => $l->isReceived() || $l->isOnOrder())) {
                throw new RuleViolation("ASN {$asn->asn_no} has lines that were already received or ordered.", 'warehouse.asns.errors.draft_lines_locked', ['no' => $asn->asn_no]);
            }
            $removed = $lines->isEmpty() ? 0 : $asn->lines()->whereKey($lines->modelKeys())->delete();
            foreach ($asn->containers()->get() as $container) {
                $container->update(['line_count' => 0]);
            }

            return $removed;
        });
    }

    /** Client submissions waiting for customer service — the 预报单 nav badge (all warehouses). */
    public function pendingClientConfirmationCount(): int
    {
        return Asn::query()->where('created_by_type', 'client')->whereNull('client_confirmed_at')->count();
    }

    /**
     * InboundService (CHANGE_REQUESTS #117): 从订单生成预报单 — Orders hands over the goods lines of the client's orders; the ASN is
     * created here exactly like a coordinator's (created_by_type coordinator, under the Job Orders chose) and every line keeps its
     * order_line_id, so the two documents are linked in both directions and 从预报单生成派送订单 skips these lines.
     */
    public function createAsnFromOrderLines(array $header, array $lines): array
    {
        if ($lines === []) {
            throw new RuleViolation('No goods lines to put on the ASN.', 'warehouse.asns.errors.no_lines_for_asn');
        }

        return DB::transaction(function () use ($header, $lines): array {
            $asn = $this->create(Arr::only($header, ['client_id', 'warehouse_id', 'job_id', 'inbound_type', 'expected_date', 'notes', 'reference', 'containers']));

            return $this->appendOrderLines($asn, $lines);
        });
    }

    /**
     * InboundService (CHANGE_REQUESTS #119): 从订单导入货物行 — the same hand-over onto an ASN that already exists. Only while the
     * ASN is still booked / arrived / receiving: once putaway starts the goods lines are history and the orders are matched by
     * 从预报单生成派送订单 instead. A line without a container goes onto the ASN's only container when it has exactly one; on a
     * multi-container ASN every line must name one of them (the devanning band is billed per container line_count).
     */
    public function addOrderLinesToAsn(int $asnId, array $lines): array
    {
        if ($lines === []) {
            throw new RuleViolation('No goods lines to put on the ASN.', 'warehouse.asns.errors.no_lines_for_asn');
        }

        return DB::transaction(function () use ($asnId, $lines): array {
            // The ASN row is held for the whole append: PutawayService::completeIfDone takes the same lock before it flips the
            // status, so the check below and the insert cannot straddle a putaway completion.
            $asn = Asn::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($asnId);
            if (! in_array($asn->status, ['booked', 'arrived', 'receiving'], true)) {
                throw new RuleViolation("ASN {$asn->asn_no} is past receiving; order lines cannot be imported.", 'warehouse.asns.errors.import_orders_closed', ['no' => $asn->asn_no]);
            }

            $containers = $asn->containers()->orderBy('id')->pluck('container_no')->map(fn ($no) => (string) $no);
            if ($containers->count() === 1) {
                $only = $containers->first();
                $lines = array_map(fn (array $line): array => filled($line['container_no'] ?? null) ? $line : [...$line, 'container_no' => $only], $lines);
            }
            foreach ($lines as $line) {
                $no = $line['container_no'] ?? null;
                if (($containers->count() > 1 && ! filled($no)) || (filled($no) && ! $containers->contains((string) $no))) {
                    throw new RuleViolation("ASN {$asn->asn_no}: every imported line must sit on one of its containers.", 'warehouse.asns.errors.import_orders_pick_container', ['no' => $asn->asn_no, 'containers' => $containers->isEmpty() ? '—' : $containers->implode(', ')]);
                }
            }

            return $this->appendOrderLines($asn, $lines);
        });
    }

    /**
     * Shared tail of createAsnFromOrderLines / addOrderLinesToAsn: whitelist the asn_lines columns, write the lines, report the
     * order_line → asn_line mapping the caller needs for its side of the link.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{asn_id:int, asn_no:string, lines:list<array{order_line_id:int, asn_line_id:int}>}
     */
    private function appendOrderLines(Asn $asn, array $lines): array
    {
        $created = $this->addLines($asn, array_map(fn (array $line): array => Arr::only($line, [
            'order_line_id', 'container_no', 'consignment_mark', 'description', 'expected_cartons', 'package_type', 'deliver_to_name', 'deliver_to_phone',
            'deliver_to_address', 'deliver_to_suburb', 'deliver_to_state', 'deliver_to_postcode', 'fba_reference', 'weight_kg', 'length_mm', 'width_mm', 'height_mm', 'cbm',
        ]), $lines));

        return [
            'asn_id' => $asn->id,
            'asn_no' => $asn->asn_no,
            'lines' => array_values(array_map(fn (AsnLine $l): array => ['order_line_id' => (int) $l->order_line_id, 'asn_line_id' => $l->id], $created)),
        ];
    }
}
