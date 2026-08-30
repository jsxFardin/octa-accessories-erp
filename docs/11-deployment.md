# 11 — Production Deployment

Runbook for putting the ERP on a VPS behind nginx and TLS. Written against the live target below;
the shape holds for any single-company deployment, only the names change.

Every step is idempotent and every command is safe to re-run. `nginx -t` is the gate: a failed
test leaves the running config untouched, so nothing breaks until it passes.

---

## 1. Target environment

| Item | Value |
|---|---|
| Hostname | `accessories.octapussolution.com` |
| App root | `/var/www/octa-accessories-erp` (document root `public/`) |
| VPS IPv4 | `200.141.2.57` |
| VPS IPv6 | `2a02:4780:63:e4a3::1` — no `AAAA` record for the subdomain |
| nginx | 1.24 |
| PHP | 8.4 FPM, `unix:/run/php/php8.4-fpm.sock` |
| Database | MySQL 8, localhost |
| Cache / queue | Redis 7, localhost |

Two traps this environment has already sprung, both worth reading before you type anything:

**The domain has a look-alike spelling.** The zone is `octap`**`us`**`solution.com`. The
"octapsolution" spelling resolves to nothing at all — a certificate request against it fails after
you have already rewritten the vhost. Confirm with `dig` before certbot, not after:

```bash
dig +short A accessories.octapussolution.com     # must equal the VPS IPv4
curl -s4 ifconfig.me; echo
```

**nginx 1.24 has no `http2` directive.** `http2 on;` is 1.25.1+ and fails the config test with
`unknown directive "http2"`. On 1.24 it is a `listen` parameter. Here it is omitted entirely,
because other vhosts on this box (`sites-enabled/default`, `sites-enabled/octapussolution.com`)
already enable it on the same 443 socket — protocol options are per-socket and the first server
block wins, so repeating it only produces `protocol options redefined` warnings.

If you add an `AAAA` record later, re-run certbot afterwards so ACME validates over both families.

---

## 2. Prerequisites

- DNS `A` record for the subdomain pointing at the VPS, TTL elapsed (`dig` above).
- MySQL 8 database and user created; credentials in `.env`.
- Redis reachable on `127.0.0.1:6379`.
- `php8.4-fpm`, `nginx`, `certbot` installed; code deployed at `/var/www/octa-accessories-erp`
  with `composer install --no-dev --optimize-autoloader` already run.
- Firewall: `sudo ufw allow 80,443/tcp`. MySQL and Redis must **not** be exposed — check
  `ss -tlnp | grep -E '3306|6379'` shows `127.0.0.1` only.

---

## 3. Step 1 — HTTP-only vhost, then the certificate

nginx refuses to load an `ssl_certificate` that does not exist yet
(`[emerg] cannot load certificate ... fullchain.pem`), and certbot cannot validate a domain nginx
does not serve. Break the cycle by serving plain HTTP first.

```bash
cd /etc/nginx/sites-available
sudo nano accessories.octapussolution.com
```

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name accessories.octapussolution.com;

    root /var/www/octa-accessories-erp/public;
    index index.php;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ ^/index\.php(/|$) {
        include snippets/fastcgi-php.conf;
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
}
```

```bash
sudo ln -sf ../sites-available/accessories.octapussolution.com /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
curl -sI http://accessories.octapussolution.com    # must answer here, not from the default vhost

sudo certbot certonly --webroot -w /var/www/octa-accessories-erp/public \
     -d accessories.octapussolution.com

