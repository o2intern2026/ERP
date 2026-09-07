# contracts/services.md — public Application Service signatures (law)

Cross-module **synchronous** reads and writes go only through these interfaces (ERP_PLAN §0.2 rule 3); state changes still travel through events (`events.md`). The PHP interfaces in the frozen zone `app/Support/Contracts/` are the authoritative signatures; this file gives the semantics. Real implementations live in the owner module's `Services/` and are bound in that module's ServiceProvider at the checkpoint shown; until then `App\Support\Fakes\*` (bound by `FakeServicesProvider` when `USE_FAKE_SERVICES=true`) implements the same interface.

| Interface (`App\Support\Contracts\…`) | Owner / seat | Real implementation | Fake (M0) |
|---|---|---|---|
| `StockService` | Warehouse / C | **M2 ✓** `App\Modules\Warehouse\Services\StockService` | retired in M2 (`FakeStockService` remains for unit tests) |
| `OrderService` | Orders / X1 | M3 | `FakeOrderService` — one order per ASN, `ORD-FAKE-<asn>-0001` |
| `TransportOptionService` | Transport / X2 | M5 | `FakeTransportOptionService` — own_fleet $75, transdirect / eiz at cost × 1.20 |
| `RateService` | Billing / C | M6 | `FakeRateService` — Edward card v1 for every client; `missing_rate` for codes without a row; `withRate()` for tests |
| `JobService` | Platform / C | **M1 ✓** `App\Modules\Platform\Services\JobService` | retired in M1 (`FakeJobService` remains for unit tests only) |
| `ExceptionService` | Platform / C | **M1 ✓** `App\Modules\Platform\Services\ExceptionService` | — |
| `DocumentService` | Platform / C | **M1 ✓** `attach()`; Document Centre pages M6 | — |
| `ManifestParser` | Orders / X1, shared with Warehouse B2b | M3 | `FakeManifestParser` — two fixed rows |

## Conventions
- ids are `int`; money is integer cents (`App\Support\Money` at the edges); dimensions mm; weights kg; timestamps ISO-8601 strings with offset. Phase 1 uses typed arrays (shapes in PHPDoc) rather than DTO classes.
- Business outcomes are **returned, never thrown**: shortfall, missing rate, POA, blocked groups. Throw only for programmer errors (unknown id → `ModelNotFoundException`, bad enum → `InvalidArgumentException`).
- Every method that writes runs inside its own `DB::transaction()` and publishes its events through `OutboxPublisher` in that transaction.
- Changing a signature is a contract change (`CHANGE_REQUESTS.md`). Seat C may add optional trailing parameters or additive array keys at a checkpoint; nothing is removed or renamed inside a phase.

## 1. `StockService` (Warehouse, C) — ERP_PLAN §4.3 rules 1, 2, 4, 10; §8.2
```php
onHand(int $clientId, int $asnLineId): array{qty_on_hand:int, qty_reserved:int, qty_available:int}
reserve(int $clientId, int $orderId, array $lines): array   // lines: list<{order_line_id, asn_line_id, qty}>
release(int $orderId, ?int $orderLineId = null, string $reason = 'order_cancelled'): int
```
- Quantities are cartons; a pallet unit counts the cartons it holds. Only `putaway`-completed, `condition = good` stock is on hand; `qty_available = qty_on_hand − qty_reserved`.
- `reserve` row-locks the candidate `stock_units` (FIFO by `received_at`), writes `stock_reservations`, and returns per line `{order_line_id, asn_line_id, requested_qty, reserved_qty, shortfall_qty, reservation_ids}`. Partial reservation is normal, not an error. The `order.confirmed` consumer that calls it emits `stock.reserved` when every line is fully reserved, otherwise `stock.reservation_failed` (with the shortfalls) — the consumer holds the correlation context.
- `release` marks active reservations `released`, writes `stock_ledger` (`movement_type = release`) and returns cartons released; the `order.cancelled` / `order.reduced` consumers emit `stock.released`.

