-- Variantes, galería y primer producto real de Tienda Natación
-- MariaDB 11.x

USE `hache_tienda`;

CREATE TABLE IF NOT EXISTS `producto_variantes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `producto_id` BIGINT UNSIGNED NOT NULL,
  `codigo` VARCHAR(64) NOT NULL,
  `nombre` VARCHAR(80) NOT NULL,
  `rango_mx` VARCHAR(80) NULL,
  `stock` INT UNSIGNED NOT NULL DEFAULT 0,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_producto_variantes_codigo` (`producto_id`, `codigo`),
  KEY `idx_producto_variantes_producto` (`producto_id`),
  KEY `idx_producto_variantes_activo` (`activo`),
  CONSTRAINT `fk_producto_variantes_producto`
    FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `producto_imagenes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `producto_id` BIGINT UNSIGNED NOT NULL,
  `url` VARCHAR(500) NOT NULL,
  `alt_text` VARCHAR(220) NULL,
  `orden` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_producto_imagenes_orden` (`producto_id`, `orden`),
  KEY `idx_producto_imagenes_producto` (`producto_id`),
  CONSTRAINT `fk_producto_imagenes_producto`
    FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO `productos`
  (`sku`, `slug`, `nombre`, `descripcion`, `precio`, `stock`, `imagen_url`, `activo`)
VALUES
  (
    'ALETAS-CORTAS-NEGRO',
    'aletas-cortas',
    'Aletas Cortas',
    'Aletas cortas para entrenamiento de natación. Disponibles en tallas M y L.',
    399.00,
    10,
    '/assets/productos/Screenshot_2026-08-26-14-49-16-803_com.mercadolibre-edit.jpg',
    1
  )
ON DUPLICATE KEY UPDATE
  `nombre` = VALUES(`nombre`),
  `descripcion` = VALUES(`descripcion`),
  `precio` = VALUES(`precio`),
  `stock` = VALUES(`stock`),
  `imagen_url` = VALUES(`imagen_url`),
  `activo` = VALUES(`activo`);

SET @producto_id = (
  SELECT `id` FROM `productos` WHERE `slug` = 'aletas-cortas' LIMIT 1
);

INSERT INTO `producto_variantes`
  (`producto_id`, `codigo`, `nombre`, `rango_mx`, `stock`, `activo`)
VALUES
  (@producto_id, 'M', 'Talla M', '22 a 24 MX', 5, 1),
  (@producto_id, 'L', 'Talla L', '24.5 a 26 MX', 5, 1)
ON DUPLICATE KEY UPDATE
  `nombre` = VALUES(`nombre`),
  `rango_mx` = VALUES(`rango_mx`),
  `stock` = VALUES(`stock`),
  `activo` = VALUES(`activo`);

INSERT INTO `producto_imagenes`
  (`producto_id`, `url`, `alt_text`, `orden`)
VALUES
  (
    @producto_id,
    '/assets/productos/Screenshot_2026-08-26-14-49-16-803_com.mercadolibre-edit.jpg',
    'Aletas cortas negras para natación, vista frontal y lateral',
    1
  ),
  (
    @producto_id,
    '/assets/productos/Screenshot_2026-08-26-14-49-28-634_com.mercadolibre-edit.jpg',
    'Aletas cortas negras para natación, vista de pala y suela',
    2
  )
ON DUPLICATE KEY UPDATE
  `url` = VALUES(`url`),
  `alt_text` = VALUES(`alt_text`);