ls /etc/letsencrypt/live/accessories.octapussolution.com/
systemctl status certbot.timer                     # renewal is a timer, confirm it is active
```

Use `certonly`, not `--nginx`: certbot issues the certificate and leaves the config alone, so
step 2 can replace the file wholesale without losing anything certbot wrote.

---

## 4. Step 2 — The production vhost

Replace `/etc/nginx/sites-available/accessories.octapussolution.com` with this in full.

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name accessories.octapussolution.com;

    # Must match the certbot -w path above, or `certbot renew` stops working.
    location ^~ /.well-known/acme-challenge/ { root /var/www/octa-accessories-erp/public; }
    location / { return 301 https://$host$request_uri; }
}

server {
    # nginx 1.24: http2 is a listen parameter, not a directive — and it is already enabled on
    # this socket by another vhost, so setting it again would only warn.
    listen 443 ssl;
    listen [::]:443 ssl;

    server_name accessories.octapussolution.com;

    ssl_certificate     /etc/letsencrypt/live/accessories.octapussolution.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/accessories.octapussolution.com/privkey.pem;
    include             /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam         /etc/letsencrypt/ssl-dhparams.pem;

    root /var/www/octa-accessories-erp/public;
    index index.php;

    charset utf-8;
    server_tokens off;

    # Artwork uploads validate at max:51200 (50 MB) plus multipart overhead. A 25M limit rejects
    # them with a 413 before Laravel ever runs, and artwork approval is gate 1 of the workflow.
    client_max_body_size 64M;
    client_body_timeout  300s;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options    "nosniff" always;
    add_header X-Frame-Options           "DENY" always;
    add_header Referrer-Policy           "strict-origin-when-cross-origin" always;

    gzip            on;
    gzip_vary       on;
    gzip_proxied    any;
    gzip_comp_level 5;
    gzip_min_length 1024;
    gzip_types      text/plain text/css application/json application/javascript
                    text/javascript image/svg+xml application/manifest+json;

    access_log /var/log/nginx/accessories.access.log;
    error_log  /var/log/nginx/accessories.error.log;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Vite emits content-hashed filenames, so these can never go stale.
    location ^~ /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
        try_files $uri =404;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /favicon.svg { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    # Only index.php reaches FPM. A broad `location ~ \.php$` would execute any .php file under
    # public/ — including anything reachable through the public/storage symlink.
    location ~ ^/index\.php(/|$) {
        include snippets/fastcgi-php.conf;
        include fastcgi_params;          # BEFORE the overrides: it is what defines SCRIPT_FILENAME

        fastcgi_pass unix:/run/php/php8.4-fpm.sock;

        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT   $realpath_root;
        fastcgi_param HTTP_PROXY      "";     # httpoxy

        fastcgi_buffer_size           128k;
        fastcgi_buffers            16 128k;
        fastcgi_busy_buffers_size     256k;
        fastcgi_temp_file_write_size  256k;

        # NFR-5 allows three minutes for a 100k-row export.
        fastcgi_connect_timeout 300;
        fastcgi_send_timeout    300;
        fastcgi_read_timeout    300;
    }

    location ~ \.php$ { return 404; }

    location ~ /\.(?!well-known).* { deny all; }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

Two details in there are easy to get backwards:

**`include fastcgi_params;` goes before the `$realpath_root` lines.** Ubuntu's
`snippets/fastcgi-php.conf` does *not* include `fastcgi_params`, so the include is required — but
`fastcgi_params` defines `SCRIPT_FILENAME` as `$document_root$fastcgi_script_name`. Included last,
it silently overwrites the `$realpath_root` values above it and the symlink-safe intent is lost.

**`location ~ \.php$ { return 404; }` is the backstop.** The `^/index\.php` location handles the
framework; this one guarantees that no other `.php` path can ever be handed to FPM, whatever ends
up on disk under `public/`.

---

## 5. Step 3 — php-fpm limits

The nginx body limit is moot on its own: PHP ships with `upload_max_filesize = 2M` and
`post_max_size = 8M`, which rejects both a 50 MB artwork file and the 10 MB spreadsheet import.

`/etc/php/8.4/fpm/conf.d/99-octa.ini`:

```ini
upload_max_filesize = 64M
post_max_size = 64M
memory_limit = 512M
max_execution_time = 300
max_input_time = 300
expose_php = Off

opcache.enable = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0
```

```bash
sudo systemctl reload php8.4-fpm
```

The three limits must stay ordered: nginx `client_max_body_size` (64M) ≥ php `post_max_size` (64M)
≥ the application's own `max:51200` rule (50 MB). Raise the app rule and both of these follow.

> **`opcache.validate_timestamps = 0` means PHP never re-reads changed files.** Every deploy must
> end with `sudo systemctl reload php8.4-fpm`, or the old code keeps serving with no error
> anywhere. This is the single most common "I deployed but nothing changed" cause.

Size the pool in `/etc/php/8.4/fpm/pool.d/www.conf` against the ~60 concurrent internal users in
[09-nfr](09-nfr.md): `pm = dynamic`, `pm.max_children ≈ (RAM available to PHP) / 80MB`.

---

## 6. Step 4 — Application environment

In `/var/www/octa-accessories-erp/.env`:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://accessories.octapussolution.com

SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SESSION_LIFETIME=480
SESSION_DOMAIN=null

LOG_LEVEL=warning
```

