# contracts/enums.md — shared enumerations (law: match verbatim)

Every status, type, UOM, role and code below is used **exactly as written** (snake_case string values) in migrations, models, Blade, tests and event payloads by all three seats (ERP_PLAN §0.2 rule 5). Values marked **※** were *named* by seat C in M0 because the plan describes them without naming them; they are open to a `contracts/CHANGE_REQUESTS.md` entry until M1, never to unilateral edits.

Conventions: store enums as string columns (not MySQL `ENUM`) so values can be added by migration; `payment_terms` `net_N` is the literal prefix `net_` followed by an integer number of days.

## 1. Platform (owner C)

### roles ※ — spatie role names (AGENTS.md "Eight roles")
| value | display (lang key `platform.roles.<value>`) |
|---|---|
| `admin` | 管理员 |
| `customer_service` | 客服 |
| `dispatcher` | 调度 |
| `warehouse_supervisor` | 仓库主管 |
| `warehouse_operator` | 仓库操作员 |
| `transport_operator` | 运输操作员 |
| `finance` | 财务 |
| `client` | 客户 |

### jobs (ERP_PLAN §1.6)
| field | values |
|---|---|
| `job_type` | `container` \| `loose` \| `transport_only` \| `return` |
| `operational_status` | `open` → `receiving` → `in_stock` → `dispatching` → `completed` ; alternative `cancelled` |
| `revenue_status` | `unbilled` → `partially_invoiced` → `invoiced` → `paid` |
| `cost_status` | `estimated` → `partially_confirmed` → `confirmed` |

