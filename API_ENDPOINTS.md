# 📡 API Endpoints — IPTV XUI.ONE Middleware

Guía completa de todos los endpoints disponibles para integrar tu chatbot con el panel XUI.ONE.

## 🔐 Autenticación

**Todos los endpoints** (excepto `/api/webhook-pago`) requieren el header:

```
X-API-Key: chatbot_secret_auth_token_here
```

El valor viene de `CHATBOT_API_KEY` en tu archivo `.env`. ⚠️ **Cámbialo antes de producción** por una cadena aleatoria larga (ej. `openssl rand -hex 32`).

## 🌐 URL Base

```
http://127.0.0.1:8000
```

(o tu dominio/IP de producción)

## 📋 Headers comunes

Todos los endpoints aceptan/requieren:

| Header | Valor | Requerido |
|---|---|---|
| `X-API-Key` | `chatbot_secret_auth_token_here` | ✅ Sí (excepto webhook) |
| `Content-Type` | `application/json` | ✅ Sí |

---

# 🎬 Gestión de Líneas IPTV

Todos los endpoints de líneas aceptan **`username` o `line_id`** indistintamente. Recomendado: usar `username` desde el chatbot. Opcionalmente puedes pasar `telefono` para vincular automáticamente al cliente.

## 1️⃣ `POST /api/consultar-linea`

Consulta el estado actual de una línea (vencimiento, conexiones, estado activo/suspendido).

### Body (mínimo)
```json
{
  "username": "EDWINERAZOTF"
}
```

### Body (completo)
```json
{
  "username": "EDWINERAZOTF",
  "telefono": "+573001234567"
}
```

### Respuesta exitosa (200)
```json
{
  "success": true,
  "line_id": 1683,
  "username": "EDWINERAZOTF",
  "exp_date": "2026-06-28 13:32:36",
  "max_connections": 1,
  "enabled": true,
  "message": "👤 Usuario: EDWINERAZOTF\n📅 Vencimiento: 2026-06-28 13:32:36\n🔌 Conexiones: 1\n⚡ Estado: Activo 🟢"
}
```

### Respuesta error (404)
```json
{
  "success": false,
  "message": "El nombre de usuario IPTV 'noexiste' no se encontró ni en BD local ni en XUI.ONE."
}
```

### cURL
```bash
curl -X POST http://127.0.0.1:8000/api/consultar-linea \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{"username":"EDWINERAZOTF"}'
```

### ✨ Características
- **Caché automático**: la primera consulta escanea XUI completo (~3 seg); las siguientes son instantáneas (~0.4 seg)
- **Auto-vinculación**: si pasas `telefono`, vincula automáticamente el cliente
- **Modo texto plano**: agrega `?format=text` para que devuelva solo el `message` sin JSON

---

## 2️⃣ `POST /api/crear-linea`

Crea una **nueva** línea IPTV en XUI.ONE y la vincula al cliente del chatbot.

### Body requerido
```json
{
  "telefono": "+573001234567",
  "username": "nuevo_cliente_2026",
  "password": "MiClaveSegura123",
  "package_id": 1,
  "max_connections": 1
}
```

### Campos
| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `telefono` | string | ✅ | Teléfono del cliente (formato libre) |
| `username` | string | ✅ | Username IPTV (único en XUI) |
| `password` | string | ✅ | Password IPTV |
| `package_id` | integer | ❌ | ID del paquete (default desde `.env`) |
| `max_connections` | integer | ❌ | Conexiones simultáneas (default desde `.env`) |

### Respuesta exitosa (201)
```json
{
  "success": true,
  "message": "Línea IPTV creada y vinculada correctamente.",
  "data": {
    "telefono": "+573001234567",
    "username": "nuevo_cliente_2026",
    "line_id": 9999,
    "fecha_vencimiento": "2026-06-29 12:00:00",
    "estado": "active"
  }
}
```

### cURL
```bash
curl -X POST http://127.0.0.1:8000/api/crear-linea \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{
    "telefono":"+573001234567",
    "username":"nuevo_cliente_2026",
    "password":"MiClaveSegura123"
  }'
```

---

## 3️⃣ `POST /api/renovar-linea`

Extiende la fecha de vencimiento N días y **reactiva automáticamente** la línea (si estaba suspendida).

### Body con días explícitos
```json
{
  "username": "EDWINERAZOTF",
  "dias": 30
}
```

### Body con package_id (deriva los días del paquete)
```json
{
  "username": "EDWINERAZOTF",
  "package_id": 3
}
```

### Campos
| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `username` | string | ✅* | Username IPTV (* o `line_id`) |
| `line_id` | integer | ✅* | ID numérico (* o `username`) |
| `dias` | integer | ✅* | Días a sumar (* o `package_id`) |
| `package_id` | integer | ✅* | ID del paquete (* o `dias`) — toma la duración del paquete + actualiza la asignación |

### Respuesta exitosa (200)
```json
{
  "success": true,
  "message": "Línea renovada con éxito.",
  "data": {
    "line_id": 1683,
    "dias_adicionados": 30,
    "nueva_expiracion": "2026-07-28 13:32:36",
    "estado": "active"
  }
}
```

### cURL
```bash
curl -X POST http://127.0.0.1:8000/api/renovar-linea \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{"username":"EDWINERAZOTF","dias":30}'
```

### ✨ Comportamiento
- **Si está vencida**: suma los días desde HOY
- **Si está vigente**: suma los días desde la fecha actual de vencimiento
- **Si está suspendida**: la reactiva automáticamente (`enabled=1`)
- **Preserva** username, password y conexiones

