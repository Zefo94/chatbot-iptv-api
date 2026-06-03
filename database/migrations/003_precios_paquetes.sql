-- Migration 003: package prices table (synced from XUI.ONE)

CREATE TABLE IF NOT EXISTS `precios_paquetes` (
  `package_id`      INT NOT NULL PRIMARY KEY,
  `package_name`    VARCHAR(150) NOT NULL DEFAULT '',
  `duracion`        INT NOT NULL DEFAULT 0,
  `duracion_unidad` VARCHAR(20) NOT NULL DEFAULT '',
  `dias`            INT NOT NULL DEFAULT 0,
  `creditos`        INT NOT NULL DEFAULT 0,
  `precio`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `moneda`          VARCHAR(10) NOT NULL DEFAULT 'EUR',
  `activo`          TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
