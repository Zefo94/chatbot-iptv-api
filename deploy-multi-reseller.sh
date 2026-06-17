#!/usr/bin/env bash
# =============================================================================
#  deploy-multi-reseller.sh — Deploy multi-revendedor Chatbot IPTV
#
#  Instala N instancias aisladas (BD + Nginx + SSL propios por revendedor).
#  Uso: sudo bash deploy-multi-reseller.sh
# =============================================================================
set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; BOLD='\033[1m'; DIM='\033[2m'; NC='\033[0m'

info()    { echo -e "${GREEN}[✓]${NC} $*"; }
warn()    { echo -e "${YELLOW}[!]${NC} $*"; }
fatal()   { echo -e "${RED}[✗]${NC} $*" >&2; exit 1; }
step()    { echo -e "\n${BOLD}${CYAN}▶ $*${NC}"; }
section() { echo -e "\n${BOLD}${CYAN}══════════════════════════════════════════════════${NC}"; echo -e "${BOLD}${CYAN}  $*${NC}"; echo -e "${BOLD}${CYAN}══════════════════════════════════════════════════${NC}"; }
ask()     { echo -e "${YELLOW}  ◆ $*${NC}"; }

[[ $EUID -ne 0 ]] && fatal "Ejecuta como root: sudo bash deploy-multi-reseller.sh"

REPO_SSH="git@github.com:Zefo94/chatbot-iptv-api.git"
REPO_HTTPS="https://github.com/Zefo94/chatbot-iptv-api.git"
BRANCH="main"
BASE_DIR="/var/www"
SUMMARY_FILE="/root/CHATBOT_RESELLERS.txt"
PHP_SOCK=""

clear
echo ""
echo -e "${BOLD}${CYAN}  ╔══════════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${CYAN}  ║    CHATBOT IPTV API — Deploy Multi-Revendedor           ║${NC}"
echo -e "${BOLD}${CYAN}  ╚══════════════════════════════════════════════════════════╝${NC}"
echo ""

# =============================================================================
#  PASO 0 — Dependencias del sistema
# =============================================================================
section "PASO 0 — Instalando dependencias"

install_if_missing() {
    dpkg -l "$1" &>/dev/null && { info "$1 ya instalado"; return; }
    apt-get install -y "$1" -qq && info "$1 instalado"
}

# Liberar locks de dpkg/apt antes de cualquier instalación
info "Verificando locks de dpkg..."
systemctl stop unattended-upgrades 2>/dev/null || true
rm -f /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/cache/apt/archives/lock 2>/dev/null || true
dpkg --configure -a 2>/dev/null || true

for i in $(seq 1 30); do
    fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 || break
    [[ $i -eq 30 ]] && fatal "dpkg sigue bloqueado tras 30 intentos — reinicia el servidor e intenta de nuevo"
    warn "dpkg ocupado, esperando... ($i/30)"
    sleep 5
done

apt-get update -qq
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
    curl git unzip openssl software-properties-common \
    certbot python3-certbot-nginx nginx

# MySQL/MariaDB
if ! command -v mysql &>/dev/null; then
    DEBIAN_FRONTEND=noninteractive apt-get install -y mariadb-server -qq
    systemctl enable mariadb --now 2>/dev/null || true
    info "MariaDB instalado"
else
    info "MariaDB ya instalado"
fi

# PHP 8.2
PHP_VERSION=""
if command -v php &>/dev/null; then
    PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)
    info "PHP $PHP_VERSION detectado"
fi
if [[ -z "$PHP_VERSION" ]]; then
    info "Agregando PPA ondrej/php..."
    add-apt-repository ppa:ondrej/php -y
    apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y \
        php8.2-fpm php8.2-cli php8.2-mysql php8.2-mbstring \
        php8.2-xml php8.2-curl php8.2-zip php8.2-bcmath php8.2-intl
    PHP_VERSION="8.2"
    info "PHP 8.2 instalado"
fi

for EXT in fpm cli mysql mbstring xml curl zip bcmath intl; do
    dpkg -l "php${PHP_VERSION}-${EXT}" &>/dev/null || \
        apt-get install -y "php${PHP_VERSION}-${EXT}" -qq 2>/dev/null || true
done

# Composer
if ! command -v composer &>/dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer -q
    info "Composer instalado"
fi