---

## 3️⃣.5️⃣ `POST /api/listar-paquetes`

Lista simplificada de paquetes vendibles desde XUI.ONE, lista para armar el menú del chatbot. Excluye trials por defecto.

### Body
```json
{}
```

O con trials incluidos:
```json
{ "incluir_trials": true }
```

### Respuesta exitosa (200)
```json
{
  "success": true,
  "message": "Paquetes disponibles.",
  "data": {
    "paquetes": [
      {
        "id": 3,
        "nombre": "1 MES FULL - 1 Dispositivo sin XXX",
        "duracion": 1,
        "duracion_unidad": "months",
        "dias": 30,
        "duracion_humana": "1 mes",
        "es_trial": false,
        "creditos": 1
      },
      {
        "id": 9,
        "nombre": "12 MESES FULL - 1 Dispositivo sin XXX",
        "duracion": 1,
        "duracion_unidad": "years",
        "dias": 365,
        "duracion_humana": "1 año",
        "es_trial": false,
        "creditos": 12
      }
    ]
  }
}
```

### cURL
```bash
curl -X POST http://127.0.0.1:8000/api/listar-paquetes \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{}'
```

### 💡 Uso en el chatbot
- Llama este endpoint al cargar el menú "Ver Planes"
- Itera sobre `paquetes` para generar botones
- Guarda el `id` cuando el cliente elige uno
- Pásalo a `crear-linea` (campo `package_id`) o `renovar-linea` (campo `package_id`)

### 💰 Precios
**No** vienen del panel — los manejas tú en el chatbot (en euros o cualquier moneda). El `creditos` es la moneda interna del reseller, no el precio al cliente.

---

## 4️⃣ `POST /api/suspender-linea`

Desactiva el acceso de una línea (sin borrarla).

### Body
```json
{
  "username": "EDWINERAZOTF"
}
```

### Respuesta exitosa (200)
```json
{
  "success": true,
  "message": "Línea suspendida correctamente.",
  "data": {
    "line_id": 1683,
    "estado": "suspended"
  }
}
```

### cURL
```bash
curl -X POST http://127.0.0.1:8000/api/suspender-linea \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{"username":"EDWINERAZOTF"}'
```

---

## 5️⃣.5️⃣ `POST /api/eliminar-linea`

Borra una línea de XUI.ONE **y** la elimina de la BD local. Operación irreversible.

### Body
```json
{
  "username": "cliente_a_borrar"
}
```

### Respuesta exitosa (200)
```json
{
  "success": true,
  "message": "Línea eliminada correctamente.",
  "data": {
    "line_id": 44348,
    "filas_borradas_local": 1
  }
}
```

### cURL
```bash
curl -X POST http://127.0.0.1:8000/api/eliminar-linea \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{"username":"cliente_a_borrar"}'
```

⚠️ **Cuidado**: esto borra permanentemente la línea del panel. No hay forma de recuperarla.

---

## 5️⃣ `POST /api/activar-linea`

Reactiva una línea suspendida (sin renovar fecha).

### Body
```json
{
  "username": "EDWINERAZOTF"
}
```

### Respuesta exitosa (200)
```json
{
  "success": true,
  "message": "Línea activada correctamente.",
  "data": {
    "line_id": 1683,
    "estado": "active"
  }
}
```

### cURL
```bash
curl -X POST http://127.0.0.1:8000/api/activar-linea \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{"username":"EDWINERAZOTF"}'
```

---

## 6️⃣ `POST /api/cambiar-password`

Cambia el password de una línea (preserva fecha, conexiones, etc.).

### Body
```json
{
  "username": "EDWINERAZOTF",
  "password": "NuevaClave2026"
}
```

### Respuesta exitosa (200)
```json
{
  "success": true,
  "message": "Contraseña actualizada con éxito.",
  "data": {
    "line_id": 1683
  }
}
```

### cURL
```bash
curl -X POST http://127.0.0.1:8000/api/cambiar-password \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{"username":"EDWINERAZOTF","password":"NuevaClave2026"}'
```

---

## 7️⃣ `POST /api/cambiar-conexiones`

Cambia el límite de conexiones simultáneas (preserva fecha, password, etc.).

### Body
```json
{
  "username": "EDWINERAZOTF",
  "conexiones": 2
}
```

### Respuesta exitosa (200)
```json
{
  "success": true,
  "message": "Límite de conexiones actualizado con éxito.",
  "data": {
    "line_id": 1683,
    "conexiones": 2
  }
}
```

### cURL
```bash
curl -X POST http://127.0.0.1:8000/api/cambiar-conexiones \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{"username":"EDWINERAZOTF","conexiones":2}'
```

---

# 👤 Búsqueda de Usuarios

## 8️⃣ `POST /api/buscar-usuario`

Busca clientes registrados en la BD local **por teléfono**. Soporta múltiples líneas por número (un cliente puede tener varias cuentas).

### Body
```json
{
  "telefono": "+573001234567"
}
```

### Respuesta exitosa (200) — 1 cuenta
```json
{
  "success": true,
  "message": "Cuentas encontradas.",
  "data": {
    "total": 1,
    "clientes": [
      {
        "id": 4,
        "telefono": "+573001234567",
        "username": "EDWINERAZOTF",
        "line_id": 1683,
        "revendedor_id": 17,
        "estado": "active",
        "fecha_vencimiento": "2026-07-28 13:32:36",
        "created_at": "2026-05-29 22:08:12"
      }
    ],
    "cliente": { "...": "(mismo objeto, retrocompat)" }
  }
}
```

