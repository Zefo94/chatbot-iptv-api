#!/bin/bash
set -e
# ===================================================================
# Chatbot IPTV — Setup automático para VPS
# Ejecución:  bash setup.sh
# ===================================================================

# ── Detectar IP pública ─────────────────────────────────────────
SERVER_IP=$(curl -s -m 5 https://ifconfig.me 2>/dev/null \
  || curl -s -m 5 https://api.ipify.org 2>/dev/null \
  || hostname -I | awk '{print $1}' || echo "")
SERVER_IP=$(echo "$SERVER_IP" | tr -d '[:space:]')

echo "=========================================="
echo "  Chatbot IPTV — Setup VPS"
echo "=========================================="
echo "  IP detectada: $SERVER_IP"
echo "=========================================="

# ── Colores ──────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
info()  { echo -e "${GREEN}[✓]${NC} $*"; }
warn()  { echo -e "${YELLOW}[!]${NC} $*"; }
fatal() { echo -e "${RED}[✗]${NC} $*" >&2; exit 1; }

cd "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── Permisos ───────────────────────────────────────────────────
chmod +x setup.sh 2>/dev/null || true
mkdir -p storage/logs storage/cache

# ── 1. Generar secrets aleatorios ────────────────────────────────
info "Generando secrets seguros..."
DB_PASS=$(openssl rand -hex 20)
MYSQL_ROOT_PASS=$(openssl rand -hex 20)
CHATBOT_KEY=$(openssl rand -hex 32)

# ── 2. Crear .env si no existe ──────────────────────────────────
if [ ! -f .env ]; then
    info "Creando archivo .env..."
    cat > .env << EOF
# ── App ─────────────────────────────────────────────────────────
APP_ENV=production
APP_DEBUG=false
APP_URL=http://${SERVER_IP}:8000

# ── Seguridad ────────────────────────────────────────────────────
CHATBOT_API_KEY=${CHATBOT_KEY}

# ── Base de datos MySQL (Docker) ────────────────────────────────
DB_HOST=db
DB_PORT=3306
DB_NAME=iptv_manager
DB_USER=iptv_user
DB_PASS=${DB_PASS}
MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASS}

# ── XUI.ONE (panel IPTV) ─────────────────────────────────────────
XUI_API_URL=http://tripic.space/miapixui/
XUI_API_KEY=30B0DD094F41213AB3394E374BA6A557
XUI_USERNAME=
XUI_PASSWORD=
XUI_DEFAULT_PACKAGE_ID=1
XUI_DEFAULT_MAX_CONNECTIONS=1
XUI_RESELLER_API_URL=http://tripic.space:80/resselerapi/

# ── Wompi ───────────────────────────────────────────────────────
WOMPI_PUBLIC_KEY=
WOMPI_PRIVATE_KEY=
WOMPI_WEBHOOK_SECRET=

# ── MercadoPago ─────────────────────────────────────────────────
MERCADOPAGO_ACCESS_TOKEN=
MERCADOPAGO_WEBHOOK_SECRET=

# ── PayPal ─────────────────────────────────────────────────────
PAYPAL_CLIENT_ID=AYDaJPU1GIsJwg9yPLgll-QdBnYIqvPRr3PO-gzB0RaHVbPKUGc0qtoUFqp3q-nl-n6t1e_W3pQxIzf7
PAYPAL_CLIENT_SECRET=ECmJk1sCytjaAhVo6nWMBzKy4LoXYJZFfAXgc7a2AytHEOgEOYVveludgyQLv5qVcmCPc8xZdIk8D0ud
PAYPAL_WEBHOOK_ID=
PAYPAL_MODE=sandbox
PAYPAL_PRICE_PER_CREDIT=10.00

