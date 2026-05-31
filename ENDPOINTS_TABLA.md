# 📋 Tabla de Endpoints — Admin vs Revendedor

**IPTV XUI.ONE Middleware** · Referencia rápida · Mayo 2026

---

## 🔐 Auth común a todos

Todos los endpoints (menos `webhook-pago`) usan los mismos headers:

```http
Content-Type: application/json
X-API-Key: chatbot_secret_auth_token_here   ← UNA SOLA key para todo
```

La diferencia entre "admin" y "revendedor" **NO** es la key — es el **propósito del endpoint** y si pasa el parámetro `revendedor_id` en el body para enrutar al reseller correcto.

---

# 🔴 ENDPOINTS DE ADMIN

Operaciones que **solo tú** (el dueño del middleware) ejecutas. Gestionan resellers, recargas, accesos al panel y órdenes de pago.

| # | Método | Endpoint | Body mínimo | Para qué |
|---|---|---|---|---|
| 1 | POST | `/api/crear-revendedor` | `{"nombre","xui_user_id","xui_username","xui_api_key"}` | Registrar reseller nuevo |
| 2 | POST | `/api/listar-revendedores` | `{}` o `{"solo_activos":true}` | Ver todos los resellers con saldo |
| 3 | POST | `/api/saldo-revendedor` | `{"revendedor_id":17}` | Saldo en vivo de un reseller |
| 4 | POST | `/api/recargar-creditos` | `{"revendedor_id":17,"creditos":50,"nota":"USDT..."}` | Sumar créditos al reseller |
| 5 | POST | `/api/historial-recargas` | `{}` o `{"revendedor_id":17}` | Audit trail de recargas |
| 6 | POST | `/api/eliminar-revendedor` | `{"revendedor_id":17}` | Soft-delete (active=0) |
| 7 | POST | `/api/panel-consultar` | `{"action":"get_packages"}` | Read-only proxy al panel XUI |
| 8 | POST | `/api/crear-orden` | `{"line_id":1683,"dias":30,"monto":15}` | Generar orden de pago |

## Ejemplos de uso (admin)

### Registrar reseller
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

### Recargar 50 créditos
```bash
curl -X POST http://tu-servidor.com/api/recargar-creditos \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{
    "revendedor_id": 17,
    "creditos": 50,
    "nota": "Pago USDT 2026-06-15"
  }'
```

### Consultar saldo
```bash
curl -X POST http://tu-servidor.com/api/saldo-revendedor \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{"revendedor_id":17}'
```

### Ver paquetes del panel
```bash
curl -X POST http://tu-servidor.com/api/panel-consultar \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{"action":"get_packages"}'
```

---

# 🟢 ENDPOINTS DEL REVENDEDOR

Operaciones que el chatbot del reseller llama para atender clientes. **TODOS aceptan `revendedor_id`** en el body para enrutar al reseller correcto y descontar créditos de su saldo cuando aplique.

## 9.1 Gestión de líneas IPTV

| # | Método | Endpoint | Body mínimo (con reseller) | Cobra créditos? |
|---|---|---|---|---|
| 9 | POST | `/api/listar-paquetes` | `{}` | ❌ No (lectura) |
| 10 | POST | `/api/consultar-linea` | `{"username":"juan","revendedor_id":17}` | ❌ No (lectura) |
| 11 | POST | `/api/listar-mis-lineas` | `{"telefono":"+57...","revendedor_id":17}` | ❌ No (lectura) |
| 12 | POST | `/api/crear-linea` | `{"telefono","username","password","package_id":3,"revendedor_id":17}` | ✅ **SÍ** (automático por XUI) |
| 13 | POST | `/api/renovar-linea` | `{"username":"juan","package_id":3,"revendedor_id":17}` | ✅ **SÍ** (descuento manual) |
| 14 | POST | `/api/suspender-linea` | `{"username":"juan","revendedor_id":17}` | ❌ No |
| 15 | POST | `/api/activar-linea` | `{"username":"juan","revendedor_id":17}` | ❌ No |
| 16 | POST | `/api/cambiar-password` | `{"username":"juan","password":"X","revendedor_id":17}` | ❌ No |
| 17 | POST | `/api/cambiar-conexiones` | `{"username":"juan","conexiones":2,"revendedor_id":17}` | ❌ No |
| 18 | POST | `/api/eliminar-linea` | `{"username":"juan","revendedor_id":17}` | ❌ No (destruct) |

## 9.2 Pagos (mixto admin/reseller)

| # | Método | Endpoint | Body mínimo | Quién lo llama |
|---|---|---|---|---|
| 19 | POST | `/api/consultar-pago` | `{"order_id":"ORD-..."}` | Admin o Reseller bot |
| 20 | POST | `/api/buscar-usuario` | `{"telefono":"+57..."}` | Reseller bot |

## Ejemplos de uso (reseller bot)