### Respuesta exitosa (200) — Múltiples cuentas
```json
{
  "data": {
    "total": 3,
    "clientes": [
      { "username": "papa_juan", "line_id": 44371, ... },
      { "username": "hijo_juan", "line_id": 44372, ... },
      { "username": "esposa_juan", "line_id": 44373, ... }
    ],
    "cliente": null
  }
}
```

### Respuesta no encontrado (404)
```json
{
  "success": false,
  "message": "No se encontró ningún usuario registrado con el número: +573001234567"
}
```

### cURL
```bash
curl -X POST http://127.0.0.1:8000/api/buscar-usuario \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{"telefono":"+573001234567"}'
```

---

## 8️⃣.5️⃣ `POST /api/listar-mis-lineas`

Variante **bot-friendly** de `buscar-usuario`. Devuelve un mensaje pre-formateado listo para mostrar al cliente + un objeto `indices` para que el chatbot mapee la selección numérica a la cuenta correspondiente.

### Body
```json
{
  "telefono": "+573001234567",
  "revendedor_id": 17
}
```

| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `telefono` | string | ✅ | Teléfono del cliente |
| `revendedor_id` | integer | ❌ | Si se pasa, filtra solo las cuentas de ese reseller |

### Respuesta exitosa (200) — Con 3 cuentas
```json
{
  "success": true,
  "message": "Listado de cuentas.",
  "data": {
    "total": 3,
    "clientes": [
      {
        "indice": 1,
        "username": "ana",
        "line_id": 44376,
        "revendedor_id": 17,
        "estado": "active",
        "fecha_vencimiento": "2026-06-30 22:45:57"
      },
      { "indice": 2, "username": "luis", ... },
      { "indice": 3, "username": "maria", ... }
    ],
    "indices": {
      "1": { "username": "ana", "line_id": 44376, ... },
      "2": { "username": "luis", "line_id": 44377, ... },
      "3": { "username": "maria", "line_id": 44378, ... }
    },
    "message": "📺 *Tus cuentas IPTV:*\n\n1️⃣ *ana*\n   📅 Vence: 2026-06-30 22:45:57\n   ⚡ Activa 🟢\n\n2️⃣ *luis*\n   📅 Vence: 2026-06-30 22:45:58\n   ⚡ Activa 🟢\n\n3️⃣ *maria*\n   📅 Vence: 2026-06-30 22:45:58\n   ⚡ Activa 🟢\n\nResponde con el *número* (1 a 3) para gestionar esa cuenta."
  }
}
```

### Respuesta — sin cuentas
```json
{
  "success": true,
  "data": {
    "total": 0,
    "clientes": [],
    "message": "No encontré cuentas IPTV asociadas a tu número. ¿Quieres crear una?"
  }
}
```

### cURL
```bash
curl -X POST http://127.0.0.1:8000/api/listar-mis-lineas \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{"telefono":"+573001234567","revendedor_id":17}'
```

### 💡 Uso en el chatbot

**Caso 1: Cliente tiene 1 sola cuenta**
- Bot envía `{data.message}` (texto formateado)
- Bot toma `{data.indices.1.username}` directamente (no necesita preguntar)

**Caso 2: Cliente tiene varias cuentas**
- Bot envía `{data.message}` (muestra menú formateado con emojis 1️⃣ 2️⃣ 3️⃣)
- Bot captura la respuesta del cliente: "1", "2", "3"
- Bot guarda `usuario_iptv = {data.indices.{input}.username}` según la opción
- Bot llama a `/api/consultar-linea`, `/api/renovar-linea`, etc. con ese `usuario_iptv`

**Caso 3: Cliente no tiene cuentas (total=0)**
- Bot envía `{data.message}` ("No encontré cuentas... ¿Quieres crear una?")
- Bot redirige al flujo de "comprar cuenta nueva"

### ✨ Características
- **Pre-formateado**: el campo `message` ya viene con emojis y formato Markdown listo para WhatsApp
- **Lookup por índice**: `indices.{1,2,3,...}` te da acceso O(1) a la cuenta por su número
- **Filtro opcional por reseller**: si pasas `revendedor_id`, solo ves las cuentas de ese revendedor
- **Hasta 10 cuentas**: usa emojis 1️⃣ a 🔟; para más usa `[11]`, `[12]`, etc.

---

# 💰 Pagos

## 9️⃣ `POST /api/crear-orden`

Genera una orden de pago para renovar una línea.

### Body
```json
{
  "line_id": 1683,
  "dias": 30,
  "monto": 15.00
}
```

### Respuesta exitosa (201)
```json
{
  "success": true,
  "message": "Orden de pago creada exitosamente.",
  "data": {
    "orden": {
      "order_id": "ORD-1234567890-abc",
      "line_id": 1683,
      "dias": 30,
      "monto": 15.00,
      "estado": "pending",
      "created_at": "2026-05-29 22:30:00"
    }
  }
}
```

### cURL
```bash
curl -X POST http://127.0.0.1:8000/api/crear-orden \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{"line_id":1683,"dias":30,"monto":15.00}'
```

---

## 🔟 `POST /api/consultar-pago`

Verifica el estado de una orden de pago.

### Body
```json
{
  "order_id": "ORD-1234567890-abc"
}
```

