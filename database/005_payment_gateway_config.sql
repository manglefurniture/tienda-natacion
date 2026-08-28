-- Configuración reutilizable de pasarelas de pago.
-- Los secretos se almacenan cifrados por la aplicación; nunca en texto plano.

CREATE TABLE IF NOT EXISTS pasarelas_pago_config (
    proveedor VARCHAR(40) PRIMARY KEY,
    activo TINYINT(1) NOT NULL DEFAULT 0,
    ambiente ENUM('TEST','PRODUCTION') NOT NULL DEFAULT 'PRODUCTION',
    public_key VARCHAR(255) NULL,
    access_token_enc TEXT NULL,
    webhook_secret_enc TEXT NULL,
    updated_by VARCHAR(120) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO pasarelas_pago_config (proveedor, activo, ambiente)
VALUES ('MERCADO_PAGO', 0, 'PRODUCTION')
ON DUPLICATE KEY UPDATE proveedor = VALUES(proveedor);
