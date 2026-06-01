#!/bin/bash
# =============================================================================
#  Chatbot IPTV — Instalación Completa en VPS Limpio (Ubuntu 22.04 / 24.04)
#  Uso: bash install-full.sh
#  Instala: Nginx + PHP 8.2 + MariaDB + Composer + HTTPS automático (certbot)
# =============================================================================
set -e

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
info()  { echo -e "${GREEN}[✓]${NC} $*"; }
warn()  { echo -e "${YELLOW}[!]${NC} $*"; }
fatal() { echo -e "${RED}[✗]${NC} $*" >&2; exit 1; }

echo "=========================================="
echo "  Chatbot IPTV — Instalación Completa"
echo "=========================================="

# ── Detectar IP pública ────────────────────────────────────────
SERVER_IP=$(curl -s -m 5 https://ifconfig.me 2>/dev/null \
  || curl -s -m 5 https://api.ipify.org 2>/dev/null \
  || hostname -I | awk '{print $1}' || echo "")
SERVER_IP=$(echo "$SERVER_IP" | tr -d '[:space:]')
info "IP detectada: $SERVER_IP"

# ── Datos de configuración ────────────────────────────────────
echo ""
read -p "Dominio (ENTER para usar solo IP, ej: solucionesdigitales.icu): " SERVER_DOMAIN
SERVER_DOMAIN=$(echo "$SERVER_DOMAIN" | tr -d '[:space:]')

if [ -n "$SERVER_DOMAIN" ]; then
    BASE_URL="https://${SERVER_DOMAIN}"
    info "Dominio: $SERVER_DOMAIN → se configurará HTTPS automáticamente"
else
    BASE_URL="http://${SERVER_IP}"
    warn "Sin dominio — usando HTTP. PayPal webhooks requieren HTTPS para producción."
fi

echo ""
read -p "Email admin (para Let's Encrypt, puede estar vacío): " ADMIN_EMAIL
read -p "XUI API URL (ej: http://tripic.space/miapixui/): " XUI_API_URL_INPUT
read -p "XUI API Key (admin): " XUI_API_KEY_INPUT
read -p "XUI Reseller API URL (ej: http://tripic.space:80/resselerapi/): " XUI_RESELLER_URL_INPUT
echo ""
echo "=========================================="

# ── 0. Limpiar locks de dpkg ────────────────────────────────────
info "Limpiando locks de dpkg..."
rm -f /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/cache/apt/archives/lock 2>/dev/null || true
dpkg --configure -a 2>/dev/null || true

# ── 1. Limpiar servicios anteriores ─────────────────────────────
info "Limpiando instalaciones anteriores..."
if command -v docker &>/dev/null; then
    docker stop $(docker ps -aq) 2>/dev/null || true
    docker rm -f $(docker ps -aq) 2>/dev/null || true
fi
service nginx stop 2>/dev/null || true
service php8.2-fpm stop 2>/dev/null || true
service mariadb stop 2>/dev/null || true

# ── 2. Esperar que no haya locks de apt ─────────────────────────
for i in $(seq 1 30); do
    if fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1; then
        warn "dpkg ocupado, esperando... ($i/30)"
        sleep 5
    else
        break
    fi
done

# ── 3. Paquetes base ─────────────────────────────────────────────
info "Instalando paquetes base..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq 2>&1 | tail -3

DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
    nginx mariadb-server mariadb-client curl git unzip openssl \
    software-properties-common certbot python3-certbot-nginx 2>&1 | tail -5

# ── 4. PHP 8.2 desde PPA ondrej ──────────────────────────────────
info "Instalando PHP 8.2 desde PPA ondrej/php..."
add-apt-repository -y ppa:ondrej/php 2>&1 | tail -3
apt-get update -qq 2>&1 | tail -3

DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
    php8.2-fpm php8.2-mysql php8.2-cli php8.2-xml php8.2-mbstring \
    php8.2-curl php8.2-zip php8.2-bcmath php8.2-opcache php8.2-intl 2>&1 | tail -5

php -v | head -1 || fatal "PHP no se instaló"
nginx -v 2>&1 | head -1 || fatal "Nginx no se instaló"
mariadb --version | head -1 || fatal "MariaDB no se instaló"

# ── 5. PHP: timezone ─────────────────────────────────────────────
info "Configurando PHP timezone..."
echo "date.timezone = America/Montevideo" > /etc/php/8.2/fpm/conf.d/99-timezone.ini
echo "date.timezone = America/Montevideo" > /etc/php/8.2/cli/conf.d/99-timezone.ini