## 2. `OrderService` (Orders, X1) — §3.7.2 A4, §4.6 B2c
```php
createFromAsn(int $asnId, string $groupingKey = 'mark_address_fba'): array{orders: list<{order_id, order_no, asn_line_ids}>, blocked: list<{consignment_mark, reason, asn_line_ids}>}
```
- Groups the ASN's received lines by consignment_mark + deliver_to (name, address, state, postcode) + fba_reference; one `from_stock` order per group, `source = manual` (creator is the coordinator), joined to the ASN's Job; order lines point at `asn_line_id` and quantities use received (not expected) cartons.
- Lines already generated (`asn_lines.order_line_id` set) are skipped. A group whose lines disagree on address or FBA reference is not created and is returned in `blocked`.
- Same code path as manual / Excel / portal / API creation; the only public entry point for creating orders from other modules.

## 3. `TransportOptionService` (Transport, X2) — §5.3, §5.6 B5c/B5d
```php
quote(int $shipmentId, string $stage): list<{transport_quote_id, carrier_id, source, service_level, cost_cents, customer_price_cents, eta_days, is_recommended, is_cheapest, is_fastest, quoted_at, expires_at}>
```
- `stage = preliminary` prices declared packages at order confirmation; `stage = final` prices measured packages after `outbound.packed` (pure-transport orders: declared packages are final).
- `CarrierAdapter` is the frozen interface `App\Support\Contracts\CarrierAdapter` (added with B5e, see `carriers.md`): `source()`, `capabilities()`, `quote()`, `book()`, `cancel()`, `label()`, `tracking()`. Phase 1 sources: `manual`, `own_fleet`, `transdirect`; `eiz` is No-Go.
- Asks every active `CarrierAdapter` (`own_fleet`, `transdirect`, `eiz`, `manual`), writes `transport_quotes` rows (`quote_stage`, `status = quoted`) and returns them. Customer price: own_fleet = fixed rate item; third party = `cost × (1 + markup)` where markup comes from the client's rate item (carrier × service level override) else `clients.default_markup_percent`.
- Flags (§5.6 B5d): cheapest = lowest customer price; fastest = lowest eta; recommended = cheapest among options meeting the requested date, with the `own_fleet_preference_percent` tie-break. Final vs preliminary variance above `variance_tolerance_percent` (default 10) returns quotes but leaves the shipment in `quoted` awaiting re-confirmation.
- Selection and confirmation are Transport pages, not this service; confirmation emits `shipment.quote_confirmed`.

## 4. `RateService` (Billing, C) — §6.3, §6.4, §6.7 A5/A6a
```php
price(int $clientId, string $chargeCode, float $qty, array $context = [], ?DateTimeInterface $at = null): array
thresholds(int $clientId, string $chargeCode): ?array
suggestPalletClass(int $clientId, int $lengthMm, int $widthMm, int $heightMm, float $weightKg): ?string
```
- `price` returns `{charge_code, rate_item_id, rate_card_id, rate_card_version, uom, qty, rate_cents, amount_cents, min_charge_applied, is_poa, missing_rate, calculation_snapshot}`. Lookup: client's active card at `$at` (default now) → the client's bound standard card → `missing_rate = true` (amount null, **never 0**). `is_poa = true` when the rate item is POA or a threshold is exceeded (`max_gross_weight_kg`, `max_line_count`). `min_billable_qty` floors the quantity and sets `min_charge_applied`; `min_charge_cents` floors the amount. `cost_plus` items need `context.cost_cents`.
- `context` keys: `pallet_class, weight_kg, zone, carrier_id, service_level, cost_cents, container_size, unpack_mode, line_count, gross_weight_kg, pallet_source, is_urgent` (`charge-codes.md` says which code reads which).
- `thresholds` returns the effective rate item's `threshold_json` (client card → standard card), e.g. `TR-TAILGATE → {tailgate_weight_kg: 25}` for Orders' tailgate rule and `WH-DEVAN-20-LOOSE → {max_line_count: 20}` for Warehouse.
- `suggestPalletClass` implements §4.8 from the client's storage items: standard → oversize_high → oversize_wide; `weight ≥ min_weight_kg` → `overweight`; `null` = beyond every band (POA, `needs_review`). The receiver may override with a reason.

