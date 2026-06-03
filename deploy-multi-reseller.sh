#!/usr/bin/env bash
# =============================================================================
#  deploy-multi-reseller.sh — Deploy multi-reseller de Chatbot IPTV PHP API
#
#  Instala N instancias del Chatbot IPTV API, una por revendedor.
#  Cada instancia tiene: su propia BD MySQL, su propio .env, su propio
#  subdominio en Nginx y SSL con Let's Encrypt.
#
#  Uso en servidor Ubuntu 22/24:
#    wget https://raw.githubusercontent.com/Zefo94/chatbot-iptv-api/main/deploy-multi-reseller.sh
#    chmod +x deploy-multi-reseller.sh && sudo ./deploy-multi-reseller.sh
# =============================================================================

set -euo pipefail

# ── Colores ───────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; MAGENTA='\033[0;35m'
BOLD='\033[1m'; DIM='\033[2m'; NC='\033[0m'

log()     { echo -e "${GREEN}[✓]${NC} $*"; }
info()    { echo -e "${BLUE}[i]${NC} $*"; }
warn()    { echo -e "${YELLOW}[!]${NC} $*"; }
error()   { echo -e "${RED}[✗]${NC} $*" >&2; exit 1; }
step()    { echo -e "\n${BOLD}${CYAN}▶ $*${NC}"; }
section() { echo -e "\n${BOLD}${MAGENTA}══════════════════════════════════════════════════${NC}"; echo -e "${BOLD}${MAGENTA}  $*${NC}"; echo -e "${BOLD}${MAGENTA}══════════════════════════════════════════════════${NC}"; }
ask()     { echo -e "${YELLOW}  ◆ $*${NC}"; }

[[ $EUID -ne 0 ]] && error "Ejecuta como root: sudo ./deploy-multi-reseller.sh"

# ── Variables globales ────────────────────────────────────────────────────────
REPO_SSH="git@github.com:Zefo94/chatbot-iptv-api.git"
REPO_HTTPS="https://github.com/Zefo94/chatbot-iptv-api.git"
BRANCH="main"
BASE_DIR="/var/www"
SUMMARY_FILE="/root/CHATBOT_RESELLERS.txt"
PHP_SOCK=""

# Arrays de resellers para el resumen final
declare -a RES_SLUGS=()
declare -a RES_NAMES=()
declare -a RES_DOMAINS=()
declare -a RES_API_KEYS=()
declare -a RES_DB_NAMES=()
declare -a RES_DB_USERS=()
declare -a RES_DB_PASSES=()

# =============================================================================
#  BANNER
# =============================================================================
clear
echo ""
echo -e "${BOLD}${CYAN}  ╔══════════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${CYAN}  ║    CHATBOT IPTV API — Deploy Multi-Revendedor           ║${NC}"
echo -e "${BOLD}${CYAN}  ║    Instala N instancias PHP aisladas en este servidor    ║${NC}"
echo -e "${BOLD}${CYAN}  ╚══════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  ${DIM}Repo: github.com/Zefo94/chatbot-iptv-api${NC}"
echo -e "  ${DIM}Fecha: $(date '+%Y-%m-%d %H:%M')${NC}"
echo ""

# =============================================================================
#  PASO 0: Dependencias del sistema
# =============================================================================
section "PASO 0 — Verificando e instalando dependencias"

install_if_missing() {
  local PKG=$1
  if ! dpkg -l "$PKG" &>/dev/null; then
    info "Instalando $PKG..."
    apt-get install -y "$PKG" -qq
    log "$PKG instalado"
  else
    log "$PKG ya está instalado"
  fi
}

apt-get update -qq

# Nginx
install_if_missing nginx

# MySQL / MariaDB — detectar cuál hay instalado
if command -v mysql &>/dev/null; then
  DB_ENGINE=$(mysql --version 2>/dev/null | grep -oi 'mariadb\|mysql' | head -1 | tr '[:upper:]' '[:lower:]')
  log "Base de datos detectada: ${DB_ENGINE:-mysql/mariadb}"
else
  info "Instalando MariaDB..."
  DEBIAN_FRONTEND=noninteractive apt-get install -y mariadb-server -qq
  systemctl enable mariadb --now 2>/dev/null || systemctl enable mysql --now 2>/dev/null
  log "MariaDB instalado y activo"
fi

# PHP — detectar versión instalada, si no hay instalar 8.2
PHP_VERSION=""
if command -v php &>/dev/null; then
  PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)
  if php -m 2>/dev/null | grep -q pdo_mysql; then
    log "PHP $PHP_VERSION detectado con PDO MySQL"
  else
    info "PHP $PHP_VERSION encontrado pero falta pdo_mysql — instalando extensión..."
    apt-get install -y "php${PHP_VERSION}-mysql" -qq 2>/dev/null || true
  fi
fi

if [[ -z "$PHP_VERSION" ]]; then
  info "Instalando PHP 8.2..."
  add-apt-repository ppa:ondrej/php -y -q 2>/dev/null || true
  apt-get update -qq
  apt-get install -y php8.2-fpm php8.2-cli php8.2-mysql php8.2-mbstring \
    php8.2-xml php8.2-curl php8.2-zip php8.2-bcmath php8.2-intl -qq
  PHP_VERSION="8.2"
  log "PHP 8.2 instalado"
fi

