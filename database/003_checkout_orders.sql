-- Checkout, variantes en pedidos y reservas de stock
-- MariaDB 11.x

USE `hache_tienda`;

ALTER TABLE `pedidos`
  ADD COLUMN `mp_preference_id` VARCHAR(120) NULL AFTER `moneda`,
  ADD COLUMN `stock_reservado` TINYINT(1) NOT NULL DEFAULT 0 AFTER `estado`,
  ADD COLUMN `reserva_expira_en` DATETIME NULL AFTER `stock_reservado`,
  ADD COLUMN `incidencia` TEXT NULL AFTER `reserva_expira_en`,
  ADD KEY `idx_pedidos_reserva` (`stock_reservado`, `reserva_expira_en`),
  ADD KEY `idx_pedidos_mp_preference` (`mp_preference_id`);

ALTER TABLE `pedido_items`
  ADD COLUMN `producto_variante_id` BIGINT UNSIGNED NULL AFTER `producto_id`,
  ADD COLUMN `variante_nombre` VARCHAR(180) NULL AFTER `producto_nombre`,
  ADD KEY `idx_pedido_items_variante` (`producto_variante_id`),
  ADD CONSTRAINT `fk_pedido_items_variante`
    FOREIGN KEY (`producto_variante_id`) REFERENCES `producto_variantes` (`id`)
    ON UPDATE CASCADE ON DELETE SET NULL;
