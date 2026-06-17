#!/bin/bash
# =============================================================================
#  Chatbot IPTV — Instalación automática en VPS limpio (Ubuntu 22.04 / 24.04)
#  Uso: sudo bash install-full.sh
# =============================================================================
set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

info()  { echo -e "${GREEN}[✓]${NC} $*"; }
warn()  { echo -e "${YELLOW}[!]${NC} $*"; }
fatal() { echo -e "${RED}[✗]${NC} $*" >&2; exit 1; }
ask()   { echo -e "${CYAN}  ◆ $*${NC}"; }

[[ $EUID -ne 0 ]] && fatal "Ejecuta como root: sudo bash install-full.sh"

clear
echo ""
echo -e "${BOLD}${CYAN}  ╔══════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${CYAN}  ║   CHATBOT IPTV API — Instalación Automática     ║${NC}"
echo -e "${BOLD}${CYAN}  ╚══════════════════════════════════════════════════╝${NC}"
echo ""

# =============================================================================
#  PASO 1 — Datos mínimos de configuración
# =============================================================================

# Auto-detectar IP pública
SERVER_IP=$(curl -s -m 5 https://ifconfig.me 2>/dev/null \
  || curl -s -m 5 https://api.ipify.org 2>/dev/null \
  || hostname -I | awk '{print $1}' || echo "")
SERVER_IP=$(echo "$SERVER_IP" | tr -d '[:space:]')

echo -e "  ${BOLD}Solo necesito 3 datos para instalar. Todo lo demás se configura solo.${NC}"
echo ""

# Dominio o IP
ask "Dominio (Enter para usar IP: ${SERVER_IP}):"
read -r DOMAIN_INPUT
DOMAIN_INPUT=$(echo "$DOMAIN_INPUT" | tr -d '[:space:]' | tr '[:upper:]' '[:lower:]')

if [[ -n "$DOMAIN_INPUT" ]]; then
    SERVER_NAME="$DOMAIN_INPUT"
    BASE_URL="https://${DOMAIN_INPUT}"
    USE_DOMAIN=true
else
    SERVER_NAME="_"
    BASE_URL="http://${SERVER_IP}"
    USE_DOMAIN=false
fi

# Modo SSL (solo si hay dominio)
SSL_MODE="none"
ADMIN_EMAIL=""
if [[ "$USE_DOMAIN" == "true" ]]; then
    echo ""
    echo -e "  ¿Cómo maneja el SSL este dominio?"
    echo -e "  ${CYAN}1)${NC} Cloudflare  — SSL en el proxy, el servidor solo escucha HTTP (recomendado)"
    echo -e "  ${CYAN}2)${NC} Let's Encrypt — instalar certificado directo en el servidor"
    echo -e "  ${CYAN}3)${NC} Sin SSL — HTTP solamente"
    echo ""
    ask "Elige [1/2/3] (Enter = 1):"
    read -r SSL_CHOICE
    SSL_CHOICE="${SSL_CHOICE:-1}"

    case "$SSL_CHOICE" in
        2)
            SSL_MODE="letsencrypt"
            ask "Email admin para Let's Encrypt (Enter para omitir):"
            read -r ADMIN_EMAIL
            ADMIN_EMAIL=$(echo "$ADMIN_EMAIL" | tr -d '[:space:]')
            BASE_URL="https://${DOMAIN_INPUT}"
            ;;
        3)
            SSL_MODE="none"
            BASE_URL="http://${DOMAIN_INPUT}"
            ;;
        *)
            SSL_MODE="cloudflare"
            BASE_URL="https://${DOMAIN_INPUT}"
            ;;
    esac
fi

echo -e "  ${DIM}── Panel XUI.ONE — URL de administración ──${NC}"
echo -e "  ${DIM}   URL del panel admin, termina en /miapixui/ o similar${NC}"
ask "XUI Admin URL (ej: http://tupanel.com/miapixui/):"
read -r XUI_API_URL_INPUT
XUI_API_URL_INPUT=$(echo "$XUI_API_URL_INPUT" | tr -d '[:space:]')
[[ -z "$XUI_API_URL_INPUT" ]] && fatal "La URL admin de XUI es obligatoria."

