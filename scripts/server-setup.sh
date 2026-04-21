#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════
#  sentinel — production server setup
#  Tested on: Ubuntu 24.04 LTS
#  Run as root: sudo bash scripts/server-setup.sh
# ═══════════════════════════════════════════════════════════════════════

set -euo pipefail

# -----------------------------------------------------------------------
# CONFIG — fill in before running
# -----------------------------------------------------------------------
APP_DOMAIN="3.0.17.28"       # e.g. "dashboard.example.com"
REPO_URL="https://github.com/sufian-azeem/sentinel"
SSL_EMAIL=""        # unused — HTTP only, add SSL later when you have a domain

APP_DIR="/var/www/sentinel"
APP_USER="www-data"

DB_NAME="trading_dashboard"
DB_USER="trading"
# Reuse existing password on re-runs so MySQL and .env stay in sync
if [[ -f "${APP_DIR}/.env" ]] && grep -q "^DB_PASSWORD=.\+" "${APP_DIR}/.env"; then
    DB_PASS="$(grep '^DB_PASSWORD=' "${APP_DIR}/.env" | cut -d'=' -f2)"
else
    DB_PASS="$(openssl rand -base64 20 | tr -d '/+=' | head -c 24)"
fi

WORKER_COUNT=4
APP_TIMEZONE="Asia/Karachi"
APP_TIMEZONE_OFFSET="+05:00"

ADMIN_EMAIL=""      # e.g. "you@example.com"
ADMIN_PASSWORD=""   # choose a strong password
# -----------------------------------------------------------------------

# ── Colours ─────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
info()    { echo -e "${GREEN}[✓]${NC} $1"; }
warn()    { echo -e "${YELLOW}[!]${NC} $1"; }
error()   { echo -e "${RED}[✗]${NC} $1"; exit 1; }
section() { echo -e "\n${CYAN}━━━  $1  ━━━${NC}"; }

# ── Validate config ──────────────────────────────────────────────────────
[[ $EUID -ne 0 ]]       && error "Run with sudo: sudo bash scripts/server-setup.sh"
[[ -z "$APP_DOMAIN" ]]      && error "Set APP_DOMAIN in the CONFIG section."
[[ -z "$REPO_URL" ]]        && error "Set REPO_URL in the CONFIG section."
[[ -z "$ADMIN_EMAIL" ]]     && error "Set ADMIN_EMAIL in the CONFIG section."
[[ -z "$ADMIN_PASSWORD" ]]  && error "Set ADMIN_PASSWORD in the CONFIG section."

# ────────────────────────────────────────────────────────────────────────
section "System update"
# ────────────────────────────────────────────────────────────────────────
apt-get update -qq
apt-get upgrade -y -qq
apt-get install -y -qq \
    curl wget gnupg2 ca-certificates lsb-release \
    unzip zip git supervisor nginx \
    python3 python3-pip \
    software-properties-common
info "Base packages installed"

# ────────────────────────────────────────────────────────────────────────
section "PHP 8.5"
# ────────────────────────────────────────────────────────────────────────
if ! command -v php &>/dev/null; then
    add-apt-repository -y ppa:ondrej/php
    apt-get update -qq
fi

apt-get install -y -qq \
    php8.5-fpm \
    php8.5-cli \
    php8.5-mysql \
    php8.5-mbstring \
    php8.5-xml \
    php8.5-zip \
    php8.5-bcmath \
    php8.5-curl \
    php8.5-gd \
    php8.5-intl \
    php8.5-sqlite3 \
    php8.5-redis

# PHP-FPM tuning for 2GB server
cat > /etc/php/8.5/fpm/pool.d/www.conf <<'PHP_FPM'
[www]
user = www-data
group = www-data
listen = /run/php/php8.5-fpm.sock
listen.owner = www-data
listen.group = www-data

pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4

php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 100M
php_admin_value[post_max_size] = 100M
PHP_FPM

systemctl restart php8.5-fpm
info "PHP 8.5 installed and configured"

# ────────────────────────────────────────────────────────────────────────
section "Composer"
# ────────────────────────────────────────────────────────────────────────
if ! command -v composer &>/dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi
info "Composer installed: $(composer --version --no-ansi 2>&1 | head -1)"

# ────────────────────────────────────────────────────────────────────────
section "Node.js 24"
# ────────────────────────────────────────────────────────────────────────
if ! command -v node &>/dev/null; then
    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key \
        | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg
    echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_24.x nodistro main" \
        > /etc/apt/sources.list.d/nodesource.list
    apt-get update -qq
    apt-get install -y -qq nodejs
fi
info "Node.js installed: $(node --version)"