### exceptions (§2.4 A28; §3.3 holds reuse this table with `type = hold`)
| field | values |
|---|---|
| `type` ※ | `discrepancy` (收货差异) \| `pick_short` \| `delivery_failed` \| `manual_transport` \| `missing_rate` \| `billing_hold` \| `integration_failed` \| `hold` \| `cancel_request` (客户在拣货 / 打包阶段申请取消,由客服执行或拒绝 — CHANGE_REQUESTS #111) \| `stock_shortage` (缺货: raised by Warehouse when order.confirmed cannot be fully reserved; auto-resolved when the backorder is allocated — CHANGE_REQUESTS #106) |
| `status` ※ | `open` \| `in_progress` \| `resolved` |
| `source_module` ※ | `platform` \| `masterdata` \| `orders` \| `warehouse` \| `transport` \| `billing` |

### documents (§2.4 A29, §5.2)
| field | values |
|---|---|
| `type` ※ | `pod` \| `docket` \| `photo` \| `waybill` \| `invoice` \| `packing_list` \| `consignment_note` \| `label` \| `goods_receipt` (入库单 PDF, related_type `asn`, client_visible; CHANGE_REQUESTS #90) |

### outbox_events / consumed_events (§0.2 rule 4; built in M1/A31)
| field | values |
|---|---|
| `status` ※ | `pending` \| `published` \| `failed` \| `dead` (retries exhausted → failed-event queue + alert) |

| `approvals.type` | rate_card_change | credit_note | stock_adjustment | financial_release | price_override | poa_quote | A19 (PLT-7); added M6-prep by C |
| `approvals.status` | pending | approved | rejected | cancelled | requester ≠ decider, enforced by ApprovalService |
| `webhook_deliveries.status` | pending | delivered | failed | A23 |

## 2. MasterData (owner C) — §2.3 A2, §6.2, §0.2 rule 9
| field | values |
|---|---|
| `clients.payment_terms` | `prepaid` \| `eom` \| `net_N` (e.g. `net_7`, `net_14`, `net_30`) — sets `invoices.due_at` only; never blocks booking or dispatch |
| `clients.invoice_mode` | `per_job` \| `monthly` |
| `client_addresses.address_type` | `business` \| `fba` \| `residential` |

`carriers` is master data only (code, name, ABN, contact); transport attributes live in `carrier_services` (X2).

## 3. Orders (owner X1) — §3.3, §3.4
| field | values |
|---|---|
| `orders.order_type` | `from_stock` \| `pickup_deliver` \| `return` |
| `orders.source` | `portal` \| `excel` \| `api` \| `manual` \| `pdf` |
| `orders.operational_status` | `received` → `confirmed` → `allocated` → `picking` → `packed` → `dispatched` → `delivered` ; terminal alternatives `returned` \| `cancelled` |
| `orders.fulfilment_status` | `unfulfilled` \| `partial` \| `fulfilled` (derived from fulfilments) |
| `orders.billing_status` | `unbilled` \| `partially_billed` \| `billed` \| `credited` (derived from charges / invoices) |
| `orders.service_level` | `standard` \| `express` \| `same_day` |
| `orders.tailgate_reason` ※ | `heavy_item` \| `residential_address` \| `manual` |
| `fulfilments.status` | `allocated` \| `picking` \| `packed` \| `dispatched` \| `delivered` |
| `holds.hold_type` | `stock` \| `financial` \| `address` \| `transport` \| `client_confirmation` |
| `holds.status` | `active` \| `released` |
| `customer_quotes.stage` | `preliminary` \| `final` |

Customer-facing status (§3.4) is derived, never stored:
| internal `operational_status` | customer sees |
|---|---|
| `received` | Received |
| `confirmed` | Confirmed |
| `allocated`, `picking`, `packed` | In warehouse |
| `dispatched` | Out for delivery |
| `delivered` | Delivered |
| `returned` | Returned |
| `cancelled` | Cancelled |
| (`billing_status` = `billed`) | Invoiced |

Rules: only Orders writes `orders.*_status`; WMS / TMS notify through events. Change / cancel lock point = entering `picking`. Only an `active` `financial` hold blocks booking and dispatch (§3.4 OMS-11); the system never places one automatically.

## 4. Warehouse (owner C) — §4.2, §4.3, §4.8
| field | values |
|---|---|
| `locations.type` | `receiving` \| `storage` \| `pickface` \| `packing` \| `staging` \| `quarantine` |
| `asns.inbound_type` | `container` \| `loose_truck` \| `parcel` |
| `asns.status` | `booked` → `arrived` → `receiving` → `putaway` → `closed` — **terminology (lead decision 2026-09-08, #92): ASN = 预报单 (ASN) in every UI string; 入库单 is reserved for `goods_receipts`** |
| `goods_receipts.status` | `open` (lines are being received) → `completed` (入库完成: totals snapshotted, PDF filed) — one batch per delivery, `receipt_no = {asn_no}-R{batch_no}` (#90) |
| `asns.created_by_type` | `client` \| `coordinator` |
| `containers.size` | `20` \| `40` |
| `containers.unpack_mode` | `pallet` \| `loose` \| `mixed` (mixed = POA) |
| `stock_units.unit_type` | `pallet` \| `carton` |
| `stock_units.pallet_class` | `standard` \| `oversize_wide` \| `oversize_high` \| `overweight` \| `pickface` |
| `stock_units.pallet_source` | `client_own` \| `warehouse_plain` \| `chep` \| `loscam` |
| `stock_units.condition` | `good` \| `quarantine` \| `damaged` |
| `warehouse_tasks.task_type` | `receiving` \| `putaway` \| `move` \| `pick` \| `pack` \| `load` \| `count` \| `return_inspection` \| `devanning` \| `wrap` \| `scanning` \| `labour` \| `waste` \| `vas_other` |
| hand-made task types (`Enums::VAS_TASK_TYPES`) | `devanning` \| `wrap` \| `scanning` \| `labour` \| `waste` \| `vas_other` — the only types a person creates on 作业登记; `receiving` (unload), `pick`, `pack`, `load`, `return_inspection` are written by their operations, `putaway` / `move` / `count` are no longer created at all (tester feedback #6, CHANGE_REQUESTS #95) |
| `warehouse_tasks.source_type` | `order` \| `fulfilment` \| `asn` \| `container` \| `stocktake` \| `wave` |
| `warehouse_tasks.status` | `pending` \| `in_progress` \| `done` \| `cancelled` \| `exception` |
| `warehouse_tasks.billable_uom` | `container` \| `pallet` \| `carton` \| `scan` \| `man_hour` \| `cbm` \| `label` |
| `stock_ledger.movement_type` | `receipt` \| `putaway` \| `pick` \| `transfer` \| `adjust` \| `release` \| `return` \| `split` \| `merge` |
| `stock_reservations.status` | `active` \| `released` \| `consumed` |
| `stock_snapshots.location_type` | = `locations.type` |
| `return_receipts.status` | `expected` → `received` → `inspected` → `closed` |
| `return_receipt_lines.disposition` | `available` \| `quarantine` \| `damaged` (all three create a stock unit at inspection: available → good, in receiving until put away; quarantine / damaged → held in the quarantine location) |
| `outbound_dispatches.handed_to` ※ | `carrier` \| `driver` \| `client` (M4) |
| `packages.package_type` ※ | `carton` \| `pallet` \| `satchel` \| `crate` (M4) |

`pallet_class` default thresholds (Edward card, §4.8 — parameters live in `rate_items.threshold_json`, never in code): `standard` = 1200 × 1200 × ≤ 1400 mm and < 800 kg; `oversize_high` = height ≤ 1800 mm; `oversize_wide` = one side ≤ 2400 mm; ≥ 800 kg → `overweight` (POA); beyond every band → POA. Reservation is a quantity, not a condition: `available = qty_on_hand − qty_reserved`.

## 5. Transport (owner X2) — §5.2, §5.3
| field | values |
|---|---|
| `carrier_services.source` | `own_fleet` \| `transdirect` \| `eiz` \| `manual` \| `karrio` (one `CarrierAdapter` implementation each; `karrio` added 2026-09-08 — open-source gateway, CHANGE_REQUESTS #49) |
| `shipments.shipment_type` | `outbound` \| `return` |
| `shipments.status` (outbound) | `quoting` → `quoted` → `quote_confirmed` → `booked` → `dispatched` → `in_transit` → `delivered` ; alternatives `failed` \| `booking_cancelled` |
| `shipments.status` (return) | `return_requested` → `return_in_transit` → `arrived_warehouse` (then WMS `return_receipts`) |
| `transport_quotes.quote_stage` | `preliminary` \| `final` |
| `transport_quotes.status` | `quoted` \| `selected` \| `expired` \| `requoted` \| `booking_cancelled` |
| `transport_quotes.selected_by` | `client` \| `coordinator` \| `system` |
| `delivery_runs.status` ※ | `planned` \| `dispatched` \| `completed` \| `cancelled` |
| `run_stops.status` ※ | `pending` \| `arrived` \| `delivered` \| `failed` |

## 6. Billing (owner C) — §6.2, §6.3, §6.4
| field | values |
|---|---|
| `charge_codes.category` | `warehouse` \| `vas` \| `transport` \| `storage` \| `other` |
| `charge_codes.default_uom` | `container_20` \| `container_40` \| `pallet` \| `pallet_week` \| `pickface_week` ※ \| `carton` \| `carton_week` \| `cbm_week` \| `order` \| `label` \| `scan` \| `cbm` \| `man_hour` \| `delivery` |
| `charge_codes.tax_treatment` | `gst_10` \| `gst_free` \| `out_of_scope` |
| `charge_rules.trigger_event` | `task.completed` \\| `manual` ※ (hand-entered charges) | `asn.putaway_completed` \| `outbound.packed` \| `shipment.quote_confirmed` \| `delivery.extra_charge` \| `snapshot.weekly` \| `return.financial_decision` |
| `charge_rules.quantity_source` | `cartons` \| `pallets` \| `pallets_warehouse_plain` ※ \| `billable_qty` \| `weeks` \| `labels` \| `scans` \| `hours_business` \| `hours_after_hours` \| `cbm` \| `pickface_slots` \| `orders` \| `one` ※ |
| `rate_cards.status` | `draft` \| `active` \| `superseded` |
| `rate_items.pricing_mode` | `fixed` \| `cost_plus` \| `percent` ※ (surcharge as % of a base amount, e.g. TR-FUEL) |
| `rate_items.pallet_class` | = `stock_units.pallet_class` |
| `charges.status` | `pending` \| `needs_review` \| `approved` \| `invoiced` \| `disputed` \| `reversed` |
| `charges.source_type` | `asn` \| `container` \| `task` \| `shipment` \| `snapshot` \| `order` |
| `invoices.invoice_type` | `service` \| `storage` \| `supplementary` \| `monthly` |
| `invoices.status` | `draft` → `issued` → `part_paid` \| `paid` (`void` ※ reserved; `is_overdue` is display only) |
| `credit_notes.status` ※ | `draft` \| `approved` \| `issued` \| `cancelled` — approval is recorded in `approvals` (PLT-7); `draft` → `issued` once approved |

`label` and `pickface_week` are added to `default_uom` because the 34-row import (§6.2 rule 2, §6.4) bills per label and per pickface·week while the §6.3 list predates that decision; `orders` is added to `quantity_source` for the per-order despatch fee (`CHANGE_REQUESTS.md` #3, #4).

## 7. Return chain (cross-module, §3.4 OMS-9)
`return_requested` (Orders) → `return_in_transit` → `arrived_warehouse` (Transport, `shipments.status`) → `received` → `inspected` (Warehouse, `return_receipts.status`, `disposition` per line) → `return.financial_decision` (Billing: credit note or none) → order `operational_status` = `returned`. A driver marking "returned" changes nothing in stock; only `return_receipts` inspection does.

## 8. Constants that are not enums
Currency `AUD`, money as integer cents (`app/Support/Money`); timezone `Australia/Melbourne`; storage billing week starts Monday (configurable); tailgate default threshold 25 kg per client; carton pick bands < 22 kg, 22–44.99 kg, ≥ 45 kg; every threshold is a `rate_items.threshold_json` parameter (`charge-codes.md` §7).

## 9. Added while extracting `db-schema.md` (all ※, named by C in M0)
| field | values |
|---|---|
| `clients.leg_type` | `first_leg` \| `last_leg` \| `both` (§2.2 "头程/尾程类型") |
| `clients.status` | `pending` \| `active` \| `inactive` (`pending` = self-registered at `/register`, waiting for staff approval — tester feedback #8, CHANGE_REQUESTS #89; `Enums::CLIENT_STATUSES`) |
| `suppliers.status`, `carriers.status` | `active` \| `inactive` (`Enums::MASTER_STATUSES`) |
| `order_events.actor_type` | `user` \| `system` |
| `order_imports.source` | `excel` \| `pdf` |
| `order_imports.status`, `asn_imports.status` | `pending` \| `imported` \| `failed` |
| `waves.status` | `planned` \| `released` \| `completed` \| `cancelled` |
| `stocktakes.status` | `open` \| `counted` \| `adjusted` \| `cancelled` |
| `tracking_events.source` | `api` \| `driver` \| `manual` |
| `carrier_invoices.status` | `received` \| `matched` \| `disputed` \| `paid` |
| `delivery.extra_charge.charge_type` (event) | `waiting` \| `redelivery` \| `failed` \| `other` |
| `return.financial_decision.decision` (event) | `credit` \| `no_credit` |
| `invoices.group_by` ※ | `job` \| `order` (tester feedback #4) |
| `clients.invoice_period` ※ | `weekly` \| `fortnightly` \| `monthly` |
| `clients.invoice_grouping` ※ | `job` \| `order` |
