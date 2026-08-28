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
- Configuración administrable de Mercado Pago en `/admin/pasarelas.php`, con secretos cifrados en base de datos.

## Migraciones

Aplicar en orden:

1. `database/001_initial_schema.sql`
2. `database/002_product_variants_and_aletas.sql`
3. `database/003_checkout_orders.sql`
4. `database/004_order_notifications.sql`
5. `database/005_payment_gateway_config.sql`

## Variables de producción

Además de las credenciales de base de datos y administración, producción requiere:

- `PAYMENT_GATEWAY_CONFIG_KEY`: clave aleatoria larga y estable usada únicamente en el servidor para cifrar Access Token y Webhook Secret guardados desde el panel.
- `UPLOAD_DIR`
- `UPLOAD_URL`

Durante la transición siguen siendo compatibles:

- `MERCADOPAGO_ACCESS_TOKEN`
- `MERCADOPAGO_PUBLIC_KEY`
- `MERCADOPAGO_WEBHOOK_SECRET`

Mientras Mercado Pago no se haya guardado desde `/admin/pasarelas.php`, checkout y webhook siguen leyendo esas variables antiguas. Después del primer guardado, la base de datos pasa a ser la única fuente de las credenciales de Mercado Pago; ya no se mezclan secretos nuevos con valores antiguos del `.env`.

`MERCADOPAGO_PUBLIC_KEY` queda disponible para futuras integraciones frontend, pero Checkout Pro por redirección no la necesita para crear preferencias desde el servidor.

## Cambio de cuenta de Mercado Pago

1. Aplica `database/005_payment_gateway_config.sql`.
2. Define una sola vez `PAYMENT_GATEWAY_CONFIG_KEY` en el `.env` del servidor. No la cambies después de guardar credenciales o no podrán descifrarse.
3. Entra a `/admin/pasarelas.php`.
4. Pega juntos el nuevo Access Token y el Webhook Secret de la misma integración. Si cambias el Access Token, el panel exige también el Webhook Secret para evitar mezclar cuentas.
5. Marca si las credenciales son de pruebas o producción y prueba la conexión.
6. Guarda la configuración.

En Checkout Pro, Mercado Pago determina si una operación es de prueba o real mediante las credenciales utilizadas. La selección del panel es una etiqueta operativa y no cambia la URL de la API.

La URL del webhook permanece en `/webhooks/mercadopago.php`; al cambiar de aplicación/cuenta, registra esa misma URL en Mercado Pago y copia el nuevo secreto al panel.

## Stock y pedidos

Al iniciar un checkout el servidor vuelve a validar precio, producto, variante y existencias. El stock queda reservado temporalmente antes de abrir Mercado Pago. Las reservas vencidas pueden liberarse con:

```bash
php bin/release-expired-reservations.php
```

En producción conviene ejecutar ese comando periódicamente mediante cron.

## Imágenes

El panel permite JPG, PNG y WebP. Cuando PHP GD está disponible, las imágenes nuevas se redimensionan automáticamente a un máximo de 1600 px y se recomprimen al volver al panel, sin cambiar sus URLs.