# Asegurar que están instaladas todas las extensiones necesarias para la versión detectada
for EXT in fpm cli mysql mbstring xml curl zip bcmath intl; do
  if ! dpkg -l "php${PHP_VERSION}-${EXT}" &>/dev/null; then
    apt-get install -y "php${PHP_VERSION}-${EXT}" -qq 2>/dev/null || true
  fi
done

# Composer
if ! command -v composer &>/dev/null; then
  info "Instalando Composer..."
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer -q
  log "Composer instalado"
else
  log "Composer ya está instalado"
fi

# Certbot (para SSL)
if ! command -v certbot &>/dev/null; then
  info "Instalando Certbot..."
  apt-get install -y certbot python3-certbot-nginx -qq
  log "Certbot instalado"
else
  log "Certbot ya está instalado"
fi

# Git
install_if_missing git

# Detectar socket PHP-FPM según la versión instalada
detect_php_sock() {
  local ver=$1
  for path in \
    "/run/php/php${ver}-fpm.sock" \
    "/var/run/php/php${ver}-fpm.sock" \
    "/run/php${ver}-fpm.sock"; do
    if [[ -S "$path" ]]; then echo "unix:$path"; return; fi
  done
}

PHP_SOCK=$(detect_php_sock "$PHP_VERSION")

if [[ -z "$PHP_SOCK" ]]; then
  # Iniciar FPM e intentar de nuevo
  systemctl enable "php${PHP_VERSION}-fpm" --now 2>/dev/null || true
  sleep 2
  PHP_SOCK=$(detect_php_sock "$PHP_VERSION")
fi

# Si aún no hay socket buscar cualquier socket php-fpm disponible
if [[ -z "$PHP_SOCK" ]]; then
  FOUND_SOCK=$(find /run/php /var/run/php -name "php*-fpm.sock" 2>/dev/null | head -1)
  if [[ -n "$FOUND_SOCK" ]]; then
    PHP_SOCK="unix:$FOUND_SOCK"
    warn "Usando socket encontrado: $PHP_SOCK"
  else
    PHP_SOCK="127.0.0.1:9000"
    warn "No se encontró socket PHP-FPM, usando TCP $PHP_SOCK"
  fi
fi

log "PHP-FPM socket: $PHP_SOCK"

# Asegurarse de que Nginx y PHP-FPM están activos
systemctl enable nginx --now 2>/dev/null || true
systemctl enable "php${PHP_VERSION}-fpm" --now 2>/dev/null || true

# =============================================================================
#  PASO 1: Configuración de GitHub (SSH o HTTPS)
# =============================================================================
section "PASO 1 — Acceso al repositorio de GitHub"

# Helpers SSH
_setup_ssh_config() {
  local key_path=$1
  local key_name
  key_name=$(basename "$key_path")
  if ! grep -q "$key_name" /root/.ssh/config 2>/dev/null; then
    cat >> /root/.ssh/config <<SSHCONF

Host github.com
    HostName github.com
    User git
    IdentityFile $key_path
    StrictHostKeyChecking no
SSHCONF
    chmod 600 /root/.ssh/config
  fi
}

_show_and_wait_for_key() {
  local key_path=$1
  echo ""
  echo -e "${BOLD}${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
  echo -e "${BOLD}  COPIA ESTA CLAVE Y AGRÉGALA A GITHUB:${NC}"
  echo -e "${BOLD}${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
  cat "${key_path}.pub"
  echo -e "${BOLD}${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
  echo ""
  echo -e "  Abre: ${CYAN}https://github.com/Zefo94/chatbot-iptv-api/settings/keys/new${NC}"
  echo -e "  Título: chatbot-$(hostname)-$(date +%Y%m%d)"
  echo ""
  ask "Presiona Enter cuando la hayas agregado en GitHub..."
  read -r
  SSH_TEST=$(ssh -T git@github.com -i "$key_path" -o StrictHostKeyChecking=no 2>&1 || true)
  if echo "$SSH_TEST" | grep -q "successfully authenticated"; then
    log "Conexión SSH con GitHub verificada"
  else
    warn "No se pudo verificar: $SSH_TEST — continuando de todas formas"
  fi
}

# ── Probar primero si ya hay SSH con GitHub funcionando ───────────────────────
USE_SSH=false
SSH_KEY_PATH=""
CLONE_URL=""

info "Verificando si ya hay conexión SSH con GitHub..."
SSH_EXISTING=$(ssh -T git@github.com -o StrictHostKeyChecking=no -o ConnectTimeout=5 2>&1 || true)

