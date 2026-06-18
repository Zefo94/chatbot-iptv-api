<?php

namespace App\Controllers;

use App\Services\PaymentService;
use App\Services\LoggerService;
use Exception;

/**
 * Controller to Manage Payment Orders, Transactions and Webhooks
 */
class PaymentController extends BaseController
{
    private PaymentService $paymentService;

    public function __construct()
    {
        $this->paymentService = new PaymentService();
    }

    /**
     * Create a payment intent order inside the system
     * 
     * POST /api/crear-orden
     */
    public function crearOrden(): void
    {
        $input = $this->getRequestData();

        $this->validate($input, [
            'line_id' => 'required|integer',
            'dias'    => 'required|integer',
            'monto'   => 'required|numeric'
        ]);

        $lineId = (int)$input['line_id'];
        $days = (int)$input['dias'];
        $amount = (float)$input['monto'];

        try {
            $order = $this->paymentService->createOrder($lineId, $days, $amount);
            
            LoggerService::logAction("CREAR_ORDEN", $input, $order);

            $this->success("Orden de pago creada exitosamente.", [
                'orden' => $order
            ], 201);

        } catch (Exception $e) {
            LoggerService::logFile("Error in crearOrden endpoint: " . $e->getMessage(), "error");
            $this->error("Error al generar la orden de pago: " . $e->getMessage(), 500);
        }
    }

    /**
     * Retrieve status of a payment order
     * 
     * POST /api/consultar-pago
     */
    public function consultarPago(): void
    {
        $input = $this->getRequestData();

        $this->validate($input, [
            'order_id' => 'required|string'
        ]);

        $orderId = trim($input['order_id']);

        try {
            $order = $this->paymentService->getOrderStatus($orderId);

            $responseData = [
                'order_id'           => $order['order_id'],
                'line_id'            => (int)$order['line_id'],
                'dias'               => (int)$order['dias'],
                'monto'              => (float)$order['monto'],
                'estado'             => $order['estado'],
                'fecha_vencimiento'  => $order['fecha_vencimiento'] ?? null,
                'creditos_restantes' => isset($order['creditos_restantes']) ? (int)$order['creditos_restantes'] : null,
                'revendedor_nombre'  => $order['revendedor_nombre'] ?? null,
                'created_at'         => $order['created_at'],
            ];

            LoggerService::logAction("CONSULTAR_PAGO", $input, $responseData);

            $this->success("Detalles de la orden de pago recuperados.", [
                'orden' => $responseData
            ]);

        } catch (Exception $e) {
            LoggerService::logFile("Error in consultarPago endpoint: " . $e->getMessage(), "error");
            $this->error("Error al consultar el estado de la orden: " . $e->getMessage(), 404);
        }
    }

