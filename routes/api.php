<?php

/**
 * API Routes Registry
 * 
 * Maps HTTP Verb -> URI path -> [ControllerClass, MethodName]
 */
return [
    'POST' => [
        '/api/buscar-usuario'     => ['App\Controllers\UserController', 'buscar'],
        '/api/listar-mis-lineas'  => ['App\Controllers\UserController', 'listarMisLineas'],
        '/api/consultar-linea'    => ['App\Controllers\LineController', 'consultar'],
        '/api/crear-linea'        => ['App\Controllers\LineController', 'crear'],
        '/api/renovar-linea'      => ['App\Controllers\LineController', 'renovar'],
        '/api/suspender-linea'    => ['App\Controllers\LineController', 'suspender'],
        '/api/activar-linea'      => ['App\Controllers\LineController', 'activar'],
        '/api/eliminar-linea'     => ['App\Controllers\LineController', 'eliminar'],
        '/api/cambiar-password'   => ['App\Controllers\LineController', 'cambiarPassword'],
        '/api/cambiar-conexiones' => ['App\Controllers\LineController', 'cambiarConexiones'],
        '/api/crear-orden'        => ['App\Controllers\PaymentController', 'crearOrden'],
        '/api/crear-pago-paypal'  => ['App\Controllers\PaymentController', 'crearPagoPayPal'],
        '/api/consultar-pago'     => ['App\Controllers\PaymentController', 'consultarPago'],
        '/api/webhook-pago'       => ['App\Controllers\PaymentController', 'webhook'],
        '/api/panel-consultar'    => ['App\Controllers\PanelController', 'consultar'],
        '/api/listar-paquetes'    => ['App\Controllers\PanelController', 'listarPaquetes'],
        '/api/crear-revendedor'   => ['App\Controllers\ResellerController', 'crear'],
        '/api/listar-revendedores'=> ['App\Controllers\ResellerController', 'listar'],
        '/api/saldo-revendedor'   => ['App\Controllers\ResellerController', 'saldo'],
        '/api/recargar-creditos'  => ['App\Controllers\ResellerController', 'recargar'],
        '/api/historial-recargas' => ['App\Controllers\ResellerController', 'historial'],
        '/api/eliminar-revendedor'=> ['App\Controllers\ResellerController', 'eliminar'],
    ]
];