### Respuesta exitosa (200)
```json
{
  "success": true,
  "message": "Detalles de la orden de pago recuperados.",
  "data": {
    "orden": {
      "order_id": "ORD-1234567890-abc",
      "line_id": 1683,
      "dias": 30,
      "monto": 15.00,
      "estado": "completed",
      "created_at": "2026-05-29 22:30:00"
    }
  }
}
```

### Estados posibles
- `pending` — Esperando pago
- `completed` — Pago confirmado, línea renovada
- `expired` — Orden caducada sin pago
- `failed` — Pago rechazado

### cURL
```bash
curl -X POST http://127.0.0.1:8000/api/consultar-pago \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{"order_id":"ORD-1234567890-abc"}'
```

---

## 1️⃣1️⃣ `POST /api/webhook-pago?gateway=wompi`

Endpoint público (sin `X-API-Key`) que reciben las pasarelas de pago tras procesar una transacción. **No lo llamas tú** — lo configuras como webhook URL en el dashboard de Wompi/MercadoPago/PayPal/Binance.

### Gateways soportados
- `?gateway=wompi`
- `?gateway=mercadopago`
- `?gateway=paypal`
- `?gateway=binance`

### Comportamiento
1. Valida la firma del payload contra el secreto configurado en `.env`
2. Si el pago es exitoso, **renueva automáticamente** la línea asociada
3. Marca la orden como `completed` en BD

### 🔧 Configuración por pasarela

Las claves se leen desde [`.env`](.env) y se mapean en [`config/payment.php`](config/payment.php). Lo que debes hacer en cada dashboard:

#### Wompi
```env
WOMPI_PUBLIC_KEY=pub_prod_TU_LLAVE_PUBLICA
WOMPI_PRIVATE_KEY=prv_prod_TU_LLAVE_PRIVADA
WOMPI_WEBHOOK_SECRET=TU_SECRETO_DE_EVENTOS
```

**En el dashboard de Wompi:**
1. Eventos → Webhooks → Crear nuevo
2. **URL**: `https://TU_DOMINIO.com/api/webhook-pago?gateway=wompi`
3. Copia el **Secreto de eventos** y pégalo en `WOMPI_WEBHOOK_SECRET`
4. Eventos a suscribir: `transaction.updated`

La firma se valida con SHA256 de:
`transaction.id + status + amount_in_cents + currency + reference + timestamp + secret`

#### MercadoPago
```env
MERCADOPAGO_ACCESS_TOKEN=APP_USR-xxxxxxxx-xxxxxxxx
MERCADOPAGO_WEBHOOK_SECRET=tu_secreto_aqui
```

**En el dashboard de MP:**
1. Tu aplicación → Webhooks → Configurar URL
2. **URL**: `https://TU_DOMINIO.com/api/webhook-pago?gateway=mercadopago`
3. Eventos: `Pagos` (payment)
4. Copia el secret de firmas → `MERCADOPAGO_WEBHOOK_SECRET`

#### PayPal
```env
PAYPAL_CLIENT_ID=tu_client_id
PAYPAL_CLIENT_SECRET=tu_client_secret
PAYPAL_MODE=live   # o sandbox para pruebas
```

**En el dashboard de PayPal Developer:**
1. My Apps → Tu app → Add Webhook
2. **URL**: `https://TU_DOMINIO.com/api/webhook-pago?gateway=paypal`
3. Eventos: `PAYMENT.CAPTURE.COMPLETED`, `CHECKOUT.ORDER.APPROVED`

#### Binance Pay
```env
BINANCE_API_KEY=tu_api_key
BINANCE_SECRET_KEY=tu_secret_key
```

**En el merchant portal de Binance Pay:**
1. Webhook Settings
2. **URL**: `https://TU_DOMINIO.com/api/webhook-pago?gateway=binance`
3. Copia el merchant secret → `BINANCE_SECRET_KEY`

### 💡 Importante
- Necesitas un **dominio público con HTTPS** para que las pasarelas puedan llamar tu webhook (no funciona con `127.0.0.1`)
- Para pruebas locales puedes usar **ngrok**: `ngrok http 8000` y registrar la URL temporal de ngrok en el dashboard de la pasarela
- Si una clave está vacía en `.env`, la verificación de firma se **omite** (modo desarrollo) — útil para tests pero **no usar en producción**

---

# 👥 Gestión de Revendedores

Sistema multi-reseller: cada revendedor tiene su propia cuenta XUI con créditos. Cuando crean/renuevan líneas vía la API con `revendedor_id`, los créditos se descuentan **automáticamente** de su saldo. Solo el admin puede recargar.

## Configuración previa

En tu `.env`, además de las credenciales admin, agrega la URL del reseller API:

```env
XUI_RESELLER_API_URL=http://tripic.space:80/resselerapi/
```

Cada revendedor necesita su propia `api_key` (generada por ti como admin desde el panel XUI). Esta key le permite a este middleware autenticarse como él.

---

## 1️⃣3️⃣ `POST /api/crear-revendedor`

Registra un revendedor en la BD local junto con sus credenciales XUI.

### Body
```json
{
  "nombre": "Brenderos 94",
  "telefono": "+573001234567",
  "xui_user_id": 17,
  "xui_username": "brenderos94",
  "xui_api_key": "52035387EB0A9C3E4CD8D6133B219493"
}
```

### Campos
| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `nombre` | string | ✅ | Display name del revendedor |
| `telefono` | string | ❌ | Teléfono de contacto |
| `xui_user_id` | integer | ✅ | ID del user en XUI.ONE |
| `xui_username` | string | ✅ | Username en XUI |
| `xui_api_key` | string | ✅ | API key del revendedor (generada por admin) |

