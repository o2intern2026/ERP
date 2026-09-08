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
| Platform | `GET /register`, `POST /register` (client self-registration → Client `pending` + inactive client-role user; tester feedback #8, CHANGE_REQUESTS #89) | `platform.register`, `platform.register.store` | `platform::auth.register` |
| MasterData | `POST /admin/clients/{client}/approve` (pending → active and activates the client's users; admin \| customer_service \| finance) | `masterdata.clients.approve` | — |
| Portal | `GET /portal/reports/export/{table}` (CSV of the client report) | `portal.reports.export` | — |
| Portal | `GET /portal/invoices`, `GET /portal/invoices/{invoice}/download` (own issued invoices, PDF via DocumentDownloader) | `portal.invoices.index`, `portal.invoices.download` | `portal::invoices.index` |
| Portal | `GET /portal/stock` (own stock, read-only) | `portal.stock.index` | `portal::stock.index` |
| Reports | `GET /reports` (boss view, admin \| finance), `GET /reports/client?client_id=` (staff client view), CSV exports | `reports.index`, `reports.client`, `reports.*.export` | `reports::index`, `reports::client` |
| Reports | `GET /reports` | `reports.index` | `reports::index` |
| Transport | `GET /transport` and `GET /driver` | `transport.index`, `transport.driver` | `transport::index`, `transport::driver` |
| Warehouse | `GET /warehouse/receiving` (待收货 worklist: every ASN line still awaiting receipt; admin \| warehouse_supervisor \| warehouse_operator \| customer_service — the 收货 button only for the three warehouse roles; CHANGE_REQUESTS #90) | `warehouse.receiving.index` | `warehouse::receiving.index` |
| Warehouse | `GET /warehouse/receiving/unplanned`, `POST /warehouse/receiving/unplanned` (无预报收货: one screen → unplanned ASN + lines + units + completed 入库单; admin \| warehouse_supervisor \| warehouse_operator; #91) | `warehouse.receiving.unplanned.form`, `warehouse.receiving.unplanned.store` | `warehouse::receiving.unplanned` |
| Warehouse | `GET /warehouse/receipts` (入库单 list with client / status / date filters), `GET /warehouse/receipts/{receipt}` (page with the ASN roll-up); admin \| warehouse_supervisor \| warehouse_operator \| customer_service \| finance (#90) | `warehouse.receipts.index`, `warehouse.receipts.show` | `warehouse::receipts.index`, `warehouse::receipts.show` |
| Warehouse | `GET /warehouse/receipts/{receipt}/pdf` (rendered live from the DB, open → 草稿 label, `application/pdf` inline `{receipt_no}.pdf`; the file stored at completion is what the document centre / portal serve) | `warehouse.receipts.pdf` | `warehouse::receipts.pdf` |
| Warehouse | `POST /warehouse/receipts/{receipt}/complete` (入库完成, optional `notes`; open receipt with ≥ 1 line; admin \| warehouse_supervisor \| warehouse_operator) | `warehouse.receipts.complete` | — |

Until M1 lands authentication, placeholders are reachable without login; M1 puts them behind `auth`.
