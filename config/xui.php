<?php

return [
    'api_url'            => $_ENV['XUI_API_URL'] ?? '',
    'api_key'            => $_ENV['XUI_API_KEY'] ?? '',
    'username'           => $_ENV['XUI_USERNAME'] ?? '',
    'password'           => $_ENV['XUI_PASSWORD'] ?? '',
    'reseller_api_url'   => $_ENV['XUI_RESELLER_API_URL'] ?? '',
    'default_package_id' => (int)($_ENV['XUI_DEFAULT_PACKAGE_ID'] ?? 1),
    'default_connections'=> (int)($_ENV['XUI_DEFAULT_MAX_CONNECTIONS'] ?? 1),
];
