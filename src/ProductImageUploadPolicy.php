<?php

declare(strict_types=1);

use HacheBase\Integrations\Upload\UploadPolicy;
use HacheBase\Integrations\Upload\UploadRejected;

final class ProductImageUploadPolicy
{
    public const MAX_BYTES = 8 * 1024 * 1024;
    public const MAX_FILES_PER_REQUEST = 6;
    public const MAX_WIDTH = 6000;
    public const MAX_HEIGHT = 6000;
    public const MAX_PIXELS = 16000000;

    public static function create(): UploadPolicy
    {
        return new UploadPolicy(
            allowedMimeExtensions: [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ],
            maxBytes: self::MAX_BYTES,
            maxFiles: self::MAX_FILES_PER_REQUEST,
            maxImageWidth: self::MAX_WIDTH,
            maxImageHeight: self::MAX_HEIGHT,
            maxImagePixels: self::MAX_PIXELS,
            scannerMode: 'disabled',
        );
    }

    public static function userMessage(UploadRejected $error): string
    {
        return match ($error->reason) {
            'too_many_files' => 'Puedes subir hasta 6 fotos a la vez.',
            'too_large' => 'Cada foto debe pesar máximo 8 MB.',
            'image_dimensions_exceeded', 'image_pixels_exceeded' => 'La resolución de una foto es demasiado grande. Usa una imagen de hasta 6000 px por lado y 16 megapíxeles.',
            'mime_not_allowed', 'invalid_image_content', 'image_mime_mismatch' => 'Solo se permiten imágenes JPG, PNG o WebP válidas y completas.',
            'invalid_transport_origin', 'malformed_transport', 'transport_error' => 'Una de las fotos no pudo subirse correctamente. Inténtalo de nuevo.',
            default => 'No se pudo validar una de las fotos. Inténtalo con otra imagen.',
        };
    }
}
