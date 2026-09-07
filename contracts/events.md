# contracts/events.md — cross-module events (law)

Every state change another module reacts to travels as an event through the transactional outbox (ERP_PLAN §0.2 rule 4). Publishers write the event **in the same DB transaction** as the business write via `App\Support\Outbox\OutboxPublisher`; consumers are idempotent via `consumed_events`. Names, publishers, consumers and payload fields below are contract: a publisher emits **every** listed field (additive extras allowed); a consumer ignores unknown fields and never reads another module's tables to fill gaps. Money is integer cents; dims mm; weights kg; timestamps ISO-8601 with offset (`Australia/Melbourne`).

## Envelope (`outbox_events` row → consumer)
| field | meaning |
|---|---|
| `event_id` | UUID, unique; the idempotency key for consumers |
| `event_name` | one of the names below |
| `event_version` | int, starts at 1; bumped only for breaking payload changes — consumers check it |
| `correlation_id` | the Job's `job_no` when known, else the originating record id (`ORD-…`, `ASN-…`); copied through every event in the chain |
| `job_id`, `client_id` | always set when the record has them (rule 1: everything hangs off a Job) |
| `occurred_at` | business time of the change |
| `payload` | the object below |

## Outbox / Inbox rules (A31 builds them in M1; M0 binds a logging no-op)
1. Publish inside the business transaction; the row starts `pending`. `php artisan outbox:dispatch` (scheduled every minute — cron `schedule:run` in production, `schedule:work` locally) delivers due `pending` / `failed` rows to their consumers and marks them `published`. Publishing outside a transaction throws (`DatabaseOutboxPublisher`).
2. Consumers record `(event_id, consumer)` in `consumed_events` inside their own transaction; a duplicate delivery is a no-op.
3. Failure → `attempts + 1`, `last_error`, `available_at` backs off (1 min, 5 min, 30 min, 2 h, 12 h); after 5 attempts → `dead`, listed in the failed-event queue with an alert (Integration Monitor).
4. Ordering is per outbox row order but **not guaranteed** across events; consumers must tolerate `stock.released` arriving before `stock.reserved` for a cancelled order (check state, don't assume sequence).
5. Payloads carry ids plus the values needed to bill or derive status — never a serialized model.
6. Until the publisher's block is merged, a consumer uses a **test event** built from the payload definition here (a factory in `tests/Feature/<Module>/`), not a Fake table.

## Publisher → consumer matrix
| event | publisher | consumers |
|---|---|---|
| `order.confirmed` | Orders (X1) | Warehouse (reserve), Transport (preliminary quote; final quote for `pickup_deliver`), Platform (Job status) |
| `order.cancelled` | Orders (X1) | Warehouse (release), Transport (cancel quotes / booking), Billing (reversals), Platform |
| `order.reduced` | Orders (X1) | Warehouse (partial release), Transport (re-quote) |
| `stock.reserved` | Warehouse (C) | Orders (→ `allocated`) |
| `stock.reservation_failed` | Warehouse (C) | Orders (OMS-7/8: warn, split fulfilment, backorder) |
| `stock.released` | Warehouse (C) | Orders (status / hold bookkeeping) |
| `asn.putaway_completed` | Warehouse (C) | Billing (putaway, inbound label, pallet purchase), Orders (A14 batch link), Platform (Job `in_stock`) |
| `task.completed` | Warehouse (C) | Billing (devanning, unload, load-out, wrap, scan, labour, waste), Orders (pick / pack progress), Platform |
| `outbound.packed` | Warehouse (C) | Transport (final quote), Orders (→ `packed`), Billing (order processing, picks, outbound labels) |
| `outbound.dispatched` | Warehouse (C) | Orders (→ `dispatched`), Transport (shipment left the warehouse), Billing (load-out is billed via `task.completed` `load`, not here) — added M4, CHANGE_REQUESTS #35 |
| `shipment.quote_confirmed` | Transport (X2) | Billing (freight + tailgate + remote — the only place these arise), Orders (quote snapshot), Platform |
| `shipment.booked` | Transport (X2) | Platform (Job cost `estimated`), Orders (tracking on the order), Billing (records `carrier_costs.expected_cost` — no charge) |
| `delivery.pod_captured` | Transport (X2) | Orders (→ `delivered`), Billing (freight settleable, cost confirmed), Platform (POD email) |
| `delivery.failed` | Transport (X2) | Orders (hold `transport`), Platform (exception `delivery_failed`) |
| `delivery.extra_charge` | Transport (X2) | Billing (decides whether / how much to charge) |
| `stock.daily_snapshot_taken` | Warehouse (C) | Billing (audit trail; weekly roll-up input) |
| `snapshot.weekly` | Warehouse (C) | Billing (storage, pallet rental, pickface — once per unit per week) |
| `return.requested` | Orders (X1) | Transport (return shipment), Warehouse (expected return receipt), Billing |
| `return.received` | Warehouse (C) | Orders (return progress) |
| `return.inspected` | Warehouse (C) | Orders (→ `returned`), Billing (awaits financial decision) |
| `return.financial_decision` | Orders (X1) | Billing (credit note or none — the only trigger) — see `CHANGE_REQUESTS.md` #11 |

## Payloads

### `order.confirmed` — §3.4, §4.3, §5.3
```
order_id, order_no, order_type, client_id, job_id, source, service_level, requested_date,
tailgate_required (bool), tailgate_reason (nullable),
deliver_to: {name, phone, address, suburb, state, postcode, address_type},
pickup_address: {name, phone, address, suburb, state, postcode} | null      # pickup_deliver only
lines: [{order_line_id, asn_line_id (nullable), carton_qty, unit_qty, actual_weight_kg, length_mm, width_mm, height_mm, cbm}],
declared_packages: [{package_type, qty, weight_kg, length_mm, width_mm, height_mm}],
confirmed_by, confirmed_at
```
### `order.cancelled`
```
order_id, order_no, job_id, client_id, previous_status, cancelled_by, reason, cancelled_at
```
### `order.reduced`
```
order_id, order_no, job_id, client_id, lines: [{order_line_id, asn_line_id, old_qty, new_qty}], changed_by, reason, changed_at
```
### `stock.reserved` — §4.3 "分配"
```
order_id, job_id, client_id, fully_reserved (bool, always true for this event),
reservations: [{reservation_id, order_line_id, asn_line_id, stock_unit_id, warehouse_id, qty}]
```
### `stock.reservation_failed`
```
order_id, job_id, client_id,
shortfalls: [{order_line_id, asn_line_id, requested_qty, reserved_qty, shortfall_qty}],
reservations: [...]                                       # what was reserved, same shape as stock.reserved
```
### `stock.released`
```
order_id, order_line_id (nullable), job_id, client_id, qty, reason, reservation_ids: [int], released_at
```
### `asn.putaway_completed` — §4.3 入库; Billing rows #10 #12 #31
```
asn_id, asn_no, job_id, client_id, warehouse_id, inbound_type,
container: {container_id, container_no, size, unpack_mode, gross_weight_kg, line_count} | null,
pallet_count,                                              # → WH-PUTAWAY-PLT qty
pallets: [{stock_unit_id, pallet_source, pallet_class, length_mm, width_mm, height_mm, weight_kg, carton_qty}],   # pallet_source → pallet purchase / rental
carton_unit_count, label_count,                            # label_count → WH-LABEL-IN qty
lines: [{asn_line_id, expected_cartons, received_cartons, damaged_cartons}],
completed_by, completed_at
```
### `task.completed` — §4.2 warehouse_tasks, §4.3 VAS; Billing rows #3–#9, #11, #26, #27, #29, #30, #33, #34
```
task_id, task_no, task_type, job_id, client_id, warehouse_id,
source_type, source_id, order_id, fulfilment_id, asn_id, container_id   (nullable where n/a),
container: {size, unpack_mode, line_count, gross_weight_kg} | null,      # devanning / cartage thresholds
billable_qty, billable_uom,
hours_business, hours_after_hours,                                       # labour / vas_other (supervisor entered)
scan_count,                                                              # scanning: = scan_records rows
started_at, completed_at, completed_by
```
### `outbound.packed` — §4.3 出库, §4.6 B4; Billing rows #20–#25, #28
```
order_id, order_no, fulfilment_id, job_id, client_id, warehouse_id,
is_urgent (bool — same-day dispatch requested after clients.dispatch_cutoff_time; decided by Warehouse),
lines: [{order_line_id, stock_unit_id, unit_type, qty, unit_weight_kg}],  # unit_type pallet → WH-PICK-PLT; carton → band by unit_weight_kg
packages: [{package_id, package_type, weight_kg, length_mm, width_mm, height_mm, carton_label}],
pallet_count, carton_count, label_count,                                 # label_count → WH-LABEL-OUT qty
packed_by, packed_at
```
### `outbound.dispatched` — §4.3 rule 5 (`packed` and `dispatched` are two timestamps); added M4 (CHANGE_REQUESTS #35)
```
order_id, fulfilment_id, job_id, client_id, warehouse_id,
shipment_id (nullable — set when the handover is against a booked TMS shipment), handed_to (carrier | driver | client),
pallet_count (loaded pallets → also a `task.completed` `load` task for WH-LOAD-PLT), package_count, carton_labels: [string],
dispatched_by, dispatched_at
```
### `shipment.quote_confirmed` — §5.3, §6.4; Billing TR-* and cartage
```
shipment_id, shipment_no, shipment_type, job_id, client_id, order_id, fulfilment_id,
transport_quote_id, quote_stage, source, carrier_id, service_level, pricing_mode,
cost_cents, customer_price_cents, markup_percent (nullable), eta_days,
tailgate_required (bool), zone,                                          # → TR-TAILGATE, TR-REMOTE conditions
packages: {count, total_weight_kg, total_cbm},
confirmed_by_type (client | coordinator | system), confirmed_by, confirmed_at
```
### `shipment.booked` — no charge
```
shipment_id, shipment_no, job_id, client_id, order_id, carrier_id, source, service_level,
booking_ref, tracking_number, waybill_document_id (nullable), expected_cost_cents, delivery_run_id (nullable), booked_at
```
### `delivery.pod_captured`
```
shipment_id, shipment_no, job_id, client_id, order_id, fulfilment_id, delivered_at, recipient_name,
pod_document_id, photo_document_ids: [int], captured_by_type (driver | carrier_api), captured_by (nullable)
```
### `delivery.failed`
```
shipment_id, shipment_no, job_id, client_id, order_id, failed_at, failure_reason, attempt_no, reported_by_type (driver | carrier_api)
```
### `delivery.extra_charge` — §5.6 B8, §6.4
```
shipment_id, shipment_no, job_id, client_id, order_id,
charge_type (waiting | redelivery | failed | other),     # → TR-WAITING / TR-REDELIVERY / TR-FAILED
qty, uom (delivery | man_hour), cost_cents (nullable), note, reported_by, occurred_at
```
### `stock.daily_snapshot_taken` — §4.2 stock_snapshots
```
snapshot_date, timezone, warehouse_id, unit_count, pallet_count, carton_unit_count, pickface_slot_count,
by_client: [{client_id, pallets, cartons, cbm, pickface_slots}]
```
### `snapshot.weekly` — §6.4, §6.7 A6b; Billing rows #13–#19 and WH-STORAGE-CTN/CBM/QUARANTINE
```
period_start, period_end, timezone, warehouse_id,
units: [{stock_unit_id, client_id, job_id, asn_line_id, unit_type, pallet_class, pallet_source, condition, location_type,
         billable_qty, billable_cbm, days_present}],                 # one row per billable unit that appeared in any daily snapshot of the week
pickfaces: [{location_id, client_id, job_id (nullable), slot_count}]
```
Billing charges each unit **once per week**: idempotency key `stock_unit_id + period_start + charge_code` (or `location_id + period_start + code` for pickface).
### `return.requested` — §3.4 OMS-9
```
return_order_id, return_order_no, original_order_id, original_shipment_id (nullable), job_id, client_id, reason,
lines: [{original_order_line_id, asn_line_id, qty}], pickup_address: {...} | null, requested_by, requested_at
```
### `return.received` — only after `return_receipts` inspection starts (§4.3 rule 7)
```
return_receipt_id, return_order_id, original_order_id (additive, M4), job_id, client_id, warehouse_id, received_at,
lines: [{return_receipt_line_id, original_order_line_id, asn_line_id, received_qty}]
```
### `return.inspected`
```
return_receipt_id, return_order_id, original_order_id (additive, M4), job_id, client_id, warehouse_id, inspected_at, inspected_by,
lines: [{return_receipt_line_id, original_order_line_id, asn_line_id, received_qty, disposition, stock_unit_id (nullable)}]
```
### `return.financial_decision` — §6.4 (the only credit trigger)
```
return_order_id, original_order_id, job_id, client_id, decision (credit | no_credit),
credit_lines: [{original_charge_id (nullable), original_order_line_id, qty, amount_cents (nullable), reason}],
decided_by, decided_at, note
```

## Billing trigger summary (`charge_rules.trigger_event`)
`asn.putaway_completed` → putaway / inbound label / pallet purchase · `task.completed` → devanning (only here), unload, load-out, wrap, scan, labour, waste · `outbound.packed` → order processing (urgent by cut-off), picks, outbound labels · `shipment.quote_confirmed` → freight, tailgate, remote, cartage (only here) · `delivery.extra_charge` → waiting / redelivery / failed · `snapshot.weekly` → storage, pallet rental, pickface · `return.financial_decision` → credit note. `shipment.booked` and `delivery.pod_captured` create **no** charge.

## Consuming events in code (shipped in M1/A31)
- Implement `App\Support\Outbox\EventConsumer` (`handle(array $envelope): void`) in your module's `Services/` (or a `Consumers/` folder inside it). Read only `$envelope['payload']` fields listed above plus the envelope keys.
- Register it in your module's `ServiceProvider::boot()`:
  `$this->app->make(\App\Support\Outbox\ConsumerRegistry::class)->register('order.confirmed', ReserveStockConsumer::class);`
- The dispatcher runs each consumer inside a DB savepoint together with its `consumed_events` row, so a consumer is applied **at most once** per event even if the process crashes half-way. Throw to have the delivery retried with the backoff above; return normally to acknowledge. Never catch-and-swallow a failure you cannot recover from.
- Publishing from inside a consumer is fine (you are already inside a transaction) and is how event chains form; keep `correlation_id` by passing the incoming envelope's value into your `DomainEvent`.
- To emit an event: extend `App\Support\Events\DomainEvent` in your module's `Events/`, build the payload exactly as listed here, and call `app(OutboxPublisher::class)->publish($event)` **inside** the `DB::transaction()` that performs the business write.
- Test helpers for every seat: `Tests\Support\Outbox\TestEvent` (any name + payload), `RecordingConsumer`, `FailingConsumer`; see `tests/Feature/Platform/OutboxTest.php` for the patterns (publish → `app(OutboxDispatcher::class)->dispatchDue()` → assert).
- Ops: `/admin/integration` (admin) lists events by status with a retry button for `failed` / `dead`; a `dead` event also raises an `integration_failed` exception linked to the Job.

## Outbound webhooks (A23, shipped M6-prep)
Every outbox event is also offered to the endpoints registered at `/admin/webhooks` (admin). Body = the envelope above as JSON (`event_id`, `event_name`, `event_version`, `correlation_id`, `job_id`, `client_id`, `occurred_at`, `payload`); headers `X-ERP-Event`, `X-ERP-Event-Id`, `X-ERP-Signature: sha256=<HMAC-SHA256(body, endpoint secret)>`. A non-2xx reply marks that endpoint's delivery `failed`; `webhooks:retry` (every five minutes) re-sends it after 1 m → 5 m → 30 m → 2 h → 12 h and marks it `dead` after five attempts — independent of the outbox, so one slow endpoint never delays the event for the others. Endpoints that already received an event are never posted again. No inbound webhooks in phase 1.