if echo "$SSH_EXISTING" | grep -q "successfully authenticated"; then
  log "¡Ya tienes SSH con GitHub configurado y funcionando!"
  USE_SSH=true
  # Detectar qué clave está siendo usada
  ACTIVE_KEY=$(ssh -T git@github.com -v -o StrictHostKeyChecking=no 2>&1 | grep "Offering public key" | tail -1 | awk '{print $NF}' || true)
  [[ -n "$ACTIVE_KEY" ]] && info "Clave activa: $ACTIVE_KEY"
  CLONE_URL="$REPO_SSH"
  echo ""
  echo -e "  ${CYAN}1)${NC} Usar la conexión SSH existente (recomendado)"
  echo -e "  ${CYAN}2)${NC} Generar una nueva clave SSH de todas formas"
  echo -e "  ${CYAN}3)${NC} Usar HTTPS en su lugar"
  echo ""
  ask "Elige [1/2/3] (Enter = 1):"
  read -r GH_CHOICE
  GH_CHOICE="${GH_CHOICE:-1}"

  if [[ "$GH_CHOICE" == "2" ]]; then
    # Generar clave nueva
    SSH_KEY_PATH="/root/.ssh/chatbot_deploy_key"
    mkdir -p /root/.ssh && chmod 700 /root/.ssh
    [[ -f "$SSH_KEY_PATH" ]] && rm -f "$SSH_KEY_PATH" "${SSH_KEY_PATH}.pub"
    ssh-keygen -t ed25519 -C "chatbot@$(hostname)" -f "$SSH_KEY_PATH" -N "" -q
    _setup_ssh_config "$SSH_KEY_PATH"
    _show_and_wait_for_key "$SSH_KEY_PATH"
  elif [[ "$GH_CHOICE" == "3" ]]; then
    USE_SSH=false
    ask "Usuario de GitHub:"
    read -r GH_USER
    ask "Token de acceso personal:"
    read -rs GH_TOKEN
    echo ""
    CLONE_URL="https://${GH_USER}:${GH_TOKEN}@github.com/Zefo94/chatbot-iptv-api.git"
    log "Usando autenticación HTTPS"
  else
    log "Usando la conexión SSH existente"
  fi

else
  # No hay SSH con GitHub — ofrecer opciones
  echo ""
  echo -e "  No se detectó SSH con GitHub. ¿Cómo quieres autenticarte?"
  echo -e "  ${CYAN}1)${NC} SSH — generar clave nueva (recomendado para updates automáticos)"
  echo -e "  ${CYAN}2)${NC} HTTPS — usuario + token (más simple)"
  echo ""
  ask "Elige [1/2]:"
  read -r GH_AUTH_METHOD

  if [[ "$GH_AUTH_METHOD" == "1" ]]; then
    USE_SSH=true
    SSH_KEY_PATH="/root/.ssh/chatbot_deploy_key"
    mkdir -p /root/.ssh && chmod 700 /root/.ssh

    # Buscar TODAS las claves existentes, incluida chatbot_deploy_key
    FOUND_KEYS=$(find /root/.ssh -name "*.pub" 2>/dev/null || true)
    if [[ -n "$FOUND_KEYS" ]]; then
      echo ""
      info "Se encontraron estas claves SSH en el servidor:"
      i_key=1
      while IFS= read -r k; do
        echo -e "  ${CYAN}${i_key})${NC} ${k}"
        i_key=$((i_key+1))
      done <<< "$FOUND_KEYS"
      echo -e "  ${CYAN}${i_key})${NC} Generar una clave nueva"
      echo ""
      ask "¿Cuál usar? Escribe el número o la ruta completa (sin .pub):"
      read -r KEY_CHOICE

      # Si eligió un número, convertir a ruta
      if [[ "$KEY_CHOICE" =~ ^[0-9]+$ ]]; then
        TOTAL_KEYS=$(echo "$FOUND_KEYS" | wc -l)
        if [[ "$KEY_CHOICE" -le "$TOTAL_KEYS" ]]; then
          CHOSEN_PUB=$(echo "$FOUND_KEYS" | sed -n "${KEY_CHOICE}p")
          KEY_CHOICE="${CHOSEN_PUB%.pub}"
        else
          KEY_CHOICE=""  # eligió "Generar nueva"
        fi
      fi

      if [[ -n "$KEY_CHOICE" ]] && [[ -f "$KEY_CHOICE" ]]; then
        SSH_KEY_PATH="$KEY_CHOICE"
        log "Usando clave: $SSH_KEY_PATH"
        SSH_TEST=$(ssh -T git@github.com -i "$SSH_KEY_PATH" -o StrictHostKeyChecking=no 2>&1 || true)
        if echo "$SSH_TEST" | grep -q "successfully authenticated"; then
          log "Clave verificada con GitHub — listo"
        else
          warn "Esta clave no está en GitHub todavía."
          _show_and_wait_for_key "$SSH_KEY_PATH"
        fi
      else
        # Generar nueva — borrar la anterior si existe para evitar prompt
        rm -f "$SSH_KEY_PATH" "${SSH_KEY_PATH}.pub"
        ssh-keygen -t ed25519 -C "chatbot@$(hostname)" -f "$SSH_KEY_PATH" -N "" -q
        log "Clave SSH generada"
        _setup_ssh_config "$SSH_KEY_PATH"
        _show_and_wait_for_key "$SSH_KEY_PATH"
      fi
    else
      # No hay ninguna clave — generar desde cero
      rm -f "$SSH_KEY_PATH" "${SSH_KEY_PATH}.pub"
      ssh-keygen -t ed25519 -C "chatbot@$(hostname)" -f "$SSH_KEY_PATH" -N "" -q
      log "Clave SSH generada"
      _setup_ssh_config "$SSH_KEY_PATH"
      _show_and_wait_for_key "$SSH_KEY_PATH"
    fi
    CLONE_URL="$REPO_SSH"
  else
    ask "Usuario de GitHub:"
    read -r GH_USER
    ask "Token de acceso personal:"
    read -rs GH_TOKEN
    echo ""
    CLONE_URL="https://${GH_USER}:${GH_TOKEN}@github.com/Zefo94/chatbot-iptv-api.git"
    log "Usando autenticación HTTPS"
  fi
fi

