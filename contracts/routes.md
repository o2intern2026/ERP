# contracts/routes.md — URL prefixes per module and seat

`routes/web.php` (frozen zone) does nothing except `require` each module's `routes.php`. Every module's `routes.php` must wrap **all** of its routes in the URL prefix(es) and route-name prefix below. `tests/Feature/Platform/RoutesContractTest.php` fails the build if any registered route falls outside this table.

| Module | Seat | URL prefix(es) | Route name prefix | Blade view namespace | lang file |
|---|---|---|---|---|---|
| Platform | C | `/` (redirects to `/jobs`), `/login`, `/register`, `/logout`, `/admin/**` (users, roles, exceptions, documents, search, approvals, audit, integration monitor), `/jobs/**` (Job workbench) | `platform.` | `platform::` | `lang/zh/platform.php` |
| MasterData | C | `/admin/clients/**`, `/admin/suppliers/**`, `/admin/carriers/**` | `masterdata.` | `masterdata::` | `lang/zh/masterdata.php` |
| Warehouse | C | `/warehouse/**` | `warehouse.` | `warehouse::` | `lang/zh/warehouse.php` |
| Billing | C | `/billing/**` | `billing.` | `billing::` | `lang/zh/billing.php` |
| Orders | X1 | `/orders/**` | `orders.` | `orders::` | `lang/zh/orders.php` |
| Portal | X1 | `/portal/**` | `portal.` | `portal::` | `lang/zh/portal.php` |
| Reports | X1 | `/reports/**` | `reports.` | `reports::` | `lang/zh/reports.php` |
| Transport | X2 | `/transport/**`, `/driver/**` (driver mobile pages) | `transport.` | `transport::` | `lang/zh/transport.php` |

