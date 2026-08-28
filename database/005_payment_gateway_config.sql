-- Configuración reutilizable y versionada de pasarelas de pago.
-- Las credenciales son inmutables: cada cambio crea una versión nueva.
-- Los pedidos conservan la versión con la que fueron creados para procesar
-- webhooks, devoluciones y contracargos aunque la cuenta activa cambie después.
-- Mientras configurado=0, la aplicación conserva el comportamiento legacy por .env.

CREATE TABLE IF NOT EXISTS pasarelas_pago_credenciales (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    proveedor VARCHAR(40) NOT NULL,
    ambiente ENUM('TEST','PRODUCTION') NOT NULL DEFAULT 'PRODUCTION',
    public_key VARCHAR(255) NULL,
    access_token_enc TEXT NOT NULL,
    webhook_secret_enc TEXT NULL,
    cuenta_id VARCHAR(80) NULL,
    cuenta_label VARCHAR(190) NULL,
    created_by VARCHAR(120) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pasarela_credenciales_proveedor (proveedor, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pasarelas_pago_config (
    proveedor VARCHAR(40) PRIMARY KEY,
    configurado TINYINT(1) NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 0,
    credencial_actual_id BIGINT UNSIGNED NULL,
    updated_by VARCHAR(120) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pasarela_config_credencial
      FOREIGN KEY (credencial_actual_id) REFERENCES pasarelas_pago_credenciales(id)
      ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO pasarelas_pago_config (proveedor, configurado, activo, credencial_actual_id)
VALUES ('MERCADO_PAGO', 0, 0, NULL)
ON DUPLICATE KEY UPDATE proveedor = VALUES(proveedor);

ALTER TABLE pedidos
  ADD COLUMN mp_credencial_id BIGINT UNSIGNED NULL AFTER mp_preference_id,
  ADD KEY idx_pedidos_mp_credencial (mp_credencial_id),
  ADD CONSTRAINT fk_pedidos_mp_credencial
    FOREIGN KEY (mp_credencial_id) REFERENCES pasarelas_pago_credenciales(id)
    ON UPDATE CASCADE ON DELETE RESTRICT;
