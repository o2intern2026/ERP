# Project: Logistics ERP

Laravel 12 + Blade SSR + MySQL 8 (Laravel 11 is past security support — contracts/CHANGE_REQUESTS.md #1). Eventual production target is a plain virtual server (cron only, no daemons, no Redis, no Docker, no Node); until it is bought, everything runs locally on free software under the same constraints.
Three seats build this in parallel on different machines, synchronised only through Git: **C** (Claude Code, Claude Max), **X1** and **X2** (Codex). This file is the single rulebook for all three; `CLAUDE.md` imports it. Operating plan: `COLLAB_PLAN.md`. Business plan and task list: `ERP_PLAN.md`.

## Who you are

- Your seat comes from a file that is NOT in the repo: Claude Code reads `CLAUDE.local.md` (git-ignored) or `~/.claude/CLAUDE.md`; Codex reads `~/.codex/AGENTS.md`. It contains one line `SEAT=C`, `SEAT=X1` or `SEAT=X2`. If you cannot find your seat, ask BEFORE writing any file.
- **Seat C** owns `app/Modules/{Platform,MasterData,Warehouse,Billing}`, `app/Support/`, `contracts/`, the frozen zone, CI, and routes `/admin /warehouse /billing /jobs`. C is the only seat that merges to `main`.
- **Seat X1** owns `app/Modules/{Orders,Portal,Reports}` and routes `/orders /portal /reports`.
- **Seat X2** owns `app/Modules/Transport` and routes `/transport /driver`.
- **Priority is WMS and TMS first** (COLLAB_PLAN.md v1.1): checkpoints run M0 platform-minimal → M2 WMS core (C) → M3 OMS minimal (X1) → M4 WMS outbound (C) → M5 TMS (X2) → M6 Billing / portal / reports. Billing events are still emitted verbatim per `contracts/events.md` from the first WMS/TMS task, so Billing can be added later without touching warehouse or transport code.
- You may edit ONLY: your own module directories, your own `lang/zh/<module>.php`, `tests/Feature/<Module>/`, and your own `resources/views/layouts/nav/<module>.blade.php` include. Plus your own rows in `ERP_PLAN.md` task tables, your own `call` line in `DatabaseSeeder`, and appending to `contracts/CHANGE_REQUESTS.md` / `HANDOFF.md`.
- Ownership binds to the branch, not the person: if C is covering a task on `block/x2-*` (registered in `HANDOFF.md`), C follows X2's directory rules there. Never two seats on one branch at the same time.

## Read first, every session

1. `ERP_PLAN.md` §0 (rules, object hierarchy) and §8 (stack, ownership, collaboration).
2. ONLY the module section for the task at hand: §2 Platform, §3 OMS, §4 WMS, §5 TMS, §6 Billing. Skip the benchmark paragraphs ("优势 / 取舍") — they explain why, not what to build.
3. `contracts/` — enums, events, table ownership, service signatures, routes, charge codes. These are law: match them verbatim. If a task needs a contract change, STOP and request it at a checkpoint; never edit `contracts/` unilaterally.

## Frozen zone (change = contract change, decided by C at a checkpoint)

`CLAUDE.md`, `contracts/**`, `composer.json` / `composer.lock`, `config/`, `bootstrap/`, `routes/web.php`, `resources/views/layouts/{app,nav}.blade.php`, `app/Support/**`, `database/migrations/` (Block 0 framework tables only). Adding a Composer package: register it in `contracts/dependencies.md` first. X1 / X2 never edit the frozen zone or `contracts/`; they append a request to `contracts/CHANGE_REQUESTS.md` (what, why, which task is blocked) and continue with a Fake or a TODO.

## Hard rules (ERP_PLAN §0.2)

- **Job is the backbone.** Every business record carries `job_id`. Job = one independent client commission; ASN is the inbound master document; Container is an optional child of ASN (0..n) with basic fields only (container_no, 20/40, unpack mode, gross weight, line count) — no container lifecycle, seals, or customs.
- **Shared platform tables** (`jobs`, `exceptions`, `documents`, `outbox_events`, audit) are written only through `JobService` / `ExceptionService` / `DocumentService` / the Outbox publisher — never directly.
- **Cross-module state changes go through `outbox_events`**, written in the SAME DB transaction as the business write. Consumers are idempotent via `consumed_events`. Synchronous reads go through the public services in `contracts/services.md`. Only Billing writes `charges`; only Warehouse writes `stock_ledger`.
- **No SKU / product master.** Inventory = client + asn_line + packaging unit + location. Marks are for grouping and search only.
- **Billing:** `charge_rules` decide when a charge arises; `charge_codes` describe what it is; `charges` keep a rate snapshot and a business-level unique key. Cancel / redo creates reversal rows — never delete. Missing rate → Missing Rate Exception, never $0. Rate lookup order: client rate card → the client's bound standard rate card (new clients are bound to it by default) → Missing Rate. **Every number in the rate card is a parameter** (`rate_items.threshold_json`): rates, 22/45 kg pick bands, pallet-size/weight thresholds, 20-line devanning cap, 22.5 t container cap, urgent cut-off, minimum charges — never hardcoded, per client. The Edward rate card is the seeded default.
- **Order flow (option B):** pick → pack (measured dims) → final quote confirmed → (per_job clients) invoice generated from existing charges → booking / dispatch. `clients.invoice_mode` = per_job | monthly (monthly: charges accumulate in the unbilled pool, one invoice at month end grouped by Job). `clients.payment_terms` = prepaid | eom | net_N only sets the invoice due date. **Unpaid or overdue invoices never block booking or dispatch** — only a manual financial hold placed by Finance/Coordinator does (OMS-11); the system never auto-locks on payment status.
- **Invoices:** status `draft → issued → paid / part_paid`, with `due_at` from payment terms and an overdue flag (display only). No separate review stage — the preparer checks charge lines on the draft page and issues. After issue, only credit notes. Manual one-off charges require a reason. Credit notes and rate-card changes need second-person approval (PLT-7); issuing an invoice does not.
- **Billing quantities come from WMS, Billing never guesses:** unload / putaway / load-out per pallet count; picks per task line (full pallet vs carton, cartons carry unit weight); labels per printed carton label / package; serial scans per `scan_records` row; labour as hours_business / hours_after_hours entered by the supervisor; waste per CBM; storage per weekly snapshot by pallet_class (standard 1200×1200×≤1400 mm <800 kg; oversize_high ≤1800; oversize_wide ≤2400 one side; ≥800 kg overweight = POA; beyond all bands = POA), plus pallet_source (client_own | warehouse_plain | chep | loscam) for pallet rental / purchase and occupied pickface locations for pickface weekly fees.
- **Tailgate (OMS-13):** any piece ≥ the client's threshold (default 25 kg), residential address, or manual flag sets `tailgate_required` at order confirmation. The charge arises once, from the charge rule at final quote confirmation — never at order confirmation.
- **Server-rendered Blade only.** No npm, no build step, no Vue / React / SPA. Pico.css (CDN) + one `app.css` ≤ 100 lines. Inline vanilla JS per page; the only CDN libs are `html5-qrcode` and the signature canvas. Every warehouse form accepts scan-gun (keyboard) input.
- **All UI strings via `lang/zh`.** No hardcoded Chinese in Blade. Money: integer cents, AUD. GST per `charge_code.tax_treatment`, summed per invoice line. **Client-facing PDF documents are English** (goods receipt, tax invoice, unit / location labels, consignment note, own-fleet label, POD — CHANGE_REQUESTS #114): their templates draw every word from `lang/en/pdf.php` — the only `lang/en` file, reached through the fallback locale — and never from `lang/zh` (`PdfEnglishTest`). Data fields print as entered.
- **Cost and margin fields must never be queried or serialised for client-role users** — enforced server side (global scope / resource classes), not by hiding in the view.
- **Multi-tenancy is a global client scope** in the data layer, not page-level checks. Eight roles: admin, customer service, dispatcher, warehouse supervisor, warehouse operator, transport operator, finance, client.

## Definition of done for every task

Migration (if any) + page(s) + Feature test + `lang/zh` entries + the acceptance items listed for that task in `ERP_PLAN.md`. Then `php artisan test` green → `./vendor/bin/pint` → commit → push.

## Git workflow (ERP_PLAN §8.5–8.6)

- Work on your **block branch**: `block/<seat><n>-<slug>` (e.g. `block/c1-platform`, `block/x1-oms`, `block/x2-stock-inbound`). Commit directly to it. One task id per session (Codex seats especially — usage is metered): finish it, push it, stop.
- **Push at the end of EVERY session.** Machines change; nothing irreproducible may live on a local disk. Secrets go in the team password vault, never in the repo (`.env` is ignored; `.env.example` is committed).
- Merges to `main` happen only at checkpoints M0 → M5, in order (see `COLLAB_PLAN.md` §4). X1 / X2 open a PR from their block branch when the block is complete and CI is green; **only C merges**, using the `integrator` flow. Never merge or push to `main` yourself. After each checkpoint, `git rebase main` immediately when C @-mentions you in `MERGELOG.md`.
- If you depend on another seat's service or event that is not yet merged, write a Fake implementation against `contracts/services.md` / a test event against `contracts/events.md` and continue.
- If your usage quota runs out mid-task: commit as `WIP: <task id> — <what is left>` and push. A pushed half-task is fine; an unpushed one is lost.
- Migrations already merged into `main` are never edited — add a new migration. Seeders are one file per module; `DatabaseSeeder` is only a `call` list.

### Session start

```bash
git fetch --all --prune
git checkout block/<your-current-block> && git pull --rebase
composer install
cp -n .env.example .env && php artisan key:generate     # fill .env values from the team vault
php artisan migrate:fresh --seed                        # local DB is disposable; real rate card + sample manifest are seeded
php artisan test                                        # must be green before you write anything
```

### Session end

```bash
php artisan test && ./vendor/bin/pint
git add -A && git commit -m "<seat>/<block>: <task id> <what>"
git push origin block/<your-current-block>
```
Then tick your task rows in `ERP_PLAN.md`.

Local dev only: `php artisan schedule:work` and `php artisan queue:listen` stand in for cron. Production still uses cron + `queue:work --stop-when-empty`; never write code that assumes a long-running process.

## Stack (do not substitute)

PHP 8.2 (composer platform lock; 8.2–8.4 locally), Laravel 12, MySQL 8 (local dev too — never SQLite), Blade + Pico.css, `QUEUE_CONNECTION=database` driven by cron `queue:work --stop-when-empty`, Laravel Scheduler via cron `schedule:run`, `barryvdh/laravel-dompdf`, `picqer/php-barcode-generator`, `spatie/laravel-permission`, `spatie/laravel-activitylog`.

## Never

- Edit files outside your ownership, or anything in the frozen zone / `contracts/` (X1 / X2: request it in `contracts/CHANGE_REQUESTS.md` instead).
- Read the whole `ERP_PLAN.md` in a session. Read §0, §8, the section for your current task, and the contracts it names — nothing else.
- Write to another module's tables directly, or bypass the Outbox for cross-module effects.
- Delete charges, invoices, stock ledger rows, or events — reverse or supersede them.
- Introduce Vue, React, a bundler, Redis, Docker, SQLite, WebSockets, or a native app.
- Hardcode Chinese in views, or return cost / margin fields to a client-role request.
- Invent business rules the plan does not state (e.g. weekly storage edge cases) — raise an open question in `ERP_PLAN.md` instead.