ask "XUI Admin API Key (key del administrador del panel):"
read -r XUI_API_KEY_INPUT
[[ -z "$XUI_API_KEY_INPUT" ]] && fatal "La API Key admin de XUI es obligatoria."

echo ""
echo -e "  ${DIM}── Panel XUI.ONE — URL de revendedores ──${NC}"
echo -e "  ${DIM}   URL específica para la API de revendedores, suele tener puerto distinto${NC}"
ask "XUI Reseller URL (Enter = misma que admin, ej: http://tupanel.com:80/resselerapi/):"
read -r XUI_RESELLER_URL_INPUT
XUI_RESELLER_URL_INPUT=$(echo "$XUI_RESELLER_URL_INPUT" | tr -d '[:space:]')
[[ -z "$XUI_RESELLER_URL_INPUT" ]] && XUI_RESELLER_URL_INPUT="$XUI_API_URL_INPUT"

echo ""
echo -e "  ${DIM}── Pasarelas de pago (Enter para dejar comentadas, configurar luego) ──${NC}"

PAYPAL_CID=""; PAYPAL_CSEC=""; PAYPAL_WEBHOOK_ID=""
PAYPAL_MODE="sandbox"; PAYPAL_CURRENCY="EUR"; PAYPAL_PRICE="10.00"
ask "PayPal Client ID (Enter para omitir):"
read -r PAYPAL_CID
if [[ -n "$PAYPAL_CID" ]]; then
    ask "PayPal Client Secret:"
    read -rs PAYPAL_CSEC; echo ""
    ask "PayPal Webhook ID:"
    read -r PAYPAL_WEBHOOK_ID
    echo -e "  ${DIM}Modo: 1) sandbox (pruebas)  2) live (producción)${NC}"
    ask "Modo PayPal [1/2] (Enter = sandbox):"
    read -r PM; [[ "${PM:-1}" == "2" ]] && PAYPAL_MODE="live" || PAYPAL_MODE="sandbox"
    ask "Moneda (Enter = EUR):"
    read -r PC; PAYPAL_CURRENCY="${PC:-EUR}"
    ask "Precio por crédito (Enter = 10.00):"
    read -r PP; PAYPAL_PRICE="${PP:-10.00}"
fi

MP_TOKEN=""; MP_WEBHOOK=""
ask "MercadoPago Access Token (Enter para omitir):"
read -r MP_TOKEN
if [[ -n "$MP_TOKEN" ]]; then
    ask "MercadoPago Webhook Secret (Enter para omitir):"
    read -r MP_WEBHOOK
fi

WOMPI_PUB=""; WOMPI_PRIV=""; WOMPI_WEBHOOK=""
ask "Wompi Public Key (Enter para omitir):"
read -r WOMPI_PUB
if [[ -n "$WOMPI_PUB" ]]; then
    ask "Wompi Private Key:"
    read -rs WOMPI_PRIV; echo ""
    ask "Wompi Webhook Secret (Enter para omitir):"
    read -r WOMPI_WEBHOOK
fi

echo ""
ask "Contraseña del Dashboard (Enter para dejar vacía):"
read -rs DASHBOARD_PASS; echo ""

echo ""
info "Datos recibidos. Iniciando instalación automática..."
echo ""

# =============================================================================
#  PASO 2 — Limpiar servicios anteriores
# =============================================================================
info "Deteniendo servicios anteriores..."
service nginx stop 2>/dev/null || true
service php8.2-fpm stop 2>/dev/null || true
service mariadb stop 2>/dev/null || true

# Liberar locks de dpkg
rm -f /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/cache/apt/archives/lock 2>/dev/null || true
dpkg --configure -a 2>/dev/null || true

# Esperar apt libre
for i in $(seq 1 30); do
    fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 || break
    [[ $i -eq 30 ]] && fatal "dpkg ocupado — vuelve a intentarlo"
    sleep 5