# Detectar socket PHP-FPM
detect_php_sock() {
    for path in "/run/php/php${1}-fpm.sock" "/var/run/php/php${1}-fpm.sock"; do
        [[ -S "$path" ]] && echo "unix:$path" && return
    done
}
systemctl enable "php${PHP_VERSION}-fpm" --now 2>/dev/null || true
sleep 1
PHP_SOCK=$(detect_php_sock "$PHP_VERSION")
[[ -z "$PHP_SOCK" ]] && PHP_SOCK="127.0.0.1:9000"
systemctl enable nginx --now 2>/dev/null || true
info "PHP-FPM socket: $PHP_SOCK"

# =============================================================================
#  PASO 1 — Acceso a GitHub
# =============================================================================
section "PASO 1 — Acceso a GitHub"

CLONE_URL=""
USE_SSH=false
SSH_KEY_PATH="/root/.ssh/chatbot_deploy_key"

SSH_TEST=$(ssh -T git@github.com -o StrictHostKeyChecking=no -o ConnectTimeout=5 2>&1 || true)
if echo "$SSH_TEST" | grep -q "successfully authenticated"; then
    info "Conexión SSH con GitHub activa — usando SSH"
    USE_SSH=true
    CLONE_URL="$REPO_SSH"
else
    echo ""
    echo -e "  No hay SSH con GitHub configurado. Elige método:"
    echo -e "  ${CYAN}1)${NC} SSH — generar clave nueva (recomendado)"
    echo -e "  ${CYAN}2)${NC} HTTPS — usuario + token"
    echo ""
    ask "Elige [1/2]:"
    read -r GH_METHOD

    if [[ "${GH_METHOD:-1}" == "1" ]]; then
        USE_SSH=true
        mkdir -p /root/.ssh && chmod 700 /root/.ssh
        rm -f "$SSH_KEY_PATH" "${SSH_KEY_PATH}.pub"
        ssh-keygen -t ed25519 -C "chatbot@$(hostname)" -f "$SSH_KEY_PATH" -N "" -q
        if ! grep -q "github.com" /root/.ssh/config 2>/dev/null; then
            cat >> /root/.ssh/config <<SSHCONF

Host github.com
    HostName github.com
    User git
    IdentityFile ${SSH_KEY_PATH}
    StrictHostKeyChecking no
SSHCONF
            chmod 600 /root/.ssh/config
        fi
        echo ""
        echo -e "${BOLD}${CYAN}  Copia esta clave en GitHub → Settings → SSH Keys:${NC}"
        echo -e "${CYAN}──────────────────────────────────────────────────${NC}"
        cat "${SSH_KEY_PATH}.pub"
        echo -e "${CYAN}──────────────────────────────────────────────────${NC}"
        echo ""
        ask "Presiona Enter cuando la hayas agregado en GitHub..."
        read -r
        CLONE_URL="$REPO_SSH"
    else
        ask "Usuario de GitHub:"
        read -r GH_USER
        ask "Token de acceso personal:"
        read -rs GH_TOKEN
        echo ""
        CLONE_URL="https://${GH_USER}:${GH_TOKEN}@github.com/Zefo94/chatbot-iptv-api.git"
    fi
fi

# =============================================================================
#  PASO 2 — Configuración global (se aplica a todos los revendedores)
# =============================================================================
section "PASO 2 — Configuración global"

echo -e "  ${BOLD}Estos datos se aplican a todos los revendedores.${NC}"
echo -e "  ${DIM}Cada revendedor solo necesita un nombre y su API Key propia de XUI.${NC}"
echo ""

# IP del servidor
SERVER_IP=$(curl -s -m 5 https://ifconfig.me 2>/dev/null \
  || curl -s -m 5 https://api.ipify.org 2>/dev/null \
  || hostname -I | awk '{print $1}')
SERVER_IP=$(echo "$SERVER_IP" | tr -d '[:space:]')
info "IP detectada: $SERVER_IP"

ask "Dominio base (Enter para usar IP, ej: miservidor.com):"
read -r DOMAIN_BASE_INPUT
DOMAIN_BASE_INPUT=$(echo "$DOMAIN_BASE_INPUT" | tr -d '[:space:]' | tr '[:upper:]' '[:lower:]')
if [[ -n "$DOMAIN_BASE_INPUT" ]]; then
    DOMAIN_BASE="$DOMAIN_BASE_INPUT"
    USE_DOMAIN=true
else
    DOMAIN_BASE="$SERVER_IP"
    USE_DOMAIN=false
fi