# =============================================================================
#  PASO 2: ¿Cuántos revendedores?
# =============================================================================
section "PASO 2 — Configuración de revendedores"

# ── Detectar instalación existente en /var/www/html ──────────────────────────
EXISTING_HTML=""
if [[ -f "/var/www/html/public/index.php" ]] && [[ -f "/var/www/html/.env" ]]; then
  echo ""
  warn "Se detectó una instalación existente en /var/www/html"
  info "Este script NO la tocará — solo crea nuevas instancias en /var/www/chatbot-{slug}/"
  echo ""
  ask "¿Quieres incluir la instancia de /var/www/html en el resumen final? [S/n]:"
  read -r INCLUDE_HTML
  if [[ "${INCLUDE_HTML,,}" != "n" ]]; then
    EXISTING_HTML="yes"
    ask "¿Qué slug/nombre tiene ese revendedor? (ej: reseller1, principal):"
    read -r HTML_SLUG
    if [[ -z "$HTML_SLUG" ]]; then HTML_SLUG="principal"; fi
    # Leer API key existente
    EXISTING_API_KEY=$(grep "CHATBOT_API_KEY=" /var/www/html/.env 2>/dev/null | cut -d'=' -f2- | tr -d '"' | tr -d "'" || echo "ver /var/www/html/.env")
    EXISTING_DB_NAME=$(grep "^DB_NAME=" /var/www/html/.env 2>/dev/null | cut -d'=' -f2- | tr -d '"' || echo "ver .env")
    EXISTING_APP_URL=$(grep "^APP_URL=" /var/www/html/.env 2>/dev/null | cut -d'=' -f2- | tr -d '"' || echo "ver .env")
    info "  Encontrado: URL=$EXISTING_APP_URL  BD=$EXISTING_DB_NAME"
  fi
fi

echo ""
ask "¿Cuántos revendedores NUEVOS quieres agregar? [1-10]:"
read -r NUM_RESELLERS
if ! [[ "$NUM_RESELLERS" =~ ^[0-9]+$ ]] || [[ "$NUM_RESELLERS" -lt 1 ]] || [[ "$NUM_RESELLERS" -gt 10 ]]; then
  error "Número inválido. Debe ser entre 1 y 10."
fi

echo ""
info "Se configurarán $NUM_RESELLERS revendedor(es)"
echo ""

