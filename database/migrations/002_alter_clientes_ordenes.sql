-- Migration 002: add revendedor_id to clientes and ordenes, allow multiple lines per phone

ALTER TABLE `clientes`
  ADD COLUMN IF NOT EXISTS `revendedor_id` INT NULL AFTER `estado`;

ALTER TABLE `clientes`
  ADD INDEX IF NOT EXISTS `idx_revendedor_id` (`revendedor_id`);

ALTER TABLE `clientes`
  DROP INDEX IF EXISTS `telefono`;

ALTER TABLE `ordenes`
  ADD COLUMN IF NOT EXISTS `revendedor_id` INT NULL AFTER `estado`;

ALTER TABLE `ordenes`
  ADD COLUMN IF NOT EXISTS `package_id` INT NULL AFTER `revendedor_id`;

ALTER TABLE `ordenes`
  ADD INDEX IF NOT EXISTS `idx_ord_revendedor` (`revendedor_id`)