ask "URL del panel XUI.ONE (ej: http://tupanel.com/miapixui/):"
read -r GLOBAL_XUI_URL
GLOBAL_XUI_URL=$(echo "$GLOBAL_XUI_URL" | tr -d '[:space:]')
[[ -z "$GLOBAL_XUI_URL" ]] && fatal "La URL del panel XUI es obligatoria."

ask "API Key admin del panel XUI:"
read -r GLOBAL_XUI_APIKEY
[[ -z "$GLOBAL_XUI_APIKEY" ]] && fatal "La API Key de XUI es obligatoria."

ask "URL API revendedores XUI (Enter = misma URL del panel):"
read -r GLOBAL_XUI_RESELLER_URL
GLOBAL_XUI_RESELLER_URL=$(echo "$GLOBAL_XUI_RESELLER_URL" | tr -d '[:space:]')
[[ -z "$GLOBAL_XUI_RESELLER_URL" ]] && GLOBAL_XUI_RESELLER_URL="$GLOBAL_XUI_URL"

echo ""
echo -e "  ${DIM}── Pasarelas de pago (Enter para dejar comentadas, configurar luego) ──${NC}"
ask "PayPal Client ID (Enter para omitir):"
read -r GLOBAL_PAYPAL_CID
ask "PayPal Client Secret (Enter para omitir):"
read -rs GLOBAL_PAYPAL_CSEC; echo ""
ask "MercadoPago Access Token (Enter para omitir):"
read -r GLOBAL_MP_TOKEN
ask "Wompi Public Key (Enter para omitir):"
read -r GLOBAL_WOMPI_PUB
ask "Wompi Private Key (Enter para omitir):"
read -rs GLOBAL_WOMPI_PRIV; echo ""

echo ""
ask "¿Cuántos revendedores instalar? [1-10]:"
read -r NUM_RESELLERS
[[ ! "$NUM_RESELLERS" =~ ^[1-9]$|^10$ ]] && fatal "Número inválido (1-10)"
info "Se instalarán $NUM_RESELLERS revendedor(es)"

# =============================================================================
#  PASO 3 — Datos por revendedor (mínimos)
# =============================================================================
section "PASO 3 — Datos de cada revendedor"

echo -e "  ${DIM}Solo se piden: nombre/slug y la API Key propia del revendedor en XUI.${NC}"
echo ""

declare -A R_DATA

for ((i=1; i<=NUM_RESELLERS; i++)); do
    echo -e "${BOLD}${YELLOW}  ┌─ Revendedor $i de $NUM_RESELLERS ─────────────────────────────┐${NC}"

    ask "Nombre del revendedor (sin espacios, ej: reseller1):"
    read -r SLUG
    SLUG=$(echo "$SLUG" | tr '[:upper:]' '[:lower:]' | tr -cs 'a-z0-9' '-' | sed 's/^-//;s/-$//')
    [[ -z "$SLUG" ]] && SLUG="reseller${i}"

    if [[ "$USE_DOMAIN" == "true" ]]; then
        ask "Subdominio (Enter = api-${SLUG}.${DOMAIN_BASE}):"
        read -r SUBDOMAIN
        if [[ -z "$SUBDOMAIN" ]]; then
            FULL_DOMAIN="api-${SLUG}.${DOMAIN_BASE}"
        elif [[ "$SUBDOMAIN" == *"."* ]]; then
            FULL_DOMAIN="$SUBDOMAIN"
        else
            FULL_DOMAIN="${SUBDOMAIN}.${DOMAIN_BASE}"
        fi
    else
        FULL_DOMAIN="$SERVER_IP"
    fi

    ask "API Key del revendedor en XUI (xui_api_key del reseller en el panel):"
    read -r XUI_RESELLER_APIKEY

    R_DATA["${i}_slug"]="$SLUG"
    R_DATA["${i}_domain"]="$FULL_DOMAIN"
    R_DATA["${i}_xui_reseller_apikey"]="${XUI_RESELLER_APIKEY:-}"

    echo -e "${GREEN}  ✓ Revendedor '$SLUG' registrado${NC}"
    echo ""
done

# =============================================================================
#  PASO 4 — Acceso MySQL
# =============================================================================
section "PASO 4 — MySQL"

ask "Contraseña root de MySQL/MariaDB (Enter si usa socket sin contraseña):"
read -rs MYSQL_ROOT_PASS; echo ""

