-- Preparación de almacenamiento v2 para credenciales de pago.
--
-- Esta migración es deliberadamente de dos pasos porque los ciphertexts v1
-- deben descifrarse y recifrarse con PAYMENT_GATEWAY_CONFIG_KEY antes de poder
-- activar AAD e inmutabilidad total.
--
-- 1) aplicar este SQL;
-- 2) ejecutar: php bin/migrate-payment-credentials-v2.php
--
-- El comando PHP es idempotente y finaliza:
-- - recifrado v1 -> v2 con AAD;
-- - credential_ref NOT NULL y único por proveedor;
-- - FK compuesta proveedor + credencial actual;
-- - trigger que impide UPDATE de cualquier versión histórica.

ALTER TABLE pasarelas_pago_credenciales
  ADD COLUMN IF NOT EXISTS credential_ref VARCHAR(64) NULL AFTER proveedor;
