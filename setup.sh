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

# ── Colores ──────────────────────────────────────────────────────
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

# ── Pedir dominio (opcional) ───────────────────────────────────
read -p "Dominio (o ENTER para omitir): " DOMAIN_INPUT
DOMAIN_INPUT=$(echo "$DOMAIN_INPUT" | tr -d '[:space:]' | tr '[:upper:]' '[:lower:]')

USE_SSL=0
if [ -n "$DOMAIN_INPUT" ]; then
    USE_SSL=1
    info "Dominio: $DOMAIN_INPUT"
    warn "Asegúrate de que el DNS ya apunta a $SERVER_IP antes de continuar."
    read -p "Presiona ENTER cuando el DNS esté propagado para generar el SSL: " _
fi

# ── Permisos ───────────────────────────────────────────────────
chmod +x setup.sh 2>/dev/null || true
mkdir -p storage/logs storage/cache

# ── 1. Generar secrets aleatorios ───────────────────────────────
info "Generando secrets seguros..."
DB_PASS=$(openssl rand -hex 20)
MYSQL_ROOT_PASS=$(openssl rand -hex 20)
CHATBOT_KEY=$(openssl rand -hex 32)

# ── 2. Crear .env si no existe ──────────────────────────────────
if [ ! -f .env ]; then
    info "Creando archivo .env..."
    if [ "$USE_SSL" = "1" ]; then
        APP_URL="https://${DOMAIN_INPUT}"
    else
        APP_URL="http://${SERVER_IP}:8000"
    fi
    cat > .env << EOF
# ── App ─────────────────────────────────────────────────────────
APP_ENV=production
APP_DEBUG=false
APP_URL=${APP_URL}

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
    info ".env generado"
else
    info ".env ya existe — omitiendo generación"
    # Asegurar que DB_HOST sea 'db' aunque exista
    sed -i 's|^DB_HOST=.*|DB_HOST=db|' .env
    if [ "$USE_SSL" = "1" ]; then
        sed -i "s|^APP_URL=.*|APP_URL=https://${DOMAIN_INPUT}|" .env
    fi
fi

# ── 3. Docker compose: ajustar puerto según SSL ──────────────
if [ "$USE_SSL" = "1" ]; then
    # SSL → nginx en puerto 80, se creará cert después
    info "Configurando nginx en puerto 80 para SSL..."
    sed -i 's|8000:80|80:80|g' docker-compose.yml
else
    # Sin SSL → nginx en puerto 8000
    info "Configurando nginx en puerto 8000..."
    sed -i 's|80:80|8000:80|g' docker-compose.yml
fi

# ── 4. Construir e iniciar Docker ───────────────────────────────
info "Construyendo contenedores..."
docker compose up -d --build

# ── 5. Esperar MySQL ───────────────────────────────────────────
info "Esperando MySQL..."
for i in $(seq 1 30); do
    if docker exec iptv_chatbot_db mysqladmin ping -h 127.0.0.1 --silent 2>/dev/null; then
        info "MySQL listo tras ${i}s"
        break
    fi
    [ $i -eq 30 ] && fatal "MySQL no respondió en 30 segundos"
    sleep 1
done

# ── 6. Crear DB y usuario ──────────────────────────────────────
info "Creando base de datos y permisos..."
docker exec iptv_chatbot_db mysql -u root -p"${MYSQL_ROOT_PASS}" << 'EOSQL'
CREATE DATABASE IF NOT EXISTS `iptv_manager`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'iptv_user'@'%' IDENTIFIED BY 'PLACEHOLDER';
SET PASSWORD FOR 'iptv_user'@'%' = PASSWORD('PLACEHOLDER');
GRANT ALL PRIVILEGES ON `iptv_manager`.* TO 'iptv_user'@'%';
FLUSH PRIVILEGES;
EOSQL

docker exec iptv_chatbot_db mysql -u root -p"${MYSQL_ROOT_PASS}" \
  -e "SET PASSWORD FOR 'iptv_user'@'%' = PASSWORD('${DB_PASS}'); FLUSH PRIVILEGES;"

# ── 7. Importar schema ─────────────────────────────────────────
info "Importando esquema de base de datos..."
docker exec -i iptv_chatbot_db mysql -u iptv_user -p"${DB_PASS}" iptv_manager < schema.sql
info "Schema importado OK"

