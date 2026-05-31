#!/bin/bash
set -e
# ===================================================================
# Chatbot IPTV — Setup automático para VPS limpio
# Usage: bash setup.sh
# ===================================================================

set -e

SERVER_IP=$(curl -s -m 5 https://ifconfig.me 2>/dev/null \
  || curl -s -m 5 https://api.ipify.org 2>/dev/null \
  || hostname -I | awk '{print $1}' || echo "")
SERVER_IP=$(echo "$SERVER_IP" | tr -d '[:space:]')

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
info()  { echo -e "${GREEN}[✓]${NC} $*"; }
warn()  { echo -e "${YELLOW}[!]${NC} $*"; }
fatal() { echo -e "${RED}[✗]${NC} $*" >&2; exit 1; }

cd "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "=========================================="
echo "  Chatbot IPTV — Setup VPS"
echo "=========================================="
echo "  IP detectada: $SERVER_IP"
echo "=========================================="

# ── Dominio opcional ───────────────────────────────────────────
read -p "Dominio (ENTER para omitir, usar http://IP:80): " DOMAIN_INPUT
DOMAIN_INPUT=$(echo "$DOMAIN_INPUT" | tr -d '[:space:]' | tr '[:upper:]' '[:lower:]')

mkdir -p storage/logs storage/cache

# ── Generar secrets ───────────────────────────────────────────
info "Generando secrets..."
DB_PASS=$(openssl rand -hex 20)
MYSQL_ROOT_PASS=$(openssl rand -hex 20)
CHATBOT_KEY=$(openssl rand -hex 32)

# ── .env ──────────────────────────────────────────────────────
if [ ! -f .env ]; then
    info "Creando .env..."
    cat > .env << EOF
APP_ENV=production
APP_DEBUG=false
APP_URL=http://${SERVER_IP}
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
else
    info ".env ya existe — omitiendo"
    sed -i 's|^DB_HOST=.*|DB_HOST=db|' .env
    sed -i "s|^APP_URL=.*|APP_URL=http://${SERVER_IP}|" .env
fi

# ── Docker compose up ─────────────────────────────────────────
info "Levantando contenedores..."
docker compose up -d --build

# ── Esperar MySQL ────────────────────────────────────────────
info "Esperando MySQL..."
for i in $(seq 1 30); do
    if docker exec iptv_chatbot_db mariadb -u root -p"${MYSQL_ROOT_PASS}" -e "SELECT 1" &>/dev/null; then
        info "MySQL listo tras ${i}s"
        break
    fi
    [ $i -eq 30 ] && fatal "MySQL no respondió"
    sleep 1
done

# ── Provisionar DB ────────────────────────────────────────────
info "Provisionando base de datos..."
docker exec iptv_chatbot_db mariadb -u root -p"${MYSQL_ROOT_PASS}" << 'EOSQL'
CREATE DATABASE IF NOT EXISTS `iptv_manager`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'iptv_user'@'%' IDENTIFIED BY 'PLACEHOLDER';
SET PASSWORD FOR 'iptv_user'@'%' = PASSWORD('PLACEHOLDER');
GRANT ALL PRIVILEGES ON `iptv_manager`.* TO 'iptv_user'@'%';
FLUSH PRIVILEGES;
EOSQL

DB_PASS_VAL=$(grep "^DB_PASS=" .env | cut -d= -f2)
docker exec iptv_chatbot_db mariadb -u root -p"${MYSQL_ROOT_PASS}" \
  -e "SET PASSWORD FOR 'iptv_user'@'%' = PASSWORD('${DB_PASS_VAL}'); FLUSH PRIVILEGES;"

docker exec -i iptv_chatbot_db mariadb -u iptv_user -p"${DB_PASS_VAL}" iptv_manager < schema.sql

docker exec iptv_chatbot_php sh -c "chown -R www-data:www-data /var/www/html/storage" 2>/dev/null || true

# ── Resumen ──────────────────────────────────────────────────
MYSQL_IP=$(docker inspect iptv_chatbot_db | grep -oP '"IPAddress"\s*:\s*"\K[0-9.]+')
API_KEY=$(grep "^CHATBOT_API_KEY=" .env | cut -d= -f2)

echo ""
echo "=========================================="
echo -e "${GREEN}  ¡DESPLIEGUE COMPLETO!${NC}"
echo "=========================================="
echo ""
echo -e "${BLUE}  API local:    ${NC}http://${SERVER_IP}/api/info"
echo -e "${BLUE}  Webhook:      ${NC}http://${SERVER_IP}/api/webhook-pago?gateway=paypal"
echo ""
echo -e "${BLUE}  DB MySQL (interno Docker):${NC}"
echo "    Host:     db"
echo "    User:     iptv_user"
echo "    Pass:     ${DB_PASS_VAL}"
echo "    Database: iptv_manager"
echo ""
echo -e "${BLUE}  API Key:  ${API_KEY}"
echo ""
echo -e "${BLUE}  Comandos:${NC}"
echo "    Ver logs:  docker compose logs -f"
echo "    Reiniciar: docker compose restart"
echo ""