mysql_cmd() {
    if [[ -n "$MYSQL_ROOT_PASS" ]]; then
        mysql -u root -p"$MYSQL_ROOT_PASS" -e "$1" 2>/dev/null
    else
        mysql -u root -e "$1" 2>/dev/null
    fi
}
mysql_file() {
    if [[ -n "$MYSQL_ROOT_PASS" ]]; then
        mysql -u root -p"$MYSQL_ROOT_PASS" "$1" < "$2" 2>/dev/null
    else
        mysql -u root "$1" < "$2" 2>/dev/null
    fi
}

mysql_cmd "SELECT 1" &>/dev/null || fatal "No se puede conectar a MySQL. Verifica la contraseña."
info "Acceso MySQL verificado"

# =============================================================================
#  PASO 5 — Desplegar cada revendedor
# =============================================================================
section "PASO 5 — Desplegando revendedores"

declare -a SUMMARY_SLUGS=()
declare -a SUMMARY_URLS=()
declare -a SUMMARY_KEYS=()
declare -a SUMMARY_DBS=()
declare -a SUMMARY_DB_USERS=()
declare -a SUMMARY_DB_PASSES=()

for ((i=1; i<=NUM_RESELLERS; i++)); do
    SLUG="${R_DATA["${i}_slug"]}"
    FULL_DOMAIN="${R_DATA["${i}_domain"]}"
    XUI_RESELLER_APIKEY="${R_DATA["${i}_xui_reseller_apikey"]:-}"

    APP_DIR="${BASE_DIR}/chatbot-${SLUG}"
    DB_NAME="chatbot_${SLUG}"
    DB_USER="chatbot_${SLUG}"
    DB_PASS=$(openssl rand -hex 16)
    CHATBOT_API_KEY=$(openssl rand -hex 32)

    [[ "$USE_DOMAIN" == "true" ]] && APP_URL="https://${FULL_DOMAIN}" || APP_URL="http://${SERVER_IP}:$((8010 + i - 1))"

    echo ""
    echo -e "${BOLD}${CYAN}  ── Revendedor: ${YELLOW}${SLUG}${NC}"

    # Clonar / actualizar
    step "Clonando código..."
    if [[ -d "$APP_DIR/.git" ]]; then
        cd "$APP_DIR"
        git pull -q origin "$BRANCH" || warn "git pull falló, usando código existente"
    else
        mkdir -p "$(dirname "$APP_DIR")"
        if [[ "$USE_SSH" == "true" ]] && [[ -f "$SSH_KEY_PATH" ]]; then
            GIT_SSH_COMMAND="ssh -i ${SSH_KEY_PATH} -o StrictHostKeyChecking=no" \
                git clone -q -b "$BRANCH" "$CLONE_URL" "$APP_DIR"
        else
            git clone -q -b "$BRANCH" "$CLONE_URL" "$APP_DIR"
        fi
    fi
    git config --global --add safe.directory "$APP_DIR" 2>/dev/null || true

    # .env
    step "Generando .env..."

    # Construir bloques de pasarelas de pago (comentados si vacíos)
    _paypal_block() {
        if [[ -n "$GLOBAL_PAYPAL_CID" ]]; then
            echo "PAYPAL_CLIENT_ID=${GLOBAL_PAYPAL_CID}"
            echo "PAYPAL_CLIENT_SECRET=${GLOBAL_PAYPAL_CSEC}"
            echo "PAYPAL_WEBHOOK_ID="
            echo "PAYPAL_MODE=sandbox"
            echo "PAYPAL_CURRENCY=EUR"
            echo "PAYPAL_PRICE_PER_CREDIT=10.00"
            echo "PAYPAL_RETURN_URL=${APP_URL}/pago-exitoso"
            echo "PAYPAL_CANCEL_URL=${APP_URL}/pago-cancelado"
        else
            echo "# Ejemplo SANDBOX (pruebas):"
            echo "#   PAYPAL_CLIENT_ID=AaBbCcDd1234_sandbox_id_aqui"
            echo "#   PAYPAL_CLIENT_SECRET=EeFfGgHh5678_sandbox_secret_aqui"
            echo "#   PAYPAL_WEBHOOK_ID=WH-SANDBOX-XXXXXXXXXXXX"
            echo "#   PAYPAL_MODE=sandbox"
            echo "#"
            echo "# Ejemplo LIVE (producción):"
            echo "#   PAYPAL_CLIENT_ID=AaBbCcDd1234_live_id_aqui"
            echo "#   PAYPAL_CLIENT_SECRET=EeFfGgHh5678_live_secret_aqui"
            echo "#   PAYPAL_WEBHOOK_ID=WH-XXXXXXXXXXXXXXXX"
            echo "#   PAYPAL_MODE=live"
            echo "#"
            echo "#PAYPAL_CLIENT_ID="
            echo "#PAYPAL_CLIENT_SECRET="
            echo "#PAYPAL_WEBHOOK_ID="
            echo "#PAYPAL_MODE=sandbox"
            echo "#PAYPAL_CURRENCY=EUR"
            echo "#PAYPAL_PRICE_PER_CREDIT=10.00"
            echo "#PAYPAL_RETURN_URL=${APP_URL}/pago-exitoso"
            echo "#PAYPAL_CANCEL_URL=${APP_URL}/pago-cancelado"
        fi
    }
    _mp_block() {
        if [[ -n "$GLOBAL_MP_TOKEN" ]]; then
            echo "MERCADOPAGO_ACCESS_TOKEN=${GLOBAL_MP_TOKEN}"
            echo "MERCADOPAGO_WEBHOOK_SECRET="
        else
            echo "#MERCADOPAGO_ACCESS_TOKEN="
            echo "#MERCADOPAGO_WEBHOOK_SECRET="
        fi
    }
    _wompi_block() {
        if [[ -n "$GLOBAL_WOMPI_PUB" ]]; then
            echo "WOMPI_PUBLIC_KEY=${GLOBAL_WOMPI_PUB}"
            echo "WOMPI_PRIVATE_KEY=${GLOBAL_WOMPI_PRIV}"
            echo "WOMPI_WEBHOOK_SECRET="
        else
            echo "#WOMPI_PUBLIC_KEY="
            echo "#WOMPI_PRIVATE_KEY="
            echo "#WOMPI_WEBHOOK_SECRET="
        fi
    }

    cat > "${APP_DIR}/.env" << ENVEOF
