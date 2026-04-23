#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════
#  sentinel — production server setup (Apache + RDS coexistence mode)
#
#  Existing server: Apache + PHP 8.1 (payment portal)
#  This script:
#    - Installs PHP 8.4 FPM (used by both payment portal and sentinel)
#    - Serves sentinel on port 8080 (separate vhost, no path conflicts)
#    - Uses AWS RDS — no local MySQL needed
#
#  Tested on: Ubuntu 22.04 / 24.04 LTS
#  Run as root: sudo bash scripts/server-setup-apache.sh
# ═══════════════════════════════════════════════════════════════════════

set -euo pipefail

# -----------------------------------------------------------------------
# CONFIG — fill in before running
# -----------------------------------------------------------------------
APP_DOMAIN="payment-portal.amaxlab.com"
APP_PORT=8080
REPO_SSH="git@github.com:sufian-azeem/sentinel.git"

APP_DIR="/var/www/sentinel"
PAYMENT_PORTAL_DIR="/var/www/paypment-portal-testing"
APP_USER="ubuntu"
WEB_GROUP="www-data"

# RDS credentials — create the database on RDS before running this script
DB_HOST=""          # e.g. "xxx.us-east-1.rds.amazonaws.com"
DB_NAME=""          # e.g. "trading_dashboard"
DB_USER=""          # RDS username
DB_PASS=""          # RDS password

WORKER_COUNT=2
APP_TIMEZONE="Asia/Karachi"
APP_TIMEZONE_OFFSET="+05:00"

ADMIN_EMAIL=""
ADMIN_PASSWORD=""
# -----------------------------------------------------------------------

# ── Colours ─────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
info()    { echo -e "${GREEN}[✓]${NC} $1"; }
warn()    { echo -e "${YELLOW}[!]${NC} $1"; }
error()   { echo -e "${RED}[✗]${NC} $1"; exit 1; }
section() { echo -e "\n${CYAN}━━━  $1  ━━━${NC}"; }

# ── Validate config ──────────────────────────────────────────────────────
[[ $EUID -ne 0 ]]          && error "Run with sudo: sudo bash scripts/server-setup-apache.sh"
[[ -z "$APP_DOMAIN" ]]     && error "Set APP_DOMAIN in the CONFIG section."
[[ -z "$DB_HOST" ]]        && error "Set DB_HOST (RDS endpoint) in the CONFIG section."
[[ -z "$DB_NAME" ]]        && error "Set DB_NAME in the CONFIG section."
[[ -z "$DB_USER" ]]        && error "Set DB_USER in the CONFIG section."
[[ -z "$DB_PASS" ]]        && error "Set DB_PASS in the CONFIG section."
[[ -z "$ADMIN_EMAIL" ]]    && error "Set ADMIN_EMAIL in the CONFIG section."
[[ -z "$ADMIN_PASSWORD" ]] && error "Set ADMIN_PASSWORD in the CONFIG section."

# ────────────────────────────────────────────────────────────────────────
section "Swap (1GB)"
# ────────────────────────────────────────────────────────────────────────
if [[ ! -f /swapfile ]]; then
    fallocate -l 1G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
    info "1GB swap created"
else
    warn "Swap already exists — skipping"
fi

# ────────────────────────────────────────────────────────────────────────
section "System packages"
# ────────────────────────────────────────────────────────────────────────
apt-get update -qq
apt-get install -y -qq \
    curl wget gnupg2 ca-certificates lsb-release \
    unzip zip git supervisor \
    python3 python3-pip python3-venv \
    software-properties-common
info "Base packages installed"

# ────────────────────────────────────────────────────────────────────────
section "PHP 8.4 FPM (shared by both sites)"
# ────────────────────────────────────────────────────────────────────────
if ! grep -rq "ondrej/php" /etc/apt/sources.list.d/ 2>/dev/null; then
    add-apt-repository -y ppa:ondrej/php
    apt-get update -qq
fi

apt-get install -y -qq \
    php8.4-fpm \
    php8.4-cli \
    php8.4-mysql \
    php8.4-mbstring \
    php8.4-xml \
    php8.4-zip \
    php8.4-bcmath \
    php8.4-curl \
    php8.4-gd \
    php8.4-intl \
    php8.4-sqlite3 \
    php8.4-redis

