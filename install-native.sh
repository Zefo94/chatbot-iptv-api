#!/bin/bash
set -e
# ===================================================================
# Chatbot IPTV — Instalación nativa limpia (Ubuntu 22.04 / 24.04)
# Usage: bash install-native.sh
# ===================================================================

set -e

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
info()  { echo -e "${GREEN}[✓]${NC} $*"; }
warn()  { echo -e "${YELLOW}[!]${NC} $*"; }
fatal() { echo -e "${RED}[✗]${NC} $*" >&2; exit 1; }

echo "=========================================="
echo "  Chatbot IPTV — Instalación Nativa"
echo "=========================================="

# ── Detectar IP ─────────────────────────────────────────────
SERVER_IP=$(curl -s -m 5 https://ifconfig.me 2>/dev/null \
  || curl -s -m 5 https://api.ipify.org 2>/dev/null \
  || hostname -I | awk '{print $1}' || echo "")
SERVER_IP=$(echo "$SERVER_IP" | tr -d '[:space:]')
echo "  IP detectada: $SERVER_IP"
echo "=========================================="

# ── 1. DETENER SERVICIOS SI EXISTEN ───────────────────────────
info "Deteniendo servicios anteriores..."
service nginx stop 2>/dev/null || true
service php8.2-fpm stop 2>/dev/null || true
service php8.1-fpm stop 2>/dev/null || true
service mariadb stop 2>/dev/null || true
service mysql stop 2>/dev/null || true

# ── 2. INSTALAR PAQUETES ──────────────────────────────────────
info "Instalando paquetes..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq software-properties-common > /dev/null 2>&1

# PHP 8.2 desde PPA ondrej para garantizar la versión correcta
add-apt-repository -y ppa:ondrej/php 2>&1 | tail -2
apt-get update -qq

apt-get install -y -qq \
    nginx \
    php8.2-fpm \
    php8.2-mysql \
    php8.2-cli \
    php8.2-xml \
    php8.2-mbstring \
    php8.2-curl \
    php8.2-zip \
    php8.2-bcmath \
    php8.2-opcache \
    php8.2-intl \
    mariadb-server \
    mariadb-client \
    curl \
    git \
    unzip \
    openssl \
    wget \
    certbot \
    python3-certbot-nginx \
    > /dev/null 2>&1

info "Paquetes instalados OK"

# ── 3. DIRECTORIOS ───────────────────────────────────────────
info "Configurando directorios..."
rm -rf /var/www/html/chatbot
mkdir -p /var/www/html
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html

# ── 4. CLONAR REPO ───────────────────────────────────────────
info "Clonando repositorio..."
cd /var/www/html
git clone https://github.com/Zefo94/chatbot-iptv-api.git . 2>&1 | tail -3

# ── 5. COMPOSER ──────────────────────────────────────────────
info "Instalando Composer..."
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
chmod +x /usr/local/bin/composer

info "Instalando dependencias PHP..."
composer install --no-dev --optimize-autoloader --no-interaction 2>&1 | tail -3

# ── 6. BASE DE DATOS ──────────────────────────────────────────
info "Configurando MySQL..."
service mariadb start

# Crear archivo SQL de inicialización temporal
cat > /tmp/init_db.sql << 'EOSQL'
CREATE DATABASE IF NOT EXISTS `iptv_manager`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'iptv_user'@'localhost' IDENTIFIED BY 'iptv_pass_placeholder';
GRANT ALL PRIVILEGES ON `iptv_manager`.* TO 'iptv_user'@'localhost';
FLUSH PRIVILEGES;
EOSQL

mariadb < /tmp/init_db.sql
mariadb -u root iptv_manager < /var/www/html/schema.sql
rm /tmp/init_db.sql

info "Base de datos importada OK"

# ── 7. GENERAR .env ─────────────────────────────────────────
info "Generando .env..."
MYSQL_ROOT_PASS=$(openssl rand -hex 20)
DB_PASS=$(openssl rand -hex 20)
CHATBOT_KEY=$(openssl rand -hex 32)

cat > /var/www/html/.env << EOF
APP_ENV=production
APP_DEBUG=false
APP_URL=http://${SERVER_IP}
CHATBOT_API_KEY=${CHATBOT_KEY}
DB_HOST=localhost
DB_PORT=3306
DB_NAME=iptv_manager
DB_USER=iptv_user
DB_PASS=${DB_PASS}
# ── Panel XUI.ONE — rellena estos campos ──────────────────────────────────
XUI_API_URL=
XUI_API_KEY=
XUI_USERNAME=
XUI_PASSWORD=
XUI_RESELLER_API_URL=
XUI_DEFAULT_PACKAGE_ID=1
XUI_DEFAULT_MAX_CONNECTIONS=1

