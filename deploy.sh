#!/bin/bash
# ===================================================================
# Chatbot IPTV — Autodeploy desde GitHub
# Usage: bash deploy.sh
# ===================================================================

set -e

REPO="https://github.com/Zefo94/chatbot-iptv-api.git"
BRANCH="main"
DEPLOY_DIR="/opt/chatbot-iptv"

RED='\033[0;31m'; GREEN='\033[0;32m'; NC='\033[0m'
info()  { echo -e "${GREEN}[✓]${NC} $*"; }
fatal() { echo -e "${RED}[✗]${NC} $*" >&2; exit 1; }

echo "=========================================="
echo "  Chatbot IPTV — Autodeploy"
echo "=========================================="

# ── Detectar si es primera instalación ─────────────────────────
FIRST_INSTALL=0
if [ ! -d "$DEPLOY_DIR/.git" ]; then
    FIRST_INSTALL=1
    info "Primera instalación — clonando repo..."
    mkdir -p "$(dirname "$DEPLOY_DIR")"
    git clone -b "$BRANCH" "$REPO" "$DEPLOY_DIR"
else
    info "Repositorio encontrado — actualizando..."
    cd "$DEPLOY_DIR" && git pull origin "$BRANCH"
fi

cd "$DEPLOY_DIR"

# ──Primera vez: generar .env y Provisionar DB ─────────────────
if [ ! -f .env ] || [ ! -s .env ]; then
    FIRST_INSTALL=1
    info "Generando .env con secrets seguros..."
    SERVER_IP=$(curl -s -m 5 https://ifconfig.me 2>/dev/null || curl -s -m 5 https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}')
    DB_PASS=$(openssl rand -hex 20)
    MYSQL_ROOT_PASS=$(openssl rand -hex 20)
    CHATBOT_KEY=$(openssl rand -hex 32)

    cat > .env << EOF
APP_ENV=production
APP_DEBUG=false
APP_URL=http://${SERVER_IP}:8000
CHATBOT_API_KEY=${CHATBOT_KEY}
DB_HOST=db
DB_PORT=3306
DB_NAME=iptv_manager
DB_USER=iptv_user
DB_PASS=${DB_PASS}
MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASS}
XUI_API_URL=http://tripic.space/miapixui/
XUI_API_KEY=30B0DD094F41213AB3394E374BA6A557
XUI_RESELLER_API_URL=http://tripic.space:80/resselerapi/
PAYPAL_CLIENT_ID=AYDaJPU1GIsJwg9yPLgll-QdBnYIqvPRr3PO-gzB0RaHVbPKUGc0qtoUFqp3q-nl-n6t1e_W3pQxIzf7
PAYPAL_CLIENT_SECRET=ECmJk1sCytjaAhVo6nWMBzKy4LoXYJZFfAXgc7a2AytHEOgEOYVveludgyQLv5qVcmCPc8xZdIk8D0ud
PAYPAL_WEBHOOK_ID=
PAYPAL_MODE=sandbox
PAYPAL_PRICE_PER_CREDIT=10.00
EOF
    info ".env generado con IP=${SERVER_IP}"
fi

# ── Instalar dependencias PHP ──────────────────────────────────
if [ ! -d "vendor" ]; then
    info "Instalando dependencias PHP (composer)..."
    composer install --no-dev --optimize-autoloader --no-interaction
fi

# ── Construir e iniciar Docker ─────────────────────────────────
info "Iniciando contenedores Docker..."
docker compose up -d --build

# ── Esperar MySQL ──────────────────────────────────────────────
info "Esperando MySQL..."
for i in $(seq 1 30); do
    if docker exec iptv_chatbot_db mysqladmin ping -h 127.0.0.1 --silent 2>/dev/null; then
        info "MySQL listo"
        break
    fi
    [ $i -eq 30 ] && fatal "MySQL no respondió"
    sleep 1
done

# ── Provisionar DB (solo si es primera vez) ────────────────────
if [ "$FIRST_INSTALL" = "1" ]; then
    source .env
    info "Creando base de datos..."
    docker exec iptv_chatbot_db mysql -u root -p"${MYSQL_ROOT_PASSWORD}" << 'EOSQL'
CREATE DATABASE IF NOT EXISTS `iptv_manager` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'iptv_user'@'%' IDENTIFIED BY 'PLACEHOLDER';
SET PASSWORD FOR 'iptv_user'@'%' = PASSWORD('PLACEHOLDER');
GRANT ALL PRIVILEGES ON `iptv_manager`.* TO 'iptv_user'@'%';
FLUSH PRIVILEGES;
EOSQL
    docker exec iptv_chatbot_db mysql -u root -p"${MYSQL_ROOT_PASSWORD}" \
        -e "SET PASSWORD FOR 'iptv_user'@'%' = PASSWORD('${DB_PASS}'); FLUSH PRIVILEGES;"

    info "Importando schema..."
    docker exec -i iptv_chatbot_db mysql -u iptv_user -p"${DB_PASS}" iptv_manager < schema.sql

    docker exec iptv_chatbot_php sh -c "chown -R www-data:www-data /var/www/html/storage" 2>/dev/null || true
    info "Base de datos provisioned OK"
fi

# ── Reiniciar servicios ─────────────────────────────────────────
info "Reiniciando servicios..."
docker compose restart

sleep 3

# ── Resumen ────────────────────────────────────────────────────
IP=$(grep "^APP_URL=" .env | cut -d= -f2 | tr -d '[:space:]' | sed 's|http://||')
echo ""
echo "=========================================="
echo -e "${GREEN}  ¡DEPLOY COMPLETO!${NC}"
echo "=========================================="
echo ""
echo "  API:        ${IP}"
echo "  Webhook:    ${IP}/api/webhook-pago?gateway=paypal"
echo ""
echo "  Para actualizar en el futuro:"
echo "    cd $DEPLOY_DIR && bash deploy.sh"
echo ""