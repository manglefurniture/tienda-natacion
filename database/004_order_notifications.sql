-- Notificación administrativa de pago aprobado
-- MariaDB 11.x

USE `hache_tienda`;

ALTER TABLE `pedidos`
  ADD COLUMN IF NOT EXISTS `notificacion_pago_en` DATETIME NULL AFTER `incidencia`;
