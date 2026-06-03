-- Migration 002: add revendedor_id to clientes and ordenes
-- Uses plain ALTER TABLE (no IF NOT EXISTS — incompatible with MySQL 8)
-- Duplicate column/key errors are handled gracefully by MigrationRunner

ALTER TABLE `clientes` ADD COLUMN `revendedor_id` INT NULL AFTER `estado`;
ALTER TABLE `clientes` ADD INDEX `idx_revendedor_id` (`revendedor_id`);
ALTER TABLE `clientes` DROP INDEX `telefono`;

ALTER TABLE `ordenes` ADD COLUMN `revendedor_id` INT NULL AFTER `estado`;
ALTER TABLE `ordenes` ADD COLUMN `package_id` INT NULL AFTER `revendedor_id`;
ALTER TABLE `ordenes` ADD INDEX `idx_ord_revendedor` (`revendedor_id`)
