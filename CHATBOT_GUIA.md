# 🤖 Guía Completa del Chatbot IPTV — Casos de Uso y Configuración

**Versión 1.0** · IPTV XUI.ONE Middleware · Mayo 2026

---

## 📑 Índice

1. [¿Qué es este chatbot?](#qué-es)
2. [Casos de uso típicos](#casos-de-uso)
3. [Arquitectura y componentes](#arquitectura)
4. [Configuración previa (antes de empezar)](#configuración-previa)
5. [Variables globales del bot](#variables-globales)
6. [Diagrama maestro del flujo](#diagrama-maestro)
7. [Configuración nodo por nodo](#configuración-nodos)
8. [Sub-flujos detallados](#sub-flujos)
9. [Tabla de precios y paquetes](#precios)
10. [Manejo de pagos](#pagos)
11. [Multi-reseller (varios revendedores)](#multi-reseller)
12. [Errores comunes y soluciones](#errores)
13. [Checklist de despliegue](#checklist)

---

# 1. ¿Qué es este chatbot? <a name="qué-es"></a>

Es un sistema **conversacional automatizado** para WhatsApp/Telegram que permite a los clientes de un revendedor IPTV gestionar su servicio sin intervención humana en el 80% de los casos:

- Consultar el estado de su línea (fecha de vencimiento, conexiones)
- Renovar su plan eligiendo entre paquetes preconfigurados
- Comprar una cuenta nueva si no tienen
- Cambiar contraseña
- Solicitar ayuda con un agente humano

El bot se conecta vía API REST al middleware que ya tienes (`API_ENDPOINTS.md`) y el middleware a su vez se comunica con el panel XUI.ONE.

**Cadena completa:**
```
Cliente (WhatsApp) → Bot → Middleware (API REST) → Panel XUI.ONE
```

---

# 2. Casos de uso típicos <a name="casos-de-uso"></a>

## 🟢 Caso A: Cliente existente consulta su cuenta

```
Cliente: "hola"
Bot:     "¡Hola! 👋 Dame un momento que reviso tu cuenta..."
Bot:     [Identifica al cliente por su número de teléfono]
Bot:     "¿Qué deseas hacer hoy?
          1) 📋 Consultar mi cuenta
          2) 🔄 Renovar mi plan
          ...
          "
Cliente: "1"
Bot:     "📺 Estado de tu cuenta:
          👤 Usuario: edwin123
          📅 Vence: 2026-06-28
          🔌 Conexiones: 1
          ⚡ Estado: Activa 🟢"
```

**Endpoints API utilizados:**
- `POST /api/buscar-usuario` (identificar por teléfono)
- `POST /api/consultar-linea` (ver estado)

## 🟢 Caso B: Renovación con pago manual (Bizum/transfer)

```
Cliente: "renovar"
Bot:     [Menú con planes]
Cliente: [Elige "3 MESES — 24,99€"]
Bot:     "Cómo pagar:
          💳 Bizum: +34 600 000 000
          🏦 IBAN: ES12...
          Cuando pagues, escribe PAGADO"
Cliente: "PAGADO" + [foto del recibo]
Bot:     "⏳ Verificando tu pago..."

[Agente humano verifica desde su panel]
[Agente aprueba → webhook → bot continúa]

Bot:     "✅ ¡Renovación exitosa!
          📅 Vence ahora: 2026-09-28
          ¡A disfrutar! 🎬"
```

**Endpoints API utilizados:**
- `POST /api/renovar-linea` (con `revendedor_id` y `package_id`)
- Webhook interno para reanudar el flujo del bot

## 🟢 Caso C: Renovación con pago automático (Stripe/PayPal/Wompi)

```
Cliente: "renovar"
Bot:     [Menú con planes]
Cliente: [Elige "1 MES — 9,99€"]
Bot:     [Crea orden de pago con la pasarela]
Bot:     "Link de pago: https://checkout.stripe.com/..."

[Cliente paga en el link]
[Pasarela envía webhook → bot continúa automático]

Bot:     "✅ Pago recibido. Renovando tu cuenta..."
Bot:     "✅ ¡Listo! Tu cuenta vence el 2026-07-28"
```

**Endpoints API utilizados:**
- `POST /api/crear-orden`
- Webhook de la pasarela (Wompi/PayPal/MP/Binance)
- `POST /api/renovar-linea`

## 🟢 Caso D: Cliente nuevo compra primera cuenta

```
Cliente: "quiero una cuenta"
Bot:     "No te encuentro registrado. ¿Quieres comprar una?"
Cliente: [Confirma]
Bot:     "Elige tu username (min 8 chars):"
Cliente: "juan_iptv"
Bot:     "Ahora elige tu contraseña (min 8 chars):"
Cliente: "MiClave2026"
Bot:     [Menú de planes]
Cliente: [Elige plan + paga]

Bot:     "✅ Tu cuenta IPTV está lista
          👤 Usuario: juan_iptv
          🔑 Contraseña: MiClave2026
          🌐 Servidor: http://tudominio.com:port
          Configura en tu app (IPTV Smarters, TiviMate)"
```

**Endpoints API utilizados:**
- `POST /api/crear-linea` (con `revendedor_id`, `package_id`)

## 🟢 Caso E-bis: Cliente con varias cuentas elige una

```
Cliente: "hola"
Bot:     [Identifica al cliente por teléfono]
Bot:     [Detecta que tiene 3 cuentas]
Bot:     "📺 Tus cuentas IPTV:

          1️⃣ papa_juan  - Vence 2026-06-30 - Activa 🟢
          2️⃣ hijo_juan  - Vence 2026-07-15 - Activa 🟢
          3️⃣ esposa     - Vence 2026-05-20 - Suspendida 🔴

          Responde con el número (1 a 3) para gestionarla."
Cliente: "2"
Bot:     [Continúa con hijo_juan como cuenta activa]
Bot:     [Menú principal con opciones sobre hijo_juan]
```

**Endpoint API utilizado:**
- `POST /api/listar-mis-lineas` (devuelve mensaje formateado + indices)

---

## 🟢 Caso E: Cambio de contraseña

```
Cliente: "cambiar contraseña"
Bot:     "Escribe tu nueva contraseña:"
Cliente: "NuevaPass2026"
Bot:     "¿Confirmas el cambio a NuevaPass2026?
          Escribe SI para confirmar."
Cliente: "SI"
Bot:     "✅ Listo, tu nueva contraseña es: NuevaPass2026
          Actualízala en tu app IPTV."
```

**Endpoint API utilizado:**
- `POST /api/cambiar-password`

## 🟢 Caso F: Cliente quiere hablar con humano

```
Cliente: "agente" o "humano" o "ayuda urgente"
Bot:     "Te paso con un agente. Espera un momento... 🙋"
[El bot transfiere la conversación a un humano]
```

**Acción**: Nodo `human_handoff` → crea ticket automáticamente

## 🟢 Caso G: Cliente con cuenta vencida quiere reactivar

```
Cliente: "renovar"
Bot:     [Detecta cuenta vencida]
Bot:     "Tu cuenta venció el 2026-05-15. Vamos a reactivarla.
          Elige tu nuevo plan:"
Cliente: [Elige plan y paga]
Bot:     "✅ Reactivada. Vence: 2026-07-15"
```

**Nota**: el endpoint `/api/renovar-linea` automáticamente reactiva (`enabled=1`) si la línea estaba suspendida.

---

# 3. Arquitectura y componentes <a name="arquitectura"></a>

```
┌───────────────────────────────────────────────────────────────┐
│  CLIENTE (WhatsApp)                                           │
│  - Escribe "renovar"                                          │
│  - Selecciona opciones del menú                               │
└────────────────────────┬──────────────────────────────────────┘
                         │
                         ↓
┌───────────────────────────────────────────────────────────────┐
│  CHATBOT (tu plataforma — ManyChat, Botpress, n8n, etc.)      │
│  - Maneja el diálogo                                          │
│  - Llama a la API REST del middleware                         │
│  - Tabla de precios en € (configurada en nodos)               │
└────────────────────────┬──────────────────────────────────────┘
                         │
                         ↓ POST /api/...
                         │ X-API-Key: chatbot_secret_...
┌───────────────────────────────────────────────────────────────┐
│  MIDDLEWARE (este proyecto)                                   │
│  - Recibe requests del bot                                    │
│  - Auth con X-API-Key                                         │
│  - Resuelve username → line_id (con caché)                    │
│  - Si hay revendedor_id, usa la api_key del reseller          │
│  - Logs y auditoría                                           │
└────────────────────────┬──────────────────────────────────────┘
                         │
                         ↓
┌───────────────────────────────────────────────────────────────┐
│  PANEL XUI.ONE (externo)                                      │
│  - Crea/edita/elimina líneas IPTV                             │
│  - Maneja créditos del reseller                               │
└───────────────────────────────────────────────────────────────┘
```

**Componentes adicionales (opcionales):**
- **Pasarela de pago** (Wompi/PayPal/MP/Binance) — para pagos automáticos
- **Panel de agentes** — para aprobar pagos manuales

---

# 4. Configuración previa <a name="configuración-previa"></a>

## 4.1 En el middleware (servidor PHP)

Edita `.env`:

```env
# === Auth del chatbot al middleware ===
CHATBOT_API_KEY=chatbot_secret_auth_token_here

# === Panel XUI admin ===
XUI_API_URL=http://tripic.space/miapixui/
XUI_API_KEY=30B0DD094F41213AB3394E374BA6A557

# === Panel XUI reseller (URL distinta) ===
XUI_RESELLER_API_URL=http://tripic.space:80/resselerapi/

# === Pasarelas de pago (si las usas) ===
WOMPI_WEBHOOK_SECRET=tu_secreto_wompi
PAYPAL_CLIENT_SECRET=tu_secreto_paypal
# etc.
```

⚠️ **IMPORTANTE para producción**: cambia `CHATBOT_API_KEY` por algo aleatorio:
```bash
openssl rand -hex 32
```

## 4.2 Registrar a cada revendedor

Antes de que un revendedor pueda usar el bot, debes registrarlo en el middleware:

```bash
curl -X POST http://tu-servidor.com/api/crear-revendedor \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{
    "nombre": "Brenderos 94",
    "telefono": "+573001234567",
    "xui_user_id": 17,
    "xui_username": "brenderos94",
    "xui_api_key": "52035387EB0A9C3E4CD8D6133B219493"
  }'
```

La respuesta devuelve el `revendedor_id` que tendrás que poner en el bot.

## 4.3 En el chatbot (la plataforma que uses)

Necesitas tener disponibles:
- ✅ URL pública de tu middleware (ej. `http://tudominio.com`)
- ✅ La `X-API-Key` (de `.env`)
- ✅ El `revendedor_id` (devuelto al registrar el reseller)
- ✅ Datos de pago (Bizum, IBAN, etc.) para mostrar al cliente

---

# 5. Variables globales del bot <a name="variables-globales"></a>

| Variable | Tipo | Origen | Para qué |
|---|---|---|---|
| `{phone}` | string | nativa de la plataforma | Teléfono del cliente |
| `{input}` | string | nativa | Último mensaje del usuario |
| `{revendedor_id}` | string | hardcoded en Variable [3] | ID del reseller dueño de este bot |
| `{res_buscar}` | string | API buscar-usuario | Username IPTV del cliente |
| `{cliente_existe}` | "si" / "no" | derivada | Si está registrado |
| `{usuario_iptv}` | string | Variable | Username IPTV (de cache o capturado) |
| `{paquete_id}` | int | Variable (menu) | ID del paquete elegido |
| `{precio_eur}` | decimal | Variable (menu) | Precio en € que cobras |
| `{paquete_nombre}` | string | Variable (menu) | Nombre legible del paquete |
| `{nuevo_username}` | string | Capturar (compra nueva) | Username deseado |
| `{nuevo_password}` | string | Capturar (compra nueva) | Password deseado |
| `{nueva_password}` | string | Capturar (cambio pass) | Nueva contraseña |
| `{confirmacion_pago}` | string | Capturar | Respuesta del cliente "PAGADO" |
| `{nueva_fecha}` | string | API renovar-linea | Nueva fecha de vencimiento |
| `{new_line_id}` | int | API crear-linea | ID de la línea recién creada |

---

# 6. Diagrama maestro del flujo <a name="diagrama-maestro"></a>

```
                       ┌─────────┐
                       │ [1] Ini │
                       └────┬────┘
                            ↓
                ┌───────────────────────┐
                │ [2] Mensaje saludo    │
                └───────────┬───────────┘
                            ↓
                ┌────────────────────────┐
                │ [3] Variable           │
                │ revendedor_id = 1      │
                └───────────┬────────────┘
                            ↓
                ┌────────────────────────────────┐
                │ [4] API buscar-usuario         │
                │ saveToVar: res_buscar          │
                │ responseField: data.cliente.   │
                │                username        │
                └───────────┬────────────────────┘
                            ↓
                ┌─────────────────────────┐
                │ [5] Cond. Variable      │
                │ res_buscar not_empty?   │
                └──┬─────────────────┬────┘
                   │ match           │ no_match
                   ↓                 ↓
              [6a-c] vars       [6b] vars
              cliente=si        cliente=no
                   └────────┬────────┘
                            ↓
                ┌─────────────────────────┐
                │ [7] Mensaje intro       │
                └───────────┬─────────────┘
                            ↓
        ┌───────────────────────────────────────────────┐
        │ [8] MENÚ PRINCIPAL                            │
        │                                               │
        │   📋 Consultar │ 🔄 Renovar │ 🛒 Comprar      │
        │   🔑 Password  │ 👤 Agente  │ ❌ Salir        │
        └─┬──┬──┬──┬──┬──┬──┘
          │  │  │  │  │  │
          ↓  ↓  ↓  ↓  ↓  ↓
        [10][20][30][40][60][70]
```

---

# 7. Configuración nodo por nodo <a name="configuración-nodos"></a>

A continuación cada nodo con su `type` exacto y campos. Sigue este patrón:

## Bloque de entrada (común a todos los flujos)

### [1] Inicio
```yaml
type: start
connections:
  out: 2
```

### [2] Mensaje — Saludo
```yaml
type: message
text: |
  ¡Hola! 👋

  Bienvenido al servicio de IPTV.
  Dame un segundo que reviso tu cuenta...
connections:
  in: 1
  out: 3
```

### [3] Variable — revendedor_id
```yaml
type: set_var
varName: revendedor_id
value: "17"        # ← TU xui_user_id del panel XUI.ONE (NO el id interno del middleware)
connections:
  in: 2
  out: 4
```

> ⚠️ **IMPORTANTE**: el valor debe ser el **`xui_user_id`** que ves en el panel XUI.ONE para ese revendedor (lo devuelve `/api/crear-revendedor` y `/api/listar-revendedores` en el campo `xui_user_id`). El middleware acepta ambos (xui_user_id tiene prioridad), pero usar el `xui_user_id` es el estándar correcto. Si despliegas el bot para varios resellers, cada instancia cambia solo este valor.

### [4] API Request — buscar-usuario
```yaml
type: api_request
url: http://tu-servidor.com/api/buscar-usuario
method: POST
headers: |
  {
    "X-API-Key": "chatbot_secret_auth_token_here",
    "Content-Type": "application/json"
  }
bodyTemplate: |
  { "telefono": "{phone}" }
responseField: ""          # ← DEJAR VACÍO para guardar el JSON completo
saveToVar: res_buscar
timeout: 8000
connections:
  in: 3
  out: 5
```

> ⚠️ **ERROR COMÚN**: no pongas `data.cliente.username` en `responseField`. Si lo haces, `res_buscar` solo tendrá el username como string y perderás el resto de la respuesta (line_id, estado, etc.). Déjalo vacío y accede a los campos con dot-notation: `{res_buscar.data.cliente.username}`.
>
> La API devuelve `data.clientes[]` (array) y `data.total`. El campo `data.cliente` existe como retrocompatibilidad únicamente cuando `total = 1`.

### [5] Cond. Variable — Cliente existe?
```yaml
type: var_condition
varName: res_buscar
operator: not_empty
connections:
  in: 4
  match: 6a     # cliente registrado
  no_match: 6b  # cliente nuevo
```

### [6a / 6b / 6c] Variables — Set cliente_existe + usuario_iptv

```yaml
# [6a] Marca como existente
type: set_var
varName: cliente_existe
value: "si"
connections:
  in: 5 (match)
  out: 6c

# [6c] Guarda username
type: set_var
varName: usuario_iptv
value: "{res_buscar.data.cliente.username}"   # ← dot-notation al campo correcto
connections:
  in: 6a
  out: 7

# [6b] Marca como nuevo
type: set_var
varName: cliente_existe
value: "no"
connections:
  in: 5 (no_match)
  out: 7
```

### [7] Mensaje — Intro al menú
```yaml
type: message
text: |
  Hola 👋

  ¿Qué deseas hacer hoy?
connections:
  in: 6b / 6c
  out: 8
```

### [8] Menú — MENÚ PRINCIPAL
```yaml
type: menu
options:
  - label: "📋 Consultar mi cuenta"
    keywords: ["consultar", "estado", "ver", "1"]
    next: 10
  - label: "🔄 Renovar mi plan"
    keywords: ["renovar", "pagar", "extender", "2"]
    next: 20
  - label: "🛒 Comprar cuenta nueva"
    keywords: ["comprar", "nuevo", "nueva", "3"]
    next: 30
  - label: "🔑 Cambiar mi contraseña"
    keywords: ["cambiar", "password", "contraseña", "4"]
    next: 40
  - label: "👤 Hablar con un agente"
    keywords: ["agente", "humano", "ayuda", "5"]
    next: 60
  - label: "❌ Salir"
    keywords: ["salir", "adios", "6"]
    next: 70
connections:
  in: 7
```

---

# 8. Sub-flujos detallados <a name="sub-flujos"></a>

## 8.0 Sub-flujo SELECTOR DE CUENTA (cliente con varias)

Este sub-flujo se ejecuta justo después de identificar al cliente (paso [4]) cuando tiene más de una cuenta. Reemplaza el path "1 cliente = 1 cuenta" del flujo básico.

```
[4] API buscar-usuario → saveToVar: cliente_data
                          responseField: total

[5x] Cond. Variable
     varName: cliente_data
     operator: equals
     compareValue: "1"
     match → [6x] Una sola cuenta, auto-seleccionar
     no_match → [5y] Posiblemente múltiples

[5y] Cond. Variable
     varName: cliente_data
     operator: equals
     compareValue: "0"
     match → [5z] Cliente nuevo, ofrecer registro
     no_match → [SEL-1] Hay múltiples, mostrar selector

[SEL-1] API Request → listar-mis-lineas
        saveToVar: lista_lineas_msg
        responseField: message

[SEL-2] Mensaje → texto = "{lista_lineas_msg}"
                  out → [SEL-3]

[SEL-3] Capturar → guarda en {seleccion} (espera "1", "2", "3"...)

[SEL-4] API Request → listar-mis-lineas (de nuevo)
        bodyTemplate: { "telefono":"{phone}", "revendedor_id":"{revendedor_id}" }
        saveToVar: usuario_iptv
        responseField: indices.{seleccion}.username

[SEL-5] Cond. Variable → usuario_iptv not_empty?
        match → continúa al menú principal [7]
        no_match → "Opción inválida, vuelve a intentarlo" → [SEL-2]
```

### Detalle de los nodos clave:

```yaml
# [SEL-1] API listar-mis-lineas — para mostrar el menú
type: api_request
url: http://tu-servidor.com/api/listar-mis-lineas
method: POST
headers: |
  {"X-API-Key":"chatbot_secret_auth_token_here","Content-Type":"application/json"}
bodyTemplate: |
  {
    "telefono": "{phone}",
    "revendedor_id": "{revendedor_id}"
  }
responseField: message
saveToVar: lista_lineas_msg
timeout: 8000
```

```yaml
# [SEL-2] Mensaje formateado
type: message
text: "{lista_lineas_msg}"
```

```yaml
# [SEL-3] Capturar selección
type: capture
question: " "   # espacio en blanco — el mensaje ya está en [SEL-2]
varName: seleccion
```

```yaml
# [SEL-4] API listar-mis-lineas — para extraer username elegido
type: api_request
url: http://tu-servidor.com/api/listar-mis-lineas
method: POST
headers: |
  {"X-API-Key":"chatbot_secret_auth_token_here","Content-Type":"application/json"}
bodyTemplate: |
  {
    "telefono": "{phone}",
    "revendedor_id": "{revendedor_id}"
  }
responseField: indices.{seleccion}.username
saveToVar: usuario_iptv
timeout: 8000
```

> **Nota**: este patrón hace 2 llamadas a la API. Si tu plataforma soporta dot-notation con interpolación de variables en el responseField (`indices.{input}.username`), puede ser 1 sola llamada. Si no, las 2 llamadas funcionan bien porque la API es rápida (~50ms cada una).

### Si tu plataforma NO soporta dot-notation con variables

Hay un workaround con `Cond. Variable` para 1-4 cuentas (la mayoría de casos):

```yaml
[SEL-4a] Cond. Variable: seleccion equals "1"
         match → set_var usuario_iptv = (1ra llamada con responseField=indices.1.username)
[SEL-4b] Cond. Variable: seleccion equals "2"
         match → set_var usuario_iptv = (otra llamada con responseField=indices.2.username)
# ... etc hasta el máximo de cuentas que esperas
```

---

## 8.1 Sub-flujo CONSULTAR

```
[10] Cond. Variable    → usuario_iptv not_empty?
        match → [11]   no_match → [10a]

        ⚠️ NO usar "cliente_existe == si". El problema: cliente_existe se fija una
        sola vez al arrancar la sesión y no se actualiza si el usuario escribe su
        username manualmente en la misma sesión. usuario_iptv sí persiste correcto.

[10a] Capturar         → primera vez aquí, pedir username para vincular cuenta
        out → [11]

[11] API consultar-linea
        responseField: message
        saveToVar: info_linea
        out → [12]

[12] Cond. Variable    → info_linea not_empty?
        match → [13]   no_match → [13a]

[13] Mensaje "📺 {info_linea}"  → [14] Pausa → [8] (vuelve al menú)

[13a] Mensaje "❌ No te encontré" → [14] → [8]
```

### Detalle de los nodos clave:

```yaml
# [10] Cond. Variable — ¿ya conocemos el username?
type: var_condition
varName: usuario_iptv
operator: not_empty
connections:
  match: 11      # ya lo tenemos → consultar directo
  no_match: 10a  # primera vez → pedir username
```

```yaml
# [10a] Capturar — primera vez del cliente
type: capture
question: |
  Es tu primera vez aquí 👋
  Escribe tu *username IPTV* para vincular tu cuenta:
varName: usuario_iptv
connections:
  out: 11
```

```yaml
# [11] API consultar-linea
type: api_request
url: http://tu-servidor.com/api/consultar-linea
method: POST
headers: |
  {"X-API-Key":"chatbot_secret_auth_token_here","Content-Type":"application/json"}
bodyTemplate: |
  {
    "username": "{usuario_iptv}",
    "telefono": "{phone}",
    "revendedor_id": "{revendedor_id}"
  }
responseField: message
saveToVar: info_linea
timeout: 8000
```

```yaml
# [13] Mensaje (éxito)
type: message
text: |
  📺 *Estado de tu cuenta*

  {info_linea}
```

```yaml
# [14] Pausa
type: delay
delayMs: 2000
connections:
  out: 8   # vuelve al menú
```

---

> **Comportamiento auto-link de `revendedor_id`** (desde v1.2)
>
> Cuando `consultar-linea` se llama con `revendedor_id` y el cliente ya existe en la
> tabla `clientes` con `revendedor_id = null` (porque fue registrado antes de esta versión
> o desde otra ruta), el sistema lo vincula automáticamente al reseller correcto.
>
> Efecto práctico: la primera vez que el bot llama `consultar-linea` para un cliente,
> ese cliente queda ligado al `revendedor_id` enviado en el body. A partir de ese momento,
> `listar-mis-lineas` con filtro `revendedor_id` ya devuelve sus cuentas correctamente.
>
> ⚠️ **No** es necesario llamar `consultar-linea` antes de `listar-mis-lineas`; el auto-link
> sucede en el primer `consultar-linea` real del flujo.

---

## 8.2 Sub-flujo RENOVAR

```
[20] Cond. Variable    → usuario_iptv not_empty?
        match → [21]   no_match → [20a] "necesitas cuenta" → [30]

        ⚠️ NO uses "cliente_existe == si". Mismo bug que en 8.1: cliente_existe
        es una var derivada que puede quedar cacheada en sesión Redis con un valor
        viejo. usuario_iptv se setea desde la respuesta real de la API.

[21] Mensaje "Elige tu plan"
[22] Menú con 4 planes hardcoded + Cancelar

[23a-d] Triple Variable por plan:
        paquete_id   = "3" / "5" / "7" / "9"
        precio_eur   = "9.99" / "24.99" / "44.99" / "79.99"
        paquete_nombre = "1 MES" / "3 MESES" / etc.

[24] Mensaje "Confirma + Bizum/IBAN/USDT"
[25] Capturar       → confirmacion_pago
[26] Cond. Variable → contains "PAGADO"?
        match → [27]   no_match → [26a] cancelar → [8]

[27] Mensaje "⏳ Verificando..."
[28] Esperar Webhook → aguarda confirmación del agente
[29] API renovar-linea (DESCUENTA CRÉDITOS DEL RESELLER)
[29b] Cond. Variable → nueva_fecha not_empty?
        match → [29c] éxito → [14b] → [8]
        no_match → [29d] error → [29e] Agente
```

### Detalle de los nodos clave:

```yaml
# [22] Menú de planes
type: menu
options:
  - label: "💎 1 MES — 9,99€"
    keywords: ["1 mes", "mes", "1"]
    next: 23a
  - label: "⭐ 3 MESES — 24,99€"
    next: 23b
  - label: "🔥 6 MESES — 44,99€"
    next: 23c
  - label: "👑 12 MESES — 79,99€"
    next: 23d
  - label: "↩️ Volver"
    next: 8
```

```yaml
# [23a] Cadena de 3 variables (ejemplo "1 MES")
type: set_var
varName: paquete_id
value: "3"
connections:
  out: 23a2

# [23a2]
type: set_var
varName: precio_eur
value: "9.99"
connections:
  out: 23a3

# [23a3]
type: set_var
varName: paquete_nombre
value: "1 MES"
connections:
  out: 24   # confluye en confirmación
```

```yaml
# [24] Confirmación + datos de pago
type: message
text: |
  🛒 *Confirma tu compra*

  📦 Plan: {paquete_nombre}
  💰 Total: *{precio_eur}€*

  *Métodos de pago:*
  💳 Bizum: +34 600 000 000
  🏦 IBAN: ES12 1234 5678 9012 3456 7890
  ₿ USDT (TRC20): TXXXXXXXXX

  Cuando hayas pagado, envía el comprobante
  y escribe *PAGADO* ✅
```

```yaml
# [28] Esperar Webhook
type: wait_webhook
pendingMessage: "Sigo esperando confirmación del pago..."
secret: tu_secreto_seguro_aqui
statusField: status
successValues: "approved,confirmed"
failureValues: "rejected"
saveField: data.payment_id
saveToVar: payment_id
successMessage: "✅ Pago confirmado. Activando tu plan..."
failureMessage: "❌ El pago no se pudo verificar."
timeoutMin: 30
timeoutMessage: "El tiempo expiró. Si pagaste, escribe AGENTE."
```

```yaml
# [29] API renovar-linea
type: api_request
url: http://tu-servidor.com/api/renovar-linea
method: POST
headers: |
  {"X-API-Key":"chatbot_secret_auth_token_here","Content-Type":"application/json"}
bodyTemplate: |
  {
    "username": "{usuario_iptv}",
    "package_id": "{paquete_id}",
    "revendedor_id": "{revendedor_id}"
  }
responseField: data.nueva_expiracion
saveToVar: nueva_fecha
timeout: 10000
```

```yaml
# [29c] Mensaje éxito
type: message
text: |
  ✅ *¡Renovación exitosa!*

  👤 Cuenta: *{usuario_iptv}*
  📅 Vence ahora: *{nueva_fecha}*

  🎬 ¡A disfrutar de tu IPTV!
```

---

## 8.3 Sub-flujo COMPRAR CUENTA NUEVA

```
[30] Capturar      → nuevo_username (pide al cliente)
[31] Capturar      → nuevo_password
[32] Mensaje       → "Elige tu plan"
[33] Menú          → planes (igual estructura que renovar)
[34a-d] Triple Variable por plan
[35] Mensaje       → Confirmar + datos de pago
[36] Capturar      → confirmacion_pago
[37] Cond. Variable → "PAGADO"?
[38] Esperar Webhook
[39] API crear-linea (XUI cobra créditos automático)
[39b] Cond. Variable → new_line_id not_empty?
        match → [39c] entregar credenciales → [8]
        no_match → [39d] error → [39e] Agente
```

### Detalle de los nodos clave:

```yaml
# [30] Capturar username
type: capture
question: |
  🛒 Genial, te ayudo a crear tu cuenta nueva.

  Escribe el *username* que quieres usar
  (mínimo 8 caracteres, sin espacios) 👇
varName: nuevo_username
```

```yaml
# [31] Capturar password
type: capture
question: |
  Ahora escribe la *contraseña* (mínimo 8 caracteres) 👇
varName: nuevo_password
```

```yaml
# [39] API crear-linea
type: api_request
url: http://tu-servidor.com/api/crear-linea
method: POST
headers: |
  {"X-API-Key":"chatbot_secret_auth_token_here","Content-Type":"application/json"}
bodyTemplate: |
  {
    "telefono": "{phone}",
    "username": "{nuevo_username}",
    "password": "{nuevo_password}",
    "package_id": "{paquete_id}",
    "revendedor_id": "{revendedor_id}"
  }
responseField: data.line_id
saveToVar: new_line_id
timeout: 10000
```

```yaml
# [39c] Mensaje éxito con credenciales
type: message
text: |
  ✅ *Tu cuenta IPTV está lista*

  👤 Usuario: *{nuevo_username}*
  🔑 Contraseña: *{nuevo_password}*

  🌐 Servidor: http://tudominio.com:port

  Configura estos datos en tu app IPTV
  (IPTV Smarters, TiviMate, etc.)

  ¡Bienvenido! 🎬
```

---

## 8.4 Sub-flujo CAMBIAR PASSWORD

```
[40] Cond. Variable → usuario_iptv not_empty?
        match → [41]   no_match → "necesitas cuenta" → [8]

        ⚠️ Igual que 8.1 y 8.2 — usar usuario_iptv y NO cliente_existe.

[41] Capturar       → nueva_password
[42] Capturar       → confirma_pass ("SI" o cualquier cosa)
[43] Cond. Variable → equals "SI"?
        match → [44]   no_match → [43a] cancelar → [8]

[44] API cambiar-password
[45] Cond. Variable → pass_ok equals "true"?
        match → [46] éxito → [8]
        no_match → [46a] error → Agente
```

### Detalle clave:

```yaml
# [44] API cambiar-password
type: api_request
url: http://tu-servidor.com/api/cambiar-password
method: POST
headers: |
  {"X-API-Key":"chatbot_secret_auth_token_here","Content-Type":"application/json"}
bodyTemplate: |
  {
    "username": "{usuario_iptv}",
    "password": "{nueva_password}",
    "revendedor_id": "{revendedor_id}"
  }
responseField: success
saveToVar: pass_ok
```

```yaml
# [45] Cond. Variable
type: var_condition
varName: pass_ok
operator: equals
compareValue: "true"
```

---

## 8.5 Sub-flujo AGENTE

```
[60] Mensaje "Te paso con un agente..."
[61] human_handoff (nodo terminal)
```

```yaml
# [60]
type: message
text: |
  Te paso con un agente humano. Espera un momento... 🙋

# [61]
type: human_handoff
```

---

## 8.6 Sub-flujo SALIR

```
[70] Fin (con mensaje de despedida)
```

```yaml
type: end
text: |
  ¡Gracias por contactarnos! 👋

  Si necesitas algo más, escribe *MENU* en cualquier momento. 🤖
```

---

# 9. Tabla de precios y paquetes <a name="precios"></a>

Esta es **tu tabla de precios** — la configuras en los nodos Variable del bot, NO en la API. Para cambiar precios, editas solo estos nodos.

| Opción menú | package_id | Duración | Tu precio | Créditos XUI |
|---|---|---|---|---|
| 1 MES | 3 | 30 días | **9,99 €** | 1 |
| 3 MESES | 5 | 90 días | **24,99 €** | 3 |
| 6 MESES | 7 | 180 días | **44,99 €** | 6 |
| 12 MESES | 9 | 365 días | **79,99 €** | 12 |
| 12 MESES ESPAÑA | 11 | 365 días | **89,99 €** | 12 |
| 1 MES ESPAÑA | 13 | 30 días | **11,99 €** | 1 |

**Margen sugerido:** vendes a 9,99€ y al reseller le cuesta 1 crédito (lo que tú le hayas vendido — por ejemplo 1€/crédito). Tu margen depende del precio que pongas vs el costo del crédito.

## 9.1 Menú de paquetes dinámico con `listar-paquetes` *(nuevo)*

En lugar de hardcodear los paquetes en nodos Variable, puedes obtenerlos en tiempo real desde XUI.ONE. Útil cuando el catálogo cambia frecuentemente.

```yaml
# Nodo API: cargar paquetes disponibles
type: api_request
url: http://tu-servidor.com/api/listar-paquetes
method: POST
headers: |
  {"X-API-Key":"chatbot_secret_auth_token_here","Content-Type":"application/json"}
bodyTemplate: |
  {}
responseField: message         # ← si quieres el texto pre-formateado
saveToVar: lista_paquetes_msg  # o deja vacío y usa dot-notation
timeout: 8000
```

La respuesta incluye por cada paquete: `id`, `nombre`, `duracion_humana` ("1 mes", "3 meses"...), `dias` y `creditos`. El campo `message` no existe en este endpoint — debes iterar `data.paquetes[]` o guardar el JSON completo y acceder con dot-notation.

**Ejemplo de acceso:**
- `{lista_paquetes.data.paquetes.0.id}` → id del primer paquete
- `{lista_paquetes.data.paquetes.0.nombre}` → nombre del primer paquete

> 💡 Para bots simples (menos de 5 planes) sigue siendo más práctico hardcodear los IDs. Usa `listar-paquetes` si el catálogo es dinámico o quieres que el bot se adapte automáticamente a cambios en XUI.ONE.

---

# 10. Manejo de pagos <a name="pagos"></a>

Hay 3 modelos posibles:

## 10.1 Pago manual (Bizum, transfer, USDT)

**El más común para revendedores que empiezan.**

```
Flujo:
1. Bot muestra datos de pago (Bizum, IBAN)
2. Cliente paga y envía comprobante
3. Cliente escribe "PAGADO"
4. Bot notifica al agente humano (via nodo human_handoff o agente custom)
5. Agente verifica el pago en su banco/Bizum
6. Agente aprueba desde panel interno
7. Panel interno hace POST a:
   /api/chatbot/webhook-resume?phone=XXX&secret=YYY
   {"status":"approved"}
8. Bot continúa con la renovación automáticamente
```

**Pro**: control total, sin comisiones de pasarelas
**Contra**: requiere intervención humana en cada pago

## 10.2 Pago automático con pasarela

Usando Wompi, PayPal, MercadoPago o Binance Pay.

```
Flujo:
1. Bot llama POST /api/crear-orden → recibe order_id
2. Bot llama a la pasarela (Stripe/Wompi/etc.) y obtiene link de pago
3. Bot envía link al cliente
4. Cliente paga en el link
5. Pasarela dispara webhook → /api/webhook-pago?gateway=wompi
6. Middleware renueva automáticamente
7. Middleware notifica al bot vía /api/chatbot/webhook-resume
8. Bot confirma al cliente
```

**Pro**: 100% automático, escalable
**Contra**: comisiones de la pasarela (3-5% típicamente)

## 10.3 Pago con créditos directo del reseller

Si el cliente tiene una "cuenta" con el reseller y va consumiendo:

```
Flujo:
1. Reseller carga "saldo" al cliente manualmente
2. Bot verifica saldo del cliente antes de cada operación
3. Si tiene saldo → renueva sin pasarela
4. Si no tiene → ofrece recargar (manual o automático)
```

Esto requiere una tabla extra `saldo_clientes` que aún no está en el middleware. Pide implementarla si la necesitas.

---

# 11. Multi-reseller (varios revendedores) <a name="multi-reseller"></a>

Si tienes varios revendedores, cada uno con su propio chatbot:

## Opción A: Un bot por reseller (recomendado)

Cada reseller despliega su propia instancia del bot:
- Cada bot tiene su propio número de WhatsApp
- En el nodo [3] (Variable) cada bot tiene su `revendedor_id` distinto
- El valor de `revendedor_id` es el **`xui_user_id`** del panel XUI.ONE, NO el id interno del middleware
- Comparten el mismo middleware

**Ejemplo (los IDs son los `xui_user_id` que ves en el panel XUI):**
- Bot Reseller A: `revendedor_id = 17` (Brenderos 94 — xui_user_id 17)
- Bot Reseller B: `revendedor_id = 25` (Juan IPTV — xui_user_id 25)
- Bot Reseller C: `revendedor_id = 34` (María Streams — xui_user_id 34)

> 💡 Si por error pones el id interno del middleware (`local_id`), también funciona como fallback de retrocompatibilidad — pero el estándar es usar `xui_user_id`.

## Opción B: Un bot multi-tenant (avanzado)

Un solo bot que detecta el reseller según el número del cliente o el dominio:
- Requiere lógica adicional en el bot para mapear cliente → reseller
- Más complejo, pero centralizado

## Tabla de resellers que ya tienes registrados

```bash
# Listar todos:
curl -X POST http://tu-servidor.com/api/listar-revendedores \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -H "Content-Type: application/json" \
  -d '{}'
```

Salida:
```json
{
  "data": {
    "revendedores": [
      {"id": 1, "nombre": "Brenderos 94", "xui_username": "brenderos94", "creditos_cache": 28, "active": true}
    ]
  }
}
```

---

# 12. Errores comunes y soluciones <a name="errores"></a>

| Síntoma | Causa | Solución |
|---|---|---|
| 401 "No autorizado" en cada llamada | `X-API-Key` mal escrito o falta | Verificar header en cada nodo API Request |
| "El nombre de usuario no se encontró" | Cliente NO está en BD local NI en XUI | Si es cliente del reseller, debe pasar `revendedor_id` para que busque en su scope |
| Renovación devuelve OK pero fecha NO cambia | Sin `revendedor_id` pero la línea es del reseller | Agregar `revendedor_id` al body |
| Créditos NO se descuentan en renovar | Faltaba `package_id` | El descuento solo aplica si se renueva CON paquete |
| Webhook nunca llega | `secret` mal configurado o agente no aprobó | Verificar logs `/api/chatbot/webhook-resume` |
| Bot se queda esperando indefinidamente | `timeoutMin` no se llegó | Configurar timeoutMin razonable (30 min) |
| Cliente recibe mensaje vacío | API devolvió respuesta sin el campo esperado | Verificar `responseField` con dot notation correcta |
| Bot siempre pide username aunque cliente ya consultó antes | `cliente_existe` cacheado en sesión Redis con valor "no" | Usar `usuario_iptv not_empty` en el Nodo [10] — ver 8.1 |
| Cambié `revendedor_id` en el flujo pero bot sigue usando el anterior | Sesión activa en Redis tiene el valor viejo | Las vars de config se recargan del flujo en cada mensaje; borrar sesión Redis si se persiste |

---

## 12.1 Acceso directo al panel XUI.ONE — `panel-consultar` *(avanzado)*

El endpoint `POST /api/panel-consultar` es un proxy de **solo lectura** hacia XUI.ONE. Útil para depuración o para construir funciones de administración en el bot (admin interno).

```bash
# Ejemplos útiles:
# Ver paquetes disponibles en XUI
curl -X POST http://tu-servidor.com/api/panel-consultar \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -H "Content-Type: application/json" \
  -d '{"action":"get_packages"}'

# Ver conexiones activas en este momento
curl -X POST http://tu-servidor.com/api/panel-consultar \
  -d '{"action":"live_connections"}'

# Obtener una línea por ID directo
curl -X POST http://tu-servidor.com/api/panel-consultar \
  -d '{"action":"get_line","id":1683}'
```

Acciones permitidas: `get_lines`, `get_packages`, `get_bouquets`, `live_connections`, `activity_logs`, `get_server_stats`, y más de 50 acciones de lectura. Escribe o modifica datos con los endpoints dedicados (`crear-linea`, `renovar-linea`, etc.) — este endpoint rechaza cualquier acción de escritura.

---

# 13. Checklist de despliegue <a name="checklist"></a>

Antes de poner el bot en producción, verifica:

## En el middleware
- [ ] `.env` con `CHATBOT_API_KEY` cambiada (no la default)
- [ ] `.env` con `XUI_API_URL` y `XUI_API_KEY` correctas
- [ ] `.env` con `XUI_RESELLER_API_URL` configurada
- [ ] Servidor PHP corriendo (verificar `ss -tlnp | grep 8000`)
- [ ] BD MariaDB corriendo (verificar contenedor docker)
- [ ] Tablas creadas: `clientes`, `revendedores`, `recargas`, `ordenes`, `pagos`, `logs`
- [ ] Al menos un revendedor registrado: `GET /api/listar-revendedores`
- [ ] Dominio público con HTTPS si vas a recibir webhooks de pasarelas

## En el chatbot
- [ ] Variable `revendedor_id` en nodo [3] contiene el `xui_user_id` del reseller (no el id interno del middleware)
- [ ] URL del middleware actualizada en TODOS los nodos API Request
- [ ] `X-API-Key` correcta en TODOS los nodos API Request
- [ ] Datos de pago (Bizum/IBAN/USDT) actualizados en nodo de pago
- [ ] Tabla de precios verificada en nodos Variable [23a-d]
- [ ] Mensajes personalizados con el branding del reseller
- [ ] Probado: caso A (consultar), B (renovar), D (comprar nuevo)

## Pruebas E2E
- [ ] Cliente nuevo: comprar primera cuenta → llega credenciales
- [ ] Cliente existente: consultar estado → muestra info correcta
- [ ] Cliente existente: renovar → fecha avanza + créditos bajan
- [ ] Cliente sin cuenta: pide hablar con agente → handoff funciona
- [ ] Salir / Volver al menú → flujo se reinicia limpiamente

## Seguridad
- [ ] `X-API-Key` no expuesta en logs ni código público
- [ ] `secret` del webhook configurado y secreto
- [ ] HTTPS en producción (no HTTP)
- [ ] Backups de BD configurados
- [ ] Logs habilitados para auditoría: `storage/logs/app.log`

---

# 🚀 Resumen ultra-corto

**Para implementar el bot del cero:**

1. **Configurar middleware** (`.env` + correr `docker compose up -d`)
2. **Registrar reseller** vía `crear-revendedor` → anotar el **`xui_user_id`** (NO el `local_id`)
3. **Crear bot** en tu plataforma con los nodos descritos:
   - Entrada + menú principal (nodos [1]-[8])
   - 5 sub-flujos (consultar, renovar, comprar, password, agente)
4. **Hardcodear** el `revendedor_id` = `xui_user_id` en el nodo Variable [3]
5. **Configurar** la `X-API-Key` y URL en todos los nodos API Request
6. **Probar** cada caso de uso
7. **Conectar** WhatsApp Business / Telegram a tu plataforma
8. **Lanzar** 🎉

---

*Documento generado para el sistema IPTV XUI.ONE Middleware · Mayo 2026*