# Pedir IP pública del servidor
SERVER_IP=$(curl -s -m 5 https://ifconfig.me 2>/dev/null || curl -s -m 5 https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}')
info "IP detectada del servidor: $SERVER_IP"
echo ""
ask "¿Es correcta esta IP? Si tienes dominio propio escríbelo, si no presiona Enter para usar la IP:"
read -r CUSTOM_DOMAIN_BASE
if [[ -z "$CUSTOM_DOMAIN_BASE" ]]; then
  DOMAIN_BASE="$SERVER_IP"
  USE_DOMAIN=false
else
  DOMAIN_BASE="$CUSTOM_DOMAIN_BASE"
  USE_DOMAIN=true
fi

# =============================================================================
#  PASO 3: Recopilar datos de cada revendedor
# =============================================================================
section "PASO 3 — Datos de cada revendedor"

declare -A RESELLER_DATA

for ((i=1; i<=NUM_RESELLERS; i++)); do
  echo ""
  echo -e "${BOLD}${YELLOW}┌─ REVENDEDOR $i de $NUM_RESELLERS ─────────────────────────────────┐${NC}"
  echo ""

  # Slug (identificador URL-friendly)
  ask "Nombre del revendedor (sin espacios, ej: reseller1, mitienda, companyxyz):"
  read -r SLUG
  SLUG=$(echo "$SLUG" | tr '[:upper:]' '[:lower:]' | tr -cs 'a-z0-9' '-' | sed 's/^-//;s/-$//')
  if [[ -z "$SLUG" ]]; then SLUG="reseller$i"; fi

  # Dominio/subdominio
  if [[ "$USE_DOMAIN" == "true" ]]; then
    ask "Subdominio para este revendedor (ej: api-$SLUG o presiona Enter para api-${SLUG}.${DOMAIN_BASE}):"
    read -r SUBDOMAIN
    if [[ -z "$SUBDOMAIN" ]]; then
      FULL_DOMAIN="api-${SLUG}.${DOMAIN_BASE}"
    else
      FULL_DOMAIN="${SUBDOMAIN}.${DOMAIN_BASE}"
    fi
  else
    FULL_DOMAIN="$SERVER_IP"
    warn "Sin dominio — la API usará la IP $SERVER_IP (no se puede hacer SSL)"
  fi

  # XUI Panel
  echo ""
  echo -e "  ${DIM}── Configuración del panel XUI.ONE ──${NC}"
  ask "URL del panel XUI.ONE (ej: http://panel.ejemplo.com:8000/api.php):"
  read -r XUI_URL
  ask "Usuario administrador del panel XUI:"
  read -r XUI_USER
  ask "Contraseña del panel XUI:"
  read -rs XUI_PASS
  echo ""
  ask "ID de paquete predeterminado [1]:"
  read -r XUI_PKG_ID
  if [[ -z "$XUI_PKG_ID" ]]; then XUI_PKG_ID="1"; fi
  ask "Conexiones máximas predeterminadas [1]:"
  read -r XUI_CONNS
  if [[ -z "$XUI_CONNS" ]]; then XUI_CONNS="1"; fi

  # URL del reseller en XUI (puede ser igual o diferente)
  ask "URL API de revendedores en XUI (Enter para usar la misma que arriba):"
  read -r XUI_RESELLER_URL
  if [[ -z "$XUI_RESELLER_URL" ]]; then XUI_RESELLER_URL="$XUI_URL"; fi

  # Pagos (opcionales)
  echo ""
  echo -e "  ${DIM}── Pasarelas de pago (opcional, Enter para omitir) ──${NC}"
  ask "PayPal Client ID:"
  read -r PAYPAL_CID
  ask "PayPal Client Secret:"
  read -rs PAYPAL_CSEC
  echo ""
  ask "MercadoPago Access Token:"
  read -r MP_TOKEN
  ask "Wompi Public Key:"
  read -r WOMPI_PUB
  ask "Wompi Private Key:"
  read -rs WOMPI_PRIV
  echo ""

  # Guardar en array asociativo
  RESELLER_DATA["${i}_slug"]="$SLUG"
  RESELLER_DATA["${i}_domain"]="$FULL_DOMAIN"
  RESELLER_DATA["${i}_xui_url"]="$XUI_URL"
  RESELLER_DATA["${i}_xui_user"]="$XUI_USER"
  RESELLER_DATA["${i}_xui_pass"]="$XUI_PASS"
  RESELLER_DATA["${i}_xui_reseller_url"]="$XUI_RESELLER_URL"
  RESELLER_DATA["${i}_xui_pkg_id"]="$XUI_PKG_ID"
  RESELLER_DATA["${i}_xui_conns"]="$XUI_CONNS"
  RESELLER_DATA["${i}_paypal_cid"]="$PAYPAL_CID"
  RESELLER_DATA["${i}_paypal_csec"]="$PAYPAL_CSEC"
  RESELLER_DATA["${i}_mp_token"]="$MP_TOKEN"
  RESELLER_DATA["${i}_wompi_pub"]="$WOMPI_PUB"
  RESELLER_DATA["${i}_wompi_priv"]="$WOMPI_PRIV"

  echo -e "${BOLD}${GREEN}  ✓ Datos del revendedor '$SLUG' guardados${NC}"
done

# =============================================================================
#  PASO 4: MySQL/MariaDB — contraseña root
# =============================================================================
section "PASO 4 — Configuración de MySQL/MariaDB"

echo ""
warn "Para crear bases de datos necesito acceso al servidor MySQL/MariaDB."
ask "Contraseña root de MySQL/MariaDB (Enter si no tiene o usa auth socket):"
read -rs MYSQL_ROOT_PASS
echo ""

# Verificar acceso — intentar varias combinaciones (MySQL, MariaDB, socket auth)
MYSQL_AUTH_SOCKET=false
if mysql -u root -p"$MYSQL_ROOT_PASS" -e "SELECT 1" &>/dev/null 2>&1; then
  log "Acceso MySQL/MariaDB verificado (contraseña)"
elif mysql -u root --socket=/var/run/mysqld/mysqld.sock -e "SELECT 1" &>/dev/null 2>&1; then
  log "Acceso MariaDB via socket verificado"
  MYSQL_ROOT_PASS=""
elif mysql -u root --socket=/run/mysqld/mysqld.sock -e "SELECT 1" &>/dev/null 2>&1; then
  log "Acceso MariaDB via socket /run/mysqld verificado"
  MYSQL_ROOT_PASS=""
else
  warn "No se pudo conectar con esa contraseña. Intentando sin contraseña..."
  if mysql -u root -e "SELECT 1" &>/dev/null 2>&1; then
    log "Acceso MySQL/MariaDB sin contraseña verificado"
    MYSQL_ROOT_PASS=""
  else
    error "No se puede acceder a MySQL. Verifica la contraseña e intenta de nuevo."
  fi
fi

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

# =============================================================================
#  PASO 5: Desplegar cada revendedor
# =============================================================================
section "PASO 5 — Desplegando revendedores"

for ((i=1; i<=NUM_RESELLERS; i++)); do
  SLUG="${RESELLER_DATA["${i}_slug"]}"
  FULL_DOMAIN="${RESELLER_DATA["${i}_domain"]}"
  XUI_URL="${RESELLER_DATA["${i}_xui_url"]}"
  XUI_USER="${RESELLER_DATA["${i}_xui_user"]}"
  XUI_PASS="${RESELLER_DATA["${i}_xui_pass"]}"
  XUI_RESELLER_URL="${RESELLER_DATA["${i}_xui_reseller_url"]}"
  XUI_PKG_ID="${RESELLER_DATA["${i}_xui_pkg_id"]}"
  XUI_CONNS="${RESELLER_DATA["${i}_xui_conns"]}"
  PAYPAL_CID="${RESELLER_DATA["${i}_paypal_cid"]}"
  PAYPAL_CSEC="${RESELLER_DATA["${i}_paypal_csec"]}"
  MP_TOKEN="${RESELLER_DATA["${i}_mp_token"]}"
  WOMPI_PUB="${RESELLER_DATA["${i}_wompi_pub"]}"
  WOMPI_PRIV="${RESELLER_DATA["${i}_wompi_priv"]}"

  APP_DIR="${BASE_DIR}/chatbot-${SLUG}"
  DB_NAME="chatbot_${SLUG}"
  DB_USER="chatbot_${SLUG}"
  DB_PASS=$(openssl rand -hex 16)
  CHATBOT_API_KEY=$(openssl rand -hex 32)

  echo ""
  echo -e "${BOLD}${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
  echo -e "${BOLD}  Revendedor $i/$NUM_RESELLERS: ${YELLOW}$SLUG${NC}"
  echo -e "${BOLD}${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

  # ── Clonar / actualizar repo ──────────────────────────────────────────────
  step "Clonando repositorio..."
  if [[ -d "$APP_DIR/.git" ]]; then
    warn "Directorio $APP_DIR ya existe — actualizando..."
    cd "$APP_DIR"
    git pull origin "$BRANCH" || warn "git pull falló, continuando con código existente"
  else
    mkdir -p "$(dirname "$APP_DIR")"
    if [[ "$USE_SSH" == "true" ]]; then
      GIT_SSH_COMMAND="ssh -i $SSH_KEY_PATH -o StrictHostKeyChecking=no" \
        git clone -b "$BRANCH" "$CLONE_URL" "$APP_DIR"
    else
      git clone -b "$BRANCH" "$CLONE_URL" "$APP_DIR"
    fi
  fi
  log "Código en $APP_DIR"

  # ── Crear .env ────────────────────────────────────────────────────────────
  step "Creando archivo .env..."
  APP_URL_VALUE="http://${FULL_DOMAIN}"
  if [[ "$USE_DOMAIN" == "true" ]]; then
    APP_URL_VALUE="https://${FULL_DOMAIN}"
  fi

  cat > "${APP_DIR}/.env" <<ENVFILE
# ============================================================
# Revendedor: $SLUG
# Generado: $(date '+%Y-%m-%d %H:%M')
# ============================================================

APP_ENV=production
APP_DEBUG=false
APP_URL=${APP_URL_VALUE}

# Chatbot Client security key
CHATBOT_API_KEY=${CHATBOT_API_KEY}

# Base de datos
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}