## 5. `JobService` (Platform, C) — §1.6, §2.4 A27
```php
create(int $clientId, string $jobType, array $attributes = []): array{job_id:int, job_no:string}
summarize(int $jobId): array{job_id, job_no, client_id, job_type, operational_status, revenue_status, cost_status, estimated_revenue_cents, actual_revenue_cents, estimated_cost_cents, actual_cost_cents, margin_cents, margin_is_estimate}
```
- The only way to create a Job (`jobs` is a shared platform table). `job_no` = `JOB-YYYYMMDD-NNNN`. `attributes`: `reference`, `notes`.
- `summarize` derives the three statuses from child records and recomputes the cached money (§1.6): revenue from `charges` / `invoices` / `payments`, cost from `carrier_costs`; `margin_is_estimate` until `cost_status = confirmed`. Cost and margin are never returned to client-role callers (server-side, M1).
- **Client-role callers** (global client scope active) receive the summary **without** `estimated_cost_cents`, `actual_cost_cents`, `margin_cents`, `margin_is_estimate` — the cost / margin rule is enforced in the service, not in views (M1).
- Job numbers are `JOB-YYYYMMDD-NNNN`, sequence per day, allocated under a row lock (M1).

## 6. `ExceptionService` (Platform, C) — §2.4 A28, §3.3 holds
```php
raise(string $type, string $sourceModule, array $attributes = []): int
resolve(int $exceptionId, int $resolvedBy, ?string $note = null): void
hasActiveHold(string $holdType, ?int $clientId = null, ?int $orderId = null): bool      // added M1
```
- `hasActiveHold` (M1): is there an unresolved hold of this type for the client — either on this order or client-wide (`order_id` null)? Transport / Orders call it before booking or dispatch; only an active `financial` hold blocks them (§3.4 OMS-11). Reads the shared table for you so no seat touches `exceptions` directly.
- The only writer of `exceptions`. `type` / `source_module` from `enums.md`; `attributes`: `job_id, client_id, source_type, source_id, order_id, hold_type, message, owner_id, created_by`.
- Order holds are `type = hold` + `hold_type`; releasing a hold = `resolve` (records `released_by/at`, `release_reason` = note). Only an active `financial` hold blocks booking / dispatch.

## 7. `DocumentService` (Platform, C) — §2.4 A29
```php
attach(string $type, string $relatedType, int $relatedId, string $storagePath, array $attributes = []): int
```
- The only writer of `documents`. `type` from `enums.md`; `relatedType` is the owning record (`shipment`, `order`, `asn`, `invoice`, `return_receipt`, …); `attributes`: `job_id, client_id, client_visible, original_name, mime, size_bytes, uploaded_by`. Portal visibility is decided here (`client_visible`), never in a view.

## 8. `ManifestParser` (Orders, X1; used by Warehouse B2b) — §3.6, §3.7.2 A4, §4.6 B2b
```php
parse(string $path): array{rows: list<Row>, errors: list<{row, column, message}>, warnings: list<{row, column, message}>}
```
- One `Row` per goods line of the real 《需派送货物清单》: `row, consignment_mark, description_cn, description_en, hs_code, material, usage, brand, package_type, carton_qty, unit_qty, unit_price_cents, total_price_cents, actual_weight_kg, length_mm, width_mm, height_mm, cbm, deliver_to_name, deliver_to_phone, deliver_to_address, deliver_to_state, deliver_to_postcode, fba_reference`.
- Hard errors (missing mark, non-numeric quantity) exclude the row; the pre-check "same consignment_mark, different deliver_to / fba_reference" is a **warning** (B2b lets the forwarder fix the list before the container lands; A4 blocks the group at order creation). Import bookkeeping (`asn_imports` / `order_imports`) belongs to the caller, not the parser.

## Fakes: rules of use
- `USE_FAKE_SERVICES=true` in `phpunit.xml` and CI; a seat turns it on locally while its dependency block is unmerged. The flag must be `false` in production.
- Fakes hold state in memory per process; they are singletons for a request / test. Never write a test that depends on a Fake behaving like the database.
- When the real implementation lands, its Feature tests must pass the same contract assertions as `tests/Feature/Platform/FakeServicesTest.php`.

