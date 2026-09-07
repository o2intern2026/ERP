# contracts/routes.md — URL prefixes per module and seat

`routes/web.php` (frozen zone) does nothing except `require` each module's `routes.php`. Every module's `routes.php` must wrap **all** of its routes in the URL prefix(es) and route-name prefix below. `tests/Feature/Platform/RoutesContractTest.php` fails the build if any registered route falls outside this table.

| Module | Seat | URL prefix(es) | Route name prefix | Blade view namespace | lang file |
|---|---|---|---|---|---|
| Platform | C | `/` (redirects to `/jobs`), `/login`, `/logout`, `/admin/**` (users, roles, exceptions, documents, search, approvals, audit, integration monitor), `/jobs/**` (Job workbench) | `platform.` | `platform::` | `lang/zh/platform.php` |
| MasterData | C | `/admin/clients/**`, `/admin/suppliers/**`, `/admin/carriers/**` | `masterdata.` | `masterdata::` | `lang/zh/masterdata.php` |
| Warehouse | C | `/warehouse/**` | `warehouse.` | `warehouse::` | `lang/zh/warehouse.php` |
| Billing | C | `/billing/**` | `billing.` | `billing::` | `lang/zh/billing.php` |
| Orders | X1 | `/orders/**` | `orders.` | `orders::` | `lang/zh/orders.php` |
| Portal | X1 | `/portal/**` | `portal.` | `portal::` | `lang/zh/portal.php` |
| Reports | X1 | `/reports/**` | `reports.` | `reports::` | `lang/zh/reports.php` |
| Transport | X2 | `/transport/**`, `/driver/**` (driver mobile pages) | `transport.` | `transport::` | `lang/zh/transport.php` |

## Rules
- Middleware: every route except `/login` and `/logout` sits behind `auth`. The global client scope (M1/A1) is enforced in the data layer, not per route.
- Client-role users may reach only `/portal/**`, `/logout` and the client views Portal links to; enforced server side (M1), never by hiding links.
- `/admin/**` is Platform's namespace; MasterData owns exactly the three sub-paths above and nothing else under `/admin`.
- Unauthenticated pages: `/login` only. No API routes in phase 1 except the reserved `/api/orders` (A4b, X1, token auth) — add it to this table before implementing it.
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
| Reports | `GET /reports` | `reports.index` | `reports::index` |
| Transport | `GET /transport` and `GET /driver` | `transport.index`, `transport.driver` | `transport::index`, `transport::driver` |

Until M1 lands authentication, placeholders are reachable without login; M1 puts them behind `auth`.