`SESSION_SECURE_COOKIE=true` is safe only once 443 answers — set it after step 2, or nobody can
log in. `SESSION_LIFETIME=480` is the 8-hour internal idle timeout from NFR-26.
`SESSION_DOMAIN=null` keeps the cookie host-only so it is never sent to the other
`*.octapussolution.com` vhosts sharing this box.

```bash
cd /var/www/octa-accessories-erp
npm ci && npm run build
php artisan migrate --force
php artisan optimize            # config, route and view caches
sudo systemctl reload php8.4-fpm
```

`php artisan optimize` caches the config, so **any later `.env` edit needs `php artisan
optimize` again** to take effect.

---

## 7. Step 5 — Filesystem and the queue worker

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
php artisan storage:link        # a symlink, not tracked in git — it will not exist on a fresh clone
```

Every directory from `/var/www` down must be traversable (`+x`) by `www-data`.

`QUEUE_CONNECTION=redis`, and notifications are queued. **With no worker running they are written
and never executed — no error, no log line, the in-app inbox simply stays empty.** Run one under
systemd, `/etc/systemd/system/octa-queue.service`:

```ini
[Unit]
Description=Octa ERP queue worker
After=network.target redis-server.service mysql.service

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=5
WorkingDirectory=/var/www/octa-accessories-erp
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now octa-queue
php artisan queue:health        # exits non-zero when nothing is draining the queue
```

Workers hold code in memory, so every deploy also needs `sudo systemctl restart octa-queue`.

---

## 8. Verification

```bash
curl -sI http://accessories.octapussolution.com                 # 301 → https
curl -sI https://accessories.octapussolution.com/up             # 200, HSTS, nosniff
curl -sI https://accessories.octapussolution.com/build/<asset>  # Cache-Control: public, immutable

curl -s -H 'Accept-Encoding: gzip' -o /dev/null -w '%{size_download}\n' \
     https://accessories.octapussolution.com/                   # smaller than without the header

# The 413 regression: a 40 MB body must reach PHP, not be cut off by nginx.
head -c 40M /dev/urandom > /tmp/big.psd
curl -s -o /dev/null -w '%{http_code}\n' -F file=@/tmp/big.psd \
     https://accessories.octapussolution.com/artworks/1/versions   # 302/419/422 — never 413

# Nothing under public/ executes except index.php.
curl -s -o /dev/null -w '%{http_code}\n' \
     https://accessories.octapussolution.com/storage/x.php      # 404

sudo certbot renew --dry-run
php artisan queue:health && echo "worker draining"
```

Then a browser pass, which catches what curl cannot: sign in over HTTPS and confirm the session
cookie carries `Secure` and `HttpOnly`; upload one artwork file larger than 25 MB end to end; run
one spreadsheet import; download one CSV export. Finish by reading
`/var/log/nginx/accessories.error.log` and `storage/logs/laravel.log` — both should be quiet.

---

## 9. Rollback

Copy the vhost before editing it (`sudo cp accessories.octapussolution.com{,.bak}`). `nginx -t`
before every reload; a failing test cannot take the site down, because nginx keeps serving the
last good config. If TLS misbehaves after step 2, restore the step 1 HTTP-only vhost and reload —
the certificate stays issued and step 2 can be retried at any time.

Application rollback is `git checkout <previous-tag> && composer install --no-dev && npm run build
&& php artisan optimize`, followed by the two reloads that every deploy needs:

```bash
sudo systemctl reload php8.4-fpm
sudo systemctl restart octa-queue
```

Migrations are additive by policy (NFR-11), so a code rollback does not require a schema rollback.

---

## 10. Still outstanding

Deliberately out of scope here, but not optional before the system carries real data — see
[09-nfr §6](09-nfr.md):

- **Backups.** NFR-38 wants RPO ≤ 15 minutes (binary log shipping off-site) and NFR-40 a nightly
  `mysqldump --single-transaction`, 30 days daily / 12 months monthly. NFR-42 is the one that
  matters: rehearse the restore, quarterly, in writing.
- **Artwork storage snapshots.** Files live on the private `local` disk under `storage/app/private`
  and are not in the database dump. Snapshot daily, retain 90 days (NFR-41).
- **Monitoring.** `/up` is a health endpoint; point an uptime check at it, and alert on
  `queue:health` exiting non-zero.
- **Log rotation** for `storage/logs` and the per-site nginx logs.
