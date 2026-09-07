# Handoff register

One branch, one seat at a time. Register before covering another seat's block branch (COLLAB_PLAN.md §5.2 rule 4).

| Date | Branch | From seat | To seat | Starting at task | Expected return | Returned |
|---|---|---|---|---|---|---|
| 2026-09-07 | block/x1-oms-min | X1 (quota exhausted) | C | A14 (A3, A17, A4, A7 done by X1) | when M3 is merged — X1 resumes with block 2 (`block/x1-portal-reports`) | 2026-09-07 — A14 / A16 / A11b / A15 delivered, PR "M3: OMS minimal block" opened; see Notes |

## Notes

### 2026-09-07 · block/x1-oms-min · C covered A14 / A16 / A11b / A15 for X1
Paste-ready context for X1's Codex when it resumes (block 2, `block/x1-portal-reports`):

- **Done by C on your branch, in your conventions** (final classes, `orders::` views, `lang/zh/orders.php`, tests in `tests/Feature/Orders`, PSR-4 under `app/Modules/Orders`):
  - `Services/TailgateRule.php` (A16) — `evaluate()` / `apply()`; threshold from `RateService::thresholds(client, 'TR-TAILGATE')['tailgate_weight_kg']` (default 25). **Assumption:** a line's `actual_weight_kg` is the line total, per-piece = total / `carton_qty`; declared packages count as pieces. Applied in `OrderCreationService::create()` and again in `OrderStatusService::transitionOperational('confirmed')` unless `tailgate_reason = manual`. Override: `POST /orders/{order}/tailgate` (`OrderController::tailgate`, reason → `order_events.note`).
  - A11b — `OrderCreationService::create()` accepts a missing `job_id` and opens a Job through `JobService` (`transport_only` for `pickup_deliver`, else `loose`); `lines` optional for `pickup_deliver`, pickup address + `declared_packages` required (see `OrderController::validated()`); `form.blade.php` shows the pickup fieldset by order type; `show.blade.php` renders pickup + packages.
  - `Services/OrderBatchService.php` + `Http/Controllers/BatchController.php` + `views/batches/index.blade.php` (A14) — `GET /orders/batches?ref=<container_no|asn_no>`; read-only joins on Warehouse's `asns` / `containers` / `asn_lines`; revenue shows "pending" until Billing (M6) adds `charges`. ASN chips on order lines via `asnRefsFor()`.
  - `Services/OrderHoldService.php` + `Http/Controllers/HoldController.php` + `Http/Controllers/QueueController.php` + `views/queue/index.blade.php` (A15) — presets `pending_confirm | short | financial_hold | exceptions | today`; holds are Platform exceptions written through `ExceptionService::raise('hold', 'orders', …)` / `resolve()`, read via `DB::table('exceptions')` (read-only). Financial holds: `admin|finance` only. This covers most of **A13** already; in block 2 only add what is missing (list highlighting, approval flow if Finance wants one).
  - `OrdersServiceProvider` registers orders in `SearchRegistry` (A30). Nav include gained 调度队列 / 入库批次.
  - New lang sections: `orders.pickup`, `orders.tailgate`, `orders.holds`, `orders.queue`, `orders.batches`.
  - Tests: `TailgateAndTransportOrderTest`, `QueueAndBatchTest` (8 tests). Full suite 106 green on the branch.
- **Branch state:** `origin/main` (M2 + platform-rest) merged into the branch as a merge commit (no rebase, nothing to force-push). PR "M3: OMS minimal block" opened by C.
- **When you resume:** `git fetch origin && git checkout block/x1-portal-reports` (create from `main` after M3 merges); read `AGENTS.md`, `CODEX_PLAN.md §3` 版块 2, `contracts/`. Portal downloads must go through `App\Support\Documents\DocumentDownloader` (services.md §8). `order.cancelled` / `order.reduced` are still to be emitted (A11); `customer_quotes` come from Billing's `QuoteService` (A7b shares it, services.md §9).