### Respuesta exitosa (201)
```json
{
  "success": true,
  "message": "Revendedor registrado correctamente.",
  "data": {
    "revendedor": {
      "id": 1,
      "nombre": "Brenderos 94",
      "telefono": "+573001234567",
      "xui_user_id": 17,
      "xui_username": "brenderos94",
      "creditos": 10,
      "active": true
    }
  }
}
```

El middleware **valida la api_key** consultando `user_info` antes de guardar — si la key es inválida, el registro falla.

---

## 1️⃣4️⃣ `POST /api/listar-revendedores`

Lista todos los revendedores registrados con su saldo cacheado.

### Body
```json
{ "solo_activos": true }
```

### Respuesta exitosa (200)
```json
{
  "success": true,
  "message": "Lista de revendedores.",
  "data": {
    "revendedores": [
      {
        "id": 1,
        "nombre": "Brenderos 94",
        "telefono": "+573001234567",
        "xui_user_id": 17,
        "xui_username": "brenderos94",
        "creditos_cache": 28,
        "active": true,
        "created_at": "2026-05-30 11:10:47",
        "updated_at": "2026-05-30 11:10:47"
      }
    ],
    "total": 1
  }
}
```

⚠️ `creditos_cache` puede estar desactualizado — usa `/api/saldo-revendedor` si necesitas el valor en tiempo real.

---

## 1️⃣5️⃣ `POST /api/saldo-revendedor`

Consulta el saldo **en vivo** desde XUI.ONE (no cacheado).

### Body
```json
{ "revendedor_id": 1 }
```

### Respuesta exitosa (200)
```json
{
  "success": true,
  "message": "Saldo consultado.",
  "data": {
    "revendedor_id": 1,
    "nombre": "Brenderos 94",
    "xui_username": "brenderos94",
    "creditos": 28
  }
}
```

Adicionalmente, refresca el `creditos_cache` en BD.

---

## 1️⃣6️⃣ `POST /api/recargar-creditos`

Solo admin. Agrega N créditos al saldo de un revendedor en XUI.

### Body
```json
{
  "revendedor_id": 1,
  "creditos": 50,
  "nota": "Pago recibido por USDT 2026-05-30"
}
```

### Respuesta exitosa (200)
```json
{
  "success": true,
  "message": "Créditos recargados correctamente.",
  "data": {
    "revendedor_id": 1,
    "creditos_antes": 8,
    "creditos_recargados": 20,
    "creditos_despues": 28
  }
}
```

Cada recarga queda registrada en la tabla `recargas` (audit trail) — consultable con `/api/historial-recargas`.

---

## 1️⃣7️⃣ `POST /api/historial-recargas`

Audit trail de todas las recargas (admin).

### Body
```json
{
  "revendedor_id": 1,
  "limite": 50
}
```

Si omites `revendedor_id`, devuelve recargas de **todos** los revendedores.

### Respuesta exitosa (200)
```json
{
  "success": true,
  "data": {
    "recargas": [
      {
        "id": 1,
        "revendedor_id": 1,
        "revendedor_nombre": "Brenderos 94",
        "xui_username": "brenderos94",
        "creditos_antes": 8,
        "creditos_recargados": 20,
        "creditos_despues": 28,
        "nota": "Pago recibido por USDT 2026-05-30",
        "created_at": "2026-05-30 11:14:56"
      }
    ],
    "total": 1
  }
}
```

---

## 1️⃣8️⃣ `POST /api/eliminar-revendedor`

Desactiva un revendedor (soft delete, `active=0`). Sus líneas existentes siguen funcionando; pero no podrá hacer nuevas operaciones.

### Body
```json
{ "revendedor_id": 1 }
```

---

## 🔁 Uso de `revendedor_id` en endpoints existentes

Cualquier endpoint de gestión de líneas acepta un parámetro opcional `revendedor_id`:

| Endpoint | Comportamiento con `revendedor_id` |
|---|---|
| `consultar-linea` | Solo encuentra líneas del revendedor |
| `crear-linea` | Crea con `member_id=<reseller>`, descuenta créditos automático |
| `renovar-linea` | Renueva + descuenta créditos manualmente según `package_id` |
| `suspender-linea` | Suspende usando auth del revendedor |
| `activar-linea` | Activa usando auth del revendedor |
| `cambiar-password` | Cambia password usando auth del revendedor |
| `cambiar-conexiones` | Modifica conexiones usando auth del revendedor |
| `eliminar-linea` | Borra usando auth del revendedor |

**Ejemplo: vender un mes a un cliente de Brenderos 94**
```bash
curl -X POST http://127.0.0.1:8000/api/crear-linea \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{
    "telefono":"+573001111111",
    "username":"juan_cliente_001",
    "password":"MiClavePass123",
    "package_id":3,
    "revendedor_id":1
  }'
```

La respuesta incluye `revendedor_id` en el body. El crédito del paquete se descuenta automáticamente del saldo de Brenderos 94.

**Ejemplo: renovar y ver saldo restante**
```bash
curl -X POST http://127.0.0.1:8000/api/renovar-linea \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{"username":"juan_cliente_001","package_id":3,"revendedor_id":1}'
```

Respuesta:
```json
{
  "success": true,
  "data": {
    "line_id": 44355,
    "dias_adicionados": 30,
    "nueva_expiracion": "2026-07-30 11:11:27",
    "package_id": 3,
    "revendedor_id": 1,
    "creditos_cobrados": 1,
    "creditos_restantes": 8
  }
}
```

