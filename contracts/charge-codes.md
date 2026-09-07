# contracts/charge-codes.md — Edward rate card → charge codes

Source: `Edward Storage rate 27022026.xlsx` (Storage_rate_27022026; AUD, ex GST), all **34** service rows (ERP_PLAN §6.2 rule 2; decision 2026-09-07: both Label rows included, the two Cartage rows are TMS freight items). Codes, categories, UOMs, triggers, quantity sources and `threshold_json` **keys** are contract. **Rates are seed data, not contract** — the "Edward rate" column is only the seed reference for the standard rate card; a client card overrides everything, thresholds included.

`tax_treatment` = `gst_10` for every row (the card excludes GST). `charge_codes.code` format: `<WH|VAS|TR>-<WHAT>[-<QUALIFIER>][-WK]`. Every threshold and band below is stored on the `rate_items` row (`threshold_json`, `weight_band_min/max`, `min_charge_cents`, `is_poa`), never in code (§0.2 rule 8).

## 1. Cartage — TMS freight items
Priced by `RateService` as `own_fleet` / `fixed` rate items and charged once at `shipment.quote_confirmed` of the cartage shipment (`CHANGE_REQUESTS.md` #7).
| # | Edward row | Code | Category | UOM | Trigger | Quantity source | Threshold | Edward rate |
|---|---|---|---|---|---|---|---|---|
| 1 | Container delivery – 20ft sideloader | `TR-CARTAGE-20` | transport | container_20 | shipment.quote_confirmed | billable_qty (1) | `{"max_gross_weight_kg":22500}` — above → POA | 1230.60 |
| 2 | Container delivery – 40ft sideloader | `TR-CARTAGE-40` | transport | container_40 | shipment.quote_confirmed | billable_qty (1) | `{"max_gross_weight_kg":22500}` — above → POA | 1291.60 |

## 2. Inbound
| # | Edward row | Code | Category | UOM | Trigger | Quantity source | Condition / threshold | Edward rate |
|---|---|---|---|---|---|---|---|---|
| 3 | Container unpack 20ft – Pallet | `WH-DEVAN-20-PLT` | warehouse | container_20 | task.completed | billable_qty | task_type=devanning, container.size=20, unpack_mode=pallet | 180.00 |
| 4 | Container unpack 20ft – Loose | `WH-DEVAN-20-LOOSE` | warehouse | container_20 | task.completed | billable_qty | task_type=devanning, size=20, unpack_mode=loose; `{"max_line_count":20}` — above → POA (`needs_review`) | 400.00 |
| 5 | Container unpack 20ft – Mixed | `WH-DEVAN-20-MIXED` | warehouse | container_20 | task.completed | billable_qty | unpack_mode=mixed → **POA** (`is_poa`; card note "$300 subject to container") | POA |
| 6 | Container unpack 40ft – Pallet | `WH-DEVAN-40-PLT` | warehouse | container_40 | task.completed | billable_qty | size=40, unpack_mode=pallet | 280.00 |
| 7 | Container unpack 40ft – Loose | `WH-DEVAN-40-LOOSE` | warehouse | container_40 | task.completed | billable_qty | size=40, unpack_mode=loose; `{"max_line_count":20}` | 550.00 |
| 8 | Container unpack 40ft – Mixed | `WH-DEVAN-40-MIXED` | warehouse | container_40 | task.completed | billable_qty | unpack_mode=mixed → **POA** (card note "$450 subject to container") | POA |
| 9 | Truck unload (LCL) – pallet | `WH-UNLOAD-PLT` | warehouse | pallet | task.completed | billable_qty | task_type=receiving, asn.inbound_type=loose_truck; qty = pallets unloaded | 4.00 |
| 10 | Putaway – pallet | `WH-PUTAWAY-PLT` | warehouse | pallet | asn.putaway_completed | pallets | — | 4.50 |
| 11 | Shrink wrap / Strap (inbound) | `WH-WRAP-IN-PLT` | vas | pallet | task.completed | billable_qty | task_type=wrap, source_type ∈ {asn, container} | 4.50 |
| 12 | Label (inbound carton labels) | `WH-LABEL-IN` | warehouse | label | asn.putaway_completed | labels | one per printed carton / pallet label | 0.30 |

## 3. Storage — weekly from `snapshot.weekly`
One charge per billable unit per week; idempotency key = unit + billing week + code (§6.7 A6b). Inbound week and outbound week each count as a full week; same-day in-and-out does not count (§4.2).
| # | Edward row | Code | Category | UOM | Trigger | Quantity source | Condition / threshold | Edward rate |
|---|---|---|---|---|---|---|---|---|
| 13 | Storage – standard pallet | `WH-STORAGE-PLT-WK` | storage | pallet_week | snapshot.weekly | weeks | pallet_class=standard; `{"max_length_mm":1200,"max_width_mm":1200,"max_height_mm":1400,"max_weight_kg":800}` (weight strictly below) | 4.50 |
| 14 | Storage – overwidth pallet | `WH-STORAGE-PLT-WIDE-WK` | storage | pallet_week | snapshot.weekly | weeks | pallet_class=oversize_wide; `{"max_long_side_mm":2400,"max_short_side_mm":1200,"max_height_mm":1400,"max_weight_kg":800}` | 9.00 |
| 15 | Storage – overheight pallet | `WH-STORAGE-PLT-HIGH-WK` | storage | pallet_week | snapshot.weekly | weeks | pallet_class=oversize_high; `{"max_length_mm":1200,"max_width_mm":1200,"max_height_mm":1800,"max_weight_kg":800}` | 8.00 |
| 16 | Storage – Pickface Storage | `WH-STORAGE-PICKFACE-WK` | storage | pickface_week | snapshot.weekly | pickface_slots | location_type=pickface; `{"slot_length_mm":2650,"slot_width_mm":1000,"slot_height_mm":600}` (informational) | 6.50 |
| 17 | Storage – overweight pallet | `WH-STORAGE-PLT-OVERWEIGHT-WK` | storage | pallet_week | snapshot.weekly | weeks | pallet_class=overweight; `{"min_weight_kg":800}` → **POA** | POA |
| 18 | Pallet rental – plain pallet | `WH-PALLET-RENT-PLAIN-WK` | storage | pallet_week | snapshot.weekly | weeks | unit_type=pallet, pallet_source=warehouse_plain | 0.70 |
| 19 | Pallet rental – CHEP / LOSCAM | `WH-PALLET-RENT-POOL-WK` | storage | pallet_week | snapshot.weekly | weeks | unit_type=pallet, pallet_source ∈ {chep, loscam} | 2.00 |

## 4. Outbound
| # | Edward row | Code | Category | UOM | Trigger | Quantity source | Condition / threshold | Edward rate |
|---|---|---|---|---|---|---|---|---|
| 20 | Order process – despatch | `WH-ORDER-DESPATCH` | warehouse | order | outbound.packed | orders (1) | is_urgent=false | 5.00 |
| 21 | Order process – urgent despatch | `WH-ORDER-DESPATCH-URGENT` | warehouse | order | outbound.packed | orders (1) | is_urgent=true (same-day dispatch requested after `clients.dispatch_cutoff_time`); `{"cutoff_source":"clients.dispatch_cutoff_time"}`; **replaces** #20 — one order-processing charge per order (`CHANGE_REQUESTS.md` #5) | 15.00 |
| 22 | Pick – pallet | `WH-PICK-PLT` | warehouse | pallet | outbound.packed | pallets | lines with unit_type=pallet | 4.00 |
| 23 | Pick – carton ≥ 45 kg | `WH-PICK-CTN-GE45` | warehouse | carton | outbound.packed | cartons | unit_type=carton; weight_band_min=45.00, weight_band_max=null | 4.50 |
| 24 | Pick – carton 22–44.99 kg | `WH-PICK-CTN-22-45` | warehouse | carton | outbound.packed | cartons | weight_band_min=22.00, weight_band_max=44.99 | 3.50 |
| 25 | Pick – carton < 22 kg | `WH-PICK-CTN-LT22` | warehouse | carton | outbound.packed | cartons | weight_band_min=0.00, weight_band_max=21.99 | 1.50 |
| 26 | Truck load out – pallet | `WH-LOAD-PLT` | warehouse | pallet | task.completed | billable_qty | task_type=load; qty = pallets loaded | 4.00 |
| 27 | Outbound shrink wrap / strap | `WH-WRAP-OUT-PLT` | vas | pallet | task.completed | billable_qty | task_type=wrap, source_type ∈ {order, fulfilment} | 4.50 |
| 28 | Label and despatch fee | `WH-LABEL-OUT` | warehouse | label | outbound.packed | labels | one per printed carton label / package | 0.30 |
| 29 | Serial number scanning | `VAS-SCAN` | vas | scan | task.completed | billable_qty | task_type=scanning; qty = `scan_records` rows | 0.50 |

## 5. Value-added services
| # | Edward row | Code | Category | UOM | Trigger | Quantity source | Condition / threshold | Edward rate |
|---|---|---|---|---|---|---|---|---|
| 30 | Waste disposal | `VAS-WASTE-CBM` | vas | cbm | task.completed | billable_qty | task_type=waste; `{"min_billable_qty":1}` (minimum 1 CBM) | 80.00 |
| 31 | Pallet purchase (wood / plastic) | `VAS-PALLET-PURCHASE` | vas | pallet | asn.putaway_completed | pallets | pallets with pallet_source=warehouse_plain (warehouse-supplied), once at putaway | 25.00 |
| 32 | Non-standard pallet purchase | `VAS-PALLET-PURCHASE-NONSTD` | vas | pallet | manual | — | no data field distinguishes a non-standard pallet → manual charge with reason (`CHANGE_REQUESTS.md` #6) | 15.00 |
| 33 | Other VAS – business hours | `VAS-LABOUR-HR` | vas | man_hour | task.completed | hours_business | task_type ∈ {labour, vas_other} | 40.00 |
| 34 | Other VAS – outside business hours | `VAS-LABOUR-HR-AH` | vas | man_hour | task.completed | hours_after_hours | task_type ∈ {labour, vas_other} | 55.00 |

## 6. Codes the plan requires that have **no Edward row**
No seed rate: priced only if a client card carries them, otherwise **Missing Rate exception — never $0** (§0.2, §6.4).
| Code | Category | UOM | Trigger | Quantity source | Source / notes |
|---|---|---|---|---|---|
| `TR-DELIVERY-BASE` | transport | delivery | shipment.quote_confirmed | billable_qty (1) | own_fleet `fixed` or third-party `cost_plus` = cost × markup (§6.3, §6.4) |
| `TR-TAILGATE` | transport | delivery | shipment.quote_confirmed | billable_qty (1) | condition tailgate_required=true; `{"tailgate_weight_kg":25}` default per client (§3.4 OMS-13) — the only place the tailgate fee arises |
| `TR-REMOTE` | transport | delivery | shipment.quote_confirmed | billable_qty (1) | condition zone=remote (§6.4) |
| `TR-FUEL` | transport | delivery | shipment.quote_confirmed | billable_qty (1) | percentage surcharge as a rate-item attribute (§6.7 A5) |
| `TR-FAILED` | transport | delivery | delivery.extra_charge | billable_qty (1) | failed delivery (§6.4) |
| `TR-REDELIVERY` | transport | delivery | delivery.extra_charge | billable_qty (1) | re-delivery (§6.4) |
| `TR-WAITING` ※ | transport | man_hour | delivery.extra_charge | hours_business | waiting time (§6.4, §6.7 A5) |
| `WH-STORAGE-CTN-WK` | storage | carton_week | snapshot.weekly | cartons | loose cartons not on a pallet, if the client card bills per carton (§6.7 A6b) |
| `WH-STORAGE-CBM-WK` | storage | cbm_week | snapshot.weekly | cbm | loose cartons by volume, if the client card bills per CBM (§6.7 A6b) |
| `WH-STORAGE-QUARANTINE-PLT-WK` ※ | storage | pallet_week | snapshot.weekly | weeks | condition ∈ {quarantine, damaged}: still charged, separate code (§4.8) |

※ = code name assigned by C in M0 (the plan requires the charge but does not name it).

## 7. `threshold_json` key catalogue
The only keys code may read; values always come from the rate item, defaults below are the Edward seed.
| Key | Used by | Meaning (Edward default) |
|---|---|---|
| `max_gross_weight_kg` | TR-CARTAGE-* | container gross weight above which cartage is POA (22 500) |
| `max_line_count` | WH-DEVAN-*-LOOSE | `asn_lines` per container above which devanning is POA (20) |
| `max_length_mm`, `max_width_mm`, `max_height_mm`, `max_weight_kg` | WH-STORAGE-PLT-WK, WH-STORAGE-PLT-HIGH-WK | pallet_class bounds (1200 / 1200 / 1400 or 1800 / 800, weight strictly below) |
| `max_long_side_mm`, `max_short_side_mm` | WH-STORAGE-PLT-WIDE-WK | one side up to 2400, the other ≤ 1200 |
| `min_weight_kg` | WH-STORAGE-PLT-OVERWEIGHT-WK | ≥ 800 → overweight (POA) |
| `slot_length_mm`, `slot_width_mm`, `slot_height_mm` | WH-STORAGE-PICKFACE-WK | pickface slot dimensions (2650 / 1000 / 600, informational) |
| `cutoff_source` | WH-ORDER-DESPATCH-URGENT | where the cut-off is read from (`clients.dispatch_cutoff_time`) |
| `min_billable_qty` | VAS-WASTE-CBM | floor applied to qty before pricing (1 CBM) |
| `tailgate_weight_kg` | TR-TAILGATE | per-piece weight that forces a tailgate (25) |
| `variance_tolerance_percent` | `TransportOptionService` final vs preliminary quote | re-confirmation required above this (10, §5.6 B5d) |
| `own_fleet_preference_percent` | `TransportOptionService` recommendation | prefer own fleet when within this % of the cheapest (0, §5.6 B5d) |

Carton pick bands use the `weight_band_min` / `weight_band_max` columns, not JSON. `pallet_class` suggestion at receiving (§4.8): evaluate the client's active card's storage items in the order standard → oversize_high → oversize_wide; first match wins; weight ≥ `min_weight_kg` → overweight; nothing matches → POA / `needs_review`. Rate lookup order for every code: client card → the client's bound standard card → Missing Rate exception (§0.2).
