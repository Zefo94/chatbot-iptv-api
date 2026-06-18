-- SQL Schema for XUI.ONE IPTV Management Middleware
-- Compatible with MySQL 8.0+ and MariaDB 10.3+

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `logs`;
DROP TABLE IF EXISTS `pagos`;
DROP TABLE IF EXISTS `ordenes`;
DROP TABLE IF EXISTS `clientes`;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. Table for Chatbot Clients
CREATE TABLE `clientes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `telefono` VARCHAR(20) NOT NULL COMMENT 'Phone number from chatbot (e.g. +573001234567) — NOT unique; one phone can have N IPTV accounts',
  `username` VARCHAR(50) NOT NULL UNIQUE COMMENT 'IPTV line username in XUI.ONE',
  `line_id` INT NOT NULL UNIQUE COMMENT 'IPTV line ID in XUI.ONE',
  `estado` VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active, suspended, expired',
  `fecha_vencimiento` DATETIME NOT NULL COMMENT 'Expiration date from XUI.ONE',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_telefono` (`telefono`),
  INDEX `idx_username` (`username`),
  INDEX `idx_line_id` (`line_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Table for Payment Orders
CREATE TABLE `ordenes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` VARCHAR(64) NOT NULL UNIQUE COMMENT 'Unique identifier for the payment session',
  `paypal_order_id` VARCHAR(32) NULL DEFAULT NULL COMMENT 'PayPal order ID for direct API capture',
  `line_id` INT NOT NULL COMMENT 'IPTV line ID',
  `dias` INT NOT NULL DEFAULT 30 COMMENT 'Days to renew (e.g., 30, 90, 365)',
  `monto` DECIMAL(10, 2) NOT NULL COMMENT 'Amount to pay',
  `estado` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, completed, expired, failed',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_order_id` (`order_id`),
  INDEX `idx_paypal_order_id` (`paypal_order_id`),
  INDEX `idx_line_id` (`line_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Table for Payments
CREATE TABLE `pagos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` VARCHAR(64) NOT NULL COMMENT 'Unique order reference',
  `line_id` INT NOT NULL COMMENT 'IPTV line ID',
  `monto` DECIMAL(10, 2) NOT NULL COMMENT 'Paid amount',
  `estado` VARCHAR(20) NOT NULL DEFAULT 'approved' COMMENT 'approved, refunded, failed',
  `metodo_pago` VARCHAR(30) NOT NULL COMMENT 'wompi, mercadopago, paypal, binance',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`order_id`) REFERENCES `ordenes` (`order_id`) ON DELETE CASCADE,
  INDEX `idx_order_id` (`order_id`),
  INDEX `idx_line_id` (`line_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Table for System Audit & Chatbot Action Logs
CREATE TABLE `logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `accion` VARCHAR(100) NOT NULL COMMENT 'Operation or endpoint called',
  `request_json` LONGTEXT NULL COMMENT 'Request input JSON payload',
  `response_json` LONGTEXT NULL COMMENT 'Response output JSON payload',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_accion` (`accion`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Table for Resellers
CREATE TABLE `revendedores` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nombre` VARCHAR(100) NOT NULL,
  `telefono` VARCHAR(20) NOT NULL,
  `xui_user_id` INT NOT NULL UNIQUE COMMENT 'XUI.ONE reseller user ID',
  `xui_username` VARCHAR(100) NOT NULL,
  `xui_api_key` VARCHAR(255) NOT NULL,
  `panel_password` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'bcrypt hash for reseller.php login',
  `creditos_cache` INT NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_xui_user_id` (`xui_user_id`),
  INDEX `idx_telefono` (`telefono`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Table for Credit Recharges
CREATE TABLE `recargas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `revendedor_id` INT NOT NULL,
  `creditos_antes` INT NOT NULL DEFAULT 0,
  `creditos_recargados` INT NOT NULL,
  `creditos_despues` INT NOT NULL,
  `nota` VARCHAR(255) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`revendedor_id`) REFERENCES `revendedores`(`id`) ON DELETE CASCADE,
  INDEX `idx_revendedor_id` (`revendedor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Update clientes to add revendedor_id column
ALTER TABLE `clientes` ADD COLUMN IF NOT EXISTS `revendedor_id` INT NULL AFTER `estado`;
ALTER TABLE `clientes` ADD INDEX IF NOT EXISTS `idx_revendedor_id` (`revendedor_id`);

-- Allow multiple IPTV accounts per phone number (one client can have N lines)
-- Safe to run even if the unique index was already dropped
ALTER TABLE `clientes` DROP INDEX IF EXISTS `telefono`;

-- Store reseller and package on orders so the webhook can deduct credits after payment
ALTER TABLE `ordenes` ADD COLUMN IF NOT EXISTS `revendedor_id` INT NULL AFTER `estado`;
ALTER TABLE `ordenes` ADD COLUMN IF NOT EXISTS `package_id`    INT NULL AFTER `revendedor_id`;
ALTER TABLE `ordenes` ADD INDEX IF NOT EXISTS `idx_ord_revendedor` (`revendedor_id`);

-- 7. Package prices (synced from XUI.ONE, editable locally)
CREATE TABLE IF NOT EXISTS `precios_paquetes` (
  `package_id`   INT NOT NULL PRIMARY KEY COMMENT 'XUI.ONE package ID',
  `package_name` VARCHAR(150) NOT NULL DEFAULT '' COMMENT 'Name from XUI.ONE',
  `duracion`     INT NOT NULL DEFAULT 0,
  `duracion_unidad` VARCHAR(20) NOT NULL DEFAULT '',
  `dias`         INT NOT NULL DEFAULT 0,
  `creditos`     INT NOT NULL DEFAULT 0,
  `precio`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `moneda`       VARCHAR(10) NOT NULL DEFAULT 'EUR',
  `activo`       TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration: add panel_password to existing installs (safe to run repeatedly)
ALTER TABLE `revendedores` ADD COLUMN IF NOT EXISTS `panel_password` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'bcrypt hash for reseller.php login' AFTER `xui_api_key`;

-- Insert dynamic seed configurations/examples for structure verification
INSERT INTO `logs` (`accion`, `request_json`, `response_json`) VALUES
('SYSTEM_INITIALIZATION', '{"status": "ready"}', '{"message": "IPTV Schema successfully initialized"}');