# XUI.ONE Panel
XUI_API_URL=${XUI_URL}
XUI_USERNAME=${XUI_USER}
XUI_PASSWORD=${XUI_PASS}
XUI_RESELLER_API_URL=${XUI_RESELLER_URL}
XUI_DEFAULT_PACKAGE_ID=${XUI_PKG_ID}
XUI_DEFAULT_MAX_CONNECTIONS=${XUI_CONNS}

# PayPal
PAYPAL_CLIENT_ID=${PAYPAL_CID}
PAYPAL_CLIENT_SECRET=${PAYPAL_CSEC}
PAYPAL_MODE=live
PAYPAL_CURRENCY=USD
PAYPAL_PRICE_PER_CREDIT=10.00

# MercadoPago
MERCADOPAGO_ACCESS_TOKEN=${MP_TOKEN}
MERCADOPAGO_WEBHOOK_SECRET=

# Wompi
WOMPI_PUBLIC_KEY=${WOMPI_PUB}
WOMPI_PRIVATE_KEY=${WOMPI_PRIV}
WOMPI_WEBHOOK_SECRET=

# Binance
BINANCE_API_KEY=
BINANCE_SECRET_KEY=
ENVFILE
  chmod 600 "${APP_DIR}/.env"
  log ".env creado"

  # ── Composer install ──────────────────────────────────────────────────────
  step "Instalando dependencias PHP (composer)..."
  cd "$APP_DIR"
  COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader -q
  log "Composer listo"

  # ── Permisos de archivos ──────────────────────────────────────────────────
  step "Configurando permisos..."
  chown -R www-data:www-data "$APP_DIR"
  find "$APP_DIR" -type f -exec chmod 644 {} \;
  find "$APP_DIR" -type d -exec chmod 755 {} \;
  chmod 600 "${APP_DIR}/.env"
  if [[ -d "${APP_DIR}/storage" ]]; then
    chmod -R 775 "${APP_DIR}/storage"
    chown -R www-data:www-data "${APP_DIR}/storage"
  fi
  log "Permisos configurados"

  # ── Crear base de datos MySQL ─────────────────────────────────────────────
  step "Creando base de datos MySQL..."
  mysql_cmd "DROP DATABASE IF EXISTS \`${DB_NAME}\`;" || true
  mysql_cmd "CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  mysql_cmd "DROP USER IF EXISTS '${DB_USER}'@'localhost';" || true
  mysql_cmd "CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
  mysql_cmd "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
  mysql_cmd "FLUSH PRIVILEGES;"
  log "Base de datos '${DB_NAME}' creada"

  # ── Importar schema ───────────────────────────────────────────────────────
  step "Importando schema SQL..."
  if [[ -f "${APP_DIR}/schema.sql" ]]; then
    mysql_file "${DB_NAME}" "${APP_DIR}/schema.sql"
    log "Schema importado correctamente"
  else
    warn "No se encontró schema.sql, la BD está vacía"
  fi

  # ── Nginx vhost ───────────────────────────────────────────────────────────
  step "Configurando Nginx..."
  NGINX_CONF="/etc/nginx/sites-available/chatbot-${SLUG}"

  if [[ "$USE_DOMAIN" == "true" ]]; then
    # Con dominio real
    cat > "$NGINX_CONF" <<NGINXCONF
