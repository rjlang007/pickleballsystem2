#!/bin/bash
set -e

mkdir -p /app/uploads/activity_photos /app/logs /app/storage
chmod -R 775 /app/uploads /app/logs /app/storage

mkdir -p /tmp/php-fpm.d

cat > /tmp/php-fpm.conf << FPMCONF
[global]
daemonize = yes
error_log = /dev/stderr

[www]
user = www-data
group = www-data
listen = 127.0.0.1:9000
pm = dynamic
pm.max_children = 5
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
FPMCONF

# PHP-FPM rejects empty env directives. Only forward variables that are set.
for fpm_var in PGHOST PGPORT PGDATABASE PGUSER PGPASSWORD DATABASE_URL APP_ENV APP_URL APP_NAME; do
    if [ -n "${!fpm_var:-}" ]; then
        printf 'env[%s] = %s\n' "$fpm_var" "${!fpm_var}" >> /tmp/php-fpm.conf
    fi
done

# ── Find php-fpm binary ──────────────────────────────────────
PHP_FPM_BIN=""
for candidate in \
    /usr/local/sbin/php-fpm \
    /usr/sbin/php-fpm \
    /usr/local/bin/php-fpm \
    /usr/bin/php-fpm; do
    if [ -x "$candidate" ]; then
        PHP_FPM_BIN="$candidate"
        break
    fi
done

if [ -z "$PHP_FPM_BIN" ]; then
    for candidate in php-fpm php-fpm8 php-fpm82 php-fpm8.2 php-fpm8.3 php-fpm8.1; do
        if command -v "$candidate" >/dev/null 2>&1; then
            PHP_FPM_BIN=$(command -v "$candidate")
            break
        fi
    done
fi

if [ -z "$PHP_FPM_BIN" ]; then
    echo "ERROR: php-fpm not found. Searched paths:" >&2
    find / -name "php-fpm*" -type f 2>/dev/null >&2
    exit 1
fi

echo "Using php-fpm: $PHP_FPM_BIN"
$PHP_FPM_BIN -y /tmp/php-fpm.conf -D
sleep 2

echo "Using FastCGI: 127.0.0.1:9000"

# ── Fix nginx tmp directories (must be writable by nginx worker) ──
mkdir -p /tmp/nginx_client_body \
         /tmp/nginx_fastcgi \
         /tmp/nginx_proxy \
         /tmp/nginx_scgi \
         /tmp/nginx_uwsgi
chmod 777 /tmp/nginx_client_body \
          /tmp/nginx_fastcgi \
          /tmp/nginx_proxy \
          /tmp/nginx_scgi \
          /tmp/nginx_uwsgi

# ── Find mime.types ──────────────────────────────────────────
MIME_TYPES=$(find /nix /usr /etc -name "mime.types" 2>/dev/null | grep -i nginx | head -1)
if [ -z "$MIME_TYPES" ]; then
    MIME_TYPES=$(find /nix /usr /etc -name "mime.types" 2>/dev/null | head -1)
fi
if [ -z "$MIME_TYPES" ]; then
    cat > /tmp/mime.types << 'MIME'
types {
    text/html                             html htm;
    text/css                              css;
    text/javascript                       js;
    application/javascript                mjs;
    application/json                      json;
    image/png                             png;
    image/jpeg                            jpg jpeg;
    image/gif                             gif;
    image/svg+xml                         svg;
    image/x-icon                          ico;
    image/webp                            webp;
    font/woff                             woff;
    font/woff2                            woff2;
    application/octet-stream              bin;
}
MIME
    MIME_TYPES=/tmp/mime.types
fi

echo "Using mime.types: $MIME_TYPES"

cat > /tmp/fastcgi_params << 'FCGI'
fastcgi_param QUERY_STRING       $query_string;
fastcgi_param REQUEST_METHOD     $request_method;
fastcgi_param CONTENT_TYPE       $content_type;
fastcgi_param CONTENT_LENGTH     $content_length;
fastcgi_param HTTP_COOKIE        $http_cookie;
fastcgi_param HTTP_AUTHORIZATION $http_authorization;
fastcgi_param HTTP_X_FORWARDED_PROTO $http_x_forwarded_proto;
fastcgi_param HTTP_X_FORWARDED_HOST  $http_x_forwarded_host;
fastcgi_param SCRIPT_NAME        $fastcgi_script_name;
fastcgi_param REQUEST_URI        $request_uri;
fastcgi_param DOCUMENT_URI       $document_uri;
fastcgi_param DOCUMENT_ROOT      $document_root;
fastcgi_param SERVER_PROTOCOL    $server_protocol;
fastcgi_param GATEWAY_INTERFACE  CGI/1.1;
fastcgi_param SERVER_SOFTWARE    nginx;
fastcgi_param REMOTE_ADDR        $remote_addr;
fastcgi_param REMOTE_PORT        $remote_port;
fastcgi_param SERVER_ADDR        $server_addr;
fastcgi_param SERVER_PORT        $server_port;
fastcgi_param SERVER_NAME        $server_name;
fastcgi_param REDIRECT_STATUS    200;
FCGI