---

# 🛠️ Acceso Directo al Panel XUI.ONE

## 1️⃣2️⃣ `POST /api/panel-consultar`

Proxy seguro de **solo lectura** hacia el panel XUI.ONE. Útil para listar paquetes, líneas, logs, etc.

### Body
```json
{
  "action": "get_packages"
}
```

### Acciones permitidas (whitelist)

**Listados:**
- `get_lines`, `get_users`, `get_mags`, `get_enigmas`
- `get_streams`, `get_channels`, `get_stations`, `get_movies`
- `get_series_list`, `get_episodes`

**Registros individuales:**
- `get_line`, `get_user`, `get_stream`, `get_channel`, etc.

**Catálogo:**
- `get_packages`, `get_bouquets`, `get_groups`, `get_categories`
- `get_access_codes`, `get_epgs`, `get_transcode_profiles`
- `get_subresellers`, `get_watch_folders`

**Servidores:**
- `get_servers`, `get_server`

**Logs y eventos:**
- `activity_logs`, `live_connections`, `credit_logs`
- `user_logs`, `stream_errors`, `system_logs`, `login_logs`

**Estadísticas:**
- `get_settings`, `get_server_stats`, `get_free_space`, `get_pids`

### Ejemplos

**Listar paquetes disponibles:**
```bash
curl -X POST http://127.0.0.1:8000/api/panel-consultar \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{"action":"get_packages"}'
```

**Obtener una línea específica:**
```bash
curl -X POST http://127.0.0.1:8000/api/panel-consultar \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{"action":"get_line","id":1683}'
```

**Ver conexiones activas:**
```bash
curl -X POST http://127.0.0.1:8000/api/panel-consultar \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{"action":"live_connections"}'
```

**Listar las primeras 100 líneas:**
```bash
curl -X POST http://127.0.0.1:8000/api/panel-consultar \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{"action":"get_lines","limit":100}'
```

### Respuesta exitosa (200)
```json
{
  "success": true,
  "message": "Consulta 'get_packages' ejecutada correctamente.",
  "data": {
    "action": "get_packages",
    "result": {
      "status": "STATUS_SUCCESS",
      "data": [
        { "id": 1, "name": "Premium HD", "...": "..." }
      ]
    }
  }
}
```

### Respuesta acción no permitida (400)
```json
{
  "success": false,
  "message": "Acción no permitida. Este endpoint solo acepta acciones de lectura.",
  "errors": {
    "permitidas": ["user_info", "get_lines", "...", "get_directory"]
  }
}
```

---

# 📊 Resumen rápido

| Endpoint | Método | Auth | Acepta username | Mutación |
|---|---|---|---|---|
| `/api/consultar-linea` | POST | ✅ | ✅ | ❌ (read) |
| `/api/listar-paquetes` | POST | ✅ | — | ❌ (read) |
| `/api/crear-linea` | POST | ✅ | (requerido) | ✅ |
| `/api/renovar-linea` | POST | ✅ | ✅ | ✅ + auto-activa · acepta package_id |
| `/api/eliminar-linea` | POST | ✅ | ✅ | ✅ destructivo |
| `/api/suspender-linea` | POST | ✅ | ✅ | ✅ |
| `/api/activar-linea` | POST | ✅ | ✅ | ✅ |
| `/api/cambiar-password` | POST | ✅ | ✅ | ✅ |
| `/api/cambiar-conexiones` | POST | ✅ | ✅ | ✅ |
| `/api/buscar-usuario` | POST | ✅ | (por teléfono) | ❌ · multi-cuenta |
| `/api/listar-mis-lineas` | POST | ✅ | (por teléfono) | ❌ · bot-friendly con mensaje formateado |
| `/api/crear-orden` | POST | ✅ | ❌ (line_id) | ✅ |
| `/api/consultar-pago` | POST | ✅ | ❌ (order_id) | ❌ |
| `/api/webhook-pago` | POST | ❌ pública | — | ✅ |
| `/api/panel-consultar` | POST | ✅ | — | ❌ whitelist |
| `/api/crear-revendedor` | POST | ✅ | — | ✅ |
| `/api/listar-revendedores` | POST | ✅ | — | ❌ (read) |
| `/api/saldo-revendedor` | POST | ✅ | — | ❌ (read live) |
| `/api/recargar-creditos` | POST | ✅ | — | ✅ admin only |
| `/api/historial-recargas` | POST | ✅ | — | ❌ (read) |
| `/api/eliminar-revendedor` | POST | ✅ | — | ✅ soft delete |

---

# 🤖 Flujos típicos del chatbot

## Flujo A: Cliente nuevo paga su primera línea

```
1. POST /api/crear-orden { line_id, dias, monto }
   → recibe order_id
2. Cliente paga en la pasarela (Wompi/MP/PayPal/Binance)
3. La pasarela llama /api/webhook-pago → renueva automáticamente
4. Cliente consulta su línea con POST /api/consultar-linea
```

## Flujo B: Cliente existente consulta su línea

```
Chatbot tiene {phone} del cliente:

1. POST /api/buscar-usuario { telefono: "{phone}" }
   → obtiene username y line_id

2. POST /api/consultar-linea { username: "{usuario}" }
   → devuelve estado actualizado de XUI
```

## Flujo C: Cliente solo conoce su username IPTV (caso real)

