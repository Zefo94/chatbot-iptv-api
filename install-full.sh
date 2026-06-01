#!/bin/bash
set -e

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
info()  { echo -e "${GREEN}[✓]${NC} $*"; }
warn()  { echo -e "${YELLOW}[!]${NC} $*"; }
fatal() { echo -e "${RED}[✗]${NC} $*" >&2; exit 1; }

echo "=========================================="
echo "  Chatbot IPTV — Instalación Completa"
echo "=========================================="

# ── Detectar IP automáticamente ───────────────────────────
SERVER_IP=$(curl -s -m 5 https://ifconfig.me 2>/dev/null \
  || curl -s -m 5 https://api.ipify.org 2>/dev/null \
  || hostname -I | awk '{print $1}' || echo "")
SERVER_IP=$(echo "$SERVER_IP" | tr -d '[:space:]')
info "IP detectada: $SERVER_IP"
echo "=========================================="

# ── 0. Limpiar locks de dpkg ────────────────────────────────
info "Limpiando locks de dpkg..."
rm -f /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/cache/apt/archives/lock 2>/dev/null || true
dpkg --configure -a 2>/dev/null || true

# ── 1. Limpiar Docker ───────────────────────────────────────
info "Limpiando Docker..."
docker stop $(docker ps -aq) 2>/dev/null || true
docker rm -f $(docker ps -aq) 2>/dev/null || true
docker rmi -f $(docker images -q) 2>/dev/null || true
docker volume rm $(docker volume ls -q) 2>/dev/null || true
service nginx stop 2>/dev/null || true
service php8.2-fpm stop 2>/dev/null || true
service mariadb stop 2>/dev/null || true

# ── 2. Instalar paquetes base ───────────────────────────────
info "Instalando paquetes base..."
export DEBIAN_FRONTEND=noninteractive

# Esperar a que no haya locks
for i in $(seq 1 30); do
    if fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1; then
        warn "dpkg ocupado, esperando... ($i/30)"
        sleep 5
    else
        break
    fi
done

apt-get update -qq 2>&1 | tail -3

# Instalar nginx + mariadb + herramientas
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
    nginx mariadb-server mariadb-client curl git unzip openssl \
    software-properties-common 2>&1 | tail -5

# ── 3. PHP desde PPA ────────────────────────────────────────
info "Instalando PHP 8.2 desde PPA..."
add-apt-repository -y ppa:ondrej/php 2>&1 | tail -3
apt-get update -qq 2>&1 | tail -3

DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
    php8.2-fpm php8.2-mysql php8.2-cli php8.2-xml php8.2-mbstring \
    php8.2-curl php8.2-zip php8.2-bcmath php8.2-opcache php8.2-intl 2>&1 | tail -5

# Verificar
php -v | head -1 || fatal "PHP no se instaló"
nginx -v 2>&1 | head -1 || fatal "Nginx no se instaló"
mariadb --version | head -1 || fatal "MariaDB no se instaló"

# ── 4. Timezone y límites PHP ───────────────────────────────
info "Configurando PHP..."
echo "date.timezone = America/Montevideo" > /etc/php/8.2/fpm/conf.d/99-timezone.ini
echo "date.timezone = America/Montevideo" > /etc/php/8.2/cli/conf.d/99-timezone.ini

# ── 5. Repo y Composer ──────────────────────────────────────
info "Clonando repo..."
rm -rf /var/www/html
mkdir -p /var/www/html
cd /var/www/html
git clone https://github.com/Zefo94/chatbot-iptv-api.git . 2>&1 | tail -3

# ── Patch: agregar función env() que falta en public/index.php ──
info "Aplicando patch de env()..."
if ! grep -q "^function env(" /var/www/html/public/index.php 2>/dev/null; then
    sed -i '/function loadEnvironmentVariables/i\
function env($key, $default = null) {\
    return $_ENV[$key] ?? getenv($key) ?: $default;\
}\
' /var/www/html/public/index.php
fi

ls -la

info "Instalando Composer..."
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
chmod +x /usr/local/bin/composer

info "Instalando dependencias PHP..."
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction 2>&1 | tail -5

# ── 6. MariaDB ──────────────────────────────────────────────
info "Configurando MariaDB..."
service mariadb start

# Esperar MySQL
for i in $(seq 1 15); do
    if mariadb -u root -e "SELECT 1" &>/dev/null; then
        info "MariaDB listo tras ${i}s"
        break
    fi
    [ $i -eq 15 ] && fatal "MariaDB no respondió"
    sleep 1
done

# Crear DB y usuario (password temporal)
mariadb -u root << 'EOSQL'
CREATE DATABASE IF NOT EXISTS `iptv_manager` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'iptv_user'@'localhost' IDENTIFIED BY 'temp_pass_placeholder';
GRANT ALL PRIVILEGES ON `iptv_manager`.* TO 'iptv_user'@'localhost';
FLUSH PRIVILEGES;
EOSQL

