-- Esquema inicial de Tienda Natación
-- MariaDB 11.x

CREATE DATABASE IF NOT EXISTS `hache_tienda`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `hache_tienda`;

CREATE TABLE IF NOT EXISTS `productos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sku` VARCHAR(64) NULL,
  `slug` VARCHAR(180) NOT NULL,
  `nombre` VARCHAR(180) NOT NULL,
  `descripcion` TEXT NULL,
  `precio` DECIMAL(10,2) NOT NULL,
  `stock` INT UNSIGNED NOT NULL DEFAULT 0,
  `imagen_url` VARCHAR(500) NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_productos_sku` (`sku`),
  UNIQUE KEY `uq_productos_slug` (`slug`),
  KEY `idx_productos_activo` (`activo`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `pedidos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `numero_pedido` VARCHAR(32) NOT NULL,
  `cliente_nombre` VARCHAR(180) NOT NULL,
  `cliente_telefono` VARCHAR(32) NOT NULL,
  `cliente_email` VARCHAR(190) NULL,
  `subtotal` DECIMAL(10,2) NOT NULL,
  `total` DECIMAL(10,2) NOT NULL,
  `moneda` CHAR(3) NOT NULL DEFAULT 'MXN',
  `estado` ENUM('pending_payment','paid','cancelled','completed') NOT NULL DEFAULT 'pending_payment',
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pedidos_numero` (`numero_pedido`),
  KEY `idx_pedidos_estado` (`estado`),
  KEY `idx_pedidos_creado` (`creado_en`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `pedido_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pedido_id` BIGINT UNSIGNED NOT NULL,
  `producto_id` BIGINT UNSIGNED NULL,
  `producto_nombre` VARCHAR(180) NOT NULL,
  `precio_unitario` DECIMAL(10,2) NOT NULL,
  `cantidad` INT UNSIGNED NOT NULL,
  `total_linea` DECIMAL(10,2) NOT NULL,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pedido_items_pedido` (`pedido_id`),
  KEY `idx_pedido_items_producto` (`producto_id`),
  CONSTRAINT `fk_pedido_items_pedido`
    FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_pedido_items_producto`
    FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `pagos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pedido_id` BIGINT UNSIGNED NOT NULL,
  `proveedor` VARCHAR(40) NOT NULL DEFAULT 'mercadopago',
  `proveedor_pago_id` VARCHAR(120) NULL,
  `referencia_externa` VARCHAR(120) NULL,
  `importe` DECIMAL(10,2) NOT NULL,
  `moneda` CHAR(3) NOT NULL DEFAULT 'MXN',
  `estado` ENUM('pending','approved','rejected','cancelled','refunded') NOT NULL DEFAULT 'pending',
  `proveedor_estado` VARCHAR(80) NULL,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pagos_proveedor_pago` (`proveedor`, `proveedor_pago_id`),
  KEY `idx_pagos_pedido` (`pedido_id`),
  KEY `idx_pagos_estado` (`estado`),
  CONSTRAINT `fk_pagos_pedido`
    FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;
