# Manual de la API — chatbot-iptv-api

**Base URL:** `https://solucionesdigitales.icu/api`  
**Autenticación:** header `X-API-Key: <CHATBOT_API_KEY>` en todos los endpoints excepto `/webhook-pago`  
**Método:** `POST` para todos los endpoints de acción, `GET` para consultas simples

---

## Tabla de contenidos

1. [Variables de entorno (.env)](#1-variables-de-entorno)
2. [Base de datos — tablas clave](#2-base-de-datos)
3. [Endpoints — Usuarios](#3-endpoints-usuarios)
4. [Endpoints — Líneas IPTV](#4-endpoints-líneas-iptv)
5. [Endpoints — Pagos y PayPal](#5-endpoints-pagos)
6. [Endpoints — Revendedores](#6-endpoints-revendedores)
7. [Webhooks de pago](#7-webhooks)
8. [Divisas múltiples](#8-divisas-múltiples)
9. [Sistema de créditos de revendedor](#9-créditos-revendedor)
10. [Cómo registrar un nuevo revendedor](#10-registrar-revendedor)
11. [Flujo completo de pago PayPal](#11-flujo-paypal)
12. [Integración con el chatbot (nodos API Request)](#12-integración-chatbot)

---

## 1. Variables de entorno

Archivo: `/var/www/chatbot-iptv-api/.env`

| Variable | Descripción | Ejemplo |
|---|---|---|
| `APP_ENV` | Entorno (`local` o `production`) | `production` |
| `APP_DEBUG` | Mostrar errores detallados (`true`/`false`) | `false` |
| `CHATBOT_API_KEY` | Clave secreta que el chatbot incluye en cada petición | `mi_clave_segura_123` |
| `DB_HOST` | Host de MySQL | `127.0.0.1` |
| `DB_PORT` | Puerto de MySQL | `3306` |
| `DB_NAME` | Nombre de la base de datos | `iptv_manager` |
| `DB_USER` | Usuario de MySQL | `iptv_user` |
| `DB_PASS` | Contraseña de MySQL | `password` |
| `XUI_API_URL` | URL base del panel XUI.ONE | `http://tripic.space/miapixui/` |
| `XUI_USERNAME` | Usuario admin de XUI | `admin` |
| `XUI_PASSWORD` | Contraseña admin de XUI | `password` |
| `XUI_DEFAULT_PACKAGE_ID` | Paquete que se asigna si no se especifica | `1` |
| `XUI_DEFAULT_MAX_CONNECTIONS` | Conexiones por defecto al crear línea | `1` |
| `PAYPAL_CLIENT_ID` | Client ID de la app PayPal | `AaBbCc...` |
| `PAYPAL_CLIENT_SECRET` | Secret de la app PayPal | `EeFfGg...` |
| `PAYPAL_WEBHOOK_ID` | ID del webhook en el dashboard PayPal | `5ML552...` |
| `PAYPAL_MODE` | `sandbox` (pruebas) o `live` (producción) | `live` |
| `PAYPAL_CURRENCY` | Moneda por defecto para cobros | `EUR` |
| `PAYPAL_PRICE_PER_CREDIT` | Precio en la moneda configurada por cada crédito XUI | `10.00` |
| `PAYPAL_RETURN_URL` | URL de redirección después del pago exitoso | `https://solucionesdigitales.icu/pago-exitoso` |
| `PAYPAL_CANCEL_URL` | URL si el cliente cancela el pago | `https://solucionesdigitales.icu/pago-cancelado` |

> **Nota:** Para cambiar la moneda de cobro sin tocar código, modifica solo `PAYPAL_CURRENCY=USD` (o cualquier código ISO 4217 soportado por PayPal: EUR, USD, MXN, COP, GBP, etc.)

---

## 2. Base de datos

### Tabla `clientes`
Guarda la relación entre número de teléfono WhatsApp y línea IPTV.

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | INT PK | ID interno |
| `telefono` | VARCHAR(20) | Número WhatsApp — **NO es único**, un teléfono puede tener varias cuentas |
| `username` | VARCHAR(50) UNIQUE | Usuario IPTV en XUI.ONE |
| `line_id` | INT UNIQUE | ID de la línea en XUI.ONE |
| `revendedor_id` | INT NULL FK | FK a `revendedores.id` (puede ser NULL si es cuenta admin) |
| `estado` | VARCHAR(20) | `active`, `suspended`, `expired` |
| `fecha_vencimiento` | DATETIME | Fecha de vencimiento sincronizada desde XUI |

### Tabla `ordenes`
Órdenes de pago generadas. Cada renovación vía PayPal crea una orden aquí.

| Campo | Tipo | Descripción |
|---|---|---|
| `order_id` | VARCHAR(64) UNIQUE | ID único tipo `ORD-AABBCCDD...` |
| `paypal_order_id` | VARCHAR(32) | ID de la orden en PayPal |
| `line_id` | INT | Línea a renovar |
| `dias` | INT | Días a añadir |
| `monto` | DECIMAL | Monto cobrado |
| `estado` | VARCHAR(20) | `pending`, `completed`, `failed` |
| `revendedor_id` | INT NULL | XUI user_id del revendedor (para deducir créditos al cobrar) |
| `package_id` | INT NULL | ID del paquete XUI (para calcular el costo en créditos) |

### Tabla `revendedores`
Revendedores registrados con sus credenciales XUI.

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | INT PK | ID interno local |
| `nombre` | VARCHAR(100) | Nombre del revendedor |
| `xui_user_id` | INT UNIQUE | **ID del revendedor en el panel XUI** (el que se envía en peticiones) |
| `xui_username` | VARCHAR(100) | Nombre de usuario en XUI |
| `xui_api_key` | VARCHAR(255) | API Key del revendedor (visible en XUI → perfil) |
| `creditos_cache` | INT | Caché local del saldo (se actualiza al operar) |
| `active` | TINYINT | `1` = activo, `0` = desactivado |

### Tabla `recargas`
Historial de recargas de créditos que el admin hace a los revendedores.

### Tabla `pagos`
Registro de pagos aprobados (una fila por pago completado).

---

## 3. Endpoints — Usuarios

### `POST /api/buscar-usuario`
Busca todas las cuentas IPTV vinculadas a un número de teléfono.

**Request:**
```json
{
  "telefono": "+573001234567"
}
```

**Response exitosa:**
```json
{
  "success": true,
  "message": "Cuentas encontradas.",
  "data": {
    "total": 2,
    "clientes": [
      {
        "id": 1,
        "telefono": "+573001234567",
        "username": "juan_iptv",
        "line_id": 44352,
        "revendedor_id": 17,
        "estado": "active",
        "fecha_vencimiento": "2026-09-01 01:12:04"
      },
      {
        "id": 2,
        "username": "juan_familiar",
        "line_id": 44400,
        "revendedor_id": 17,
        "estado": "active",
        "fecha_vencimiento": "2027-01-15 10:00:00"
      }
    ],
    "cliente": null
  }
}
```

> Si solo hay 1 cuenta, también se devuelve `"cliente": {...}` para compatibilidad con flujos que esperan un solo objeto.

---

### `POST /api/listar-mis-lineas`
Lista las cuentas de un teléfono con formato listo para mostrar en WhatsApp y con índices numerados.

**Request:**
```json
{
  "telefono": "+573001234567",
  "revendedor_id": 17
}
```

> `revendedor_id` es opcional. Si se incluye, filtra solo las cuentas de ese revendedor.

**Response exitosa:**
```json
{
  "success": true,
  "data": {
    "total": 2,
    "message": "📺 *Tus cuentas IPTV:*\n\n1️⃣ *juan_iptv*\n   📅 Vence: 2026-09-01\n   ⚡ Activa 🟢\n\n2️⃣ *juan_familiar*\n   📅 Vence: 2027-01-15\n   ⚡ Activa 🟢",
    "clientes": [...],
    "indices": {
      "1": { "username": "juan_iptv", "line_id": 44352, ... },
      "2": { "username": "juan_familiar", "line_id": 44400, ... }
    }
  }
}
```

---

### `POST /api/seleccionar-cuenta`
Devuelve la cuenta en la posición N de la lista del teléfono (usado cuando el usuario escribe un número).

**Request:**
```json
{
  "telefono": "+573001234567",
  "indice": 2
}
```

**Response exitosa:**
```json
{
  "success": true,
  "data": {
    "cliente": {
      "indice": 2,
      "username": "juan_familiar",
      "line_id": 44400,
      "revendedor_id": 17,
      "estado": "active",
      "fecha_vencimiento": "2027-01-15 10:00:00"
    }
  }
}
```

---

## 4. Endpoints — Líneas IPTV

Todos los endpoints de línea aceptan la línea identificada por `line_id` **o** por `username`.

### `POST /api/consultar-linea`
Consulta el estado actual **directamente desde XUI.ONE** (siempre datos frescos) y sincroniza la caché local.

**Request:**
```json
{
  "line_id": 44352,
  "revendedor_id": 17
}
```

**Response exitosa:**
```json
{
  "success": true,
  "line_id": 44352,
  "username": "juan_iptv",
  "exp_date": "2026-09-01 01:12:04",
  "max_connections": 2,
  "enabled": true,
  "message": "👤 Usuario: juan_iptv\n📅 Vencimiento: 2026-09-01 01:12:04\n🔌 Conexiones: 2\n⚡ Estado: Activo 🟢"
}
```

---

### `POST /api/crear-linea`
Crea una nueva línea en XUI.ONE y la registra en la base de datos local.

**Request:**
```json
{
  "telefono": "+573001234567",
  "username": "nuevo_usuario",
  "password": "pass123",
  "package_id": 9,
  "max_connections": 2,
  "revendedor_id": 17
}
```

> `package_id`, `max_connections` y `revendedor_id` son opcionales.

**Response exitosa (HTTP 201):**
```json
{
  "success": true,
  "message": "Línea IPTV creada y vinculada correctamente.",
  "data": {
    "telefono": "+573001234567",
    "username": "nuevo_usuario",
    "line_id": 44500,
    "revendedor_id": 17,
    "fecha_vencimiento": "2026-07-01 00:00:00",
    "estado": "active"
  }
}
```

---

### `POST /api/vincular-cuenta`
Vincula un usuario IPTV existente en XUI al número de teléfono. Si ya existía, actualiza el teléfono y devuelve los datos.

**Request:**
```json
{
  "telefono": "+573001234567",
  "username": "usuario_existente_en_xui",
  "revendedor_id": 17
}
```

**Response exitosa:**
```json
{
  "success": true,
  "vinculado": true,
  "ya_existia": false,
  "username": "usuario_existente_en_xui",
  "line_id": 44352,
  "estado": "active",
  "fecha_vencimiento": "2026-09-01 01:12:04",
  "message": "✅ Cuenta vinculada exitosamente.\n\n📺 *usuario_existente_en_xui*\n📅 Vence: 2026-09-01\n⚡ Activo 🟢"
}
```

---

### `POST /api/renovar-linea`
Renueva la línea directamente (sin pasar por pago). Deduce créditos al revendedor si aplica.

**Request:**
```json
{
  "line_id": 44352,
  "package_id": 9,
  "revendedor_id": 17
}
```

> Puedes usar `"dias": 90` en lugar de `package_id` si prefieres especificar días manualmente.

**Response exitosa:**
```json
{
  "success": true,
  "data": {
    "line_id": 44352,
    "dias_adicionados": 90,
    "nueva_expiracion": "2026-12-01 01:12:04",
    "estado": "active",
    "package_id": 9,
    "revendedor_id": 17,
    "creditos_cobrados": 12,
    "creditos_restantes": 80
  }
}
```

---

### `POST /api/suspender-linea`

**Request:**
```json
{ "line_id": 44352, "revendedor_id": 17 }
```

---

### `POST /api/activar-linea`

**Request:**
```json
{ "line_id": 44352, "revendedor_id": 17 }
```

---

### `POST /api/cambiar-password`

**Request:**
```json
{ "line_id": 44352, "password": "nuevaPass456", "revendedor_id": 17 }
```

---

### `POST /api/cambiar-conexiones`

**Request:**
```json
{ "line_id": 44352, "conexiones": 3, "revendedor_id": 17 }
```

---

### `POST /api/eliminar-linea`

**Request:**
```json
{ "line_id": 44352, "revendedor_id": 17 }
```

---

## 5. Endpoints — Pagos

### `POST /api/crear-pago-paypal`
Crea una orden local + orden en PayPal y devuelve la URL de pago para enviarle al cliente.

**Request mínimo (con `package_id`):**
```json
{
  "line_id": 44352,
  "package_id": 9,
  "revendedor_id": 17,
  "currency": "EUR"
}
```

> `currency` es opcional — si no se envía, usa el valor de `PAYPAL_CURRENCY` del `.env` (por defecto `EUR`).

**Request manual (con `dias` y `monto` explícitos):**
```json
{
  "line_id": 44352,
  "dias": 90,
  "monto": 15.00,
  "revendedor_id": 17,
  "currency": "USD"
}
```

**Response exitosa (HTTP 201):**
```json
{
  "success": true,
  "data": {
    "order_id": "ORD-A1B2C3D4E5F6G7H8",
    "paypal_order_id": "5O190127TN364715T",
    "approve_url": "https://www.paypal.com/checkoutnow?token=5O190127TN364715T",
    "monto": 15.00,
    "dias": 90
  }
}
```

El chatbot debe enviar `approve_url` al cliente para que pague.

---

### `POST /api/consultar-pago` (también `GET`)
Consulta el estado de una orden. Si está `pending` con PayPal, intenta capturar el pago automáticamente.

**Request:**
```json
{ "order_id": "ORD-A1B2C3D4E5F6G7H8" }
```

**Response:**
```json
{
  "success": true,
  "data": {
    "orden": {
      "order_id": "ORD-A1B2C3D4E5F6G7H8",
      "line_id": 44352,
      "dias": 90,
      "monto": 15.00,
      "estado": "completed",
      "fecha_vencimiento": "2026-12-01 01:12:04"
    }
  }
}
```

Estados posibles: `pending`, `completed`, `failed`.

---

## 6. Endpoints — Revendedores

### `POST /api/crear-revendedor`
Registra un revendedor en la base de datos local. La `xui_api_key` se valida conectando a XUI antes de guardar.

**Request:**
```json
{
  "nombre": "Juan Pérez",
  "telefono": "+573001234567",
  "xui_user_id": 17,
  "xui_username": "brenderos94",
  "xui_api_key": "52035387EB0A9C3E4CD8D6133B219493"
}
```

> Para obtener el `xui_user_id` y `xui_api_key`: entra al panel XUI.ONE como admin → Usuarios → editar el revendedor → el ID aparece en la URL y la API Key en el perfil.

**Response exitosa (HTTP 201):**
```json
{
  "success": true,
  "data": {
    "revendedor": {
      "revendedor_id": 17,
      "xui_user_id": 17,
      "local_id": 1,
      "nombre": "Juan Pérez",
      "xui_username": "brenderos94",
      "creditos": 92,
      "active": true
    }
  }
}
```

> **Importante:** El campo `revendedor_id` que el chatbot debe guardar y enviar en futuras peticiones es el `xui_user_id` (en el ejemplo: `17`).

---

### `POST /api/listar-revendedores`

**Request:**
```json
{ "solo_activos": true }
```

---

### `POST /api/saldo-revendedor`
Consulta el saldo en créditos **en tiempo real desde XUI**.

**Request:**
```json
{ "revendedor_id": 17 }
```

**Response:**
```json
{
  "success": true,
  "data": {
    "revendedor_id": 17,
    "xui_username": "brenderos94",
    "creditos": 92
  }
}
```

---

### `POST /api/recargar-creditos`
Añade créditos al balance de un revendedor (operación de admin). Queda registrado en el historial.

**Request:**
```json
{
  "revendedor_id": 17,
  "creditos": 50,
  "nota": "Pago recibido por transferencia 2026-06-01"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "creditos_antes": 92,
    "creditos_recargados": 50,
    "creditos_despues": 142
  }
}
```

---

### `POST /api/historial-recargas`

**Request:**
```json
{
  "revendedor_id": 17,
  "limite": 20
}
```

> `revendedor_id` es opcional. Si se omite, devuelve el historial de todos los revendedores.

---

### `POST /api/eliminar-revendedor`
Desactiva el revendedor (soft-delete). Sus líneas existentes en XUI se mantienen.

**Request:**
```json
{ "revendedor_id": 17 }
```

---

## 7. Webhooks

### `POST /api/webhook-pago?gateway=paypal`
Recibe la confirmación de pago de la pasarela y ejecuta la renovación automáticamente.

**URL de webhook a configurar en PayPal Developer Dashboard:**
```
https://solucionesdigitales.icu/api/webhook-pago?gateway=paypal
```

No requiere `X-API-Key`. PayPal envía sus propios headers de firma que la API verifica automáticamente.

**Gateways soportados:**

| Gateway | Parámetro URL |
|---|---|
| PayPal | `?gateway=paypal` |
| Wompi | `?gateway=wompi` |
| MercadoPago | `?gateway=mercadopago` |
| Binance Pay | `?gateway=binance` |

---

## 8. Divisas múltiples

El sistema soporta cualquier moneda que PayPal acepte. La moneda se determina en este orden de prioridad:

1. **Campo `currency` en el body** del request → máxima prioridad, sobrescribe todo
2. **Variable `PAYPAL_CURRENCY` en `.env`** → default para todo el servidor
3. **Default hardcoded** = `EUR`

### Configurar moneda por servidor (`.env`)
```
PAYPAL_CURRENCY=EUR   # Para España/Europa
```
```
PAYPAL_CURRENCY=USD   # Para USA/Latinoamérica (USDT equivalente)
```

### Configurar moneda por flujo de chatbot
En el nodo API Request que llama a `/api/crear-pago-paypal`, incluir en el body:
```json
{
  "line_id": "{line_id}",
  "package_id": {package_id},
  "revendedor_id": {revendedor_id},
  "currency": "EUR"
}
```

Esto permite tener **flujos diferentes para distintos mercados** sin cambiar el servidor:
- Flujo Revendedor Europa → `"currency": "EUR"`
- Flujo Revendedor México → `"currency": "MXN"`
- Flujo Revendedor USA → `"currency": "USD"`

### Precio por crédito (`PAYPAL_PRICE_PER_CREDIT`)
Determina cuánto cobra el sistema por cada crédito XUI del paquete. Ejemplo:
- Paquete tiene `official_credits = 12`
- `PAYPAL_PRICE_PER_CREDIT = 1.50`
- Precio calculado automáticamente = `12 × 1.50 = 18.00 EUR`

---

## 9. Créditos revendedor

### Cómo funcionan
- Cada paquete XUI tiene un campo `official_credits` (costo en créditos)
- Cuando un cliente paga por renovar vía PayPal, **después de que el webhook confirma el pago**, la API:
  1. Actualiza la fecha de vencimiento en XUI
  2. Activa la línea
  3. Llama a `edit_user` vía admin API para restar los créditos del revendedor

### Dónde configurar el costo de un paquete
En el panel XUI.ONE → Paquetes → editar paquete → campo **"Official Credits"**. Ese valor es el que se deduce.

### Renovación directa (sin PayPal)
El endpoint `/api/renovar-linea` también deduce créditos si se pasa `revendedor_id` + `package_id`. Lo hace antes de ejecutar la renovación para garantizar que haya saldo suficiente (devuelve error 402 si no alcanza).

---

## 10. Registrar un nuevo revendedor

Pasos completos:

1. **En el panel XUI.ONE:** crear el usuario con grupo "Revendedor", asignarle créditos iniciales, obtener su `user_id` (visible en la URL al editarlo) y su `api_key` (en su perfil).

2. **Llamar al endpoint:**
```bash
curl -X POST https://solucionesdigitales.icu/api/crear-revendedor \
  -H "Content-Type: application/json" \
  -H "X-API-Key: TU_CHATBOT_API_KEY" \
  -d '{
    "nombre": "Nombre Revendedor",
    "telefono": "+573001234567",
    "xui_user_id": 17,
    "xui_username": "username_en_xui",
    "xui_api_key": "APIKEY_DEL_REVENDEDOR"
  }'
```

3. **El `revendedor_id` que devuelve** (igual al `xui_user_id`) es el que se debe configurar en el flujo del chatbot como variable de sesión `revendedor_id`.

---

## 11. Flujo completo de pago PayPal

```
Cliente escribe "Renovar"
        │
        ▼
[API: /api/crear-pago-paypal]
  body: { line_id, package_id, revendedor_id, currency }
  responde: { approve_url, order_id, monto, dias }
        │
        ▼
Chatbot envía approve_url al cliente por WhatsApp
        │
        ▼
Cliente hace clic → paga en PayPal
        │
        ▼
PayPal envía webhook a:
  POST /api/webhook-pago?gateway=paypal
        │
        ▼
API verifica firma PayPal
        │
        ▼
API ejecuta resolveRenewal():
  1. Bloquea la orden (FOR UPDATE, evita doble-renovación)
  2. Calcula nueva fecha de vencimiento
  3. Llama edit_line en XUI (con auth del revendedor)
  4. Activa la línea (enable_line)
  5. Deduce créditos del revendedor (edit_user via admin)
  6. Guarda pago en tabla `pagos`
  7. Actualiza orden a `completed`
  8. Actualiza `fecha_vencimiento` en tabla `clientes`
        │
        ▼
Bot detecta: "confirmar" → /api/consultar-pago
  responde: { estado: "completed", fecha_vencimiento: "..." }
        │
        ▼
Chatbot muestra mensaje de confirmación al cliente
```

---

## 12. Integración con el chatbot (nodos API Request)

### Variables de sesión clave

Estas variables se almacenan en la sesión del bot y se pasan entre nodos:

| Variable | Origen | Uso |
|---|---|---|
| `{phone}` | WhatsApp (automático) | Teléfono del cliente |
| `{revendedor_id}` | Config del flujo | ID del revendedor en XUI (ej: `17`) |
| `{line_id}` | Respuesta de `buscar-usuario` | ID de la línea seleccionada |
| `{username}` | Respuesta de `buscar-usuario` | Usuario IPTV |
| `{order_id}` | Respuesta de `crear-pago-paypal` | ID de la orden para consultar después |
| `{approve_url}` | Respuesta de `crear-pago-paypal` | URL de pago a enviar al cliente |

### Ejemplo: nodo "Consultar mi cuenta"
```
URL: https://solucionesdigitales.icu/api/consultar-linea
Method: POST
Headers: X-API-Key: TU_KEY
Body:
{
  "line_id": {line_id},
  "revendedor_id": {revendedor_id}
}
Guardar respuesta en: res_consultar
```
Luego en un nodo Mensaje: `{res_consultar.message}`

### Ejemplo: nodo "Iniciar pago PayPal"
```
URL: https://solucionesdigitales.icu/api/crear-pago-paypal
Method: POST
Headers: X-API-Key: TU_KEY
Body:
{
  "line_id": {line_id},
  "package_id": 9,
  "revendedor_id": {revendedor_id},
  "currency": "EUR"
}
Guardar respuesta en: res_pago
```
Luego en nodo Mensaje: `Haz clic para pagar: {res_pago.approve_url}`

### Ejemplo: nodo "Verificar pago"
```
URL: https://solucionesdigitales.icu/api/consultar-pago
Method: POST
Headers: X-API-Key: TU_KEY
Body:
{
  "order_id": "{order_id}"
}
Guardar respuesta en: res_verificar
```
Condición de éxito: `{res_verificar.orden.estado}` === `completed`

---

## Páginas de redirección PayPal (GET)

| URL | Descripción |
|---|---|
| `https://solucionesdigitales.icu/pago-exitoso` | Página que ve el cliente después de pagar |
| `https://solucionesdigitales.icu/pago-cancelado` | Página si el cliente cancela |

Estas páginas le indican al cliente que regrese al WhatsApp y escriba **"confirmar"** para que el bot verifique el pago.

---

*Manual generado para chatbot-iptv-api — Última actualización: 2026-06-01*