# ────────────────────────────────────────────────────────────────────────
section "MySQL"
# ────────────────────────────────────────────────────────────────────────
if ! command -v mysql &>/dev/null; then
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq mysql-server
    systemctl enable mysql
    systemctl start mysql
fi

# Tune innodb buffer pool for 2GB server
cat > /etc/mysql/conf.d/trading-tuning.cnf <<'MYSQL_CNF'
[mysqld]
innodb_buffer_pool_size = 256M
innodb_log_file_size    = 64M
max_connections         = 100
MYSQL_CNF

# Allow remote connections
sed -i "s/^bind-address.*=.*/bind-address = 0.0.0.0/" /etc/mysql/mysql.conf.d/mysqld.cnf
sed -i "s/^mysqlx-bind-address.*=.*/mysqlx-bind-address = 0.0.0.0/" /etc/mysql/mysql.conf.d/mysqld.cnf

systemctl restart mysql

# Create database and user (idempotent)
mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
FLUSH PRIVILEGES;
SQL
info "MySQL database '${DB_NAME}' and user '${DB_USER}' ready"

# ────────────────────────────────────────────────────────────────────────
section "Python dependencies"
# ────────────────────────────────────────────────────────────────────────
apt-get install -y -qq python3-venv

VENV_DIR="/opt/sentinel-venv"
python3 -m venv "$VENV_DIR"
"$VENV_DIR/bin/pip" install --quiet --no-cache-dir \
    pymysql \
    python-dotenv \
    pandas \
    numpy \
    ccxt \
    ta \
    pydantic \
    pydantic-settings \
    apscheduler \
    pyarrow \
    anthropic
info "Python venv created at ${VENV_DIR}"

# ────────────────────────────────────────────────────────────────────────
section "Application"
# ────────────────────────────────────────────────────────────────────────
git config --global --add safe.directory "$APP_DIR"

if [[ ! -d "$APP_DIR/.git" ]]; then
    git clone "$REPO_URL" "$APP_DIR"
else
    warn "Repo already exists at $APP_DIR — pulling latest"
    git -C "$APP_DIR" pull
fi

# .env setup
if [[ ! -f "$APP_DIR/.env" ]]; then
    cp "$APP_DIR/.env.example" "$APP_DIR/.env"
    sed -i "s|APP_URL=.*|APP_URL=http://${APP_DOMAIN}|"       "$APP_DIR/.env"
    sed -i "s|APP_ENV=.*|APP_ENV=production|"                  "$APP_DIR/.env"
    sed -i "s|APP_DEBUG=.*|APP_DEBUG=false|"                   "$APP_DIR/.env"
    sed -i "s|APP_TIMEZONE_OFFSET=.*|APP_TIMEZONE_OFFSET=${APP_TIMEZONE_OFFSET}|" "$APP_DIR/.env"
    sed -i "s|DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|"          "$APP_DIR/.env"
    sed -i "s|DB_USERNAME=.*|DB_USERNAME=${DB_USER}|"          "$APP_DIR/.env"
    sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|"          "$APP_DIR/.env"
    sed -i "s|QUEUE_CONNECTION=.*|QUEUE_CONNECTION=database|"  "$APP_DIR/.env"
    sed -i "s|CACHE_STORE=.*|CACHE_STORE=database|"            "$APP_DIR/.env"
    sed -i "s|SESSION_DRIVER=.*|SESSION_DRIVER=database|"      "$APP_DIR/.env"
    echo "ADMIN_EMAIL=${ADMIN_EMAIL}"                        >> "$APP_DIR/.env"
    echo "ADMIN_PASSWORD=${ADMIN_PASSWORD}"                  >> "$APP_DIR/.env"
    info ".env created"
fi

# Set correct app timezone in config/app.php
sed -i "s|'timezone' => '.*'|'timezone' => '${APP_TIMEZONE}'|" "$APP_DIR/config/app.php" || true

# Give www-data ownership before composer/npm so they can write vendor/ and node_modules/
chown -R "$APP_USER":"$APP_USER" "$APP_DIR"

# Composer install (production — no dev deps)
cd "$APP_DIR"
sudo -u "$APP_USER" composer install --no-dev --optimize-autoloader --quiet

# Node build
npm ci --prefix "$APP_DIR" --silent
npm run build --prefix "$APP_DIR"

# Generate app key if not set
php artisan key:generate --force --quiet

# Run migrations and seed admin user
php artisan migrate --force --quiet
php artisan db:seed --force --quiet
info "Migrations and seed complete"

# Cache config/routes/views for production
php artisan config:cache
php artisan route:cache
php artisan view:cache
info "Application caches warmed"

# ────────────────────────────────────────────────────────────────────────
section "File permissions"
# ────────────────────────────────────────────────────────────────────────
chown -R "$APP_USER":"$APP_USER" "$APP_DIR"
find "$APP_DIR" -type f -exec chmod 644 {} \;
find "$APP_DIR" -type d -exec chmod 755 {} \;
chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
chmod +x "$APP_DIR/artisan"
info "Permissions set"