cat > /etc/php/8.4/fpm/pool.d/www.conf <<'PHP_FPM'
[www]
user = www-data
group = www-data
listen = /run/php/php8.4-fpm.sock
listen.owner = www-data
listen.group = www-data

pm = dynamic
pm.max_children = 15
pm.start_servers = 3
pm.min_spare_servers = 2
pm.max_spare_servers = 6

php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 100M
php_admin_value[post_max_size] = 100M
PHP_FPM

systemctl restart php8.4-fpm

# Set php8.4 as the default php CLI
update-alternatives --install /usr/bin/php php /usr/bin/php8.4 84
update-alternatives --set php /usr/bin/php8.4
info "PHP 8.4 FPM installed and set as default CLI (socket: /run/php/php8.4-fpm.sock)"

# ────────────────────────────────────────────────────────────────────────
section "Composer"
# ────────────────────────────────────────────────────────────────────────
if ! command -v composer &>/dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi
info "Composer: $(composer --version --no-ansi 2>&1 | head -1)"

# ────────────────────────────────────────────────────────────────────────
section "Node.js 24"
# ────────────────────────────────────────────────────────────────────────
curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key \
    | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg
echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_24.x nodistro main" \
    > /etc/apt/sources.list.d/nodesource.list
apt-get update -qq
apt-get install -y -qq nodejs
info "Node.js: $(node --version)"

# ────────────────────────────────────────────────────────────────────────
section "Python dependencies"
# ────────────────────────────────────────────────────────────────────────
VENV_DIR="/opt/sentinel-venv"
if [[ ! -d "$VENV_DIR" ]]; then
    python3 -m venv "$VENV_DIR"
fi
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
info "Python venv ready at ${VENV_DIR}"

# ────────────────────────────────────────────────────────────────────────
section "Payment portal — update composer deps for PHP 8.4"
# ────────────────────────────────────────────────────────────────────────
if [[ -f "$PAYMENT_PORTAL_DIR/composer.json" ]]; then
    chown -R "$APP_USER":"$WEB_GROUP" "$PAYMENT_PORTAL_DIR"
    cd "$PAYMENT_PORTAL_DIR"
    sudo -u "$APP_USER" php /usr/local/bin/composer install --no-dev --optimize-autoloader --quiet \
        && info "Payment portal composer deps updated for PHP 8.4" \
        || warn "composer install for payment portal failed — check manually"
fi

# ────────────────────────────────────────────────────────────────────────
section "GitHub deploy key"
# ────────────────────────────────────────────────────────────────────────
KEY_FILE="/root/.ssh/sentinel_deploy"
if [[ ! -f "$KEY_FILE" ]]; then
    ssh-keygen -t ed25519 -C "sentinel-deploy" -f "$KEY_FILE" -N ""
    info "Deploy key generated at ${KEY_FILE}"
fi

# Trust github.com
if ! grep -q "github.com" /root/.ssh/known_hosts 2>/dev/null; then
    ssh-keyscan -t ed25519 github.com >> /root/.ssh/known_hosts 2>/dev/null
fi

# Configure SSH to use this key for github.com
if ! grep -q "sentinel_deploy" /root/.ssh/config 2>/dev/null; then
    cat >> /root/.ssh/config <<SSH_CFG

Host github.com
    IdentityFile ${KEY_FILE}
    IdentitiesOnly yes
SSH_CFG
fi

echo ""
echo -e "${YELLOW}══════════════════════════════════════════════════════${NC}"
echo -e "${YELLOW}  ACTION REQUIRED — add this deploy key to GitHub:${NC}"
echo -e "${YELLOW}  https://github.com/sufian-azeem/sentinel/settings/keys${NC}"
echo -e "${YELLOW}══════════════════════════════════════════════════════${NC}"
echo ""
cat "${KEY_FILE}.pub"
echo ""
read -rp "Press ENTER after adding the deploy key to GitHub..."

# ────────────────────────────────────────────────────────────────────────
section "Sentinel application"
# ────────────────────────────────────────────────────────────────────────
git config --global --add safe.directory "$APP_DIR"

if [[ ! -d "$APP_DIR/.git" ]]; then
    git clone "$REPO_SSH" "$APP_DIR"
else
    warn "Repo already exists — pulling latest"
    git -C "$APP_DIR" pull
fi