done

# =============================================================================
#  PASO 3 — Instalar paquetes del sistema
# =============================================================================
info "Instalando paquetes del sistema..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq

apt-get install -y -qq \
    nginx mariadb-server mariadb-client curl git unzip openssl \
    software-properties-common certbot python3-certbot-nginx

add-apt-repository -y ppa:ondrej/php 2>&1 | tail -1
apt-get update -qq

apt-get install -y -qq \
    php8.2-fpm php8.2-mysql php8.2-cli php8.2-xml php8.2-mbstring \
    php8.2-curl php8.2-zip php8.2-bcmath php8.2-opcache php8.2-intl

php -v | head -1 | grep -q "8.2" || fatal "PHP 8.2 no se instaló correctamente"
info "PHP 8.2, Nginx y MariaDB instalados"

# =============================================================================
#  PASO 4 — PHP timezone
# =============================================================================
echo "date.timezone = America/Montevideo" > /etc/php/8.2/fpm/conf.d/99-timezone.ini
echo "date.timezone = America/Montevideo" > /etc/php/8.2/cli/conf.d/99-timezone.ini

# =============================================================================
#  PASO 5 — Clonar repositorio
# =============================================================================
info "Clonando repositorio..."
rm -rf /var/www/html
mkdir -p /var/www/html
git clone -q https://github.com/Zefo94/chatbot-iptv-api.git /var/www/html
info "Código clonado en /var/www/html"

# =============================================================================
#  PASO 6 — Composer
# =============================================================================
info "Instalando Composer y dependencias PHP..."
curl -sS https://getcomposer.org/installer | php -- --quiet
mv composer.phar /usr/local/bin/composer
chmod +x /usr/local/bin/composer
cd /var/www/html
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction -q
info "Dependencias PHP instaladas"

# =============================================================================
#  PASO 7 — Base de datos
# =============================================================================
info "Configurando MariaDB..."
service mariadb start

for i in $(seq 1 20); do
    mariadb -u root -e "SELECT 1" &>/dev/null && break
    [[ $i -eq 20 ]] && fatal "MariaDB no respondió"
    sleep 1
done

CHATBOT_KEY=$(openssl rand -hex 32)
DB_PASS=$(openssl rand -hex 20)