# ────────────────────────────────────────────────────────────────────────
section "Nginx"
# ────────────────────────────────────────────────────────────────────────
cat > /etc/nginx/sites-available/sentinel <<NGINX
server {
    listen 80;
    server_name ${APP_DOMAIN};

    root ${APP_DIR}/public;
    index index.php;

    charset utf-8;
    client_max_body_size 100M;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    access_log /var/log/nginx/sentinel.access.log;
    error_log  /var/log/nginx/sentinel.error.log;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX

ln -sf /etc/nginx/sites-available/sentinel /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default

nginx -t && systemctl reload nginx
info "Nginx configured for ${APP_DOMAIN}"

# ────────────────────────────────────────────────────────────────────────
section "Supervisor (workers + scheduler)"
# ────────────────────────────────────────────────────────────────────────
cat > /etc/supervisor/conf.d/sentinel.conf <<SUPERVISOR
[program:worker]
command=php ${APP_DIR}/artisan queue:work --sleep=3 --tries=3 --timeout=300
directory=${APP_DIR}
user=${APP_USER}
environment=PATH="/opt/sentinel-venv/bin:%(ENV_PATH)s"
numprocs=${WORKER_COUNT}
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopwaitsecs=300
stdout_logfile=${APP_DIR}/storage/logs/worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
stderr_logfile=${APP_DIR}/storage/logs/worker.log
stderr_logfile_maxbytes=0

[program:health-worker]
command=php ${APP_DIR}/artisan queue:work --queue=health --sleep=3 --tries=3 --timeout=30
directory=${APP_DIR}
user=${APP_USER}
environment=PATH="/opt/sentinel-venv/bin:%(ENV_PATH)s"
autostart=true
autorestart=true
stdout_logfile=${APP_DIR}/storage/logs/worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
stderr_logfile=${APP_DIR}/storage/logs/worker.log
stderr_logfile_maxbytes=0

[program:scheduler]
command=php ${APP_DIR}/artisan schedule:work
directory=${APP_DIR}
user=${APP_USER}
environment=PATH="/opt/sentinel-venv/bin:%(ENV_PATH)s"
autostart=true
autorestart=true
stdout_logfile=${APP_DIR}/storage/logs/scheduler.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
stderr_logfile=${APP_DIR}/storage/logs/scheduler.log
stderr_logfile_maxbytes=0

[program:pulse]
command=php ${APP_DIR}/artisan pulse:check
directory=${APP_DIR}
user=${APP_USER}
autostart=true
autorestart=true
stdout_logfile=${APP_DIR}/storage/logs/pulse.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
stderr_logfile=${APP_DIR}/storage/logs/pulse.log
stderr_logfile_maxbytes=0
SUPERVISOR

supervisorctl reread
supervisorctl update
supervisorctl start all
info "Supervisor programs started"

# ────────────────────────────────────────────────────────────────────────
section "Logrotate"
# ────────────────────────────────────────────────────────────────────────
cat > /etc/logrotate.d/sentinel <<'LOGROTATE'
/var/www/sentinel/storage/logs/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 664 www-data www-data
    sharedscripts
    postrotate
        supervisorctl restart worker:* health-worker scheduler pulse > /dev/null 2>&1 || true
    endscript
}
LOGROTATE

# ────────────────────────────────────────────────────────────────────────
section "Done"
# ────────────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}════════════════════════════════════════════${NC}"
echo -e "${GREEN}  Setup complete!${NC}"
echo -e "${GREEN}════════════════════════════════════════════${NC}"
echo ""
echo -e "  App URL    : ${CYAN}http://${APP_DOMAIN}${NC}"
echo -e "  App dir    : ${CYAN}${APP_DIR}${NC}"
echo -e "  DB name    : ${CYAN}${DB_NAME}${NC}"
echo -e "  DB user    : ${CYAN}${DB_USER}${NC}"
echo -e "  DB pass    : ${YELLOW}${DB_PASS}${NC}  ← save this!"
echo ""
echo -e "${YELLOW}Next steps:${NC}"
echo "  1. Edit ${APP_DIR}/.env and fill in:"
echo "     - DISCORD_WEBHOOK_URL"
echo "     - Any other service-specific keys"
echo "  2. Reload config cache after editing .env:"
echo "     cd ${APP_DIR} && php artisan config:cache"
echo "  3. Check worker status:"
echo "     supervisorctl status"
echo "  4. When you have a domain, add SSL with:"
echo "     apt install certbot python3-certbot-nginx"
echo "     certbot --nginx -d yourdomain.com"
echo ""