# Revendedor: ${SLUG} — generado $(date '+%Y-%m-%d %H:%M')

APP_ENV=production
APP_DEBUG=false
APP_URL=${APP_URL}

# ── Autenticación del Chatbot ──────────────────────────────────────────────
CHATBOT_API_KEY=${CHATBOT_API_KEY}

# ── Base de Datos ──────────────────────────────────────────────────────────
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}

# ── Panel XUI.ONE ──────────────────────────────────────────────────────────
XUI_API_URL=${GLOBAL_XUI_URL}
XUI_API_KEY=${GLOBAL_XUI_APIKEY}
XUI_USERNAME=
XUI_PASSWORD=
XUI_RESELLER_API_URL=${GLOBAL_XUI_RESELLER_URL}
XUI_RESELLER_API_KEY=${XUI_RESELLER_APIKEY}
XUI_DEFAULT_PACKAGE_ID=1
XUI_DEFAULT_MAX_CONNECTIONS=1

# ── PayPal ─────────────────────────────────────────────────────────────────
$(_paypal_block)

# ── MercadoPago ────────────────────────────────────────────────────────────
$(_mp_block)

# ── Wompi ──────────────────────────────────────────────────────────────────
$(_wompi_block)

# ── Binance Pay ────────────────────────────────────────────────────────────
#BINANCE_API_KEY=
#BINANCE_SECRET_KEY=
ENVEOF

    chmod 600 "${APP_DIR}/.env"

    # Composer
    step "Instalando dependencias PHP..."
    cd "$APP_DIR"
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader -q

    # Permisos
    chown -R www-data:www-data "$APP_DIR"
    find "$APP_DIR" -type f -exec chmod 644 {} \;
    find "$APP_DIR" -type d -exec chmod 755 {} \;
    chmod 600 "${APP_DIR}/.env"
    [[ -d "${APP_DIR}/storage" ]] && chmod -R 775 "${APP_DIR}/storage"

    # Base de datos
    step "Creando base de datos..."
    mysql_cmd "DROP DATABASE IF EXISTS \`${DB_NAME}\`;" || true
    mysql_cmd "CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql_cmd "DROP USER IF EXISTS '${DB_USER}'@'localhost';" || true
    mysql_cmd "CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
    mysql_cmd "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
    mysql_cmd "FLUSH PRIVILEGES;"
    [[ -f "${APP_DIR}/schema.sql" ]] && mysql_file "${DB_NAME}" "${APP_DIR}/schema.sql"

    # Nginx vhost
    step "Configurando Nginx..."
    NGINX_CONF="/etc/nginx/sites-available/chatbot-${SLUG}"

    if [[ "$USE_DOMAIN" == "true" ]]; then
        cat > "$NGINX_CONF" << NGINXCONF