mariadb -u root << EOSQL
CREATE DATABASE IF NOT EXISTS \`iptv_manager\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'iptv_user'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`iptv_manager\`.* TO 'iptv_user'@'localhost';
FLUSH PRIVILEGES;
EOSQL

mariadb -u iptv_user -p"${DB_PASS}" iptv_manager < /var/www/html/schema.sql
info "Base de datos 'iptv_manager' creada e importada"

# =============================================================================
#  PASO 8 — Generar .env
# =============================================================================
info "Generando .env..."

cat > /var/www/html/.env << EOF
APP_ENV=production
APP_DEBUG=false
APP_URL=${BASE_URL}

# ── Autenticación del Chatbot ──────────────────────────────────────────────
CHATBOT_API_KEY=${CHATBOT_KEY}

# ── Base de Datos ──────────────────────────────────────────────────────────
DB_HOST=localhost
DB_PORT=3306
DB_NAME=iptv_manager
DB_USER=iptv_user
DB_PASS=${DB_PASS}

# ── Panel XUI.ONE — Administración ────────────────────────────────────────
# URL y key del administrador del panel (acceso global)
XUI_API_URL=${XUI_API_URL_INPUT}
XUI_API_KEY=${XUI_API_KEY_INPUT}
XUI_USERNAME=
XUI_PASSWORD=

# ── Panel XUI.ONE — Revendedor ─────────────────────────────────────────────
# URL específica para la API de revendedores (suele tener puerto distinto)
XUI_RESELLER_API_URL=${XUI_RESELLER_URL_INPUT}
XUI_DEFAULT_PACKAGE_ID=1
XUI_DEFAULT_MAX_CONNECTIONS=1

$(if [[ -n "$PAYPAL_CID" ]]; then
cat << PAYPAL
# ── PayPal ─────────────────────────────────────────────────────────────────
PAYPAL_CLIENT_ID=${PAYPAL_CID}
PAYPAL_CLIENT_SECRET=${PAYPAL_CSEC}
PAYPAL_WEBHOOK_ID=${PAYPAL_WEBHOOK_ID}
PAYPAL_MODE=${PAYPAL_MODE}
PAYPAL_CURRENCY=${PAYPAL_CURRENCY}
PAYPAL_PRICE_PER_CREDIT=${PAYPAL_PRICE}
PAYPAL_RETURN_URL=${BASE_URL}/pago-exitoso
PAYPAL_CANCEL_URL=${BASE_URL}/pago-cancelado
PAYPAL
else
cat << PAYPAL
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
#PAYPAL_RETURN_URL=${BASE_URL}/pago-exitoso
#PAYPAL_CANCEL_URL=${BASE_URL}/pago-cancelado
PAYPAL
fi)

$(if [[ -n "$MP_TOKEN" ]]; then
cat << MP
# ── MercadoPago ────────────────────────────────────────────────────────────
MERCADOPAGO_ACCESS_TOKEN=${MP_TOKEN}
MERCADOPAGO_WEBHOOK_SECRET=${MP_WEBHOOK}
MP
else
echo "# ── MercadoPago ──────────────────────────────────────────────────────────"
echo "#MERCADOPAGO_ACCESS_TOKEN="
echo "#MERCADOPAGO_WEBHOOK_SECRET="
fi)

$(if [[ -n "$WOMPI_PUB" ]]; then
cat << WOMPI
# ── Wompi ──────────────────────────────────────────────────────────────────
WOMPI_PUBLIC_KEY=${WOMPI_PUB}
WOMPI_PRIVATE_KEY=${WOMPI_PRIV}
WOMPI_WEBHOOK_SECRET=${WOMPI_WEBHOOK}
WOMPI
else
echo "# ── Wompi ────────────────────────────────────────────────────────────────"
echo "#WOMPI_PUBLIC_KEY="
echo "#WOMPI_PRIVATE_KEY="
echo "#WOMPI_WEBHOOK_SECRET="
fi)

# ── Binance Pay ────────────────────────────────────────────────────────────
#BINANCE_API_KEY=
#BINANCE_SECRET_KEY=

# ── Dashboard ──────────────────────────────────────────────────────────────
DASHBOARD_PASSWORD=${DASHBOARD_PASS}
EOF

chown www-data:www-data /var/www/html/.env
chmod 600 /var/www/html/.env
info ".env generado"

# =============================================================================
#  PASO 9 — Nginx
# =============================================================================
info "Configurando Nginx..."

cat > /etc/nginx/sites-available/chatbot << NGINXCONF
server {
    listen 80;
    server_name ${SERVER_NAME};
    root /var/www/html/public;
    index index.php index.html;
    client_max_body_size 50M;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(env|ht) { deny all; return 404; }
}
NGINXCONF

rm -f /etc/nginx/sites-enabled/default
ln -sf /etc/nginx/sites-available/chatbot /etc/nginx/sites-enabled/chatbot

# =============================================================================
#  PASO 10 — Permisos
# =============================================================================
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html
mkdir -p /var/www/html/storage/logs /var/www/html/storage/cache
chown -R www-data:www-data /var/www/html/storage
chmod -R 775 /var/www/html/storage
chmod 600 /var/www/html/.env

# =============================================================================
#  PASO 11 — Arrancar servicios
# =============================================================================
info "Arrancando servicios..."
service php8.2-fpm restart
service nginx restart
service mariadb restart

# =============================================================================
#  PASO 12 — SSL
# =============================================================================
if [[ "$SSL_MODE" == "cloudflare" ]]; then
    info "Cloudflare SSL — el servidor escucha HTTP, Cloudflare provee HTTPS"
    info "Asegúrate de que el proxy de Cloudflare esté activo (nube naranja) para ${DOMAIN_INPUT}"

elif [[ "$SSL_MODE" == "letsencrypt" ]]; then
    info "Instalando certificado Let's Encrypt..."
    sleep 2
    CERTBOT_FLAGS="--nginx -d ${DOMAIN_INPUT} --non-interactive --agree-tos --redirect"
    if [[ -n "$ADMIN_EMAIL" ]]; then
        CERTBOT_FLAGS="$CERTBOT_FLAGS --email ${ADMIN_EMAIL}"
    else
        CERTBOT_FLAGS="$CERTBOT_FLAGS --register-unsafely-without-email"
    fi
    if certbot $CERTBOT_FLAGS 2>/dev/null; then
        info "Certificado SSL instalado — HTTPS activo"
        service nginx restart
    else
        warn "Certbot falló — verifica que ${DOMAIN_INPUT} apunte a esta IP (${SERVER_IP})"
        warn "Para activar SSL luego: certbot --nginx -d ${DOMAIN_INPUT}"
        BASE_URL="http://${DOMAIN_INPUT}"
    fi

else
    info "Sin SSL — API disponible por HTTP"
fi

# =============================================================================
#  PASO 13 — Verificar que la API responde
# =============================================================================
sleep 2
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/api/info" -k 2>/dev/null || echo "000")

# =============================================================================
#  RESUMEN FINAL
# =============================================================================
SUMMARY_FILE="/root/CHATBOT_CREDENCIALES.txt"

{
echo "======================================================================"
echo "  CHATBOT IPTV API — CREDENCIALES DE ACCESO"
echo "  Instalado: $(date '+%Y-%m-%d %H:%M')"
echo "======================================================================"
echo ""
echo "  ── URLs de acceso ────────────────────────────────────────────"
echo "  API (chatbot)    : ${BASE_URL}"
echo "  Dashboard admin  : ${BASE_URL}/dashboard.php"
echo "  Panel revendedor : ${BASE_URL}/reseller.php"
echo ""
echo "  ── Autenticación ─────────────────────────────────────────────"
echo "  API Key (header X-API-Key):"
echo "  ${CHATBOT_KEY}"
echo ""
echo "  ── Base de Datos ─────────────────────────────────────────────"
echo "  Host:     localhost"
echo "  BD:       iptv_manager"
echo "  Usuario:  iptv_user"
echo "  Password: ${DB_PASS}"
echo ""
echo "  ── Archivos importantes ──────────────────────────────────────"
echo "  Código:   /var/www/html"
echo "  Config:   /var/www/html/.env"
echo "  Logs:     /var/www/html/storage/logs/"
echo "  Nginx:    /etc/nginx/sites-available/chatbot"
echo ""
echo "  ── Activar pasarelas de pago ────────────────────────────────"
echo "  Edita /var/www/html/.env, descomenta la sección de la pasarela"
echo "  y rellena las credenciales. Luego: service php8.2-fpm restart"
echo ""
echo "  ── Comandos útiles ──────────────────────────────────────────"
echo "  service nginx restart"
echo "  service php8.2-fpm restart"
echo "  tail -f /var/www/html/storage/logs/*.log"
echo "======================================================================"
} | tee "$SUMMARY_FILE"

echo ""
if [[ "$HTTP_CODE" == "401" ]] || [[ "$HTTP_CODE" == "200" ]]; then
    echo -e "${BOLD}${GREEN}  ✅ API respondiendo correctamente (HTTP ${HTTP_CODE})${NC}"
else
    echo -e "${BOLD}${YELLOW}  ⚠  API respondió HTTP ${HTTP_CODE} — revisa los logs${NC}"
    echo -e "     ${YELLOW}journalctl -u nginx -u php8.2-fpm --no-pager | tail -20${NC}"
fi

echo ""
echo -e "  ${BOLD}Credenciales guardadas en:${NC} ${CYAN}${SUMMARY_FILE}${NC}"
echo ""