server {
    listen 80;
    server_name ${FULL_DOMAIN};

    root ${APP_DIR}/public;
    index index.php index.html;

    client_max_body_size 50M;

    access_log /var/log/nginx/chatbot-${SLUG}-access.log;
    error_log  /var/log/nginx/chatbot-${SLUG}-error.log;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass ${PHP_SOCK};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ~ /\.env {
        deny all;
        return 404;
    }

    location ~ /\.ht {
        deny all;
    }
}
NGINXCONF
  else
    # Sin dominio — usar puerto diferente por cada revendedor
    PORT=$((8010 + i - 1))
    RESELLER_DATA["${i}_port"]="$PORT"
    cat > "$NGINX_CONF" <<NGINXCONF
server {
    listen ${PORT};
    server_name _;

    root ${APP_DIR}/public;
    index index.php index.html;

    client_max_body_size 50M;

    access_log /var/log/nginx/chatbot-${SLUG}-access.log;
    error_log  /var/log/nginx/chatbot-${SLUG}-error.log;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass ${PHP_SOCK};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ~ /\.env {
        deny all;
        return 404;
    }

    location ~ /\.ht {
        deny all;
    }
}
NGINXCONF
  fi

  ln -sf "$NGINX_CONF" "/etc/nginx/sites-enabled/chatbot-${SLUG}"
  log "Nginx configurado para $SLUG"

  # ── SSL con Let's Encrypt ─────────────────────────────────────────────────
  if [[ "$USE_DOMAIN" == "true" ]]; then
    step "Configurando SSL con Let's Encrypt..."
    ask "¿Configurar SSL para $FULL_DOMAIN? [S/n]:"
    read -r DO_SSL
    if [[ "${DO_SSL,,}" != "n" ]]; then
      nginx -t && systemctl reload nginx
      if certbot --nginx -d "$FULL_DOMAIN" --non-interactive --agree-tos \
           -m "admin@${DOMAIN_BASE}" --redirect 2>/dev/null; then
        log "SSL activo para https://$FULL_DOMAIN"
        RESELLER_DATA["${i}_ssl"]="true"
      else
        warn "No se pudo configurar SSL automáticamente."
        warn "Ejecuta manualmente: certbot --nginx -d $FULL_DOMAIN"
        RESELLER_DATA["${i}_ssl"]="false"
      fi
    else
      RESELLER_DATA["${i}_ssl"]="false"
      info "SSL omitido para $SLUG"
    fi
  fi

  # ── Guardar para resumen ──────────────────────────────────────────────────
  RES_SLUGS+=("$SLUG")
  RES_NAMES+=("$SLUG")
  RES_DOMAINS+=("$FULL_DOMAIN")
  RES_API_KEYS+=("$CHATBOT_API_KEY")
  RES_DB_NAMES+=("$DB_NAME")
  RES_DB_USERS+=("$DB_USER")
  RES_DB_PASSES+=("$DB_PASS")

  log "Revendedor '$SLUG' desplegado correctamente"

done

# =============================================================================
#  PASO 6: Recargar Nginx
# =============================================================================
section "PASO 6 — Activando configuración Nginx"

nginx -t && systemctl reload nginx
log "Nginx recargado"

# Abrir puertos en firewall si no hay dominio
if [[ "$USE_DOMAIN" == "false" ]]; then
  if command -v ufw &>/dev/null && ufw status | grep -q "Status: active"; then
    for ((i=1; i<=NUM_RESELLERS; i++)); do
      PORT=$((8010 + i - 1))
      ufw allow "$PORT/tcp" &>/dev/null || true
    done
    log "Puertos abiertos en UFW"
  fi
fi

# =============================================================================
#  PASO 7: Script de actualización
# =============================================================================
section "PASO 7 — Creando script de actualización"

cat > /usr/local/bin/update-chatbot-resellers.sh <<'UPDATESCRIPT'
#!/usr/bin/env bash
# Actualiza todos los revendedores del chatbot IPTV desde GitHub

set -euo pipefail
GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'

for APP_DIR in /var/www/chatbot-*/; do
  SLUG=$(basename "$APP_DIR" | sed 's/chatbot-//')
  echo -e "${YELLOW}[→]${NC} Actualizando $SLUG..."
  if [[ -d "${APP_DIR}.git" ]]; then
    cd "$APP_DIR"
    git pull origin main
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader -q
    chown -R www-data:www-data "$APP_DIR"
    echo -e "${GREEN}[✓]${NC} $SLUG actualizado"
  else
    echo "  Saltando $SLUG — no es un repositorio git"
  fi
done

nginx -t && systemctl reload nginx
echo -e "${GREEN}[✓]${NC} Todos los revendedores actualizados"
UPDATESCRIPT

chmod +x /usr/local/bin/update-chatbot-resellers.sh
log "Script de actualización: /usr/local/bin/update-chatbot-resellers.sh"

# =============================================================================
#  RESUMEN FINAL
# =============================================================================
section "INSTALACIÓN COMPLETADA"

# Escribir archivo de resumen
{
echo "======================================================================"
echo "  CHATBOT IPTV API — RESUMEN DE INSTALACIÓN"
echo "  Generado: $(date '+%Y-%m-%d %H:%M')"
echo "======================================================================"
echo ""
echo "Para actualizar todos los revendedores:"
echo "  sudo update-chatbot-resellers.sh"
echo ""
echo "----------------------------------------------------------------------"
} > "$SUMMARY_FILE"

