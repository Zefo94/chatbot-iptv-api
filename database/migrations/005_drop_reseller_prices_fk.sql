-- Migration 005: remove FK on revendedor_precios so xui_user_id can be stored as revendedor_id
-- This aligns with the standard deploy approach: revendedor_id stores xui_user_id, not local PK.

ALTER TABLE `revendedor_precios` DROP FOREIGN KEY `revendedor_precios_ibfk_1`;

-- Migrate any rows saved with local id → xui_user_id
UPDATE `revendedor_precios` rp
JOIN `revendedores` r ON r.id = rp.revendedor_id
SET rp.revendedor_id = r.xui_user_id
WHERE rp.revendedor_id != r.xui_user_id;