# ── PayPal ─────────────────────────────────────────────────────────────────
# Ejemplo SANDBOX (pruebas):
#   PAYPAL_CLIENT_ID=AaBbCcDd1234_sandbox_id_aqui
#   PAYPAL_CLIENT_SECRET=EeFfGgHh5678_sandbox_secret_aqui
#   PAYPAL_WEBHOOK_ID=WH-SANDBOX-XXXXXXXXXXXX
#   PAYPAL_MODE=sandbox
#
# Ejemplo LIVE (producción):
#   PAYPAL_CLIENT_ID=AaBbCcDd1234_live_id_aqui
#   PAYPAL_CLIENT_SECRET=EeFfGgHh5678_live_secret_aqui
#   PAYPAL_WEBHOOK_ID=WH-XXXXXXXXXXXXXXXX
#   PAYPAL_MODE=live
#
#PAYPAL_CLIENT_ID=
#PAYPAL_CLIENT_SECRET=
#PAYPAL_WEBHOOK_ID=
#PAYPAL_MODE=sandbox
#PAYPAL_CURRENCY=EUR
#PAYPAL_PRICE_PER_CREDIT=10.00
#PAYPAL_RETURN_URL=http://${SERVER_IP}/pago-exitoso
#PAYPAL_CANCEL_URL=http://${SERVER_IP}/pago-cancelado

# ── MercadoPago ────────────────────────────────────────────────────────────
#MERCADOPAGO_ACCESS_TOKEN=
#MERCADOPAGO_WEBHOOK_SECRET=

# ── Wompi ──────────────────────────────────────────────────────────────────
#WOMPI_PUBLIC_KEY=
#WOMPI_PRIVATE_KEY=
#WOMPI_WEBHOOK_SECRET=

# ── Binance Pay ────────────────────────────────────────────────────────────
#BINANCE_API_KEY=
#BINANCE_SECRET_KEY=
EOF

# BUG FIX: HEREDOC sin comillas para que ${DB_PASS} se sustituya correctamente
mariadb -u root << EOSQL
ALTER USER 'iptv_user'@'localhost' IDENTIFIED BY '${DB_PASS}';
FLUSH PRIVILEGES;
EOSQL

chown www-data:www-data /var/www/html/.env
chmod 600 /var/www/html/.env

# ── 8. NGINX ──────────────────────────────────────────────────
info "Configurando Nginx..."

cat > /etc/nginx/sites-available/chatbot << 'NGINX'
server {
    listen 80;
    server_name _;

    root /var/www/html/public;
    index index.php index.html;

    client_max_body_size 50M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /\.ht {
        deny all;
    }
}
NGINX

rm -f /etc/nginx/sites-enabled/default
ln -sf /etc/nginx/sites-available/chatbot /etc/nginx/sites-enabled/chatbot

# ── 9. PERMISOS ─────────────────────────────────────────────
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html
mkdir -p /var/www/html/storage/logs /var/www/html/storage/cache
chown -R www-data:www-data /var/www/html/storage

# ── 10. ARRANCAR SERVICIOS ────────────────────────────────────
info "Arrancando servicios..."
service php8.2-fpm restart
service nginx restart
service mariadb restart

# ── 11. VERIFICAR ────────────────────────────────────────────
sleep 2

echo ""
echo "=========================================="
echo "  Verificando..."
echo "=========================================="

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/api/info)
if [ "$HTTP_CODE" = "401" ]; then
    info "API respondiendo correctamente (401 = necesita X-API-Key)"
elif [ "$HTTP_CODE" = "200" ]; then
    info "API respondiendo correctamente"
else
    warn "API respondió código: $HTTP_CODE"
fi

# Mostrar info
curl -s http://localhost/api/info | head -c 200

echo ""
echo ""
echo "=========================================="
echo -e "${GREEN}  ¡INSTALACIÓN COMPLETA!${NC}"
echo "=========================================="
echo ""
echo -e "${BLUE}  URL:        ${NC}http://${SERVER_IP}/api/info"
echo -e "${BLUE}  MySQL:      ${NC}localhost"
echo -e "${BLUE}  DB User:   ${NC}iptv_user"
echo -e "${BLUE}  DB Pass:   ${NC}${DB_PASS}"
echo -e "${BLUE}  Database:  ${NC}iptv_manager"
echo -e "${BLUE}  API Key:   ${NC}${CHATBOT_KEY}"
echo ""
echo -e "${BLUE}  Puertos abiertos en DatabaseMart:${NC}"
echo "    - 80   → HTTP (para Cloudflare)"
echo "    - 10035 → SSH"
echo ""
echo -e "${BLUE}  Comandos útiles:${NC}"
echo "    service nginx restart"
echo "    service php8.2-fpm restart"
echo "    service mariadb restart"
echo ""