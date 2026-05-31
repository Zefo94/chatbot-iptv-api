<?php

/**
 * API Endpoint Catalog (metadata for the Dashboard / API Explorer)
 *
 * Esta es la "wiki" viva de tu API. Cada entrada describe un endpoint:
 * cómo llamarlo, qué campos acepta y un ejemplo. El panel en
 * public/dashboard.php lee este archivo y genera los formularios y la
 * documentación automáticamente.
 *
 * PARA DAR DE ALTA UN ENDPOINT NUEVO:
 *   1. Regístralo en routes/api.php  (HTTP verb -> ruta -> [Controlador, método]).
 *   2. Añade su descripción aquí abajo copiando un bloque y ajustándolo.
 *   3. Recarga el dashboard: aparecerá solo.
 *
 * Tipos de campo soportados: 'string', 'integer', 'number', 'json'.
 * 'in' indica dónde viaja el campo: 'body' (JSON) o 'query' (?param=).
 */

return [
    // ───────────────────────────── USUARIOS ─────────────────────────────
    [
        'id'          => 'buscar-usuario',
        'group'       => 'Usuarios',
        'method'      => 'POST',
        'path'        => '/api/buscar-usuario',
        'title'       => 'Buscar usuario por teléfono',
        'description' => 'Busca un cliente registrado en la base de datos local a partir de su número de teléfono.',
        'auth'        => true,
        'fields'      => [
            ['name' => 'telefono', 'label' => 'Teléfono', 'type' => 'string', 'in' => 'body', 'required' => true, 'example' => '+573001234567', 'help' => 'Número tal como lo manda el chatbot.'],
        ],
    ],

    // ───────────────────────────── LÍNEAS ───────────────────────────────
    [
        'id'          => 'consultar-linea',
        'group'       => 'Líneas',
        'method'      => 'POST',
        'path'        => '/api/consultar-linea',
        'title'       => 'Consultar línea',
        'description' => 'Consulta el estado de una línea IPTV directamente en el panel XUI.ONE. Puedes buscar por line_id (directo al panel) o por username (se resuelve con la tabla local clientes).',
        'auth'        => true,
        'xui'         => 'get_line',
        'note'        => 'Envía SOLO uno: line_id o username. Si usas username, debe existir en la tabla local clientes.',
        'fields'      => [
            ['name' => 'line_id', 'label' => 'Line ID', 'type' => 'integer', 'in' => 'body', 'required' => false, 'example' => 5236, 'help' => 'ID de la línea en el panel.'],
            ['name' => 'username', 'label' => 'Username', 'type' => 'string', 'in' => 'body', 'required' => false, 'example' => '', 'help' => 'Alternativa al line_id (requiere registro local).'],
        ],
    ],
    [
        'id'          => 'crear-linea',
        'group'       => 'Líneas',
        'method'      => 'POST',
        'path'        => '/api/crear-linea',
        'title'       => 'Crear línea',
        'description' => 'Provisiona una línea IPTV nueva en XUI.ONE y la vincula a un teléfono en la base local.',
        'auth'        => true,
        'xui'         => 'user (action_type=add)',
        'danger'      => true,
        'note'        => 'CUIDADO: esto crea una línea REAL en tu panel.',
        'fields'      => [
            ['name' => 'telefono', 'label' => 'Teléfono', 'type' => 'string', 'in' => 'body', 'required' => true, 'example' => '+573009998877'],
            ['name' => 'username', 'label' => 'Username', 'type' => 'string', 'in' => 'body', 'required' => true, 'example' => 'cliente_nuevo'],
            ['name' => 'password', 'label' => 'Password', 'type' => 'string', 'in' => 'body', 'required' => true, 'example' => 'clave_segura'],
            ['name' => 'package_id', 'label' => 'Package ID', 'type' => 'integer', 'in' => 'body', 'required' => false, 'example' => 1, 'help' => 'Opcional. Por defecto el del .env.'],
            ['name' => 'max_connections', 'label' => 'Conexiones', 'type' => 'integer', 'in' => 'body', 'required' => false, 'example' => 1, 'help' => 'Opcional. Por defecto el del .env.'],
        ],
    ],
    [
        'id'          => 'renovar-linea',
        'group'       => 'Líneas',
        'method'      => 'POST',
        'path'        => '/api/renovar-linea',
        'title'       => 'Renovar línea',
        'description' => 'Extiende la fecha de vencimiento de una línea N días y la reactiva.',
        'auth'        => true,
        'xui'         => 'edit_line (exp_date) + enable_line',
        'fields'      => [
            ['name' => 'line_id', 'label' => 'Line ID', 'type' => 'integer', 'in' => 'body', 'required' => true, 'example' => 5236],
            ['name' => 'dias', 'label' => 'Días', 'type' => 'integer', 'in' => 'body', 'required' => true, 'example' => 30],
        ],
    ],
    [
        'id'          => 'suspender-linea',
        'group'       => 'Líneas',
        'method'      => 'POST',
        'path'        => '/api/suspender-linea',
        'title'       => 'Suspender línea',
        'description' => 'Desactiva (suspende) una línea en el panel y marca el cliente como suspended.',
        'auth'        => true,
        'xui'         => 'disable_line',
        'fields'      => [
            ['name' => 'line_id', 'label' => 'Line ID', 'type' => 'integer', 'in' => 'body', 'required' => true, 'example' => 5236],
        ],
    ],
    [
        'id'          => 'activar-linea',
        'group'       => 'Líneas',
        'method'      => 'POST',
        'path'        => '/api/activar-linea',
        'title'       => 'Activar línea',
        'description' => 'Reactiva una línea suspendida.',
        'auth'        => true,
        'xui'         => 'enable_line',
        'fields'      => [
            ['name' => 'line_id', 'label' => 'Line ID', 'type' => 'integer', 'in' => 'body', 'required' => true, 'example' => 5236],
        ],
    ],
    [
        'id'          => 'cambiar-password',
        'group'       => 'Líneas',
        'method'      => 'POST',
        'path'        => '/api/cambiar-password',
        'title'       => 'Cambiar contraseña',
        'description' => 'Cambia la contraseña de acceso de una línea IPTV.',
        'auth'        => true,
        'xui'         => 'edit_line (password)',
        'fields'      => [
            ['name' => 'line_id', 'label' => 'Line ID', 'type' => 'integer', 'in' => 'body', 'required' => true, 'example' => 5236],
            ['name' => 'password', 'label' => 'Nueva contraseña', 'type' => 'string', 'in' => 'body', 'required' => true, 'example' => 'nueva_clave_123'],
        ],
    ],
    [
        'id'          => 'cambiar-conexiones',
        'group'       => 'Líneas',
        'method'      => 'POST',
        'path'        => '/api/cambiar-conexiones',
        'title'       => 'Cambiar conexiones',
        'description' => 'Cambia el número de conexiones simultáneas permitidas para una línea.',
        'auth'        => true,
        'xui'         => 'edit_line (max_connections)',
        'fields'      => [
            ['name' => 'line_id', 'label' => 'Line ID', 'type' => 'integer', 'in' => 'body', 'required' => true, 'example' => 5236],
            ['name' => 'conexiones', 'label' => 'Conexiones', 'type' => 'integer', 'in' => 'body', 'required' => true, 'example' => 2],
        ],
    ],

    // ───────────────────────────── PAGOS ────────────────────────────────
    [
        'id'          => 'crear-orden',
        'group'       => 'Pagos',
        'method'      => 'POST',
        'path'        => '/api/crear-orden',
        'title'       => 'Crear orden de pago',
        'description' => 'Genera una orden de pago pendiente para renovar una línea. Verifica antes que la línea exista en XUI.ONE.',
        'auth'        => true,
        'fields'      => [
            ['name' => 'line_id', 'label' => 'Line ID', 'type' => 'integer', 'in' => 'body', 'required' => true, 'example' => 5236],
            ['name' => 'dias', 'label' => 'Días', 'type' => 'integer', 'in' => 'body', 'required' => true, 'example' => 30],
            ['name' => 'monto', 'label' => 'Monto', 'type' => 'number', 'in' => 'body', 'required' => true, 'example' => 15000.00],
        ],
    ],
    [
        'id'          => 'consultar-pago',
        'group'       => 'Pagos',
        'method'      => 'POST',
        'path'        => '/api/consultar-pago',
        'title'       => 'Consultar pago / orden',
        'description' => 'Consulta el estado actual de una orden de pago por su order_id.',
        'auth'        => true,
        'fields'      => [
            ['name' => 'order_id', 'label' => 'Order ID', 'type' => 'string', 'in' => 'body', 'required' => true, 'example' => 'ORD-XXXXXXXXXXXXXXXX'],
        ],
    ],
    [
        'id'          => 'webhook-pago',
        'group'       => 'Pagos',
        'method'      => 'POST',
        'path'        => '/api/webhook-pago',
        'title'       => 'Webhook de pago (entrante)',
        'description' => 'Endpoint que llaman las pasarelas (Wompi, MercadoPago, PayPal, Binance) cuando hay un pago. NO requiere X-API-Key. El cuerpo es el JSON crudo de la pasarela y el gateway va en la URL.',
        'auth'        => false,
        'note'        => 'Normalmente lo invoca la pasarela, no tú. Aquí puedes simularlo.',
        'fields'      => [
            ['name' => 'gateway', 'label' => 'Gateway', 'type' => 'string', 'in' => 'query', 'required' => true, 'example' => 'wompi', 'help' => 'wompi | mercadopago | paypal | binance'],
        ],
        'rawBody'     => true,
        'rawExample'  => "{\n  \"event\": \"transaction.updated\",\n  \"data\": {\n    \"transaction\": {\n      \"id\": \"tx-123\",\n      \"status\": \"APPROVED\",\n      \"amount_in_cents\": 1500000,\n      \"currency\": \"COP\",\n      \"reference\": \"ORD-XXXXXXXXXXXXXXXX\"\n    }\n  }\n}",
    ],

    // ──────────────────── PANEL XUI (SOLO LECTURA) ──────────────────────
    // Todas usan el endpoint genérico /api/panel-consultar con un allowlist.
    [
        'id' => 'panel-paquetes', 'group' => 'Panel XUI (lectura)', 'method' => 'POST',
        'path' => '/api/panel-consultar', 'title' => 'Paquetes', 'auth' => true, 'xui' => 'get_packages',
        'description' => 'Lista los paquetes/planes configurados en el panel.',
        'fields' => [
            ['name' => 'action', 'label' => 'Acción', 'type' => 'string', 'in' => 'body', 'required' => true, 'default' => 'get_packages'],
        ],
    ],
    [
        'id' => 'panel-lineas', 'group' => 'Panel XUI (lectura)', 'method' => 'POST',
        'path' => '/api/panel-consultar', 'title' => 'Líneas', 'auth' => true, 'xui' => 'get_lines',
        'description' => 'Lista las líneas IPTV del panel. La respuesta puede ser grande.',
        'fields' => [
            ['name' => 'action', 'label' => 'Acción', 'type' => 'string', 'in' => 'body', 'required' => true, 'default' => 'get_lines'],
        ],
    ],
    [
        'id' => 'panel-usuarios', 'group' => 'Panel XUI (lectura)', 'method' => 'POST',
        'path' => '/api/panel-consultar', 'title' => 'Usuarios (admin)', 'auth' => true, 'xui' => 'get_users',
        'description' => 'Lista los usuarios administradores / resellers del panel.',
        'fields' => [
            ['name' => 'action', 'label' => 'Acción', 'type' => 'string', 'in' => 'body', 'required' => true, 'default' => 'get_users'],
        ],
    ],
    [
        'id' => 'panel-bouquets', 'group' => 'Panel XUI (lectura)', 'method' => 'POST',
        'path' => '/api/panel-consultar', 'title' => 'Bouquets', 'auth' => true, 'xui' => 'get_bouquets',
        'description' => 'Lista los bouquets (grupos de canales) disponibles.',
        'fields' => [
            ['name' => 'action', 'label' => 'Acción', 'type' => 'string', 'in' => 'body', 'required' => true, 'default' => 'get_bouquets'],
        ],
    ],
    [
        'id' => 'panel-grupos', 'group' => 'Panel XUI (lectura)', 'method' => 'POST',
        'path' => '/api/panel-consultar', 'title' => 'Grupos', 'auth' => true, 'xui' => 'get_groups',
        'description' => 'Lista los grupos de usuarios del panel.',
        'fields' => [
            ['name' => 'action', 'label' => 'Acción', 'type' => 'string', 'in' => 'body', 'required' => true, 'default' => 'get_groups'],
        ],
    ],
    [
        'id' => 'panel-stats', 'group' => 'Panel XUI (lectura)', 'method' => 'POST',
        'path' => '/api/panel-consultar', 'title' => 'Estadísticas del servidor', 'auth' => true, 'xui' => 'get_server_stats',
        'description' => 'Estadísticas del servidor (CPU, RAM, red…). Es específica de servidor: envía server_id.',
        'note' => 'Si no sabes el server_id, prueba 1 (el servidor principal). Consulta "Servidores" más abajo para ver los IDs.',
        'fields' => [
            ['name' => 'action', 'label' => 'Acción', 'type' => 'string', 'in' => 'body', 'required' => true, 'default' => 'get_server_stats'],
            ['name' => 'server_id', 'label' => 'Server ID', 'type' => 'integer', 'in' => 'body', 'required' => false, 'default' => 1],
        ],
    ],
    [
        'id' => 'panel-servidores', 'group' => 'Panel XUI (lectura)', 'method' => 'POST',
        'path' => '/api/panel-consultar', 'title' => 'Servidores', 'auth' => true, 'xui' => 'get_servers',
        'description' => 'Lista los servidores (load balancers) registrados y sus IDs.',
        'fields' => [
            ['name' => 'action', 'label' => 'Acción', 'type' => 'string', 'in' => 'body', 'required' => true, 'default' => 'get_servers'],
        ],
    ],
    [
        'id' => 'panel-conexiones', 'group' => 'Panel XUI (lectura)', 'method' => 'POST',
        'path' => '/api/panel-consultar', 'title' => 'Conexiones en vivo', 'auth' => true, 'xui' => 'live_connections',
        'description' => 'Muestra las conexiones activas en este momento.',
        'fields' => [
            ['name' => 'action', 'label' => 'Acción', 'type' => 'string', 'in' => 'body', 'required' => true, 'default' => 'live_connections'],
        ],
    ],
    [
        'id' => 'panel-libre', 'group' => 'Panel XUI (lectura)', 'method' => 'POST',
        'path' => '/api/panel-consultar', 'title' => 'Consulta libre', 'auth' => true,
        'description' => 'Ejecuta cualquier acción de SOLO LECTURA permitida por el allowlist. Si pides un registro concreto, añade su id.',
        'note' => 'Acciones bloqueadas (create/edit/delete/start/stop/kill/mysql_query…) devolverán 400.',
        'fields' => [
            ['name' => 'action', 'label' => 'Acción', 'type' => 'string', 'in' => 'body', 'required' => true, 'default' => 'get_packages', 'help' => 'Ej: get_line, get_user, get_streams, get_categories, activity_logs, get_free_space…'],
            ['name' => 'id', 'label' => 'ID (opcional)', 'type' => 'integer', 'in' => 'body', 'required' => false, 'example' => 5236, 'help' => 'Para acciones de un solo registro (get_line, get_user…).'],
        ],
    ],
];
