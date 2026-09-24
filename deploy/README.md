# Deploy notes (trial server)

`deploy/deploy-trial.sh` pushes `main` and runs `deploy/server/deploy.sh` on the box (pull, `composer install`, additive `migrate`, rebuild caches). Nothing here resets data.

## Upload limits (recommendation — CR #135, audit TMS-07; not applied by any script)

The driver POD form posts up to 5 photos (≤ 5 MB each after the phone-side downscale to 1600 px JPEG, typically 300–500 KB) plus a PNG signature, and the carrier POD form a PDF / JPG / PNG of up to 10 MB. PHP's defaults (`upload_max_filesize = 2M`, `post_max_size = 8M`) and nginx's default `client_max_body_size 1m` reject such a post with a 413 / an empty `$_FILES` after the receiver has already signed. Set, once, on the server:

| where | setting | value |
|---|---|---|
| PHP-FPM ini (`/etc/php/8.3/fpm/php.ini` or a `conf.d/99-erp.ini`) | `upload_max_filesize` | `8M` |
| PHP-FPM ini | `post_max_size` | `40M` |
| PHP-FPM ini | `max_input_vars` | `10000` (a bulk form with hundreds of rows; the receiving form also packs its rows into one field since CR #148, so this is belt and braces) |
| nginx server block for the site | `client_max_body_size` | `40m;` |

Then `systemctl reload php8.3-fpm nginx`. Check with `php -i | grep -E 'upload_max_filesize|post_max_size'` (CLI ini may differ from FPM's — check FPM's) and `nginx -T | grep client_max_body_size`. The application never changes these; `docs/demo-guide.zh.md` §8 carries the same note for the lead.

## Inbox folders for automatic list import (CR #145)

`php artisan imports:inbox` runs from the scheduler every five minutes (the same `schedule:run` cron line as everything else — nothing to add). For a client whose admin record has **自动导入 → 启用收件夹自动导入** ticked, the sweep creates and reads `storage/app/private/imports/inbox/<client code>/` on the server. Drop the client's CSV / XLSX there (scp, SFTP, a sync tool — the file must be untouched for 60 s before it is read); the sweep moves it to `processed/`, `review/` (pending in the client portal) or `failed/` with a `<file>.result.txt` beside it, and mails the result when the client has a notify / contact email (`MAIL_MAILER=log` on the trial server writes it to the log instead). The folder must stay writable by the PHP-FPM user (`www-data`). Manual run: `php artisan imports:inbox --client=<code> --min-age=0`.

## HTTPS on the trial server (2026-09-24)

The phone camera (scan page, driver photos) needs a secure origin, so the trial server serves **https://103-6-171-144.sslip.io** (sslip.io resolves the name to the IP; no domain purchase). Certificate: Let's Encrypt via `certbot certonly --webroot -w /var/www/erp/public -d 103-6-171-144.sslip.io` (no account email), renewed by `certbot.timer` with a `systemctl reload nginx` deploy hook. nginx: the port-80 block serves `/.well-known/acme-challenge/` from the app's public dir and redirects everything else to the https name (the bare IP has no certificate, so http://103.6.171.144 lands on the name); the 443 block is the old server block plus the certificate lines; the previous http-only config is kept at `/etc/nginx/sites-available/erp.bak-2026-09-24-http`. `.env` `APP_URL=https://103-6-171-144.sslip.io`. On the future server with a real domain: same recipe with that domain, then point `APP_URL` at it.

## Removing a manual test round (trial server only)

`php artisan erp:purge-test-round --jobs=41,42 --imports=9,10` prints what one test round holds (every record hanging off those Jobs and imports); add `--force` to delete it in one transaction. Dump first: `mysqldump --single-transaction --quick -u <user> erp | gzip > /root/backups/erp-<date>.sql.gz` (the password via `MYSQL_PWD`, never on the command line). Find the ids with `Job` numbers on the Job workbench (`JOB-<date>-<n>`) and the portal 已提交清单 (`#<id>`). Never on production data (CR #157).
