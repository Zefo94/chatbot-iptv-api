-- Migration 004: reseller panel support
-- panel_password (hashed) for reseller login + per-reseller price overrides

ALTER TABLE `revendedores` ADD COLUMN `panel_password` VARCHAR(255) NOT NULL DEFAULT '' AFTER `xui_api_key`;

CREATE TABLE IF NOT EXISTS `revendedor_precios` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `revendedor_id` INT NOT NULL,
  `package_id`   INT NOT NULL,
  `precio`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `moneda`       VARCHAR(10) NOT NULL DEFAULT 'EUR',
  `activo`       TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_rev_pkg` (`revendedor_id`, `package_id`),
  FOREIGN KEY (`revendedor_id`) REFERENCES `revendedores`(`id`) ON DELETE CASCADE,
  INDEX `idx_revendedor_id` (`revendedor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
