# FleetForge nginx Configuration Runbook

Decision: **D202** | Locked: 2026-05-16 | Session: S-NGINX-PROD-CONFIG

## CONTEXT

Production runs **nginx**, not Apache. `.htaccess` files are inert. All routing is via `/etc/nginx/sites-enabled/fleetforge`. FleetForge uses a front-controller pattern — `public/index.php` is the single PHP entry point. nginx must route all non-asset requests to this file via `fastcgi_pass` with `SCRIPT_FILENAME` **hardcoded** to `/var/www/fleetforge/public/index.php`. The router reads `REQUEST_URI` internally and dispatches to the correct file. nginx never resolves individual API or app file paths directly.

## ROOT CAUSE OF ORIGINAL FAILURE (2026-05-16)

The original config had:

```
location ~ \.php$ {
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
}
```

For a request to `/fleetforge/api/v1/payments/index.php` this constructed the path:

```
/var/www/fleetforge/public/fleetforge/api/v1/payments/index.php
```

— which **does not exist**. API files live at `/var/www/fleetforge/api/`, **not** under `public/`. Every AJAX call 404'd. Pages loaded because non-`.php` paths hit `try_files` and fell through to `index.php` correctly.

## WORKING CONFIG

File: `/etc/nginx/sites-enabled/fleetforge`

```nginx
server {
    server_name mainlandrentals.com www.mainlandrentals.com;
    root /var/www/fleetforge/public;
    index index.php;

    location ^~ /fleetforge/assets/ {
        alias /var/www/fleetforge/public/assets/;
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    location / {
        fastcgi_pass  unix:/var/run/php/php8.2-fpm.sock;
        include       fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/fleetforge/public/index.php;
        fastcgi_param SCRIPT_NAME     /fleetforge/index.php;
        fastcgi_param REQUEST_URI     $request_uri;
        fastcgi_param DOCUMENT_ROOT   /var/www/fleetforge/public;
        fastcgi_read_timeout 300;
        fastcgi_buffers      16 16k;
        fastcgi_buffer_size  32k;
    }

    location ~ /\.(?!well-known) { deny all; }
    client_max_body_size 20M;

    listen 443 ssl;
    ssl_certificate     /etc/letsencrypt/live/mainlandrentals.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/mainlandrentals.com/privkey.pem;
    include             /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam         /etc/letsencrypt/ssl-dhparams.pem;
}

server {
    if ($host = www.mainlandrentals.com) { return 301 https://$host$request_uri; }
    if ($host = mainlandrentals.com) { return 301 https://$host$request_uri; }
    listen 80;
    server_name mainlandrentals.com www.mainlandrentals.com;
    return 404;
}
```

## HOW TO UPDATE

1. `sudo nano /etc/nginx/sites-enabled/fleetforge`
2. `sudo nginx -t`  (must say "syntax is ok" — **never skip this**)
3. `sudo systemctl reload nginx`
4. `curl -s -o /dev/null -w "%{http_code}\n" https://mainlandrentals.com/fleetforge/api/v1/health.php`
   Expected: **200**

## POST-CHANGE VERIFICATION

- `health.php` returns **200** with `db:true`
- `/fleetforge/auth/login` returns **200**
- `/fleetforge/dashboard` returns **302**
- `/fleetforge/assets/css/app.css` returns **200**
- `sudo tail -20 /var/log/nginx/error.log` shows no "Primary script unknown"

## ENVIRONMENT

- PHP-FPM socket: `unix:/var/run/php/php8.2-fpm.sock`
- PHP version: **8.2**
