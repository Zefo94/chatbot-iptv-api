<?php

return [
    'wompi' => [
        'public_key'     => $_ENV['WOMPI_PUBLIC_KEY'] ?? '',
        'private_key'    => $_ENV['WOMPI_PRIVATE_KEY'] ?? '',
        'webhook_secret' => $_ENV['WOMPI_WEBHOOK_SECRET'] ?? '',
    ],
    'mercadopago' => [
        'access_token'   => $_ENV['MERCADOPAGO_ACCESS_TOKEN'] ?? '',
        'webhook_secret' => $_ENV['MERCADOPAGO_WEBHOOK_SECRET'] ?? '',
    ],
    'paypal' => [
        'client_id'        => $_ENV['PAYPAL_CLIENT_ID'] ?? '',
        'client_secret'    => $_ENV['PAYPAL_CLIENT_SECRET'] ?? '',
        'webhook_id'       => $_ENV['PAYPAL_WEBHOOK_ID'] ?? '',        // from PayPal developer dashboard
        'mode'             => $_ENV['PAYPAL_MODE'] ?? 'sandbox',       // sandbox or live
        'price_per_credit' => (float)($_ENV['PAYPAL_PRICE_PER_CREDIT'] ?? 10.00), // USD per XUI credit
    ],
    'binance' => [
        'api_key'    => $_ENV['BINANCE_API_KEY'] ?? '',
        'secret_key' => $_ENV['BINANCE_SECRET_KEY'] ?? '',
    ],
];
