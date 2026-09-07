# contracts/dependencies.md — Composer package register

Adding **any** package (`require` or `require-dev`) to `composer.json` is a frozen-zone change: register it here first (X1 / X2: append a row to `contracts/CHANGE_REQUESTS.md`), then seat C adds it at a checkpoint. The integrator STOPs on an unregistered addition.

## Framework baseline (M0)
| Package / setting | Constraint | Why |
|---|---|---|
| `laravel/framework` | `^12.0` | Laravel 11 left security support on 2026-03-12 and every 11.x release is blocked by Composer security advisories. Laravel 12 keeps the same skeleton, the same `bootstrap/app.php` style and the PHP 8.2 platform lock (decision 2026-09-07, `CHANGE_REQUESTS.md` #1). |
| `laravel/tinker` | `^2.10` | ships with the skeleton |
| `config.platform.php` | `8.2.0` | the three machines may run different PHP minors; the lock file resolves as if PHP 8.2 (COLLAB_PLAN §2.1) |

## Application packages (ERP_PLAN §8.1)
| Package | Constraint | Purpose | Module(s) | Registered |
|---|---|---|---|---|
| `spatie/laravel-permission` | `^6.25` | eight roles + permissions; the global client scope is built on top of it | Platform (consumed by all) | M0 |
| `spatie/laravel-activitylog` | `^4.12` | audit trail (PLT-8 / A20) | Platform | M0 |
| `barryvdh/laravel-dompdf` | `^3.1` | PDFs: invoices, receipts, pick lists, consignment notes, own labels | Billing, Warehouse, Transport | M0 |
| `picqer/php-barcode-generator` | `^3.3` | carton / location / label barcodes, pure PHP | Warehouse, Transport | M0 |

`^6.x` and `^4.x` are the newest spatie majors that still run on PHP 8.2; 7.x / 8.x and 5.x require PHP ≥ 8.3 / 8.4.

## Dev-only (skeleton)
`laravel/pint`, `laravel/pail`, `phpunit/phpunit`, `mockery/mockery`, `nunomaduro/collision`, `fakerphp/faker`. `laravel/sail` was removed (no Docker).

## Explicitly not allowed (AGENTS.md "Never")
npm / Vite / any bundler, Vue / React / Livewire / Inertia, Redis, SQLite, Horizon, Sail, Octane, WebSockets. Front-end assets are Pico.css from CDN, one `public/css/app.css` (≤ 100 lines, served statically — no build step), and only `html5-qrcode` plus the signature canvas from CDN.
