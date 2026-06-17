# Manual de Deployment — Chatbot IPTV API

> Sistema de deploy **100 % nativo** (Nginx + PHP-FPM + MariaDB, sin Docker).
> Todos los scripts requieren **Ubuntu 22.04 / 24.04** y ejecutarse como **root**.

---

## Resumen rápido — ¿cuándo usar cada script?

| Script | Caso de uso | Instancias | Tiempo estimado |
|---|---|---|---|
| `install-full.sh` | VPS limpio, un solo revendedor, con dominio + SSL | 1 | ~5 min |
| `install-native.sh` | VPS que venía con Docker, migrar a nativo | 1 | ~5 min |
| `deploy-multi-reseller.sh` | Instalar N revendedores aislados en un servidor | N (1–10) | ~3 min × N |

---

## 1. `install-full.sh` — Instalación completa en VPS limpio

### ¿Cuándo usarlo?
- Servidor Ubuntu **recién creado**, sin nada instalado.
- Quieres desplegar **un solo revendedor**.
- Tienes (o no) un dominio propio y quieres SSL automático con Let's Encrypt.

### ¿Qué hace?
1. Limpia locks de `dpkg` y para servicios anteriores (si los hay).
2. Instala todos los paquetes del sistema: **Nginx, MariaDB, PHP 8.2** (desde PPA `ondrej/php`), Composer, Certbot.
3. Clona el repo desde GitHub en `/var/www/html`.
4. Instala dependencias PHP con Composer.
5. Crea la base de datos `iptv_manager` y el usuario `iptv_user` con password aleatorio.
6. Importa `schema.sql`.
7. Genera `.env` con secretos seguros (`openssl rand`). Te pide durante la instalación:
   - Dominio (o Enter para usar solo la IP)
   - Email admin (para Let's Encrypt)
   - XUI API URL
   - XUI API Key
   - XUI Reseller API URL
8. Configura el vhost Nginx en `/etc/nginx/sites-available/chatbot`.
9. Si pusiste dominio → corre Certbot y activa HTTPS automáticamente.
10. Arranca `php8.2-fpm`, `nginx` y `mariadb`.
11. Verifica que la API responda y muestra las credenciales generadas.

### Cómo ejecutarlo
```bash
wget https://raw.githubusercontent.com/Zefo94/chatbot-iptv-api/main/install-full.sh
chmod +x install-full.sh
sudo bash install-full.sh
```

### Resultado
- API en `https://tu-dominio.com` (o `http://IP`)
- Código en `/var/www/html`
- `.env` en `/var/www/html/.env`
- Configuración Nginx en `/etc/nginx/sites-available/chatbot`

### Pendiente tras instalar
Editar `/var/www/html/.env` y completar:
```
PAYPAL_CLIENT_ID=
PAYPAL_CLIENT_SECRET=
PAYPAL_WEBHOOK_ID=
PAYPAL_MODE=live        # cambiar de sandbox a live
XUI_API_KEY=            # si no lo pusiste durante la instalación
```

---

## 2. `install-native.sh` — Instalación nativa silenciosa

### ¿Cuándo usarlo?
- Quieres hacer una instalación **rápida sin preguntas interactivas**.
- Instalación de **un solo revendedor** con configuración mínima.
- Prefieres rellenar el `.env` a mano después de instalar.

### ¿Qué hace diferente a `install-full.sh`?
- No pregunta nada durante la instalación (instala todo en silencio).
- Deja el `.env` con campos XUI y pagos vacíos para rellenar a mano.
- El `.env` generado usa `APP_URL=http://IP` (sin dominio). SSL se configura después a mano con Certbot.

### Cómo ejecutarlo
```bash
wget https://raw.githubusercontent.com/Zefo94/chatbot-iptv-api/main/install-native.sh
chmod +x install-native.sh
sudo bash install-native.sh
```

### Resultado
- API en `http://IP`
- Código en `/var/www/html`
- Configuración Nginx en `/etc/nginx/sites-available/chatbot`

### Pendiente tras instalar
Editar `/var/www/html/.env` y completar todos los campos XUI y de pagos:
```bash
nano /var/www/html/.env
```
Si quieres SSL después:
```bash
certbot --nginx -d tu-dominio.com
```

---

## 3. `deploy-multi-reseller.sh` — Instalar N revendedores aislados

### ¿Cuándo usarlo?
- Quieres tener **múltiples revendedores** en el mismo servidor, cada uno con su propia instancia.
- Cada revendedor necesita su propia URL, BD, API Key y panel XUI.
- Escenario típico: 1 servidor = 2–10 revendedores con subdominios diferentes.

### ¿Qué hace?
**Paso 0** — Instala dependencias del sistema (mismas que `install-full.sh`) si no están ya.

**Paso 1** — Configura acceso a GitHub. Ofrece:
- SSH con clave existente o nueva (recomendado para updates automáticos)
- HTTPS con usuario + token personal

**Paso 2** — Si existe una instalación en `/var/www/html`, lee su `.env` como plantilla para no tener que reescribir todos los datos XUI y de pago.

**Paso 3** — Por cada revendedor pide interactivamente:
- Nombre/slug (ej: `reseller2`, `tiendaXYZ`)
- Subdominio (ej: `api.tienda.com`) o usa la IP con puerto
- API Key del revendedor en XUI
- Pasarelas de pago (se pueden copiar de la plantilla existente)

**Paso 4** — Pide la contraseña root de MySQL/MariaDB.

**Paso 5** — Por cada revendedor ejecuta:
- `git clone` del repo en `/var/www/chatbot-{slug}/`
- Genera `.env` único con secretos aleatorios
- `composer install`
- Crea BD `chatbot_{slug}` con usuario y password propios
- Importa `schema.sql`
- Configura vhost Nginx con subdominio (o puerto `8010+N` si no hay dominio)
- SSL opcional con Certbot

**Paso 6** — Recarga Nginx y abre puertos en UFW si aplica.

**Paso 7** — Crea `/usr/local/bin/update-chatbot-resellers.sh` para actualizar todos los revendedores a futuro con un solo comando.

**Resumen final** — Guarda todas las credenciales en `/root/CHATBOT_RESELLERS.txt`.

### Cómo ejecutarlo
```bash
wget https://raw.githubusercontent.com/Zefo94/chatbot-iptv-api/main/deploy-multi-reseller.sh
chmod +x deploy-multi-reseller.sh
sudo bash deploy-multi-reseller.sh
```

### Estructura resultante en el servidor
```
/var/www/
├── chatbot-reseller1/      # Revendedor 1
│   ├── .env                # Credenciales únicas
│   ├── public/
│   └── ...
├── chatbot-tiendaXYZ/      # Revendedor 2
│   ├── .env
│   └── ...
└── ...

/etc/nginx/sites-available/
├── chatbot-reseller1       # vhost → api-reseller1.dominio.com
└── chatbot-tiendaXYZ       # vhost → api.tiendaXYZ.com

/root/CHATBOT_RESELLERS.txt # Todas las credenciales
```

### Actualizar todos los revendedores a futuro
```bash
sudo update-chatbot-resellers.sh
```
Hace `git pull` + `composer install` en cada instancia y recarga Nginx.

### Endpoint de prueba por revendedor
```bash
curl -s -X POST https://api-reseller1.dominio.com/api/info \
     -H "Content-Type: application/json" \
     -H "X-API-Key: <api_key_del_revendedor>"
```

---

## Árbol de decisión

```
¿Necesitas 1 sola instancia o múltiples revendedores?
│
├── 1 sola instancia ───── ¿Quieres configurar credenciales durante la instalación?
│                          ├── SÍ ──► install-full.sh   (interactivo, soporte SSL)
│                          └── NO ──► install-native.sh (silencioso, editas .env luego)
│
└── Múltiples revendedores ──► deploy-multi-reseller.sh
```

---

## Comandos útiles post-instalación

```bash
# Ver logs de la API
tail -f /var/www/chatbot-{slug}/storage/logs/*.log

# Reiniciar servicios
service nginx restart
service php8.2-fpm restart
service mariadb restart

# Verificar Nginx
nginx -t

# Ver errores Nginx
tail -f /var/log/nginx/chatbot-{slug}-error.log
```
