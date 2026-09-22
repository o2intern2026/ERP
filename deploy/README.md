# Deploy notes (trial server)

`deploy/deploy-trial.sh` pushes `main` and runs `deploy/server/deploy.sh` on the box (pull, `composer install`, additive `migrate`, rebuild caches). Nothing here resets data.

## Upload limits (recommendation — CR #135, audit TMS-07; not applied by any script)

The driver POD form posts up to 5 photos (≤ 5 MB each after the phone-side downscale to 1600 px JPEG, typically 300–500 KB) plus a PNG signature, and the carrier POD form a PDF / JPG / PNG of up to 10 MB. PHP's defaults (`upload_max_filesize = 2M`, `post_max_size = 8M`) and nginx's default `client_max_body_size 1m` reject such a post with a 413 / an empty `$_FILES` after the receiver has already signed. Set, once, on the server:

| where | setting | value |
|---|---|---|
| PHP-FPM ini (`/etc/php/8.3/fpm/php.ini` or a `conf.d/99-erp.ini`) | `upload_max_filesize` | `8M` |
| PHP-FPM ini | `post_max_size` | `40M` |
| nginx server block for the site | `client_max_body_size` | `40m;` |

Then `systemctl reload php8.3-fpm nginx`. Check with `php -i | grep -E 'upload_max_filesize|post_max_size'` (CLI ini may differ from FPM's — check FPM's) and `nginx -T | grep client_max_body_size`. The application never changes these; `docs/demo-guide.zh.md` §8 carries the same note for the lead.
