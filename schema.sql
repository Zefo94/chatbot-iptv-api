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
  `telefono` VARCHAR(20) NOT NULL UNIQUE COMMENT 'Phone number from chatbot (e.g. +573001234567)',
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
  `line_id` INT NOT NULL COMMENT 'IPTV line ID',
  `dias` INT NOT NULL DEFAULT 30 COMMENT 'Days to renew (e.g., 30, 90, 365)',
  `monto` DECIMAL(10, 2) NOT NULL COMMENT 'Amount to pay',
  `estado` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, completed, expired, failed',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_order_id` (`order_id`),
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

-- Insert dynamic seed configurations/examples for structure verification
INSERT INTO `logs` (`accion`, `request_json`, `response_json`) VALUES 
('SYSTEM_INITIALIZATION', '{"status": "ready"}', '{"message": "IPTV Schema successfully initialized"}');