```
1. POST /api/consultar-linea {
     "username": "{usuario}",
     "telefono": "{phone}"   ← opcional pero recomendado
   }
   → resuelve automáticamente desde XUI y vincula al teléfono
   → devuelve línea con line_id, exp_date, etc.
```

## Flujo D: Renovación manual desde chatbot

```
1. POST /api/renovar-linea {
     "username": "{usuario}",
     "dias": 30
   }
   → extiende vencimiento y reactiva si estaba suspendida
```

## Flujo E: Venta por paquetes (menú dinámico)

```
1. Cliente toca "Ver Planes" en el chatbot
2. Bot llama POST /api/listar-paquetes {}
3. Bot itera la lista y arma botones con nombre + precio (precio lo defines tú en €)

   Ejemplo de menú generado:
   • 1 MES FULL  →  €12  (package_id 3)
   • 3 MESES FULL → €30  (package_id 5)
   • 12 MESES FULL → €90  (package_id 9)

4. Cliente elige "3 MESES FULL"
5. Bot llama POST /api/crear-orden {
     "line_id": <ID_cliente>,
     "dias": 90,
     "monto": 30.00
   }
   → genera order_id
6. Cliente paga con Wompi/PayPal
7. Webhook llega → renovación automática con package_id 5

Alternativa para cliente NUEVO (no tiene línea aún):
4b. Bot pide username y password deseados
5b. Bot llama POST /api/crear-linea {
      "telefono": "{phone}",
      "username": "{nuevo_user}",
      "password": "{nuevo_pass}",
      "package_id": 5
    }
```

## Flujo F: Upgrade de paquete (cliente cambia plan)

```
Cliente con paquete mensual quiere pasarse al anual:

1. POST /api/renovar-linea {
     "username": "{usuario}",
     "package_id": 9
   }
   → suma los días del nuevo paquete (365)
   → actualiza la asignación de paquete en XUI
   → reactiva si estaba suspendida
```

## Flujo H: Cliente con múltiples cuentas elige una

```
Cliente con teléfono +57300... tiene 3 cuentas IPTV registradas:

1. POST /api/listar-mis-lineas {
     "telefono": "{phone}",
     "revendedor_id": 17
   }
   → respuesta: { total: 3, indices: {1:..., 2:..., 3:...}, message: "📺 Tus cuentas..." }

2. Bot envía data.message al cliente:
   "📺 *Tus cuentas IPTV:*
    1️⃣ *papa_juan*  - Vence 2026-06-30 - Activa 🟢
    2️⃣ *hijo_juan*  - Vence 2026-07-15 - Activa 🟢
    3️⃣ *esposa*     - Vence 2026-05-20 - Suspendida 🔴

    Responde con el número (1 a 3) para gestionar esa cuenta."

3. Cliente escribe "2"

4. Bot captura input y setea:
   usuario_iptv = {data.indices.2.username}  →  "hijo_juan"

5. Continúa con el flujo normal de renovar/consultar/etc usando {usuario_iptv}
```

## Flujo G: Venta operada por un revendedor (multi-tenant)

```
Setup inicial (una vez):
1. Admin registra al reseller:
   POST /api/crear-revendedor {
     "nombre": "Brenderos 94",
     "xui_user_id": 17,
     "xui_username": "brenderos94",
     "xui_api_key": "52035387..."
   }

2. Cliente pide al reseller un mes:
   Bot del reseller llama:
   POST /api/crear-linea {
     "telefono":"+57...",
     "username":"juan",
     "password":"...",
     "package_id":3,
     "revendedor_id":1   ← clave: identifica al reseller
   }
   → línea creada con member_id=17 (atribuida al reseller)
   → XUI descuenta 1 crédito automáticamente del saldo de brenderos94

3. Al renovar:
   POST /api/renovar-linea {
     "username":"juan",
     "package_id":3,
     "revendedor_id":1
   }
   → línea extendida +30 días
   → middleware descuenta 1 crédito manualmente del reseller
   → respuesta incluye creditos_restantes

4. Cuando se queda sin créditos:
   - Reseller te paga (banco/USDT/cripto)
   - Admin recarga:
     POST /api/recargar-creditos {
       "revendedor_id":1,
       "creditos":50,
       "nota":"USDT recibido 2026-06-01"
     }
   - Audit trail queda en tabla `recargas`
```

---

# 🔧 Códigos de error comunes

| Status | Causa | Solución |
|---|---|---|
| `400` | Body inválido o falta `line_id`/`username` | Revisar campos requeridos |
| `401` | `X-API-Key` ausente o incorrecto | Verificar header vs `CHATBOT_API_KEY` |
| `404` | Username/teléfono no existe | Verificar datos del cliente |
| `422` | Validación falló (tipos incorrectos) | Revisar `errors` en la respuesta |
| `500` | Error en XUI.ONE | Ver logs en `storage/logs/app.log` |

---

# 📝 Notas técnicas

## Resolución de `username → line_id`

Los endpoints aceptan `username` gracias a un helper interno (`resolveLineId`) que:

1. **Primero busca en BD local** (tabla `clientes`) — instantáneo
2. **Si no encuentra**, escanea XUI.ONE completo via `get_lines&limit=50000`
3. **Cachea el mapeo** para próximas consultas
4. **Opcionalmente vincula el teléfono** si lo provees en el body

## Preservación de campos en `edit_line`

XUI.ONE resetea campos a default cuando se omiten en `edit_line`. El middleware **preserva automáticamente**:
- `username`
- `password`
- `exp_date`
- `max_connections`

Por eso `cambiar-password` no borra la fecha, y `renovar-linea` no regenera el password.