### Listar paquetes para el menú
```bash
curl -X POST http://tu-servidor.com/api/listar-paquetes \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{}'
```

### Listar todas las cuentas del cliente
```bash
curl -X POST http://tu-servidor.com/api/listar-mis-lineas \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{"telefono":"+573001111111","revendedor_id":17}'
```

### Consultar estado de una línea
```bash
curl -X POST http://tu-servidor.com/api/consultar-linea \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{"username":"juan_iptv","revendedor_id":17}'
```

### Crear nueva línea (cobra 1 crédito al reseller automático)
```bash
curl -X POST http://tu-servidor.com/api/crear-linea \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{
    "telefono": "+573001111111",
    "username": "juan_iptv",
    "password": "MiClave12345",
    "package_id": 3,
    "revendedor_id": 17
  }'
```

### Renovar (cobra créditos según paquete al reseller)
```bash
curl -X POST http://tu-servidor.com/api/renovar-linea \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{
    "username": "juan_iptv",
    "package_id": 3,
    "revendedor_id": 17
  }'
```

### Suspender una línea
```bash
curl -X POST http://tu-servidor.com/api/suspender-linea \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{"username":"juan_iptv","revendedor_id":17}'
```

### Cambiar contraseña
```bash
curl -X POST http://tu-servidor.com/api/cambiar-password \
  -H "Content-Type: application/json" \
  -H "X-API-Key: chatbot_secret_auth_token_here" \
  -d '{
    "username":"juan_iptv",
    "password":"NuevaClave2026",
    "revendedor_id": 17
  }'
```

---

# 🟡 ENDPOINTS PÚBLICOS (sin X-API-Key)

| # | Método | Endpoint | Auth | Quién lo llama |
|---|---|---|---|---|
| 21 | POST | `/api/webhook-pago?gateway=wompi` | Firma criptográfica | La pasarela de pago (Wompi/PayPal/MP/Binance) |

⚠️ **NO** lo llamas tú ni el chatbot. Lo configuran las pasarelas como URL de callback. La autenticación es por firma del payload, no por `X-API-Key`.

---

# 📊 Resumen visual

```
┌────────────────────────────────────────────────────────────┐
│  CHATBOT (admin o reseller)                                │
│  → SIEMPRE: X-API-Key: chatbot_secret_auth_token_here      │
└─────────────┬──────────────────────────────────────────────┘
              │
              ↓
   ┌──────────────────────────────┐
   │ Body tiene revendedor_id?    │
   └─────────────┬────────────────┘
        ┌────────┴────────┐
        │ NO              │ SÍ (revendedor_id=17 del panel XUI)
        ↓                 ↓
  ┌──────────────┐  ┌──────────────────────────────────┐
  │ Modo ADMIN   │  │ Modo RESELLER                    │
  │ usa .env's   │  │ busca xui_user_id=17 en BD       │
  │ XUI_API_KEY  │  │ usa la api_key del reseller      │
  │              │  │ XUI descuenta créditos auto      │
  └──────────────┘  └──────────────────────────────────┘
```

---

# 🎯 Diferencia clave entre admin y reseller

| Aspecto | Admin | Reseller |
|---|---|---|
| **X-API-Key** | Misma para todos | Misma para todos |
| **revendedor_id en body** | ❌ No incluir | ✅ Siempre incluir (xui_user_id) |
| **Quién paga créditos?** | Tú (admin, ilimitado) | El reseller (de su saldo) |
| **Quién es dueño de la línea creada?** | Admin | El reseller (member_id=xui_user_id) |
| **Qué endpoints puede usar?** | TODOS | Solo gestión de líneas + lectura |
| **Recargas/audit/listar resellers** | ✅ Acceso completo | ❌ No debería (solo lo verás reflejado) |

---

# 🚀 Cheat-sheet para implementación

### Bot del admin
```
X-API-Key: chatbot_secret_auth_token_here
Endpoints frecuentes:
  - /api/listar-revendedores
  - /api/saldo-revendedor
  - /api/recargar-creditos
  - /api/historial-recargas
  - /api/panel-consultar
```

### Bot del reseller (ej. Brenderos 94, xui_user_id=17)
```
X-API-Key: chatbot_secret_auth_token_here   ← misma key
Hardcoded en el bot: revendedor_id = 17     ← xui_user_id del panel XUI

Endpoints frecuentes:
  - /api/listar-paquetes                    (menú de planes)
  - /api/listar-mis-lineas    + revendedor_id  (cliente multi-cuenta)
  - /api/consultar-linea      + revendedor_id  (estado)
  - /api/crear-linea          + revendedor_id  (nueva venta)
  - /api/renovar-linea        + revendedor_id  (renovación)
  - /api/buscar-usuario                       (por teléfono)
  - /api/saldo-revendedor     (revendedor_id: el suyo) (ver su saldo)
```

---

*Documento generado para el sistema IPTV XUI.ONE Middleware · Mayo 2026*
