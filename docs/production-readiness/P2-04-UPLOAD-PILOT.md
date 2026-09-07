# P2-04 — piloto de uploads seguros en Tienda Natación

**Estado:** INTEGRADO EN `main` / CI VERDE / pendiente despliegue y smoke real de producción  
**Proyecto:** `manglefurniture/tienda-natacion`  
**Caso real:** fotos de productos desde `/admin/producto.php`  
**Referencia reusable:** Hache Base P2-04 baseline merge `572a9512eadf5a8e3d3a9b7df404aa667eac0a04`; hardening vigente hasta `61af8705671836e1e9a535b2373d15a10d39ef4b`

## Objetivo

Validar P2-04 sobre un flujo real sin ampliar el alcance a un sistema general de documentos/imágenes ni introducir infraestructura no justificada.

El endpoint ya requería sesión administrativa y CSRF. El piloto sustituye la validación/guardado manual de imágenes por el contrato reusable de Hache Base, conservando el comportamiento de negocio de la tienda.

## Threat model y controles

| Riesgo | Control aplicado |
| --- | --- |
| filename/path traversal | nombre del cliente descartado; nombre de servidor aleatorio de 128 bits |
| MIME declarado falso | `finfo` sobre bytes; allowlist JPG/PNG/WebP |
| imagen truncada/corrupta | metadata + validación estructural y decodificación real antes de almacenar |
| imagen comprimida con dimensiones peligrosas | límites antes de decodificar: 6000 px por lado y 16 MP; PNG limita además la inflación al layout declarado |
| formato reconocido solo por cabecera | JPEG/WebP fallan cerrado si el runtime no dispone del decoder real del formato; PNG conserva validación fuerte propia con CRC/zlib/scanlines |
| archivo demasiado grande | máximo 8 MB por archivo |
| lote abusivo | máximo 6 uploads nuevos por request |
| overwrite/collision | almacenamiento create-exclusive; no overwrite silencioso |
| bytes alterados durante almacenamiento | tamaño y SHA-256 almacenados deben coincidir con source validado |
| ejecución por extensión controlada por cliente | extensión deriva exclusivamente del MIME allowlisted |
| rollback parcial | receipts de archivos nuevos se eliminan si falla la transacción posterior |
| malware conocido | scanner explícitamente `disabled`; no existe infraestructura AV justificada en este proyecto |

## Política project-owned

- MIME: `image/jpeg`, `image/png`, `image/webp`.
- Tamaño: 8 MB por archivo.
- Cantidad: 6 archivos nuevos por request.
- Dimensiones: 6000 × 6000 máximo por lado.
- Píxeles: 16,000,000 máximo.
- Scanner: `disabled` explícitamente.
- Storage físico: `UPLOAD_DIR`, actualmente destinado a imágenes de producto.
- URL pública: `UPLOAD_URL`.
- Modo de archivo del adapter local: `0644`, preservando la servibilidad pública existente.

Estos valores pertenecen a Tienda Natación y no se convierten en defaults de Hache Base.

## Evidencia automática

`tests/product-image-upload-regression.php` cubre:

- policy y allowlist del proyecto;
- descarte de filename/MIME del cliente;
- aceptación de PNG válido con datos de imagen reales;
- rechazo de PNG truncado/header-only aunque `getimagesize()` pueda leerlo;
- rechazo de PNG pequeño cuyo stream intenta inflar mucho más que sus dimensiones declaradas;
- WebP válido cuando existe decoder real y rechazo de contenedor WebP header-only sin bitstream;
- fail-closed cuando falta el decoder real de un formato no-PNG;
- hash SHA-256 source/storage;
- nombre de storage independiente del nombre del cliente;
- cleanup;
- límite de cantidad;
- mensajes de error controlados.

`.github/workflows/quality.yml` ejecuta esta regresión junto con la suite existente.

## Evidencia ya cerrada

- PR #3 integró el piloto base; merge `200151d214c3bd2e18c0c3863e5353e461465f2d`.
- PR #4 incorporó el guard de decoder real y validación fail-closed; merge `f4c291820821febd04f14c7a75f082238b69065e`.
- PR #5 corrigió el mensaje de fallback para no recomendar un formato cuyo decoder también podría faltar; merge `c8138d8fd5b563373fc365b2a8acef6bd1559042`.
- Quality post-merge run `34075867775` terminó `success` sobre `main`.
- El feedback automático técnicamente válido observado durante el ciclo quedó atendido y sin hilos técnicos pendientes antes de cada merge.

## Evidencia que falta para cerrar el piloto

Este documento no declara PASS todavía. Para cerrar la adopción real se requiere:

1. despliegue mediante el mecanismo real del proyecto;
2. smoke administrativo con una imagen válida y una inválida, sin afectar pedidos/stock;
3. confirmar que la URL almacenada se sirve como imagen y que la ruta no ejecuta scripts;
4. revisión humana final.

El repositorio solo versiona el workflow de Quality; por eso un despliegue real no puede inferirse de GitHub Actions y debe conservarse como evidencia separada.

Hasta entonces P2-04 está implementado como primitive reusable en Hache Base e integrado en `main` de Tienda Natación, pero la adopción de producción sigue abierta.
