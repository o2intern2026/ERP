# Logistics ERP

Laravel 12 + Blade SSR + MySQL 8 · 3PL warehouse / transport / billing system. No npm, no build step, no daemons: the code runs on a plain virtual server with cron (ERP_PLAN §8.1).

| File | Read it if you are… |
|---|---|
| `AGENTS.md` | any seat — the rulebook (Codex reads it automatically; `CLAUDE.md` imports it) |
| `CODEX_PLAN.md` | seat X1 or X2 — setup + ordered task list |
| `COLLAB_PLAN.md` | anyone — how three seats collaborate, checkpoints M0–M6 |
| `ERP_PLAN.md` | anyone — business rules, data models, task specs (read only your section) |
| `contracts/` | everyone — enums, events, schema, service signatures, routes, charge codes. **Law.** |
| `MERGELOG.md` / `HANDOFF.md` | checkpoint merges / covering another seat's branch |

## Local setup (once per machine — COLLAB_PLAN §2.1)

| Component | macOS | Windows |
|---|---|---|
| PHP 8.2+ / Composer | `brew install php@8.4 && brew link --force php@8.4`, `brew install composer --ignore-dependencies` (or Laravel Herd) | Laragon or Herd |
| MySQL 8 | `brew install mysql@8.4 && brew services start mysql@8.4` (or DBngin) | Laragon's MySQL 8 |
| GitHub CLI | `brew install gh && gh auth login` | winget / installer |

Never SQLite — reservations and the Outbox rely on MySQL row locks and transactions. Create the databases once:

```sql
CREATE DATABASE erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE erp_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Seat identity is **not** in the repo: create `CLAUDE.local.md` (git-ignored) with `SEAT=C`, or `~/.codex/AGENTS.md` with `SEAT=X1` / `SEAT=X2`.

## Every session (COLLAB_PLAN §5.1)

```bash
# start
git fetch --all --prune
git checkout block/<your-current-block> && git pull --rebase
composer install
cp -n .env.example .env && php artisan key:generate      # then set DB_USERNAME / DB_PASSWORD only
php artisan migrate:fresh --seed
php artisan test                                          # green before you write anything

# run locally
php artisan serve                                         # http://localhost:8000
php artisan schedule:work                                 # stands in for cron `schedule:run`
php artisan queue:listen                                  # stands in for cron `queue:work --stop-when-empty`

# finish
php artisan test && ./vendor/bin/pint
git add -A && git commit -m "<seat>/<block>: <task id> <what>"
git push origin block/<your-current-block>
```

`USE_FAKE_SERVICES=true` in `.env` binds `app/Support/Fakes` (contracts/services.md) while the block you depend on is unmerged; tests and CI always run with it on.

## Demo logins (after `migrate:fresh --seed`, local only)

One user per role, password `password` (override with `SEED_DEMO_PASSWORD` in `.env`): `admin@erp.local`, `customer-service@erp.local`, `dispatcher@erp.local`, `warehouse-supervisor@erp.local`, `warehouse-operator@erp.local`, `transport-operator@erp.local`, `finance@erp.local`, `client@erp.local` (bound to client `EDWARD`; sees only /portal). Admin-only pages: /admin/users, /admin/integration. Master data (/admin/clients|suppliers|carriers): admin, customer service, finance.

## Inbound demo flow (M2)

`/warehouse/asns` → 新建 ASN(整柜填柜号)→ 导入清单或手工加货物行 → 每行「收货」(实收 / 破损 / 托盘尺寸重量 / 托盘来源;差异自动进异常)→ `/warehouse/putaway` 扫库位码上架 → `/warehouse` 查库存与流水。拆柜 / 人工时等作业在 `/warehouse/tasks` 建任务并完成,完成即发 `task.completed` 给计费。`php artisan stock:reconcile` 校验流水与余额。

## Layout

```
app/Modules/<Module>/   <Module>ServiceProvider, Http/Controllers, Models, Services, Events, routes.php, views/, migrations/, Seeders/
app/Support/            frozen zone: Money, DomainEvent, OutboxPublisher, ClientScope, Contracts/ (service interfaces), Fakes/
contracts/              frozen zone: the eight contract files + CHANGE_REQUESTS.md
lang/zh/<module>.php    all UI strings (no hardcoded Chinese in Blade)
resources/views/layouts app.blade.php + nav.blade.php (+ nav/<module>.blade.php per module)
public/css/app.css      the only stylesheet (≤ 100 lines) next to Pico.css from CDN
tests/Feature/<Module>/ follows the module owner
.claude/agents/integrator.md   checkpoint merge agent (seat C only)
```

Owners: **C** Platform, MasterData, Warehouse, Billing, `app/Support`, `contracts`, CI · **X1** Orders, Portal, Reports · **X2** Transport. Routes per module: `contracts/routes.md`.