echo ""
TOTAL_RES=$NUM_RESELLERS
[[ "$EXISTING_HTML" == "yes" ]] && TOTAL_RES=$((NUM_RESELLERS + 1))
echo -e "${BOLD}${GREEN}  ✅ INSTALACIÓN COMPLETADA — $TOTAL_RES revendedor(es) en total${NC}"
echo ""

# Mostrar reseller existente en /var/www/html si aplica
if [[ "$EXISTING_HTML" == "yes" ]]; then
  echo -e "${BOLD}${CYAN}  ┌─ Revendedor (existente): ${YELLOW}$HTML_SLUG${NC}  ${DIM}[/var/www/html]${NC}"
  echo -e "${BOLD}${CYAN}  │${NC}  URL API:     ${GREEN}${EXISTING_APP_URL}${NC}"
  echo -e "${BOLD}${CYAN}  │${NC}  API Key:     ${YELLOW}${EXISTING_API_KEY}${NC}"
  echo -e "${BOLD}${CYAN}  │${NC}  BD MySQL:    ${DIM}${EXISTING_DB_NAME}${NC}"
  echo -e "${BOLD}${CYAN}  │${NC}  Directorio:  ${DIM}/var/www/html${NC}"
  echo -e "${BOLD}${CYAN}  └──────────────────────────────────────────────${NC}"
  echo ""
  {
  echo "----------------------------------------------------------------------"
  echo "REVENDEDOR (EXISTENTE): $HTML_SLUG  [/var/www/html]"
  echo "----------------------------------------------------------------------"
  echo "  URL API     : $EXISTING_APP_URL"
  echo "  API Key     : $EXISTING_API_KEY"
  echo "  BD MySQL    : $EXISTING_DB_NAME"
  echo "  Directorio  : /var/www/html"
  echo ""
  } >> "$SUMMARY_FILE"
fi

for ((i=0; i<${#RES_SLUGS[@]}; i++)); do
  SLUG="${RES_SLUGS[$i]}"
  DOMAIN="${RES_DOMAINS[$i]}"
  API_KEY="${RES_API_KEYS[$i]}"
  DB_NAME_V="${RES_DB_NAMES[$i]}"
  DB_USER_V="${RES_DB_USERS[$i]}"
  DB_PASS_V="${RES_DB_PASSES[$i]}"
  APP_DIR="${BASE_DIR}/chatbot-${SLUG}"

  if [[ "$USE_DOMAIN" == "true" ]]; then
    SSL_FLAG="${RESELLER_DATA["$((i+1))_ssl"]:-false}"
    if [[ "$SSL_FLAG" == "true" ]]; then
      API_URL="https://${DOMAIN}"
    else
      API_URL="http://${DOMAIN}"
    fi
  else
    PORT=$((8010 + i))
    API_URL="http://${SERVER_IP}:${PORT}"
  fi

  echo -e "${BOLD}${CYAN}  ┌─ Revendedor: ${YELLOW}$SLUG${NC}"
  echo -e "${BOLD}${CYAN}  │${NC}  URL API:     ${GREEN}${API_URL}${NC}"
  echo -e "${BOLD}${CYAN}  │${NC}  API Key:     ${YELLOW}${API_KEY}${NC}"
  echo -e "${BOLD}${CYAN}  │${NC}  BD MySQL:    ${DIM}${DB_NAME_V}${NC}"
  echo -e "${BOLD}${CYAN}  │${NC}  BD Usuario:  ${DIM}${DB_USER_V}${NC}"
  echo -e "${BOLD}${CYAN}  │${NC}  BD Password: ${DIM}${DB_PASS_V}${NC}"
  echo -e "${BOLD}${CYAN}  │${NC}  Directorio:  ${DIM}${APP_DIR}${NC}"
  echo -e "${BOLD}${CYAN}  └──────────────────────────────────────────────${NC}"
  echo ""

  {
  echo "----------------------------------------------------------------------"
  echo "REVENDEDOR: $SLUG"
  echo "----------------------------------------------------------------------"
  echo "  URL API     : $API_URL"
  echo "  API Key     : $API_KEY"
  echo "  BD MySQL    : $DB_NAME_V"
  echo "  BD Usuario  : $DB_USER_V"
  echo "  BD Password : $DB_PASS_V"
  echo "  Directorio  : $APP_DIR"
  echo ""
  echo "  Endpoint de prueba:"
  echo "    curl -s -X POST ${API_URL}/api/info \\"
  echo "         -H 'Content-Type: application/json' \\"
  echo "         -H 'X-API-Key: ${API_KEY}'"
  echo ""
  } >> "$SUMMARY_FILE"

done

echo "----------------------------------------------------------------------" >> "$SUMMARY_FILE"
echo "Para actualizar todos: sudo update-chatbot-resellers.sh" >> "$SUMMARY_FILE"
echo "======================================================================" >> "$SUMMARY_FILE"

echo -e "  ${DIM}Resumen guardado en: ${BOLD}$SUMMARY_FILE${NC}"
echo ""
echo -e "  ${YELLOW}Usa la API Key de cada revendedor como header:${NC}"
echo -e "  ${DIM}X-API-Key: <api_key>${NC}"
echo ""
echo -e "  ${YELLOW}En el chatbot builder (nodo API Request), usa la URL de cada revendedor.${NC}"
echo ""
echo -e "${BOLD}${GREEN}  ¡Todo listo! 🎉${NC}"
echo ""
