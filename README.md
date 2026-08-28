# Tienda Natación

Tienda pública independiente de Hache Natación.

## Producción

https://tienda.hnatacion.com

## Arquitectura

- Frontend público servido desde `public/`.
- Lógica de aplicación en `src/`.
- Configuración local mediante `.env`.
- Base de datos MariaDB independiente: `hache_tienda`.
- Panel administrativo en `/admin/`.
- Imágenes de catálogo subidas por el panel a `public/uploads/productos/` y fuera de Git.
- Checkout protegido en servidor con reservas de stock.
- Checkout Pro de Mercado Pago mediante Preferences API.
- Webhook firmado de Mercado Pago en `/webhooks/mercadopago.php`.
- Configuración administrable de Mercado Pago en `/admin/pasarelas.php`, con secretos cifrados y versionados en base de datos.

## Migraciones

Aplicar en orden:

1. `database/001_initial_schema.sql`
2. `database/002_product_variants_and_aletas.sql`
3. `database/003_checkout_orders.sql`
4. `database/004_order_notifications.sql`
5. `database/005_payment_gateway_config.sql`
6. `database/006_payment_gateway_credentials_hardening.sql`
7. `php bin/migrate-payment-credentials-v2.php`

La migración `005` introduce el historial de credenciales y `mp_credencial_id`. La `006` prepara `credential_ref`; el comando PHP convierte de forma idempotente cualquier sobre AES-GCM v1 existente a v2 con AAD contextual y después finaliza las restricciones de MariaDB.

**Orden de despliegue para una instalación que ya usa la tienda:** conservar sin cambios `PAYMENT_GATEWAY_CONFIG_KEY`, aplicar primero `006`, ejecutar inmediatamente `php bin/migrate-payment-credentials-v2.php` y verificar que termine sin errores. El lector mantiene compatibilidad transitoria con filas v1 sin `credential_ref`, pero toda credencial nueva se escribe en v2. Una vez finalizado el comando, MariaDB impide modificar versiones históricas.

## Variables de producción

Además de las credenciales de base de datos y administración, producción requiere:

- `PAYMENT_GATEWAY_CONFIG_KEY`: clave aleatoria larga y estable usada únicamente en el servidor para cifrar Access Token y Webhook Secret guardados desde el panel.
- `UPLOAD_DIR`
- `UPLOAD_URL`

Durante la transición siguen siendo compatibles:

- `MERCADOPAGO_ACCESS_TOKEN`
- `MERCADOPAGO_PUBLIC_KEY`
- `MERCADOPAGO_WEBHOOK_SECRET`

Mientras Mercado Pago no se haya guardado desde `/admin/pasarelas.php`, checkout y webhook siguen leyendo esas variables antiguas. En el primer guardado, la aplicación conserva las credenciales legacy como una versión histórica y vincula a ella los pedidos previos. Después de esa transición, la base de datos pasa a ser la fuente de credenciales.

## Versionado de credenciales

Cada cambio efectivo de Access Token, Webhook Secret, Public Key o tipo de credenciales crea una versión nueva. La configuración solo apunta a cuál es la versión actual; las anteriores permanecen cifradas para poder procesar correctamente eventos posteriores de pedidos antiguos.

Cada versión posee un `credential_ref` opaco e inmutable. Access Token y Webhook Secret se cifran con AES-256-GCM usando un tag completo de 16 bytes y AAD compuesto por `proveedor + credential_ref + propósito del campo`. Por ello, un ciphertext no puede moverse a otra versión, otro proveedor o de `access_token` a `webhook_secret` y seguir autenticando.

Cada pedido guarda la versión con la que se creó. El retorno del checkout usa esa versión específica. El webhook evalúa las versiones históricas hasta encontrar la firma correcta y usa el Access Token de esa misma versión para consultar el pago. Así, reembolsos y contracargos siguen funcionando aunque la cuenta activa haya cambiado después.

El checkout toma un lock compartido sobre la configuración mientras crea el pedido y el cambio de configuración usa un lock exclusivo. Esto serializa la transición inicial desde `.env` y evita que un pedido quede entre dos versiones.

MariaDB refuerza además la invariancia: la configuración apunta a `(proveedor, credencial_actual_id)` mediante FK compuesta y un trigger bloquea cualquier `UPDATE` de una fila histórica de credenciales. Las versiones se rotan insertando una fila nueva; no se renombran ni se sobreescriben.

## Cambio de cuenta de Mercado Pago

1. Verifica que `database/006_payment_gateway_credentials_hardening.sql` y `php bin/migrate-payment-credentials-v2.php` ya fueron ejecutados si la instalación proviene de la versión anterior.
2. Define una sola vez `PAYMENT_GATEWAY_CONFIG_KEY` en el `.env` del servidor. No la cambies después de guardar credenciales o no podrán descifrarse.
3. Entra a `/admin/pasarelas.php`.
4. Pega juntos el nuevo Access Token y el Webhook Secret de la misma integración. Si cambias el Access Token, el panel exige también el Webhook Secret.
5. Marca si las credenciales son de pruebas o producción y prueba la conexión.
6. Guarda la configuración. La versión anterior se conserva automáticamente para los pedidos que la usaron.

En modo `TEST`, checkout usa `sandbox_init_point`; en `PRODUCTION`, usa `init_point`. Las credenciales deben corresponder al mismo tipo seleccionado.

La URL del webhook permanece en `/webhooks/mercadopago.php`; al cambiar de aplicación/cuenta, registra esa misma URL en Mercado Pago y copia el nuevo secreto al panel.

## Stock y pedidos

Al iniciar un checkout el servidor vuelve a validar precio, producto, variante y existencias. El stock queda reservado temporalmente antes de abrir Mercado Pago. La preferencia de Mercado Pago vence junto con la reserva a los 45 minutos. Las reservas vencidas pueden liberarse con:

```bash
php bin/release-expired-reservations.php
```

En producción conviene ejecutar ese comando periódicamente mediante cron.

## Imágenes

El panel permite JPG, PNG y WebP. Cuando PHP GD está disponible, las imágenes nuevas se redimensionan automáticamente a un máximo de 1600 px y se recomprimen al volver al panel, sin cambiar sus URLs.