# ── Binance ──────────────────────────────────────────────────────
BINANCE_API_KEY=
BINANCE_SECRET_KEY=
EOF
else
    # Si .env existe, asegurar que DB_PASS y CHATBOT_API_KEY estén generados
    info "Actualizando .env con nuevos secrets..."
    sed -i "s|^DB_PASS=.*|DB_PASS=${DB_PASS}|" .env
    sed -i "s|^MYSQL_ROOT_PASSWORD=.*|MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASS}|" .env
    sed -i "s|^CHATBOT_API_KEY=.*|CHATBOT_API_KEY=${CHATBOT_KEY}|" .env
    sed -i "s|^DB_HOST=.*|DB_HOST=db|" .env
    sed -i "s|^APP_URL=.*|APP_URL=http://${SERVER_IP}:8000|" .env
fi

info ".env generado con IP=${SERVER_IP}"

# ── 3. Construir e iniciar Docker ────────────────────────────────
info "Construyendo contenedores (primera vez puede tardar 2-3 min)..."
docker compose up -d --build

# ── 4. Esperar MySQL ────────────────────────────────────────────
info "Esperando MySQL..."
for i in $(seq 1 30); do
    if docker exec iptv_chatbot_db mysqladmin ping -h 127.0.0.1 --silent 2>/dev/null; then
        info "MySQL listo tras ${i}s"
        break
    fi
    [ $i -eq 30 ] && fatal "MySQL no respondió en 30 segundos"
    sleep 1
done

# ── 5. Crear DB y usuario ───────────────────────────────────────
info "Creando base de datos y permisos..."
docker exec iptv_chatbot_db mysql -u root -p"${MYSQL_ROOT_PASS}" << 'EOSQL'
CREATE DATABASE IF NOT EXISTS `iptv_manager`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'iptv_user'@'%' IDENTIFIED BY 'PLACEHOLDER';
SET PASSWORD FOR 'iptv_user'@'%' = PASSWORD('PLACEHOLDER');
GRANT ALL PRIVILEGES ON `iptv_manager`.* TO 'iptv_user'@'%';
FLUSH PRIVILEGES;
EOSQL

# Actualizar el password real
docker exec iptv_chatbot_db mysql -u root -p"${MYSQL_ROOT_PASS}" \
  -e "SET PASSWORD FOR 'iptv_user'@'%' = PASSWORD('${DB_PASS}'); FLUSH PRIVILEGES;"

# ── 6. Importar schema ─────────────────────────────────────────
info "Importando esquema de base de datos..."
docker exec -i iptv_chatbot_db mysql -u iptv_user -p"${DB_PASS}" iptv_manager < schema.sql
info "Schema importado OK"

# ── 7. Permisos storage ────────────────────────────────────────
docker exec iptv_chatbot_php sh -c "chown -R www-data:www-data /var/www/html/storage" 2>/dev/null || true

# ── 8. Esperar PHP ─────────────────────────────────────────────
info "Esperando PHP..."
sleep 3

# ── 9. Estado final ─────────────────────────────────────────────
echo ""
echo "=========================================="
echo -e "${GREEN}  ¡DESPLIEGUE COMPLETO!${NC}"
echo "=========================================="
echo ""
echo -e "${BLUE}  URLs del servidor:${NC}"
echo "  ─────────────────────────────────"
echo "  Chatbot API:  http://${SERVER_IP}:8000"
echo "  Health check: http://${SERVER_IP}:8000/api/listar-paquetes"
echo ""
echo -e "${BLUE}  Datos importantes (guárdalos):${NC}"
echo "  ─────────────────────────────────"
echo "  MySQL host:     db"
echo "  MySQL user:     iptv_user"
echo "  MySQL pass:     ${DB_PASS}"
echo "  Database:       iptv_manager"
echo "  Chatbot API-Key: ${CHATBOT_KEY}"
echo ""
echo -e "${BLUE}  PayPal Sandbox (prueba):${NC}"
echo "  ─────────────────────────────────"
echo "  Endpoint:     http://${SERVER_IP}:8000/api/crear-pago-paypal"
echo "  Webhook:      http://${SERVER_IP}:8000/api/webhook-pago?gateway=paypal"
echo ""
echo -e "${BLUE}  Comandos útiles:${NC}"
echo "  ─────────────────────────────────"
echo "  Ver logs:    docker compose logs -f"
echo "  Reiniciar:   docker compose restart"
echo "  Parar:       docker compose down"
echo "  Estado:      docker compose ps"
echo ""