server {
    listen 80;
    server_name ${FULL_DOMAIN};
    root ${APP_DIR}/public;
    index index.php index.html;
    client_max_body_size 50M;
    access_log /var/log/nginx/chatbot-${SLUG}-access.log;
    error_log  /var/log/nginx/chatbot-${SLUG}-error.log;

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }

    location ~ \.php$ {
        fastcgi_pass ${PHP_SOCK};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(env|ht) { deny all; return 404; }
}
NGINXCONF
    else
        PORT=$((8010 + i - 1))
        APP_URL="http://${SERVER_IP}:${PORT}"
        cat > "$NGINX_CONF" << NGINXCONF
server {
    listen ${PORT};
    server_name _;
    root ${APP_DIR}/public;
    index index.php index.html;
    client_max_body_size 50M;
    access_log /var/log/nginx/chatbot-${SLUG}-access.log;
    error_log  /var/log/nginx/chatbot-${SLUG}-error.log;

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }

    location ~ \.php$ {
        fastcgi_pass ${PHP_SOCK};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(env|ht) { deny all; return 404; }
}
NGINXCONF
        ufw allow "${PORT}/tcp" &>/dev/null || true
    fi

    ln -sf "$NGINX_CONF" "/etc/nginx/sites-enabled/chatbot-${SLUG}"

    # SSL
    if [[ "$USE_DOMAIN" == "true" ]]; then
        nginx -t 2>/dev/null && systemctl reload nginx
        echo ""
        echo -e "  ¿Cómo maneja el SSL ${FULL_DOMAIN}?"
        echo -e "  ${CYAN}1)${NC} Cloudflare  — proxy activo, servidor solo HTTP (recomendado)"
        echo -e "  ${CYAN}2)${NC} Let's Encrypt — instalar certificado en el servidor"
        echo -e "  ${CYAN}3)${NC} Sin SSL — HTTP solamente"
        echo ""
        ask "Elige [1/2/3] (Enter = 1):"
        read -r SSL_CHOICE
        SSL_CHOICE="${SSL_CHOICE:-1}"

        case "$SSL_CHOICE" in
            2)
                if certbot --nginx -d "$FULL_DOMAIN" --non-interactive --agree-tos \
                     -m "admin@${DOMAIN_BASE}" --redirect 2>/dev/null; then
                    APP_URL="https://${FULL_DOMAIN}"
                    info "Let's Encrypt activo para $FULL_DOMAIN"
                else
                    warn "Certbot falló — ejecuta luego: certbot --nginx -d ${FULL_DOMAIN}"
                fi
                ;;
            3)
                APP_URL="http://${FULL_DOMAIN}"
                info "Sin SSL — HTTP solamente"
                ;;
            *)
                APP_URL="https://${FULL_DOMAIN}"
                info "Cloudflare SSL — el servidor escucha HTTP, Cloudflare provee HTTPS"
                ;;
        esac
    fi

    SUMMARY_SLUGS+=("$SLUG")
    SUMMARY_URLS+=("$APP_URL")
    SUMMARY_KEYS+=("$CHATBOT_API_KEY")
    SUMMARY_DBS+=("$DB_NAME")
    SUMMARY_DB_USERS+=("$DB_USER")
    SUMMARY_DB_PASSES+=("$DB_PASS")

    info "Revendedor '${SLUG}' instalado"
done

# =============================================================================
#  PASO 6 — Recargar Nginx
# =============================================================================
nginx -t && systemctl reload nginx
info "Nginx recargado"

# =============================================================================
#  PASO 7 — Script de actualización rápida
# =============================================================================
cat > /usr/local/bin/update-chatbot-resellers.sh << 'UPDATESCRIPT'
#!/usr/bin/env bash
set -euo pipefail
GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'

for APP_DIR in /var/www/chatbot-*/; do
    SLUG=$(basename "$APP_DIR" | sed 's/chatbot-//')
    [[ ! -d "${APP_DIR}.git" ]] && echo "  Saltando $SLUG (no es repo git)" && continue
    echo -e "${YELLOW}[→]${NC} Actualizando $SLUG..."
    cd "$APP_DIR"
    git pull -q origin main
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader -q
    chown -R www-data:www-data "$APP_DIR"
    echo -e "${GREEN}[✓]${NC} $SLUG actualizado"