cat > /tmp/nginx.conf << EOF
worker_processes auto;
error_log /dev/stderr warn;
pid /tmp/nginx.pid;

events { worker_connections 1024; }

http {
    include ${MIME_TYPES};
    default_type application/octet-stream;
    access_log /dev/stdout;
    sendfile on;

    # ── All temp paths → /tmp (always writable on Railway) ────────────
    client_body_temp_path /tmp/nginx_client_body;
    fastcgi_temp_path     /tmp/nginx_fastcgi;
    proxy_temp_path       /tmp/nginx_proxy;
    scgi_temp_path        /tmp/nginx_scgi;
    uwsgi_temp_path       /tmp/nginx_uwsgi;

    # ── Upload / POST body limits ──────────────────────────────────────
    # client_body_buffer_size: if POST body fits in this buffer it never
    # needs to write to client_body_temp_path at all.
    # 30M covers payment proof image uploads.
    client_max_body_size    30M;
    client_body_buffer_size 30M;

    # ── FastCGI response buffers ───────────────────────────────────────
    # schedule.php returns ~537 KB. If the response fits in these buffers
    # nginx never writes to fastcgi_temp_path.
    # 32 × 32k = 1 MB buffer — enough for any page in this app.
    fastcgi_buffer_size       128k;
    fastcgi_buffers           32 32k;
    fastcgi_busy_buffers_size 256k;
    # Raise the temp file limit just in case a response exceeds buffers
    fastcgi_temp_file_write_size 256k;
    fastcgi_max_temp_file_size   0;    # 0 = no limit, use temp file freely

    server {
        listen ${PORT:-8080};
        root /app;
        index public/index.php index.php index.html;

        # ── Front page (public/index.php) ──────────────────────────────
        location = / {
            fastcgi_pass 127.0.0.1:9000;
            include /tmp/fastcgi_params;
            fastcgi_param SCRIPT_FILENAME /app/public/index.php;
            fastcgi_param SCRIPT_NAME     /public/index.php;
            fastcgi_param PATH_INFO       "";
            fastcgi_read_timeout 120;
        }

        location = /index.php {
            fastcgi_pass 127.0.0.1:9000;
            include /tmp/fastcgi_params;
            fastcgi_param SCRIPT_FILENAME /app/public/index.php;
            fastcgi_param SCRIPT_NAME     /public/index.php;
            fastcgi_param PATH_INFO       "";
            fastcgi_read_timeout 120;
        }
        # ── Support subdirectory deployment path /pickleball ──────────────
        location = /pickleball {
            return 301 /pickleball/;
        }

        location /pickleball/ {
            rewrite ^/pickleball(/.*)$ $1 last;
        }
        # ── Static assets under /public/ ───────────────────────────────
        location /public/ {
            try_files \$uri =404;
            expires 1y;
            access_log off;
        }

        # ── Block sensitive directories ─────────────────────────────────
        location ~* ^/(config|includes|storage|\.git)/ {
            deny all;
            return 403;
        }

        # ── All PHP files ───────────────────────────────────────────────
        location ~ [^/]\.php(/|$) {
            fastcgi_split_path_info ^(.+?\.php)(/.*)$;
            fastcgi_pass 127.0.0.1:9000;
            fastcgi_index index.php;
            include /tmp/fastcgi_params;
            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
            fastcgi_param PATH_INFO       \$fastcgi_path_info;
            fastcgi_read_timeout 120;
        }

        # ── Custom error pages ──────────────────────────────────────────
        error_page 404 /public/errors/404.html;
        error_page 500 502 503 504 /public/errors/500.html;

        location ^~ /public/errors/ {
            internal;
            try_files \$uri =404;
        }

        location ~ /\. { deny all; }
    }
}
EOF

exec nginx -c /tmp/nginx.conf -g 'daemon off;'