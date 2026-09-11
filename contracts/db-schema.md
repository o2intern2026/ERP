# contracts/db-schema.md — tables, owners, columns (law)

The live version of ERP_PLAN §8.2 with owners as **seats** (COLLAB_PLAN §1.2): every table has exactly one owner that writes its migration and model; other seats read through `services.md` or react to `events.md`. Columns come verbatim from the module data models (§1.6, §2, §3.3, §4.2, §5.2, §6.3); `id`, `created_at`/`updated_at` and the FKs implied by the text are added. Owners may add columns **additively** in their own migrations; renaming or removing a listed column is a contract change. Enum-valued columns use `enums.md`. Money `*_cents` = unsigned/ signed bigint; dims `*_mm` int; weights `*_kg` decimal(10,3); `cbm` decimal(10,4).

Conventions: `job_id` on every business record (§0.2 rule 1); `client_id` on every client-scoped record (`BelongsToClient`); soft deletes nowhere — reverse or supersede instead.

## 0. Framework (owner C, `database/migrations/` — the only migrations outside modules)
| table | columns |
|---|---|
| `users` | id, name, email (unique), password, remember_token, timestamps; M1 adds `client_id` (nullable → clients: client-role users), `is_active`, `last_login_at`; roles via spatie tables `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` (M1) |
| `password_reset_tokens`, `sessions`, `cache`, `cache_locks` | Laravel defaults |
| `queue_jobs`, `job_batches`, `failed_jobs` | Laravel queue tables. **`queue_jobs` replaces the default name `jobs`**, which is the business Job table (`CHANGE_REQUESTS.md` #2) |
| `activity_log` (spatie, M6/A20), `approvals`, `webhooks` (M6) | Platform, later |

## 1. Platform (owner C) — shared tables written only through JobService / ExceptionService / DocumentService / OutboxPublisher
| table | columns |
|---|---|
| `jobs` | id, job_no (unique, `JOB-YYYYMMDD-NNNN`), client_id, job_type, operational_status, revenue_status, cost_status, estimated_revenue_cents, actual_revenue_cents, estimated_cost_cents, actual_cost_cents, reference, notes, created_by, timestamps (§1.6) |
| `exceptions` | id, type, source_module, status, job_id, client_id, order_id, source_type, source_id, hold_type (type = hold), message, owner_id, created_by, resolved_by, resolved_at, released_by, released_at, release_reason, timestamps (§2.4 A28; §3.3 holds live here) |
| `documents` | id, type, related_type, related_id, job_id, client_id, client_visible (bool), storage_path, original_name, mime, size_bytes, uploaded_by, timestamps (§2.4 A29) |
| `outbox_events` | id, event_id (uuid, unique), event_name, event_version, correlation_id, job_id, client_id, payload (json), status, attempts, available_at, published_at, last_error, created_at (§0.2 rule 4, A31) |
| `consumed_events` | id, event_id, consumer, consumed_at; unique (event_id, consumer) |
| `approvals` | id, type (contracts/enums.md approvals.type), subject_type, subject_id, client_id, job_id, requested_by, request_note, payload (json), status (pending | approved | rejected | cancelled), decided_by, decided_at, decision_note, timestamps — A19, added M6-prep |
| `webhook_endpoints` | id, name, url, secret, events (json list or ["*"]), active, created_by, timestamps — A23 |
| `webhook_deliveries` | id, endpoint_id, event_id, event_name, status (pending | delivered | failed), attempts, response_code, last_error, delivered_at, created_at; unique (endpoint_id, event_id) — A23 |
| `activity_log` | spatie/laravel-activitylog default schema (log_name, description, subject, event, causer, properties json, batch_uuid) — A20; written automatically by models using LogsActivity |

## 2. MasterData (owner C) — §2.3 A2, §6.2, §3.3
| table | columns |
|---|---|
| `clients` | id, code (unique), name, abn, leg_type, contact_name, contact_phone, contact_email, billing_email, address, suburb, state, postcode, status, payment_terms, invoice_mode, invoice_period, invoice_grouping (2026-09-08), default_markup_percent (decimal 5,2), dispatch_cutoff_time (time), standard_rate_card_id (nullable → rate_cards; new clients bound to the standard card by default), timestamps |
| `suppliers` | id, code (unique), name, abn, contact_name, contact_phone, contact_email, address, status, timestamps |
| `carriers` | id, code (unique), name, abn, contact_name, contact_phone, contact_email, status, timestamps — master data only; transport attributes are `carrier_services` (X2) |

## 3. Orders (owner X1) — §3.3
| table | columns |
|---|---|
| `orders` | id, order_no (unique, `ORD-YYYYMMDD-NNNN`), client_id, job_id, order_type, source, external_ref, consignment_mark, fba_reference, pickup_address (json, pickup_deliver), deliver_to_name, deliver_to_phone, deliver_to_address, deliver_to_suburb, deliver_to_state, deliver_to_postcode, deliver_to_address_type, requested_date, operational_status, fulfilment_status, billing_status, service_level, tailgate_required (bool), tailgate_reason, customer_quote_id (nullable → customer_quotes), transport_preference (json, nullable — the transport option the client chose with the 估价: source, service_level, carrier_id, carrier_name, customer_price_cents, eta_days, flags, chosen_at, chosen_by; CHANGE_REQUESTS #118), created_by, timestamps, original_order_id (nullable → orders; return orders, A11), return_inspected_at, return_decision (credit \| no_credit), return_decided_by, return_decided_at, return_decision_note (A11 — CHANGE_REQUESTS #38) |
| `order_lines` | id, order_id, description_cn, description_en, hs_code, material, usage, brand, package_type, carton_qty, unit_qty, unit_price_cents, total_price_cents, actual_weight_kg, length_mm, width_mm, height_mm, cbm, qty_shipped, qty_backordered, asn_line_id (nullable → asn_lines), stock_unit_ref (nullable), timestamps, original_order_line_id (nullable; return orders, A11 — CHANGE_REQUESTS #38) |
| `declared_packages` | id, order_id, package_type, qty, weight_kg, length_mm, width_mm, height_mm |
| `fulfilments` | id, order_id, seq (`F1`, `F2`…), warehouse_id, status, shipment_id (nullable → shipments), timestamps |
| `fulfilment_lines` | id, fulfilment_id, order_line_id, qty |
| `client_addresses` | id, client_id, label, contact_name, phone, address, suburb, state, postcode, address_type, default_instructions, usage_count, last_used_at, timestamps (§3.3 OMS-14; owner per `CHANGE_REQUESTS.md` #9) |
| `order_events` | id, order_id, from_status, to_status, actor_type (user \| system), actor_id, note, created_at (append-only) |
| `order_imports` | id, client_id, source (excel \| pdf), document_id (nullable → documents), status, row_count, error_count, errors (json), created_by, timestamps |
| `order_api_tokens` | id, client_id, name, token_hash (sha256 of the bearer token; plain value shown once), last_used_at, revoked_at, created_by, timestamps (A4b — CHANGE_REQUESTS #39) |
| `report_deliveries` | id, client_id, period_type (weekly \| monthly), period_from, period_to, recipient_email, sent_at, attachments (json) — A22 send log (X1, CHANGE_REQUESTS #54) |
| `order_api_idempotency_keys` | id, client_id, idempotency_key, order_id, created_at (unique per client; replay returns the first order — A4b) |
Not tables: **holds** = `exceptions` rows with `type = hold`; **order_documents** = `documents`; **return requests** = `orders` with `order_type = return` (`CHANGE_REQUESTS.md` #10).

## 4. Warehouse (owner C) — §4.2
| table | columns |
|---|---|
| `warehouses` | id, code (unique), name, address, suburb (M7), state, postcode (M7), active (bool), business_hours (json: per weekday open/close for hours_business vs hours_after_hours), timestamps |
| `locations` | id, warehouse_id, zone, aisle, bin, full_code (unique per warehouse), type, active (bool), timestamps |
| `asns` | id, asn_no (unique), job_id, client_id, warehouse_id, expected_date, inbound_type, status, created_by_type, created_by, unplanned (bool), unplanned_confirmed (bool), client_confirmed_at / client_confirmed_by (nullable — set when customer service confirms a client-submitted ASN, i.e. created_by_type = client from the portal form or API push; CHANGE_REQUESTS #116), arrived_at, receiving_completed_at (nullable — set when a 入库单 batch completes and every line has been received; CHANGE_REQUESTS #90), putaway_completed_at, closed_at, notes, timestamps. **Terminology: ASN = 预报单 (pre-advice); 入库单 = `goods_receipts` (lead decision 2026-09-08, #92)** |
| `containers` | id, asn_id, job_id, container_no, size, unpack_mode, gross_weight_kg, line_count (derived), timestamps — basic fields only, no lifecycle |
| `asn_lines` | id, asn_id, container_id (nullable), consignment_mark, deliver_to_name, deliver_to_phone, deliver_to_address, deliver_to_suburb, deliver_to_state, deliver_to_postcode, fba_reference, description, package_type, expected_cartons, received_cartons, damaged_cartons, variance_reason, weight_kg, length_mm, width_mm, height_mm, cbm, order_line_id (nullable → order_lines, set by B2c), timestamps |
| `asn_imports` | id, asn_id (nullable), client_id, job_id, document_id, status, row_count, error_count, errors (json), warnings (json), created_by, timestamps (B2b) |
| `goods_receipts` | id, receipt_no (unique, `{asn_no}-R{batch_no}` e.g. `ASN-20260908-0001-R1`), asn_id, client_id, warehouse_id, job_id (nullable), batch_no (per ASN from 1; unique with asn_id), status (`open` \| `completed`), unplanned (bool, copied from the ASN), delivery_reference (送货单号 / 车牌 / 司机, free text), opened_at, opened_by, completed_at, completed_by, expected_cartons, received_cartons, damaged_cartons, variance_cartons (signed: received + damaged − expected), line_count, unit_count, pallet_count (snapshot totals written at completion), pdf_document_id (nullable → documents, type `goods_receipt`), notes, timestamps; index (client_id, status). One receiving batch of an ASN = one 入库单; all batches roll up to the ASN (tester feedback round 3 item 2, CHANGE_REQUESTS #90) |
| `goods_receipt_lines` | id, goods_receipt_id, asn_line_id (unique together — a line is received once), expected_cartons, received_cartons, damaged_cartons, variance_reason, unit_count, pallet_count, received_at, received_by, timestamps (snapshot of what the batch recorded; the live figures stay on `asn_lines`) |
| `stock_units` | id, client_id, job_id, asn_line_id, goods_receipt_id (nullable → goods_receipts: the 入库单 batch that created the unit, incl. the `-DMG` unit; #90), warehouse_id, unit_type, label_code (unique), location_id, qty_on_hand, qty_reserved, qty_inbound, pallet_class, pallet_class_overridden_reason, length_mm, width_mm, height_mm, weight_kg, pallet_source, condition, condition_reason, condition_changed_at, putaway_completed (bool — a unit is available only when true, §4.3 rule 2), received_at, timestamps |
| `warehouse_tasks` | id, task_no (unique), task_type, job_id, client_id, warehouse_id, source_type, source_id, order_id, fulfilment_id, wave_id (nullable, M4), asn_id, container_id, priority, assigned_user_id, status, exception_reason, cancel_reason, billable_qty, billable_uom, hours_business, hours_after_hours, notes, started_at, completed_at, completed_by, billable_event_id (→ outbox_events.event_id), timestamps |
| `warehouse_task_lines` | id, task_id, stock_unit_id (nullable), asn_line_id (nullable), order_line_id (nullable, M4 — pick lines), location_id, required_qty, completed_qty, confirmed_at |
| `scan_records` | id, task_id, stock_unit_id (nullable), package_id (nullable), serial_no, scanned_by, scanned_at |
| `stock_ledger` | id, stock_unit_id, movement_type, qty, qty_before, qty_after, movement_group_id, from_stock_unit_id, to_stock_unit_id, from_location_id, to_location_id, source_type, source_id, operator_id, created_at (append-only; the single source of truth, §4.3 rule 9) |
| `stock_snapshots` | id, snapshot_date, timezone, client_id, job_id, asn_line_id, stock_unit_id, warehouse_id, location_id, condition, pallet_class, unit_type, pallet_source, location_type, billable_qty, billable_cbm, period_start, period_end, created_at (immutable once written) |
| `stock_reservations` | id, order_id, order_line_id, stock_unit_id, qty, status, created_at, released_at, released_reason |
| `waves` | id, wave_no (unique), warehouse_id, status, released_by, released_at, notes, timestamps |
| `packages` | id, fulfilment_id, order_id, job_id, client_id, package_type, weight_kg, length_mm, width_mm, height_mm, carton_label (unique), timestamps |
| `outbound_dispatches` | id, fulfilment_id (unique), order_id, job_id, client_id, warehouse_id, pallet_count, package_count, handed_to, shipment_id (nullable), dispatched_by, dispatched_at, timestamps (M4 — the `dispatched` timestamp of §4.3 rule 5; CHANGE_REQUESTS #35) |
| `stocktakes` | id, stocktake_no, warehouse_id, status, counted_by, timestamps |
| `stocktake_lines` | id, stocktake_id, stock_unit_id, location_id, system_qty, counted_qty, variance, reason |
| `return_receipts` | id, receipt_no (unique, M4), job_id, client_id (M4), return_order_id (nullable — set when the return order exists), original_order_id, original_shipment_id, return_shipment_id, warehouse_id, status, received_at, inspected_at, inspected_by, completed_at, notes (M4), timestamps |
| `return_receipt_lines` | id, return_receipt_id, original_order_line_id, asn_line_id, original_fulfilment_id, description (M4), expected_qty, received_qty, condition, disposition, stock_unit_id (nullable, created at inspection for every disposition with received_qty > 0), received_at, inspected_at (M4) |

## 4b. Portal (owner X1) — CHANGE_REQUESTS #116

| Table | Columns |
|---|---|
| `portal_asn_submissions` | id, client_id, asn_id, channel (portal \| api), idempotency_key (nullable; unique per client — an API replay returns the first ASN), token_id (nullable → order_api_tokens), user_id (nullable), line_count, created_at. One row per client self-service ASN submission; the ASN itself is Warehouse's `asns` row with created_by_type = client |

## 5. Transport (owner X2) — §5.2
| table | columns |
|---|---|
| `carrier_services` | id, carrier_id (→ carriers), source, service_level, default_eta_days, active (bool), config (json: adapter settings, never credentials), timestamps |
| `shipments` | id, shipment_no (unique), job_id, client_id, order_id, fulfilment_id (nullable), shipment_type, status, selected_quote_id (nullable → transport_quotes), carrier_id, service_level, booking_ref, tracking_number, waybill_document_id, consignment_note_document_id, tailgate_required (bool), delivery_run_id (nullable), dispatched_at, delivered_at, timestamps |
| `transport_quotes` | id, shipment_id, carrier_id, source, service_level, cost_cents, customer_price_cents, markup_percent, eta_days, is_recommended, is_cheapest, is_fastest, quote_stage, status, selected_by, selected_by_user_id, quoted_at, expires_at, raw_response (json), timestamps |
| `delivery_runs` | id, run_no (unique), run_date, driver_id (→ users), vehicle, status, timestamps |
| `run_stops` | id, delivery_run_id, shipment_id, seq, eta, arrived_at, status |
| `pods` | id, shipment_id, delivered_at, recipient_name, signature_document_id, photo_document_ids (json), pod_document_id, failure_reason, captured_by, timestamps |
| `tracking_events` | id, shipment_id, status, description, location, source (api \| driver \| manual), occurred_at, raw (json), created_at |
| `carrier_costs` | id, shipment_id, job_id, carrier_id, expected_cost_cents, actual_cost_cents, variance_cents, note, confirmed_at, timestamps |
| `carrier_invoices` | id, carrier_id, invoice_no, period_from, period_to, total_cents, status, document_id, timestamps |
| `carrier_invoice_lines` | id, carrier_invoice_id, shipment_id (nullable), tracking_number, billed_cents, expected_cents, variance_cents, matched (bool), note |

## 6. Billing (owner C) — §6.3
| table | columns |
|---|---|
| `charge_codes` | id, code (unique, `charge-codes.md`), category, default_uom, customer_description, internal_description, tax_treatment, active (bool), timestamps |
| `charge_rules` | id, trigger_event, charge_code_id, condition (json), quantity_source, rate_match_priority, effective_from, effective_to, idempotency_key_template, active (bool), timestamps |
| `rate_cards` | id, client_id (nullable for the standard card), name, currency (`AUD`), version, effective_from, effective_to, status, is_standard (bool), created_by, approved_by, notes, timestamps |
| `rate_items` | id, rate_card_id, charge_code_id, pallet_class (nullable), threshold_json (json), weight_band_min, weight_band_max, zone, pricing_mode, carrier_id (nullable), service_level (nullable), markup_percent, rate_cents, min_charge_cents, is_poa (bool), notes, timestamps — never edited in place; a price change is a new card version |
| `charges` | id, job_id, client_id, charge_date, charge_code_id, rate_card_id, rate_card_version, rate_item_id, uom, qty, rate_snapshot_cents, amount_cents, calculation_snapshot_json, tax_treatment, status, source_type, source_id, source_activity_id, activity_version, reversal_of_charge_id, is_manual (bool), manual_reason, created_by, invoice_line_id (nullable), timestamps; **unique (source_activity_id, charge_code_id, activity_version)** |
| `invoices` | id, invoice_no (unique), client_id, invoice_type, group_by (job \| order, 2026-09-08), period_from, period_to, bill_to_name, bill_to_address, bill_to_abn, status, issued_at, due_at, is_overdue (display flag), paid_at, paid_amount_cents, subtotal_cents, gst_cents, total_cents, pdf_document_id, created_by, notes, timestamps |
| `invoice_jobs` | id, invoice_id, job_id |
| `invoice_lines` | id, invoice_id, charge_id, job_id, order_id (nullable, resolved from the charge source, 2026-09-08), charge_code, description, qty, uom, amount_cents, tax_treatment, gst_cents |
| `credit_notes` | id, credit_note_no (unique), invoice_id, job_id, client_id, reason, amount_cents, gst_cents, status, created_by, approved_by, issued_at, timestamps |
| `credit_note_lines` | id, credit_note_id, invoice_line_id, charge_id, description, amount_cents, gst_cents |
| `payments` | id, invoice_id, amount_cents, paid_at, method, reference, recorded_by, created_at |
| `customer_quotes` | id, quote_no (unique), job_id, client_id, order_id (nullable), stage, valid_until, status, subtotal_cents, gst_cents, total_cents, notes, created_by, timestamps (FIN-6 & OMS-3; X1's A7b reads/creates through RateService + Billing's quote service, `CHANGE_REQUESTS.md` #10) |
| `customer_quote_lines` | id, customer_quote_id, charge_code, qty, uom, amount_cents, transport_quote_id (nullable), assumptions (json) |

## Ownership quick check (used by the integrator)
C: §0, §1, §2, §4, §6 tables. X1: §3 tables. X2: §5 tables. Cross-module writes: **none** — Warehouse never writes `charges`; Billing never writes `stock_ledger`; nobody writes `jobs`, `exceptions`, `documents`, `outbox_events` except through Platform services (§0.2 rules 2–3).