## Sentinel "Nunca (Ilimitada)"

XUI usa el timestamp `2147483647` (int32 max = 2038-01-19) para líneas sin vencimiento. El middleware lo muestra como `"Nunca (Ilimitada)"` en `consultar-linea`.

---

# 🚀 Quick-start

```bash
# 1. Levantar la BD
docker compose up -d db

# 2. Levantar el servidor PHP
php -S 127.0.0.1:8000 -t public/

# 3. Probar
curl -X POST http://127.0.0.1:8000/api/consultar-linea \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{"username":"EDWINERAZOTF"}'
```

---

# ✅ Estado de pruebas (validado contra panel real)

Todos los endpoints fueron probados contra el panel real `tripic.space/miapixui/` con la línea `EDWINERAZOTF` (line_id 1683):

| # | Endpoint | Happy path | Errores | Notas |
|---|---|---|---|---|
| 1 | `consultar-linea` | ✅ 200 | ✅ 404 (no existe) | Resuelve username → line_id, cachea |
| 2 | `crear-linea` | ✅ 201 | ✅ 422 (faltan campos) · ✅ 400 (duplicado) | Crea en XUI + vincula BD |
| 3 | `renovar-linea` | ✅ 200 | ✅ 422 (sin días) | Extiende + reactiva si suspendida |
| 4 | `suspender-linea` | ✅ 200 | ✅ 400 (sin user/id) | `enabled=0` en XUI |
| 5 | `activar-linea` | ✅ 200 | ✅ 400 (sin user/id) | `enabled=1` en XUI |
| 6 | `cambiar-password` | ✅ 200 | ✅ 422 (sin password) | Preserva exp_date y conexiones |
| 7 | `cambiar-conexiones` | ✅ 200 | ✅ 422 (sin conexiones) | Preserva exp_date y password |
| 8 | `buscar-usuario` | ✅ 200 | ✅ 404 · ✅ 422 | Lookup por teléfono |
| 9 | `crear-orden` | ✅ 201 | ✅ 422 · ✅ 500 (line inexistente) | Genera order_id único |
| 10 | `consultar-pago` | ✅ 200 | ✅ 404 · ✅ 422 | Estado de orden |
| 11 | `webhook-pago` | ⚠️ Requiere firma real | ✅ 400 (sin gateway · firma inválida · gateway desconocido) | Configurar en dashboard |
| 12 | `panel-consultar` | ✅ 200 (varias acciones) | ✅ 400 (acción no permitida) · ✅ 422 | 50+ acciones de lectura |
| 13 | `crear-revendedor` | ✅ 201 | ✅ 500 (api_key inválida) · ✅ 422 (campos) | Valida api_key contra XUI |
| 14 | `listar-revendedores` | ✅ 200 | — | Lista con saldo cacheado |
| 15 | `saldo-revendedor` | ✅ 200 | ✅ 404 (id no existe) · ✅ 422 | Live de XUI + refresh cache |
| 16 | `recargar-creditos` | ✅ 200 (+20 → saldo aumentó) | ✅ 422 | Aplica en XUI + audit trail |
| 17 | `historial-recargas` | ✅ 200 | — | Filtrable por reseller |
| 18 | `eliminar-revendedor` | ✅ 200 | ✅ 404 | Soft delete |
| — | **Líneas vía reseller** | ✅ Crear deduce 1 crédito · Renovar deduce 1 crédito · member_id=17 | — | Tested E2E con brenderos94 |
| — | **Auth** | — | ✅ 401 (sin/mal X-API-Key) · ✅ 404 (endpoint inexistente) | |

## 🐛 Bugs encontrados y corregidos durante pruebas

| Bug | Síntoma | Fix |
|---|---|---|
| `edit_line` regeneraba username/password aleatorios | Líneas con username random `EtYgdpNhVx`, `5uGCAThHV6` | `XuiService::editLine` ahora preserva username/password automáticamente |
| `STATUS_INVALID_DATE` se reportaba como éxito | `renovar-linea` decía OK pero fecha real no cambiaba | `XuiService::request` ahora trata cualquier status != `STATUS_SUCCESS` como error |
| Unix timestamps rechazados por XUI | `STATUS_INVALID_DATE` con cualquier renovación | `renovar` ahora envía `exp_date` como string `Y-m-d H:i:s` |
| `cambiar-password` borraba `exp_date` | Renovación perdida tras cambiar clave | `editLine` ahora preserva también `exp_date` y `max_connections` |
| `get_lines` paginado a 50 por defecto | Líneas con id > 50 no encontradas | `findLineByUsername` ahora envía `limit=50000` |
| Falsos positivos: `user_info` devolvía admin | Cualquier consulta devolvía datos del admin | Validación estricta: el username devuelto debe coincidir con el solicitado |
| Reseller API usa `package=` no `package_id=` | `create_line` falla con STATUS_INVALID_PACKAGE | `createLine` ahora envía **ambos** parámetros, cada API ignora el que no reconoce |
| Reseller API NO expone `get_package` | `daysFromPackage` fallaba al renovar como reseller | Nuevo helper `requestAsAdmin()` para lookups globales |
| `edit_line` no descuenta créditos en renovaciones | Resellers renovaban gratis | Manual: `chargeResellerCredits()` aplica el costo del paquete tras editar |
| `edit_user` regenera username/email si no se pasan | Igual que el bug de `edit_line` con usernames | `ResellerController::recargar` y `chargeResellerCredits` envían siempre username + email |
