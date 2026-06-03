-- Migration 001: core tables
-- clientes, ordenes, pagos, logs, revendedores, recargas

CREATE TABLE IF NOT EXISTS `clientes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `telefono` VARCHAR(20) NOT NULL,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `line_id` INT NOT NULL UNIQUE,
  `estado` VARCHAR(20) NOT NULL DEFAULT 'active',
  `fecha_vencimiento` DATETIME NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_telefono` (`telefono`),
  INDEX `idx_username` (`username`),
  INDEX `idx_line_id` (`line_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ordenes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` VARCHAR(64) NOT NULL UNIQUE,
  `paypal_order_id` VARCHAR(32) NULL DEFAULT NULL,
  `line_id` INT NOT NULL,
  `dias` INT NOT NULL DEFAULT 30,
  `monto` DECIMAL(10,2) NOT NULL,
  `estado` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_order_id` (`order_id`),
  INDEX `idx_paypal_order_id` (`paypal_order_id`),
  INDEX `idx_line_id` (`line_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pagos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` VARCHAR(64) NOT NULL,
  `line_id` INT NOT NULL,
  `monto` DECIMAL(10,2) NOT NULL,
  `estado` VARCHAR(20) NOT NULL DEFAULT 'approved',
  `metodo_pago` VARCHAR(30) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`order_id`) REFERENCES `ordenes` (`order_id`) ON DELETE CASCADE,
  INDEX `idx_order_id` (`order_id`),
  INDEX `idx_line_id` (`line_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `accion` VARCHAR(100) NOT NULL,
  `request_json` LONGTEXT NULL,
  `response_json` LONGTEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_accion` (`accion`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `revendedores` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nombre` VARCHAR(100) NOT NULL,
  `telefono` VARCHAR(20) NOT NULL,
  `xui_user_id` INT NOT NULL UNIQUE,
  `xui_username` VARCHAR(100) NOT NULL,
  `xui_api_key` VARCHAR(255) NOT NULL,
  `creditos_cache` INT NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_xui_user_id` (`xui_user_id`),
  INDEX `idx_telefono` (`telefono`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recargas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `revendedor_id` INT NOT NULL,
  `creditos_antes` INT NOT NULL DEFAULT 0,
  `creditos_recargados` INT NOT NULL,
  `creditos_despues` INT NOT NULL,
  `nota` VARCHAR(255) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`revendedor_id`) REFERENCES `revendedores`(`id`) ON DELETE CASCADE,
  INDEX `idx_revendedor_id` (`revendedor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
