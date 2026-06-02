# Guía: Gestión de Revendedores

## Cómo agregar un nuevo revendedor

### Requisitos previos

El revendedor debe existir primero en el panel **XUI One**. Necesitas obtener 3 datos de su perfil en XUI:

| Dato | Dónde encontrarlo en XUI One |
|------|------------------------------|
| `user_id` | Panel → Resellers → columna **ID** |
| `username` | Panel → Resellers → columna **Username** |
| `api_key` | Panel → Resellers → click en el reseller → **API Key** |

---

### Paso 1 — Registrar el revendedor en la BD local

Ejecuta este curl desde cualquier terminal (o desde el VPS):

```bash
curl -s -X POST https://solucionesdigitales.icu/api/crear-revendedor \
  -H "Content-Type: application/json" \
  -H "X-API-Key: TU_CHATBOT_API_KEY" \
  -d '{
    "nombre": "Nombre del revendedor",
    "telefono": "+50399999999",
    "xui_user_id": ID_EN_XUI,
    "xui_username": "username_en_xui",
    "xui_api_key": "API_KEY_DEL_REVENDEDOR"
  }'
```

Respuesta exitosa:
```json
{
  "success": true,
  "message": "Revendedor registrado correctamente.",
  "data": {
    "revendedor": {
      "local_id": 1,
      "xui_user_id": 17,
      "nombre": "brenderos94",
      ...
    }
  }
}
```

> El `local_id` es el ID interno de la BD. El `xui_user_id` es el que usas en el flujo del chatbot.

---

### Paso 2 — Configurar el flujo del chatbot

En el **Chatbot Builder** (ticket-system frontend), abre el flujo del revendedor y localiza el nodo `nf-set-revendedor`. Cambia el valor de `revendedor_id` al `xui_user_id` del nuevo revendedor.

Si quieres un flujo independiente por revendedor:
1. Duplica el flujo existente ("Flujo Full Revendedor v1")
2. Cambia el nodo `nf-set-revendedor` con el nuevo `xui_user_id`
3. Activa el nuevo flujo y desactiva el anterior (solo puede haber uno activo)

---

### Revendedores actualmente registrados

| xui_user_id | username | BD local_id | Registrado |
|-------------|----------|-------------|------------|
| 17 | brenderos94 | 1 | 2026-06-02 |

*(actualiza esta tabla cada vez que registres uno nuevo)*

---

## Cómo funciona el sistema de seguridad

Cuando el chatbot llama a la API con `revendedor_id: X`:

1. La API busca el revendedor `X` en la BD local → si no existe → **error 400** (no fallback a admin)
2. Activa las credenciales del revendedor en XUI One (`XUI_RESELLER_API_URL` + su `api_key`)
3. Todas las consultas a XUI se hacen con esas credenciales → XUI solo devuelve las cuentas de ese revendedor
4. Si intenta acceder a una cuenta que no es suya → **403 Prohibido**

### Por qué es importante registrar el revendedor antes de usarlo

Si el `revendedor_id` existe en XUI One pero **no está en la BD local**, el sistema devuelve error 400 en lugar de usar credenciales de admin. Esto es intencional: evita que un revendedor no registrado pueda ver o vincular cuentas de otros revendedores.

---

## Variables de entorno relevantes (VPS `/var/www/html/.env`)

| Variable | Descripción |
|----------|-------------|
| `XUI_API_URL` | URL del API de admin en XUI One |
| `XUI_RESELLER_API_URL` | URL del API de revendedor en XUI One (scopeado) |
| `XUI_API_KEY` | API key del admin |
| `CHATBOT_API_KEY` | Token de autenticación de la API chatbot |

---

## Consultar revendedores registrados

```bash
curl -s -X GET https://solucionesdigitales.icu/api/listar-revendedores \
  -H "X-API-Key: TU_CHATBOT_API_KEY"
```