## 8. Platform-rest services (C, `block/c5-platform-rest`)
- `App\Modules\Platform\Services\ApprovalService` (A19): `request(type, subjectType, subjectId, User $by, attrs) → Approval` (one open request per subject), `approve(Approval, User $by, ?note)`, `reject(...)`, `cancel(Approval, User)`, `isApproved(type, subjectType, subjectId): bool`. **The requester can never decide their own request** (PLT-7). Modules that need a second-person gate call `isApproved()` before applying the sensitive change (Billing: `rate_card_change`, `credit_note`, `price_override`, `poa_quote`; Orders: `financial_release`; Warehouse: `stock_adjustment`).
- `App\Support\Search\SearchRegistry` (A30): modules register `register('<module>', fn (string $q): array)` in their provider's `boot()`; a hit is `['type' => ..., 'label' => ..., 'url' => ..., 'meta' => ...]`. Platform, Warehouse and MasterData are registered; X1 adds orders / tracking numbers, X2 shipments, Billing invoices.
- `App\Support\Documents\DocumentDownloader` (A29): `canDownload(Document, ?User)` / `respond(Document, ?User)` — the single visibility rule (staff: all; client user: `client_visible` and own `client_id`). Portal (X1) must serve downloads through it from a `/portal/**` route.
- `ExceptionService::assign(id, ?ownerId)` and `start(id, userId)` (A28) — additive; the Exception Centre at `/admin/exceptions` is the shared queue for every module's exceptions (X1's Coordinator queue may filter the same table by hold types).
- `ConsumerRegistry::register('*', Consumer::class)` receives every event (used by the webhook pusher, A23).

## 9. Billing services (C, `block/c4-billing`)
- `App\Modules\Billing\Services\ChargeEngine` (A6a): `applyEvent(array $envelope): list<Charge>` — runs the active `charge_rules` for the event, prices through `RateService`, writes `charges` with rate + calculation snapshots under the idempotency key `(source_activity_id, charge_code, activity_version)`. Missing rate → `missing_rate` exception, no charge; POA → `needs_review`; a higher `activity_version` reverses the older charges (uninvoiced: original + audit twin marked `reversed`; invoiced: billable negative twin). `manual(...)` (reason required), `review(Charge, amountCents, note)`, `reverse(Charge, reason)`.
- `BillingChargeConsumer` is registered for `task.completed`, `asn.putaway_completed`, `outbound.packed`, `shipment.quote_confirmed`, `delivery.extra_charge`. Payload fields read: see `charge-codes.md` quantity sources plus `activity_version` (redo counter), `carrier_cost_cents` / `client_price_cents` / `zone` / `carrier_id` / `service_level` / `tailgate_required` / `charge_code` (shipment.quote_confirmed), `charge_type` (delivery.extra_charge), `lines[].unit_type|qty|unit_weight_kg`, `is_urgent`, `label_count` (outbound.packed).
- `StorageBillingService::billWeek(date)` / `billing:storage-weekly` (Monday 01:00): one charge per unit per ISO week from `stock_snapshots` — pallet class code (quarantine on its own code), pallet rental by source, loose cartons per carton or CBM, pickface slots per client × job.
- `InvoiceService` (A8a / A10): `draftForJob`, `draftMonthly(client, from, to)`, `draftStorageWeek(client, day)` take charges out of the unbilled pool; `issue()` numbers `INV-YYYYMM-NNNN`, snapshots bill-to, GST per line, `due_at` from `Client::dueDateFor`, stores the PDF through `DocumentService` (client-visible); `discardDraft`, `recordPayment`, `outstandingCents(client)`, `flagOverdue` (`billing:flag-overdue`, display only).
- `CreditNoteService` (A8b): `draft(invoice, lines, reason, by)` requests a `credit_note` approval; `issue(note, by)` only when a second person approved.
- `RateCardService` (A5): `newVersion`, `createClientCard`, `updateItem` / `addItem` (drafts only), `requestActivation` (approval `rate_card_change`), `activate` (supersedes the previous version from the new effective date; clients bound to the old standard card follow the new one).
- `QuoteService` (A18, shared with X1's A7b): `create(clientId, lines[{charge_code, qty, context}], attrs)` prices each line (POA / missing flagged in `assumptions`), `setStatus`.
