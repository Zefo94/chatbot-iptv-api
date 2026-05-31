<?php

namespace App\Controllers;

class ServerInfoController extends BaseController
{
    /**
     * GET /api/info
     * Devuelve información pública del servidor: URL base, webhook,
     * versión de API, y URLs de los endpoints más importantes.
     */
    public function info(): void
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl  = "{$protocol}://{$host}";

        $paypalWebhookId = env('PAYPAL_WEBHOOK_ID', '');

        $this->json([
            'ok'        => true,
            'servidor'  => [
                'nombre'    => 'Chatbot IPTV API',
                'version'   => '1.0.0',
                'timezone'  => date_default_timezone_get(),
            ],
            'urls'      => [
                'base'                 => $baseUrl,
                'webhook_paypal'      => "{$baseUrl}/api/webhook-pago?gateway=paypal",
                'crear_pago_paypal'   => "{$baseUrl}/api/crear-pago-paypal",
                'consultar_pago'      => "{$baseUrl}/api/consultar-pago",
                'listar_paquetes'     => "{$baseUrl}/api/listar-paquetes",
                'documentacion'       => "{$baseUrl}/api/info",
            ],
            'pagos'     => [
                'paypal' => [
                    'modo'          => env('PAYPAL_MODE', 'sandbox'),
                    'webhook_id'    => $paypalWebhookId ?: null,
                    'webhook_listo' => !empty($paypalWebhookId),
                ],
            ],
            'endpoints' => [
                'POST /api/crear-pago-paypal' => [
                    'body' => [
                        'username'   => 'string (opcional, username del cliente)',
                        'line_id'    => 'int (opcional, ID de línea)',
                        'package_id' => 'int (requerido para auto-calcular monto)',
                    ],
                ],
                'GET /api/consultar-pago' => [
                    'query' => ['orden_id' => 'string'],
                ],
                'GET /api/listar-paquetes' => [],
            ],
        ]);
    }
}