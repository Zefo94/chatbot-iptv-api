# Middleware API REST para Gestión IPTV XUI.ONE

Este es un sistema de middleware empresarial desarrollado en **PHP 8.2 puro** (sin frameworks pesados), diseñado como una capa intermedia ultra segura y de alto rendimiento que conecta un **Chatbot externo**, pasarelas de pago (**Wompi, MercadoPago, PayPal, Binance Pay**) y el panel de control **XUI.ONE**.

```text
  Chatbot de Ventas (WhatsApp/Telegram)
                │
                ▼ (Autenticado vía X-API-Key)
    ┌───────────────────────┐
    │     API Middleware    │◄─── Webhooks de Pasarelas (Wompi, MP, etc.)
    └───────────────────────┘
                │
                ▼ (Canal Seguro Privado - cURL Admin)
      Panel IPTV XUI.ONE
```

---

## ── SERVICIO DE ACOMPAÑAMIENTO: ARTIFACTS ADICIONALES ──
> [!NOTE]
> Se ha creado un plan de implementación detallado para revisar la arquitectura lógica y el modelado de datos en el archivo de Artifacts del sistema: [Plan de Arquitectura e Implementación](file:///home/dev/.gemini/antigravity/brain/2cbb46c4-cafe-4d64-9b8b-3a67eb91d11d/artifacts/implementation_plan.md)

---

## 1. Estructura de Directorios del Proyecto

El proyecto está diseñado siguiendo altos estándares de ingeniería de software, separando responsabilidades por capas y aislando el código fuente de los archivos accesibles al público:

```text
/home/dev/Projects/personal/chatbot/
├── .env                        # Configuración activa del sistema (Base de Datos, API Keys, Credenciales)
├── .env.example                # Plantilla de ejemplo de variables de entorno
├── composer.json               # Configuración opcional para estándares de autocarga PSR-4
├── schema.sql                  # Script SQL completo de creación de base de datos
├── nginx.conf                  # Directivas de reescritura de URL y seguridad para aaPanel/Nginx
├── README.md                   # Guía de producción y especificaciones de endpoints (Este archivo)
├── config/
│   ├── database.php            # Configuración de conexiones PDO
│   ├── xui.php                 # Parámetros de comunicación y autenticación con XUI.ONE
│   └── payment.php             # Configuración y credenciales de pasarelas de pago
├── routes/
│   └── api.php                 # Mapeo centralizado de endpoints y métodos HTTP
├── public/
│   ├── index.php               # Único punto de entrada de la aplicación, Router y CORS
│   └── .htaccess               # Reglas de reescritura fallback para servidores Apache
├── storage/
│   └── logs/
│       └── app.log             # Archivo rotativo de logs de depuración del sistema
└── src/
    ├── Autoloader.php          # Autocargador PSR-4 nativo de alto rendimiento (Zero Dependencies)
    ├── Controllers/
    │   ├── BaseController.php  # Controlador base (Manejador de JSON, validaciones de entrada)
    │   ├── UserController.php  # Endpoints de consulta y búsqueda de clientes
    │   ├── LineController.php  # Endpoints de operaciones directas sobre XUI.ONE
    │   └── PaymentController.php # Endpoints de órdenes, estados de cobro y webhook de pagos
    ├── Services/
    │   ├── LoggerService.php   # Servicio de auditoría dual (Archivos físicos + logs en Base de Datos)
    │   ├── XuiService.php      # Encapsulador de la API Administrativa de XUI.ONE
    │   └── PaymentService.php  # Procesamiento automatizado de cobros y renovación en segundo plano
    └── Database/
        └── Connection.php      # Conexión Singleton optimizada a la Base de Datos con PDO
```

---

## 2. Guía de Instalación Paso a Paso en Ubuntu 22.04 + aaPanel

Sigue estos pasos detallados para configurar y desplegar el middleware en tu servidor Ubuntu administrado con aaPanel:

### Paso 1: Configurar el Entorno del Servidor en aaPanel
1. Accede a tu panel de **aaPanel**.
2. Ve a la sección **App Store** e instala los siguientes servicios si aún no lo has hecho:
   * **Nginx** (Versión 1.22 o superior recomendado).
   * **MySQL** o **MariaDB** (MySQL 8.0 o MariaDB 10.6 recomendado).
   * **PHP 8.2** (Instala las extensiones requeridas: `php-curl`, `php-pdo`, `php-mysql`, `php-json`).

### Paso 2: Crear el Sitio Web y Configurar el Directorio de Ejecución
1. Ve a la pestaña **Website** en aaPanel y haz clic en **Add site**.
2. Rellena los datos de tu dominio (ej. `api.tuproveedor.com`) y selecciona **PHP-8.2**.
3. Una vez creado el sitio, haz clic en el nombre del sitio para abrir la ventana de **configuración**.
4. En el menú de la izquierda, entra a **Site directory**:
   * Cambia el **Running directory** de `/` a `/public`. *Esto es crucial para que el archivo `.env` y el código fuente queden aislados y protegidos.*
   * Haz clic en **Save**.

### Paso 3: Configurar las Reglas de Reescritura de URL (Nginx URL Rewrite)
1. En la misma ventana de configuración del sitio, haz clic en **URL rewrite** en el menú izquierdo.
2. Selecciona la opción **User-defined** en el menú desplegable.
3. Pega las siguientes directivas de reescritura (provistas en tu archivo `nginx.conf`):
   ```nginx
   location / {
       try_files $uri $uri/ /index.php$is_args$args;
   }

   # Denegar acceso directo a archivos sensibles
   location ~ /\.(env|git|htaccess) {
       deny all;
       return 404;
   }

   location ~* ^/(config|src|routes|storage|database)/ {
       deny all;
       return 404;
   }
   ```
4. Haz clic en **Save**.

### Paso 4: Cargar la Base de Datos
1. Ve a la pestaña **Database** en aaPanel y haz clic en **Add database**.
2. Configura los accesos:
   * **DB Name**: `iptv_manager`
   * **Username**: `iptv_user`
   * **Password**: `tu_password_seguro`
3. Abre tu gestor de base de datos (o haz clic en **phpMyAdmin** en aaPanel) e importa el archivo `schema.sql` provisto en el proyecto para estructurar las tablas con sus respectivos índices y relaciones.

### Paso 5: Desplegar el Código y Configurar los Permisos
1. Copia o clona la estructura completa de este proyecto en el directorio raíz asignado por aaPanel (ej. `/www/wwwroot/api.tuproveedor.com/`).
2. Copia el archivo de configuración de ejemplo y edítalo con tus accesos reales:
   ```bash
   cp .env.example .env
   nano .env
   ```
   *Rellena los datos de la base de datos recién creada, los accesos a la API de tu panel XUI.ONE y define tu `CHATBOT_API_KEY` secreta.*

3. **Permisos de Escritura de Logs**: Ejecuta los siguientes comandos desde la terminal para asegurarte de que el servidor web de Nginx/aaPanel (`www`) pueda escribir logs físicos:
   ```bash
   chown -R www:www /www/wwwroot/api.tuproveedor.com/storage
   chmod -R 775 /www/wwwroot/api.tuproveedor.com/storage
   ```

---

## 3. Catálogo Técnico de Endpoints para el Chatbot

> [!IMPORTANT]
> **SEGURIDAD**: Todos los endpoints (a excepción de `/api/webhook-pago`) requieren que el chatbot envíe de forma obligatoria la cabecera `X-API-Key` con el valor exacto configurado en el archivo `.env`.
> **Formato de datos**: Todas las peticiones deben ser enviadas vía método `POST` y con cuerpo de datos tipo `JSON` (`Content-Type: application/json`).

---

### A. BUSCAR USUARIO POR TELÉFONO
Busca si un número telefónico ya cuenta con una línea IPTV asignada en nuestra base de datos.

* **Endpoint**: `POST /api/buscar-usuario`
* **Request (`curl`)**:
  ```bash
  curl -X POST http://api.tuproveedor.com/api/buscar-usuario \
    -H "X-API-Key: chatbot_secret_auth_token_here" \
    -H "Content-Type: application/json" \
    -d '{"telefono": "+573001234567"}'
  ```
* **Response Exitoso (200 OK)**:
  ```json
  {
    "success": true,
    "message": "Usuario encontrado exitosamente.",
    "data": {
      "cliente": {
        "id": 1,
        "telefono": "+573001234567",
        "username": "usuario_demo",
        "line_id": 44342,
        "estado": "active",
        "fecha_vencimiento": "2026-06-28 20:00:00",
        "created_at": "2026-05-29 19:20:00"
      }
    }
  }
  ```
* **Response No Encontrado (404 Not Found)**:
  ```json
  {
    "success": false,
    "message": "No se encontró ningún usuario registrado con el número: +573001234567"
  }
  ```

---

### B. CONSULTAR DETALLES DE LÍNEA EN XUI.ONE
Consulta en tiempo real la información directamente desde el panel XUI.ONE.

* **Endpoint**: `POST /api/consultar-linea`
* **Request (`curl`)**:
  ```bash
  curl -X POST http://api.tuproveedor.com/api/consultar-linea \
    -H "X-API-Key: chatbot_secret_auth_token_here" \
    -H "Content-Type: application/json" \
    -d '{"line_id": 44342}'
  ```
* **Response (200 OK)**:
  ```json
  {
    "success": true,
    "username": "usuario_demo",
    "exp_date": "2026-06-28 20:00:00",
    "max_connections": 1,
    "enabled": true
  }
  ```

---

### C. CREAR NUEVA LÍNEA IPTV
Crea un usuario en el panel XUI.ONE y guarda su vinculación local con el número telefónico del chatbot.

* **Endpoint**: `POST /api/crear-linea`
* **Request (`curl`)**:
  ```bash
  curl -X POST http://api.tuproveedor.com/api/crear-linea \
    -H "X-API-Key: chatbot_secret_auth_token_here" \
    -H "Content-Type: application/json" \
    -d '{
      "telefono": "+573001234567",
      "username": "usuario_demo",
      "password": "mi_password_seguro",
      "package_id": 1,
      "max_connections": 1
    }'
  ```
* **Response (201 Created)**:
  ```json
  {
    "success": true,
    "message": "Línea IPTV creada y vinculada correctamente.",
    "data": {
      "telefono": "+573001234567",
      "username": "usuario_demo",
      "line_id": 44342,
      "fecha_vencimiento": "2026-06-28 19:28:44",
      "estado": "active"
    }
  }
  ```

---

### D. RENOVAR LÍNEA DIRECTAMENTE
Suma días a la fecha de vencimiento de la línea (si la línea ya está vencida, suma los días a partir de la fecha actual; si está vigente, los suma a partir de su vencimiento futuro).

* **Endpoint**: `POST /api/renovar-linea`
* **Request (`curl`)**:
  ```bash
  curl -X POST http://api.tuproveedor.com/api/renovar-linea \
    -H "X-API-Key: chatbot_secret_auth_token_here" \
    -H "Content-Type: application/json" \
    -d '{
      "line_id": 44342,
      "dias": 30
    }'
  ```
* **Response (200 OK)**:
  ```json
  {
    "success": true,
    "message": "Línea renovada con éxito.",
    "data": {
      "line_id": 44342,
      "dias_adicionados": 30,
      "nueva_expiracion": "2026-07-28 19:28:44",
      "estado": "active"
    }
  }
  ```

---

### E. SUSPENDER LÍNEA
Deshabilita el acceso a los canales de transmisión de la línea IPTV.

* **Endpoint**: `POST /api/suspender-linea`
* **Request (`curl`)**:
  ```bash
  curl -X POST http://api.tuproveedor.com/api/suspender-linea \
    -H "X-API-Key: chatbot_secret_auth_token_here" \
    -H "Content-Type: application/json" \
    -d '{"line_id": 44342}'
  ```
* **Response (200 OK)**:
  ```json
  {
    "success": true,
    "message": "Línea suspendida correctamente.",
    "data": {
      "line_id": 44342,
      "estado": "suspended"
    }
  }
  ```

---

### F. ACTIVAR LÍNEA
Reactiva el acceso de transmisión de la línea.

* **Endpoint**: `POST /api/activar-linea`
* **Request (`curl`)**:
  ```bash
  curl -X POST http://api.tuproveedor.com/api/activar-linea \
    -H "X-API-Key: chatbot_secret_auth_token_here" \
    -H "Content-Type: application/json" \
    -d '{"line_id": 44342}'
  ```
* **Response (200 OK)**:
  ```json
  {
    "success": true,
    "message": "Línea activada correctamente.",
    "data": {
      "line_id": 44342,
      "estado": "active"
    }
  }
  ```

---

### G. CAMBIAR CONTRASEÑA DE LÍNEA
Cambia las credenciales de acceso de la línea del cliente.

* **Endpoint**: `POST /api/cambiar-password`
* **Request (`curl`)**:
  ```bash
  curl -X POST http://api.tuproveedor.com/api/cambiar-password \
    -H "X-API-Key: chatbot_secret_auth_token_here" \
    -H "Content-Type: application/json" \
    -d '{
      "line_id": 44342,
      "password": "nuevo_password_123"
    }'
  ```
* **Response (200 OK)**:
  ```json
  {
    "success": true,
    "message": "Contraseña actualizada con éxito.",
    "data": {
      "line_id": 44342
    }
  }
  ```

---

### H. CAMBIAR LÍMITE DE CONEXIONES
Modifica la cantidad de pantallas/conexiones simultáneas de la línea IPTV.

* **Endpoint**: `POST /api/cambiar-conexiones`
* **Request (`curl`)**:
  ```bash
  curl -X POST http://api.tuproveedor.com/api/cambiar-conexiones \
    -H "X-API-Key: chatbot_secret_auth_token_here" \
    -H "Content-Type: application/json" \
    -d '{
      "line_id": 44342,
      "conexiones": 2
    }'
  ```
* **Response (200 OK)**:
  ```json
  {
    "success": true,
    "message": "Límite de conexiones actualizado con éxito.",
    "data": {
      "line_id": 44342,
      "conexiones": 2
    }
  }
  ```

---

### I. CREAR ORDEN DE PAGO
Genera una sesión de pago pendiente y calcula el monto para el chatbot.

* **Endpoint**: `POST /api/crear-orden`
* **Request (`curl`)**:
  ```bash
  curl -X POST http://api.tuproveedor.com/api/crear-orden \
    -H "X-API-Key: chatbot_secret_auth_token_here" \
    -H "Content-Type: application/json" \
    -d '{
      "line_id": 44342,
      "dias": 30,
      "monto": 15000.00
    }'
  ```
* **Response (201 Created)**:
  ```json
  {
    "success": true,
    "message": "Orden de pago creada exitosamente.",
    "data": {
      "orden": {
        "order_id": "ORD-5F2D9C3E1B0F4A7D",
        "line_id": 44342,
        "dias": 30,
        "monto": 15000.00,
        "estado": "pending"
      }
    }
  }
  ```

---

### J. CONSULTAR ESTADO DE PAGO / ORDEN
Permite al chatbot verificar el estado de una orden.

* **Endpoint**: `POST /api/consultar-pago`
* **Request (`curl`)**:
  ```bash
  curl -X POST http://api.tuproveedor.com/api/consultar-pago \
    -H "X-API-Key: chatbot_secret_auth_token_here" \
    -H "Content-Type: application/json" \
    -d '{"order_id": "ORD-5F2D9C3E1B0F4A7D"}'
  ```
* **Response (200 OK)**:
  ```json
  {
    "success": true,
    "message": "Detalles de la orden de pago recuperados.",
    "data": {
      "orden": {
        "order_id": "ORD-5F2D9C3E1B0F4A7D",
        "line_id": 44342,
        "dias": 30,
        "monto": 15000.00,
        "estado": "completed",
        "created_at": "2026-05-29 19:28:44"
      }
    }
  }
  ```

---

### K. WEBHOOK DE COBRO EXITOSO (PASARELA AUTOMÁTICA)
Manejador criptográfico para notificaciones automatizadas. Cuando la pasarela aprueba el cobro, el webhook realiza:
1. Verificación de firmas criptográficas (ej. checksum hash SHA256 para Wompi).
2. Recupera la orden de la base de datos (`order_id`).
3. Calcula el vencimiento acumulado del IPTV.
4. **Renueva la cuenta automáticamente en XUI.ONE** y la activa si estaba suspendida.
5. Registra el pago en la tabla `pagos` y cambia la orden a `completed`.

* **Endpoint**: `POST /api/webhook-pago?gateway=wompi`
* **Headers requeridos por Wompi**: `X-Event-Signature: <calculated_hash_signature>`
* **Cuerpo de Datos de ejemplo (Wompi)**:
  ```json
  {
    "event": "transaction.updated",
    "data": {
      "transaction": {
        "id": "12345-womp-tx",
        "status": "APPROVED",
        "amount_in_cents": 1500000,
        "currency": "COP",
        "reference": "ORD-5F2D9C3E1B0F4A7D"
      }
    },
    "timestamp": 1716943800
  }
  ```
* **Response de Aprobación Exitoso (200 OK)**:
  ```json
  {
    "success": true,
    "message": "Webhook procesado exitosamente por la API.",
    "result": {
      "success": true,
      "order_id": "ORD-5F2D9C3E1B0F4A7D",
      "gateway": "wompi",
      "status": "processed",
      "renewal": true
    }
  }
  ```

---

## 4. Buenas Prácticas de Seguridad en Producción

Para garantizar la estabilidad operacional de tu sistema middleware en producción bajo Ubuntu 22.04 y aaPanel, te recomendamos encarecidamente implementar las siguientes políticas de seguridad informática:

1. **Forzar SSL/TLS Exclusivo**:
   * En la configuración del sitio web en aaPanel, activa **Let's Encrypt SSL** para forzar todo el tráfico del chatbot y de los webhooks a través del protocolo cifrado HTTPS (evitando ataques de interceptación Man-in-the-Middle).

2. **Mitigación de Denegación de Servicio (Rate Limiting)**:
   * Agrega límites de solicitudes por minuto en Nginx para proteger tus endpoints de ataques DDoS o abusos. Puedes agregar esto en la configuración global de Nginx:
     ```nginx
     limit_req_zone $binary_remote_addr zone=iptv_limit:10m rate=5r/s;
     ```
     Y dentro de tu bloque `location /` en la configuración del sitio:
     ```nginx
     location / {
         limit_req zone=iptv_limit burst=10 nodelay;
         try_files $uri $uri/ /index.php$is_args$args;
     }
     ```

3. **Restringir IPs para Webhooks**:
   * Si es posible, bloquea las llamadas entrantes al endpoint `/api/webhook-pago` permitiendo únicamente los rangos de direcciones IP oficiales de tus procesadores de pago (Wompi, MercadoPago, etc.).

4. **Monitoreo y Rotación de Logs**:
   * El sistema escribe logs robustos en `storage/logs/app.log`. Asegúrate de configurar un script de `logrotate` en Ubuntu para que el archivo de log no consuma todo el espacio del disco a largo plazo.