## Rules
- Middleware: every route except `/login`, `/register` and `/logout` sits behind `auth`. The global client scope (M1/A1) is enforced in the data layer, not per route.
- Client-role users may reach only `/portal/**`, `/logout` and the client views Portal links to; enforced server side (M1), never by hiding links.
- `/admin/**` is Platform's namespace; MasterData owns exactly the three sub-paths above and nothing else under `/admin`.
- Unauthenticated pages: `/login` and `/register` (client self-registration, tester feedback #8 / CHANGE_REQUESTS #89; hidden when `erp.allow_signup` is false). The only API route in phase 1 is A4b's `POST /orders/api/orders` (X1, bearer token from `order_api_tokens`, `Idempotency-Key` header; no session, CSRF or client scope — the token names the client). It sits under the Orders prefix so the prefix rule holds (CHANGE_REQUESTS #39).
- Nav: `resources/views/layouts/nav.blade.php` includes `layouts/nav/<module>.blade.php` for every module; each module edits only its own include.

## M0 placeholders (one per module; Feature test in `tests/Feature/<Module>/`)
| Module | Route | Name | View |
|---|---|---|---|
| Platform | `GET /jobs` | `platform.index` | `platform::index` |
| MasterData | `GET /admin/clients` | `masterdata.index` | `masterdata::index` |
| Warehouse | `GET /warehouse` | `warehouse.index` | `warehouse::index` |
| Billing | `GET /billing` | `billing.index` | `billing::index` |
| Orders | `GET /orders` | `orders.index` | `orders::index` |
| Portal | `GET /portal` | `portal.index` | `portal::index` |
| Portal | `GET /portal/reports` (client view of A21, client users only — ClientScope confines them to `/portal/**`, CHANGE_REQUESTS #52) | `portal.reports.index` | `reports::client` |
| Orders | `POST /orders/{order}/estimate` (A7b 估价 → Billing QuoteService) | `orders.estimate` | — |
| Portal | `POST /portal/orders/{order}/estimate` (client estimate, 客户价 only) | `portal.orders.estimate` | — |
| Portal | `POST /portal/orders/{order}/quotes/{quote}/confirm` (client confirms the final transport quote → QuoteSelectionService, §5.7 #2) | `portal.orders.quotes.confirm` | — |
| Orders | `GET /orders/addresses/suggest?q=&client_id=` (JSON address suggestions from the client's address book + past orders) | `orders.addresses.suggest` | — |
| Portal | `GET /portal/addresses/suggest?q=` (same, for the signed-in client) | `portal.addresses.suggest` | — |
| Portal | `POST /portal/orders/preview` (获取估价: validates and prices the unsaved order form, nothing persisted; the form then offers 确认提交订单 → `portal.orders.store`; tester feedback #10, CHANGE_REQUESTS #88) | `portal.orders.preview` | `portal::orders.create` |
| Portal | `POST /portal/orders/{order}/cancel` (stage 1: client cancels a received / confirmed / allocated order directly → OrderChangeService::cancelByClient, same `order.cancelled` event) and `POST /portal/orders/{order}/cancel-request` (stage 2: picking / packed → `cancel_request` exception for the coordinator) — CHANGE_REQUESTS #111 | `portal.orders.cancel`, `portal.orders.cancel_request` | `portal::orders.show` |
| Orders | `POST /orders/{order}/cancel-request/reject` (coordinator declines the client's request; reason shown in the portal) | `orders.cancel_request.reject` | — |
| Orders | `GET /orders/requests` (客户请求 inbox: open cancel requests + portal return requests + 30-day history; staff roles admin \| customer_service \| dispatcher \| warehouse_supervisor \| finance; CHANGE_REQUESTS #112) | `orders.requests.index` | `orders::requests.index` |
| Orders | `GET /orders/inbound`, `POST /orders/inbound` (从订单生成预报单, CHANGE_REQUESTS #117: worklist of from_stock orders whose goods have no ASN line yet, grouped by client; a picked set + warehouse / inbound type / container / ETA → OrderInboundService::generate → Warehouse InboundService::createAsnFromOrderLines; orders merged into the first order's Job, emptied Jobs cancelled via JobService::cancelIfEmpty; roles admin / customer_service / warehouse_supervisor) | `orders.inbound.index`, `orders.inbound.store` | `orders::inbound.index` |
| Platform | `GET /register`, `POST /register` (client self-registration → Client `pending` + inactive client-role user; tester feedback #8, CHANGE_REQUESTS #89) | `platform.register`, `platform.register.store` | `platform::auth.register` |
| MasterData | `POST /admin/clients/{client}/approve` (pending → active and activates the client's users; admin \| customer_service \| finance) | `masterdata.clients.approve` | — |
| Portal | `GET /portal/reports/export/{table}` (CSV of the client report) | `portal.reports.export` | — |
| Portal | `GET /portal/invoices`, `GET /portal/invoices/{invoice}/download` (own issued invoices, PDF via DocumentDownloader) | `portal.invoices.index`, `portal.invoices.download` | `portal::invoices.index` |
| Portal | `GET /portal/stock` (own stock, read-only) | `portal.stock.index` | `portal::stock.index` |
| Portal | `GET /portal/asns`, `GET /portal/asns/{asn}` (预报入库, read-only: the client's own ASNs with progress, goods lines, packing-list parse results and the 入库单 PDFs via DocumentDownloader; CHANGE_REQUESTS #116 → #117 — clients no longer create ASNs) | `portal.asns.index`, `portal.asns.show` | `portal::asns.index`, `portal::asns.show` |
| Reports | `GET /reports` (boss view, admin \| finance), `GET /reports/client?client_id=` (staff client view), CSV exports | `reports.index`, `reports.client`, `reports.*.export` | `reports::index`, `reports::client` |
| Reports | `GET /reports` | `reports.index` | `reports::index` |
| Transport | `GET /transport` and `GET /driver` | `transport.index`, `transport.driver` | `transport::index`, `transport::driver` |
| Warehouse | `GET /warehouse/receiving` (待收货 worklist: every ASN line still awaiting receipt; admin \| warehouse_supervisor \| warehouse_operator \| customer_service — the 收货 button only for the three warehouse roles; CHANGE_REQUESTS #90) | `warehouse.receiving.index` | `warehouse::receiving.index` |
| Warehouse | `GET /warehouse/receiving/unplanned`, `POST /warehouse/receiving/unplanned` (无预报收货: one screen → unplanned ASN + lines + units + completed 入库单; admin \| warehouse_supervisor \| warehouse_operator; #91) | `warehouse.receiving.unplanned.form`, `warehouse.receiving.unplanned.store` | `warehouse::receiving.unplanned` |
| Warehouse | `GET /warehouse/asns/{asn}/receive`, `POST /warehouse/asns/{asn}/receive` (手动填写入库单: every not-yet-received line of the ASN on one screen → GoodsReceiptService::receiveLines, optional 入库完成 in the same submit; tester feedback #4 2026-09-10, CHANGE_REQUESTS #94) | `warehouse.receiving.bulk_form`, `warehouse.receiving.bulk_store` | `warehouse::receiving.bulk` |
| Warehouse | `GET /warehouse/asns/{asn}/lines/{line}/delivery`, `POST /warehouse/asns/{asn}/lines/{line}/delivery` (编辑收件信息: the consignee fields 从预报单生成派送订单 needs, one goods line at a time, optionally copied to the ASN's other lines under the same mark → AsnService::updateLineDelivery; refused once the line is on an order; roles admin / warehouse_supervisor / warehouse_operator / customer_service; lead request 2026-09-11, CHANGE_REQUESTS #115) | `warehouse.asns.lines.delivery.edit`, `warehouse.asns.lines.delivery.update` | `warehouse::asns.delivery` |
| Warehouse | `POST /warehouse/asns/{asn}/confirm-client` (确认客户预报: customer service signs off a portal / API-submitted ASN → AsnService::confirmClientSubmission; roles admin / customer_service; a flag for the screens and the portal, never a gate on receiving or putaway; `GET /warehouse/asns?pending=1` lists the ones still waiting; CHANGE_REQUESTS #116) | `warehouse.asns.confirm_client` | — (redirect back) |
| Warehouse | `POST /warehouse/asns/{asn}/import-orders` (从订单导入货物行: on a booked / arrived / receiving ASN the staff tick the client's pending from_stock orders — received / confirmed, ≥ 1 goods line without an ASN line, listed by OrderService::awaitingAsn — and OrderService::attachOrdersToAsn → InboundService::addOrderLinesToAsn adds one goods line per order line with the order's consignee / phone / address / suburb / state / postcode / FBA / mark / description / cartons, linked both ways; the orders are merged into the ASN's Job and the emptied Jobs cancelled; form `order_ids[]` (integers, min 1); errors under `import_orders`; roles admin / customer_service / warehouse_supervisor; the manual add-line form on the same page is the collapsed exception; lead request 2026-09-11, CHANGE_REQUESTS #119) | `warehouse.asns.import_orders` | — (redirect to `warehouse.asns.show`) |
| Warehouse | `POST /warehouse/tasks/{task}/cancel` (cancels a hand-made 作业登记 record without a billing event; system tasks pick/pack/load/return_inspection refuse) | `warehouse.tasks.cancel` | — |
| Warehouse | `GET /warehouse/receipts` (入库单 list with client / status / date filters), `GET /warehouse/receipts/{receipt}` (page with the ASN roll-up); admin \| warehouse_supervisor \| warehouse_operator \| customer_service \| finance (#90) | `warehouse.receipts.index`, `warehouse.receipts.show` | `warehouse::receipts.index`, `warehouse::receipts.show` |
| Warehouse | `GET /warehouse/receipts/{receipt}/pdf` (rendered live from the DB, open → 草稿 label, `application/pdf` inline `{receipt_no}.pdf`; the file stored at completion is what the document centre / portal serve) | `warehouse.receipts.pdf` | `warehouse::receipts.pdf` |
| Warehouse | `POST /warehouse/receipts/{receipt}/complete` (入库完成, optional `notes`; open receipt with ≥ 1 line; admin \| warehouse_supervisor \| warehouse_operator) | `warehouse.receipts.complete` | — |

Until M1 lands authentication, placeholders are reachable without login; M1 puts them behind `auth`.