if [[ ! -f "$APP_DIR/.env" ]]; then
    cp "$APP_DIR/.env.example" "$APP_DIR/.env"
    sed -i "s|APP_URL=.*|APP_URL=http://${APP_DOMAIN}:${APP_PORT}|"       "$APP_DIR/.env"
    sed -i "s|APP_ENV=.*|APP_ENV=production|"                             "$APP_DIR/.env"
    sed -i "s|APP_DEBUG=.*|APP_DEBUG=false|"                              "$APP_DIR/.env"
    sed -i "s|APP_TIMEZONE_OFFSET=.*|APP_TIMEZONE_OFFSET=${APP_TIMEZONE_OFFSET}|" "$APP_DIR/.env"
    sed -i "s|DB_HOST=.*|DB_HOST=${DB_HOST}|"                             "$APP_DIR/.env"
    sed -i "s|DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|"                     "$APP_DIR/.env"
    sed -i "s|DB_USERNAME=.*|DB_USERNAME=${DB_USER}|"                     "$APP_DIR/.env"
    sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|"                     "$APP_DIR/.env"
    sed -i "s|QUEUE_CONNECTION=.*|QUEUE_CONNECTION=database|"             "$APP_DIR/.env"
    sed -i "s|CACHE_STORE=.*|CACHE_STORE=database|"                       "$APP_DIR/.env"
    sed -i "s|SESSION_DRIVER=.*|SESSION_DRIVER=database|"                 "$APP_DIR/.env"
    echo "ADMIN_EMAIL=${ADMIN_EMAIL}"       >> "$APP_DIR/.env"
    echo "ADMIN_PASSWORD=${ADMIN_PASSWORD}" >> "$APP_DIR/.env"
    info ".env created"
fi

sed -i "s|'timezone' => '.*'|'timezone' => '${APP_TIMEZONE}'|" "$APP_DIR/config/app.php" || true

chown -R "$APP_USER":"$WEB_GROUP" "$APP_DIR"

cd "$APP_DIR"
sudo -u "$APP_USER" php /usr/local/bin/composer install --no-dev --optimize-autoloader --quiet

npm ci --prefix "$APP_DIR" --silent
chmod +x "$APP_DIR/node_modules/vite/bin/vite.js"
npm run build --prefix "$APP_DIR"

php artisan key:generate --force --quiet
php artisan migrate --force --quiet
php artisan db:seed --force --quiet
info "Migrations and seed complete"

php artisan config:cache
php artisan route:cache
php artisan view:cache
info "Application caches warmed"

# ────────────────────────────────────────────────────────────────────────
section "File permissions"
# ────────────────────────────────────────────────────────────────────────
chown -R "$APP_USER":"$WEB_GROUP" "$APP_DIR"
find "$APP_DIR" -type f -exec chmod 644 {} \;
find "$APP_DIR" -type d -exec chmod 755 {} \;
chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
chmod +x "$APP_DIR/artisan"
info "Permissions set"

# ────────────────────────────────────────────────────────────────────────
section "Apache — PHP 8.4 FPM for payment portal + sentinel on port ${APP_PORT}"
# ────────────────────────────────────────────────────────────────────────
a2enmod proxy_fcgi setenvif rewrite headers

VHOST_CONF="/etc/apache2/sites-available/000-default.conf"
SENTINEL_CONF="/etc/apache2/sites-available/sentinel.conf"

# Switch payment portal to PHP 8.4 FPM (replace 8.1 socket if present)
if grep -q "php8.1-fpm.sock" "$VHOST_CONF" 2>/dev/null; then
    sed -i "s|php8.1-fpm.sock|php8.4-fpm.sock|g" "$VHOST_CONF"
    info "Updated vhost: PHP 8.1 → 8.4 FPM socket"
elif ! grep -q "php8.4-fpm.sock\|proxy:unix" "$VHOST_CONF" 2>/dev/null; then
    # No existing FPM handler — inject inside the Directory block if present, else at vhost level
    sed -i "s|</Directory>|    <FilesMatch \\.php\$>\n        SetHandler \"proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost\"\n    </FilesMatch>\n</Directory>|" "$VHOST_CONF"
    info "PHP 8.4 FPM handler added to payment portal vhost"
fi