# Importar schema
mariadb -u iptv_user -p'temp_pass_placeholder' iptv_manager < /var/www/html/schema.sql 2>&1 | tail -3

info "Base de datos lista"

# ── 7. Nginx ────────────────────────────────────────────────
info "Configurando Nginx..."

cat > /etc/nginx/sites-available/chatbot << 'NGINXCONF'
server {
    listen 80;
    server_name _;
    root /var/www/html/public;
    index index.php index.html;
    client_max_body_size 50M;

    # Seguridad
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /\.ht { deny all; }
}
NGINXCONF

rm -f /etc/nginx/sites-enabled/default
ln -sf /etc/nginx/sites-available/chatbot /etc/nginx/sites-enabled/chatbot

# ── 8. .env ──────────────────────────────────────────────────
info "Generando .env..."

CHATBOT_KEY=$(openssl rand -hex 32)
DB_PASS=$(openssl rand -hex 20)
MYSQL_ROOT_PASS=$(openssl rand -hex 20)

# PayPal: dejar vacío para que el usuario lo complete desde el panel
PAYPAL_CLIENT_ID_VALUE="YOUR_PAYPAL_CLIENT_ID"
PAYPAL_CLIENT_SECRET_VALUE="YOUR_PAYPAL_CLIENT_SECRET"

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
MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASS}
XUI_API_URL=http://tripic.space/miapixui/
XUI_API_KEY=30B0DD094F41213AB3394E374BA6A557
XUI_RESELLER_API_URL=http://tripic.space:80/resselerapi/
PAYPAL_CLIENT_ID=${PAYPAL_CLIENT_ID_VALUE}
PAYPAL_CLIENT_SECRET=${PAYPAL_CLIENT_SECRET_VALUE}
PAYPAL_WEBHOOK_ID=
PAYPAL_MODE=sandbox
PAYPAL_PRICE_PER_CREDIT=10.00
EOF

chown www-data:www-data /var/www/html/.env
chmod 600 /var/www/html/.env

# Actualizar password real del usuario DB usando variable expandida
info "Estableciendo password de DB..."
mariadb -u root -e "SET PASSWORD FOR 'iptv_user'@'localhost' = PASSWORD('${DB_PASS}'); FLUSH PRIVILEGES;"

# ── 9. Permisos ─────────────────────────────────────────────
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html
mkdir -p /var/www/html/storage/logs /var/www/html/storage/cache
chown -R www-data:www-data /var/www/html/storage

# ── 10. Verificar symlink del socket PHP-FPM ──────────────
if [ -L /var/run/php/php8.2-fpm.sock ] || [ -e /var/run/php/php8.2-fpm.sock ]; then
    info "Socket PHP-FPM OK: $(ls -l /var/run/php/php8.2-fpm.sock 2>/dev/null | awk '{print $NF}')"
else
    warn "Socket PHP-FPM no encontrado en /var/run/php/"
    ls -la /var/run/php/ 2>/dev/null || warn "Directorio /var/run/php/ no existe"
fi

# ── 11. Arrancar servicios ────────────────────────────────────
info "Arrancando servicios..."
service php8.2-fpm restart
service nginx restart
service mariadb restart

# ── 12. Verificar ───────────────────────────────────────────
sleep 3

echo ""
echo "=========================================="
echo "  Verificando..."
echo "=========================================="

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/api/info 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "401" ]; then
    info "API respondiendo correctamente (401 = necesita X-API-Key)"
elif [ "$HTTP_CODE" = "200" ]; then
    info "API respondiendo correctamente"
else
    warn "API respondió código: $HTTP_CODE"
fi

curl -s http://localhost/api/info 2>/dev/null | head -c 200

echo ""
echo ""
echo "=========================================="
echo -e "${GREEN}  ¡INSTALACIÓN COMPLETA!${NC}"
echo "=========================================="
echo ""
echo -e "${BLUE}  URL:       ${NC}http://${SERVER_IP}/api/info"
echo -e "${BLUE}  DB User:  ${NC}iptv_user"
echo -e "${BLUE}  DB Pass:  ${NC}${DB_PASS}"
echo -e "${BLUE}  Database: ${NC}iptv_manager"
echo -e "${BLUE}  API Key:  ${NC}${CHATBOT_KEY}"
echo ""
echo -e "${YELLOW}  ⚠️  PayPal: configurar PAYPAL_CLIENT_ID y PAYPAL_CLIENT_SECRET en /var/www/html/.env"
echo ""
echo -e "${BLUE}  Comandos útiles:${NC}"
echo "    service nginx restart"
echo "    service php8.2-fpm restart"
echo "    service mariadb restart"