# ── 8. Permisos storage ────────────────────────────────────────
docker exec iptv_chatbot_php sh -c "chown -R www-data:www-data /var/www/html/storage" 2>/dev/null || true

# ── 9. Esperar PHP y Nginx ─────────────────────────────────────
info "Esperando PHP..."
sleep 5

# ── 10. SSL con Certbot (si hay dominio) ──────────────────────
if [ "$USE_SSL" = "1" ]; then
    info "Generando certificado SSL para ${DOMAIN_INPUT}..."

    # Instalar certbot si no existe
    if ! command -v certbot &> /dev/null; then
        warn "Instalando certbot..."
        apt-get update -qq
        apt-get install -y -qq certbot python3-certbot-nginx > /dev/null 2>&1
    fi

    # Detener nginx temporalmente para que certbot use el puerto 80
    docker compose stop nginx

    # Generar certificado (standalone = certbot responde el challenge HTTP)
    if certbot certonly --standalone \
        --non-interactive \
        --agree-tos \
        --email "admin@${DOMAIN_INPUT}" \
        --domains "${DOMAIN_INPUT}" \
        --pre-hook "systemctl stop nginx 2>/dev/null || true" \
        --post-hook "systemctl start nginx 2>/dev/null || true" \
        2>&1 | tee /tmp/certbot.log; then

        CERTS_DIR="/etc/letsencrypt/live/${DOMAIN_INPUT}"
        if [ -d "$CERTS_DIR" ]; then
            info "Certificado generado OK"

            # Copiar certificados a volumenes accesibles por nginx
            mkdir -p ./ssl
            cp "${CERTS_DIR}/fullchain.pem" ./ssl/cert.pem
            cp "${CERTS_DIR}/privkey.pem" ./ssl/key.pem
            chmod 644 ./ssl/cert.pem
            chmod 600 ./ssl/key.pem

            # Actualizar nginx.conf para SSL
            info "Configurando nginx con SSL..."
            cat > nginx.conf << 'ENGINX'
server {
    listen 80;
    server_name _;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name _;

    ssl_certificate /etc/nginx/ssl/cert.pem;
    ssl_certificate_key /etc/nginx/ssl/key.pem;

    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    root /var/www/html/public;
    index index.php index.html;

    client_max_body_size 50M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht {
        deny all;
    }
}
ENGINX

            # Agregar volumen SSL al servicio nginx en docker-compose.yml
            if ! grep -q "ssl:/etc/nginx/ssl" docker-compose.yml; then
                sed -i '/volumes:/,/nginx.conf:ro/a\      - ./ssl:/etc/nginx/ssl:ro' docker-compose.yml
            fi

            docker compose up -d --build nginx
            info "SSL configurado y activo"
        fi
    else
        warn "No se pudo generar el certificado SSL"
        warn "Revisa /tmp/certbot.log"
        docker compose start nginx
    fi
fi

# ── 11. Resumen ────────────────────────────────────────────────
APP_URL=$(grep "^APP_URL=" .env | cut -d= -f2 | tr -d '[:space:]')
WEBHOOK_URL="${APP_URL}/api/webhook-pago?gateway=paypal"
API_KEY=$(grep "^CHATBOT_API_KEY=" .env | cut -d= -f2)

echo ""
echo "=========================================="
echo -e "${GREEN}  ¡DESPLIEGUE COMPLETO!${NC}"
echo "=========================================="
echo ""
echo -e "${BLUE}  URL de la API:${NC}  ${APP_URL}"
echo -e "${BLUE}  Webhook PayPal:${NC} ${WEBHOOK_URL}"
echo ""
echo -e "${BLUE}  Datos importantes:${NC}"
echo "  ─────────────────────────────────"
echo "  MySQL host:     db"
echo "  MySQL user:     iptv_user"
echo "  MySQL pass:     ${DB_PASS}"
echo "  Database:       iptv_manager"
echo "  Chatbot API-Key: ${API_KEY}"
echo ""
echo -e "${BLUE}  Comandos útiles:${NC}"
echo "  ─────────────────────────────────"
echo "  Ver logs:    docker compose logs -f"
echo "  Reiniciar:  docker compose restart"
echo "  Estado:     docker compose ps"
echo ""