# ── 6. Clonar repositorio ────────────────────────────────────────
info "Clonando repositorio..."
rm -rf /var/www/html
mkdir -p /var/www/html
cd /var/www/html
git clone https://github.com/Zefo94/chatbot-iptv-api.git . 2>&1 | tail -3

# ── 7. Composer + dependencias PHP ───────────────────────────────
info "Instalando Composer..."
curl -sS https://getcomposer.org/installer | php -- --quiet
mv composer.phar /usr/local/bin/composer
chmod +x /usr/local/bin/composer

info "Instalando dependencias PHP..."
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction 2>&1 | tail -5

# ── 8. MariaDB ───────────────────────────────────────────────────
info "Configurando MariaDB..."
service mariadb start

for i in $(seq 1 15); do
    if mariadb -u root -e "SELECT 1" &>/dev/null; then
        info "MariaDB listo tras ${i}s"
        break
    fi
    [ $i -eq 15 ] && fatal "MariaDB no respondió en 15 intentos"
    sleep 1
done

# Generar passwords seguros
CHATBOT_KEY=$(openssl rand -hex 32)
DB_PASS=$(openssl rand -hex 20)

mariadb -u root << EOSQL
CREATE DATABASE IF NOT EXISTS \`iptv_manager\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'iptv_user'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`iptv_manager\`.* TO 'iptv_user'@'localhost';
FLUSH PRIVILEGES;
EOSQL

mariadb -u iptv_user -p"${DB_PASS}" iptv_manager < /var/www/html/schema.sql 2>&1 | tail -3
info "Base de datos lista"

# ── 9. Generar .env completo ─────────────────────────────────────
info "Generando .env..."

cat > /var/www/html/.env << EOF
APP_ENV=production
APP_DEBUG=false
APP_URL=${BASE_URL}

# ── Chatbot Auth ──────────────────────────────────────────────────
CHATBOT_API_KEY=${CHATBOT_KEY}

# ── Base de Datos ─────────────────────────────────────────────────
DB_HOST=localhost
DB_PORT=3306
DB_NAME=iptv_manager
DB_USER=iptv_user
DB_PASS=${DB_PASS}

# ── XUI.ONE Panel ─────────────────────────────────────────────────
XUI_API_URL=${XUI_API_URL_INPUT:-http://tripic.space/miapixui/}
XUI_API_KEY=${XUI_API_KEY_INPUT}
XUI_USERNAME=
XUI_PASSWORD=
XUI_RESELLER_API_URL=${XUI_RESELLER_URL_INPUT:-http://tripic.space:80/resselerapi/}
XUI_DEFAULT_PACKAGE_ID=1
XUI_DEFAULT_MAX_CONNECTIONS=1

# ── PayPal ────────────────────────────────────────────────────────
PAYPAL_CLIENT_ID=
PAYPAL_CLIENT_SECRET=
PAYPAL_WEBHOOK_ID=
PAYPAL_MODE=sandbox
PAYPAL_CURRENCY=EUR
PAYPAL_PRICE_PER_CREDIT=10.00
PAYPAL_RETURN_URL=${BASE_URL}/pago-exitoso
PAYPAL_CANCEL_URL=${BASE_URL}/pago-cancelado

# ── Otros gateways (opcional) ─────────────────────────────────────
WOMPI_PUBLIC_KEY=
WOMPI_PRIVATE_KEY=
WOMPI_WEBHOOK_SECRET=
MERCADOPAGO_ACCESS_TOKEN=
MERCADOPAGO_WEBHOOK_SECRET=
BINANCE_API_KEY=
BINANCE_SECRET_KEY=
EOF

chown www-data:www-data /var/www/html/.env
chmod 600 /var/www/html/.env

# ── 10. Nginx — config HTTP base ─────────────────────────────────
info "Configurando Nginx..."

if [ -n "$SERVER_DOMAIN" ]; then
    SERVER_NAME="$SERVER_DOMAIN"
else
    SERVER_NAME="_"
fi

cat > /etc/nginx/sites-available/chatbot << NGINXCONF
server {
    listen 80;
    server_name ${SERVER_NAME};
    root /var/www/html/public;
    index index.php index.html;
    client_max_body_size 50M;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }

    location ~ /\.ht { deny all; }
}
NGINXCONF

rm -f /etc/nginx/sites-enabled/default
ln -sf /etc/nginx/sites-available/chatbot /etc/nginx/sites-enabled/chatbot

# ── 11. Permisos ─────────────────────────────────────────────────
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html
mkdir -p /var/www/html/storage/logs /var/www/html/storage/cache
chown -R www-data:www-data /var/www/html/storage
chmod -R 775 /var/www/html/storage

# ── 12. Arrancar servicios ────────────────────────────────────────
info "Arrancando servicios..."
service php8.2-fpm restart
service nginx restart
service mariadb restart

# ── 13. HTTPS automático con certbot ─────────────────────────────
if [ -n "$SERVER_DOMAIN" ]; then
    info "Configurando HTTPS con Let's Encrypt..."
    sleep 2

    CERTBOT_FLAGS="--nginx -d ${SERVER_DOMAIN} --non-interactive --agree-tos"
    if [ -n "$ADMIN_EMAIL" ]; then
        CERTBOT_FLAGS="$CERTBOT_FLAGS --email ${ADMIN_EMAIL}"
    else
        CERTBOT_FLAGS="$CERTBOT_FLAGS --register-unsafely-without-email"
    fi

    if certbot $CERTBOT_FLAGS 2>&1 | tail -5; then
        info "HTTPS configurado correctamente para ${SERVER_DOMAIN}"
        # Reiniciar nginx con la nueva config SSL de certbot
        service nginx restart
    else
        warn "Certbot falló — verifica que el dominio ${SERVER_DOMAIN} apunte a esta IP (${SERVER_IP})"
        warn "Para activar HTTPS manualmente más tarde: certbot --nginx -d ${SERVER_DOMAIN}"
    fi
fi

# ── 14. Verificar ─────────────────────────────────────────────────
sleep 3

echo ""
echo "=========================================="
echo "  Verificando..."
echo "=========================================="

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/api/info" -k 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "401" ]; then
    info "API respondiendo correctamente (401 = necesita X-API-Key) ✓"
elif [ "$HTTP_CODE" = "200" ]; then
    info "API respondiendo correctamente ✓"
else
    warn "API respondió HTTP ${HTTP_CODE} — revisa los logs: journalctl -u nginx -u php8.2-fpm"
fi

echo ""
echo "=========================================="
echo -e "${GREEN}  ¡INSTALACIÓN COMPLETA!${NC}"
echo "=========================================="
echo ""
echo -e "${BLUE}  URL de la API:${NC}  ${BASE_URL}/api/info"
echo -e "${BLUE}  Webhook PayPal:${NC} ${BASE_URL}/api/webhook-pago?gateway=paypal"
echo ""
echo -e "${BLUE}  Base de datos:${NC}"
echo "    DB:   iptv_manager"
echo "    User: iptv_user"
echo "    Pass: ${DB_PASS}"
echo ""
echo -e "${BLUE}  API Key del chatbot:${NC} ${CHATBOT_KEY}"
echo ""
echo -e "${YELLOW}  ════════════════════════════════════════${NC}"
echo -e "${YELLOW}  CONFIGURACIÓN PENDIENTE EN /var/www/html/.env:${NC}"
echo -e "${YELLOW}  ════════════════════════════════════════${NC}"
echo ""
echo -e "  1. ${RED}PAYPAL_CLIENT_ID${NC}      — ID de tu app PayPal"
echo -e "  2. ${RED}PAYPAL_CLIENT_SECRET${NC}  — Secret de tu app PayPal"
echo -e "  3. ${RED}PAYPAL_WEBHOOK_ID${NC}     — ID del webhook en dashboard PayPal"
echo -e "  4. ${RED}PAYPAL_MODE${NC}           — cambiar sandbox → live en producción"
echo -e "  5. ${RED}PAYPAL_CURRENCY${NC}       — moneda: EUR, USD, MXN, etc."
echo -e "  6. ${RED}XUI_API_KEY${NC}           — API Key admin de tu panel XUI"
echo ""
echo -e "  Editar: ${BLUE}nano /var/www/html/.env${NC}"
echo ""
echo -e "${BLUE}  Comandos útiles:${NC}"
echo "    service nginx restart"
echo "    service php8.2-fpm restart"
echo "    service mariadb restart"
echo "    tail -f /var/www/html/storage/logs/*.log"
echo ""
