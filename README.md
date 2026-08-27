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

## Migraciones

Aplicar en orden:

1. `database/001_initial_schema.sql`
2. `database/002_product_variants_and_aletas.sql`
3. `database/003_checkout_orders.sql`

## Variables de producción

Además de las credenciales de base de datos y administración, producción requiere:

- `MERCADOPAGO_ACCESS_TOKEN`
- `MERCADOPAGO_WEBHOOK_SECRET`
- `UPLOAD_DIR`
- `UPLOAD_URL`

`MERCADOPAGO_PUBLIC_KEY` queda disponible para futuras integraciones frontend, pero Checkout Pro por redirección no la necesita para crear preferencias desde el servidor.

## Stock y pedidos

Al iniciar un checkout el servidor vuelve a validar precio, producto, variante y existencias. El stock queda reservado temporalmente antes de abrir Mercado Pago. Las reservas vencidas pueden liberarse con:

```bash
php bin/release-expired-reservations.php
```

En producción conviene ejecutar ese comando periódicamente mediante cron.

## Imágenes

El panel permite JPG, PNG y WebP. Cuando PHP GD está disponible, las imágenes nuevas se redimensionan automáticamente a un máximo de 1600 px y se recomprimen al volver al panel, sin cambiar sus URLs.