done

nginx -t && systemctl reload nginx
echo -e "${GREEN}[✓]${NC} Todos los revendedores actualizados"
UPDATESCRIPT
chmod +x /usr/local/bin/update-chatbot-resellers.sh

# =============================================================================
#  RESUMEN FINAL
# =============================================================================
section "INSTALACIÓN COMPLETADA"

{
echo "======================================================================"
echo "  CHATBOT IPTV — CREDENCIALES DE ACCESO"
echo "  Generado: $(date '+%Y-%m-%d %H:%M')"
echo "======================================================================"
echo ""
echo "  Para actualizar todos los revendedores:"
echo "    sudo update-chatbot-resellers.sh"
echo ""
} > "$SUMMARY_FILE"

for ((i=0; i<${#SUMMARY_SLUGS[@]}; i++)); do
    SLUG="${SUMMARY_SLUGS[$i]}"
    URL="${SUMMARY_URLS[$i]}"
    KEY="${SUMMARY_KEYS[$i]}"
    DB="${SUMMARY_DBS[$i]}"
    DBU="${SUMMARY_DB_USERS[$i]}"
    DBP="${SUMMARY_DB_PASSES[$i]}"

    echo -e "${BOLD}${CYAN}  ┌─ Revendedor: ${YELLOW}${SLUG}${NC}"
    echo -e "${BOLD}${CYAN}  │${NC}  URL API:        ${GREEN}${URL}${NC}"
    echo -e "${BOLD}${CYAN}  │${NC}  API Key:        ${YELLOW}${KEY}${NC}"
    echo -e "${BOLD}${CYAN}  │${NC}  Prueba:         curl -s ${URL}/api/info -H \"X-API-Key: ${KEY}\""
    echo -e "${BOLD}${CYAN}  │${NC}  BD:             ${DIM}${DB} / usuario: ${DBU}${NC}"
    echo -e "${BOLD}${CYAN}  │${NC}  BD Password:    ${DIM}${DBP}${NC}"
    echo -e "${BOLD}${CYAN}  │${NC}  Directorio:     ${DIM}/var/www/chatbot-${SLUG}${NC}"
    echo -e "${BOLD}${CYAN}  │${NC}  Config .env:    ${DIM}/var/www/chatbot-${SLUG}/.env${NC}"
    echo -e "${BOLD}${CYAN}  └──────────────────────────────────────────────${NC}"
    echo ""

    {
    echo "----------------------------------------------------------------------"
    echo "REVENDEDOR: $SLUG"
    echo "----------------------------------------------------------------------"
    echo "  URL API     : $URL"
    echo "  API Key     : $KEY"
    echo "  BD          : $DB"
    echo "  BD Usuario  : $DBU"
    echo "  BD Password : $DBP"
    echo "  Directorio  : /var/www/chatbot-${SLUG}"
    echo "  Config .env : /var/www/chatbot-${SLUG}/.env"
    echo ""
    echo "  Activar pasarelas de pago:"
    echo "    nano /var/www/chatbot-${SLUG}/.env"
    echo "    (descomenta y rellena PAYPAL_, MERCADOPAGO_, WOMPI_ según necesites)"
    echo "    service php8.2-fpm restart"
    echo ""
    } >> "$SUMMARY_FILE"
done

{
echo "======================================================================"
echo "  Comandos útiles"
echo "======================================================================"
echo "  Actualizar todo:  sudo update-chatbot-resellers.sh"
echo "  Ver logs nginx:   tail -f /var/log/nginx/chatbot-{slug}-error.log"
echo "  Ver logs API:     tail -f /var/www/chatbot-{slug}/storage/logs/*.log"
echo "  Reiniciar nginx:  service nginx restart"
echo "  Reiniciar PHP:    service php8.2-fpm restart"
echo "======================================================================"
} >> "$SUMMARY_FILE"

echo -e "  ${BOLD}Credenciales guardadas en:${NC} ${CYAN}${SUMMARY_FILE}${NC}"
echo ""
echo -e "${BOLD}${GREEN}  ✅ Deploy completado — ${#SUMMARY_SLUGS[@]} revendedor(es) instalados${NC}"
echo ""