    /**
     * Create a local order + PayPal order and return the approve URL.
     * The chatbot sends this URL to the client so they can pay.
     *
     * POST /api/crear-pago-paypal
     */
    public function crearPagoPayPal(): void
    {
        $input = $this->getRequestData();

        // Resolve line_id: accept explicit field or resolve from username
        if (empty($input['line_id']) && !empty($input['username'])) {
            $db   = \App\Database\Connection::getInstance();
            $stmt = $db->prepare("SELECT `line_id` FROM `clientes` WHERE `username` = :u LIMIT 1");
            $stmt->execute([':u' => trim($input['username'])]);
            $row  = $stmt->fetch();
            if (!$row) {
                $this->error("No se encontró la cuenta '{$input['username']}' en el sistema.", 404);
            }
            $input['line_id'] = (int)$row['line_id'];
        }

        if (empty($input['line_id'])) {
            $this->error("Debes proporcionar 'line_id' o 'username'.", 400);
        }

        try {
            // Resolve dias and monto from package_id when not explicitly provided
            $dias  = isset($input['dias'])  ? (int)$input['dias']    : 0;
            $monto = isset($input['monto']) ? (float)$input['monto'] : 0.0;

            if (!empty($input['package_id'])) {
                $pkgId = (int)$input['package_id'];
                if ($dias <= 0) {
                    $dias = $this->paymentService->daysFromPackagePublic($pkgId);
                    if ($dias <= 0) {
                        $this->error("No se pudo determinar la duración del package_id={$pkgId}.", 400);
                    }
                }
            }

            if ($dias <= 0) $this->error("Debes proporcionar 'dias' o 'package_id'.", 400);

            // Reseller price takes full priority over any fallback
            if ($monto <= 0.0 && !empty($input['package_id']) && !empty($input['revendedor_id'])) {
                try {
                    $stmt = \App\Database\Connection::getInstance()
                        ->prepare("SELECT precio, moneda FROM revendedor_precios WHERE revendedor_id = :rid AND package_id = :pid AND activo = 1 LIMIT 1");
                    $stmt->execute([':rid' => (int)$input['revendedor_id'], ':pid' => (int)$input['package_id']]);
                    $row = $stmt->fetch();
                    if ($row) {
                        $monto = (float)$row['precio'];
                        if (!isset($input['currency']) && !empty($row['moneda'])) {
                            $input['currency'] = $row['moneda'];
                        }
                    }
                } catch (\Exception $e) { /* fall through */ }
            }

            if ($monto <= 0) {
                LoggerService::logFile("crearPagoPayPal: no price found for revendedor_id=" . ($input['revendedor_id'] ?? 'null') . " package_id=" . ($input['package_id'] ?? 'null') . " monto_input=" . ($input['monto'] ?? '0'), "warning");
                $this->error("No se encontró precio configurado para este paquete.", 400);
            }

            // Pre-flight: block payment generation if reseller has insufficient credits
            if (!empty($input['revendedor_id']) && !empty($input['package_id'])) {
                try {
                    $this->paymentService->checkResellerCredits(
                        (int)$input['revendedor_id'],
                        (int)$input['package_id']
                    );
                } catch (Exception $creditEx) {
                    if (str_contains($creditEx->getMessage(), 'INSUFFICIENT_CREDITS')) {
                        LoggerService::logFile("crearPagoPayPal blocked: " . $creditEx->getMessage(), "warning");
                        $this->error("No es posible generar tu enlace de pago. Por favor contacta a tu proveedor.", 400);
                    }
                    // Other errors (XUI unreachable) — log but don't block
                    LoggerService::logFile("Credit pre-check non-fatal: " . $creditEx->getMessage(), "warning");
                }
            }

            $revendedorId = !empty($input['revendedor_id']) ? (int)$input['revendedor_id'] : null;
            $pkgIdForOrder = isset($input['package_id']) ? (int)$input['package_id'] : null;

            $order = $this->paymentService->createOrder(
                (int)$input['line_id'],
                $dias,
                $monto,
                $revendedorId,
                $pkgIdForOrder
            );

            $config      = require dirname(__DIR__, 2) . '/config/payment.php';
            $currency    = isset($input['currency']) ? strtoupper(trim($input['currency'])) : ($config['paypal']['currency'] ?? 'EUR');
            $paypalData  = $this->paymentService->createPayPalOrder($order['order_id'], $monto, $currency);

            LoggerService::logAction("CREAR_PAGO_PAYPAL", $input, array_merge($order, $paypalData));

            $this->success("Enlace de pago PayPal generado.", [
                'order_id'        => $order['order_id'],
                'paypal_order_id' => $paypalData['paypal_order_id'],
                'approve_url'     => $paypalData['approve_url'],
                'monto'           => $monto,
                'dias'            => $dias,
            ], 201);

        } catch (Exception $e) {
            LoggerService::logFile("Error in crearPagoPayPal: " . $e->getMessage(), "error");
            $this->error("Error al generar enlace de pago PayPal: " . $e->getMessage(), 500);
        }
    }

    /**
     * Handles payment gateway callbacks (Wompi, MercadoPago, PayPal, Binance Pay)
     *
     * POST /api/webhook-pago?gateway=wompi
     */
    public function webhook(): void
    {
        // Extract gateway query parameter
        $gateway = $_GET['gateway'] ?? '';
        
        if (empty($gateway)) {
            LoggerService::logFile("Webhook warning: Triggered without specifying a gateway parameter.", "warning");
            $this->error("Debe especificar el gateway en la URL (ej. /api/webhook-pago?gateway=wompi).", 400);
        }

        // Retrieve raw JSON body and all server HTTP headers
        $rawData = file_get_contents('php://input');
        $payload = json_decode($rawData, true) ?? [];
        
        // Grab all HTTP headers from the request
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') || $key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('HTTP_', '', $key))] = $value;
            }
        }

        try {
            $result = $this->paymentService->processWebhook($gateway, $payload, $_SERVER);

            // Respond to payment gateway with status 200 OK to confirm reception
            LoggerService::logAction("WEBHOOK_PAGO_SUCCESS", ['gateway' => $gateway, 'payload' => $payload], $result);
            
            $this->json([
                'success' => true,
                'message' => "Webhook procesado exitosamente por la API.",
                'result'  => $result
            ], 200);

        } catch (Exception $e) {
            $errPayload = [
                'success' => false,
                'message' => "Fallo durante el procesamiento del webhook.",
                'error'   => $e->getMessage()
            ];

            LoggerService::logAction("WEBHOOK_PAGO_FAILURE", ['gateway' => $gateway, 'payload' => $payload], $errPayload);
            
            // Return 400 or 500 error code so payment gateway retries callback later
            $this->json($errPayload, 400);
        }
    }
}