# Add port 8080 to Apache listeners (idempotent)
if ! grep -q "Listen ${APP_PORT}" /etc/apache2/ports.conf; then
    sed -i "s/Listen 80/Listen 80\nListen ${APP_PORT}/" /etc/apache2/ports.conf
    info "Port ${APP_PORT} added to ports.conf"
fi

# Create sentinel vhost on port APP_PORT (idempotent)
if [[ ! -f "$SENTINEL_CONF" ]]; then
    cat > "$SENTINEL_CONF" <<SENTINEL_VHOST
<VirtualHost *:${APP_PORT}>
    ServerAdmin webmaster@localhost
    DocumentRoot ${APP_DIR}/public

    ErrorLog \${APACHE_LOG_DIR}/sentinel-error.log
    CustomLog \${APACHE_LOG_DIR}/sentinel-access.log combined

    <Directory ${APP_DIR}/public>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
        <FilesMatch \\.php\$>
            SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
        </FilesMatch>
    </Directory>
</VirtualHost>
SENTINEL_VHOST
    a2ensite sentinel.conf
    info "Sentinel vhost created on port ${APP_PORT}"
else
    warn "Sentinel vhost already exists — skipping"
fi

apache2ctl configtest && systemctl reload apache2
info "Apache reloaded"

# ────────────────────────────────────────────────────────────────────────
section "Supervisor"
# ────────────────────────────────────────────────────────────────────────
cat > /etc/supervisor/conf.d/sentinel.conf <<SUPERVISOR
[program:sentinel-worker]
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

[program:sentinel-health-worker]
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

[program:sentinel-scheduler]
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

[program:sentinel-pulse]
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
section "Aliases"
# ────────────────────────────────────────────────────────────────────────
cat >> /root/.bashrc << 'BASHRC'

# sentinel — deploy latest
alias clcache='cd /var/www/sentinel && \
  GIT_SSH_COMMAND="ssh -i /root/.ssh/sentinel_deploy" git pull && \
  chgrp -R www-data storage bootstrap/cache && \
  chmod -R ug+rwx storage bootstrap/cache && \
  sudo -u www-data php /usr/local/bin/composer install --no-dev --optimize-autoloader && \
  npm ci && \
  chmod +x node_modules/vite/bin/vite.js && \
  npm run build && \
  php artisan migrate --force && \
  php artisan config:cache && \
  php artisan route:cache && \
  php artisan view:cache && \
  php artisan optimize && \
  supervisorctl restart sentinel-worker:* sentinel-health-worker sentinel-scheduler sentinel-pulse && \
  echo "Done."'

# sentinel — artisan shortcut
alias sentinel='cd /var/www/sentinel && php artisan'
BASHRC
info "Added 'clcache' and 'sentinel' aliases"

# ────────────────────────────────────────────────────────────────────────
section "Logrotate"
# ────────────────────────────────────────────────────────────────────────
cat > /etc/logrotate.d/sentinel <<'LOGROTATE'
# worker.log is rotated daily by Laravel's scheduler — not managed here

/var/www/sentinel/storage/logs/scheduler.log
/var/www/sentinel/storage/logs/pulse.log
/var/www/sentinel/storage/logs/laravel.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    dateext
    dateformat -%Y-%m-%d
    copytruncate
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
echo -e "  Trading dashboard : ${CYAN}http://${APP_DOMAIN}:${APP_PORT}${NC}"
echo -e "  Payment portal    : ${CYAN}http://${APP_DOMAIN}${NC} (now on PHP 8.4)"
echo -e "  ${YELLOW}Remember: open port ${APP_PORT} in AWS security group inbound rules${NC}"
echo -e "  App dir           : ${CYAN}${APP_DIR}${NC}"
echo -e "  DB host           : ${CYAN}${DB_HOST}${NC}"
echo ""
echo -e "${YELLOW}Next steps:${NC}"
echo "  1. Edit ${APP_DIR}/.env — add DISCORD_WEBHOOK_URL"
echo "  2. Reload config:    sentinel config:cache"
echo "  3. Check workers:    supervisorctl status"
echo "  4. Deploy updates:   clcache"
echo ""
echo -e "${YELLOW}CLI quick reference:${NC}"
echo "  sentinel migrate       → php artisan migrate (sentinel)"
echo "  sentinel tinker        → php artisan tinker  (sentinel)"
echo "  php artisan ...     → payment portal artisan"
echo ""
