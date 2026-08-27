<?php
declare(strict_types=1);

final class ImageOptimizer
{
    public static function optimizeDirectory(string $directory, int $maxDimension = 1600): int
    {
        if (!extension_loaded('gd') || !is_dir($directory) || !is_writable($directory)) {
            return 0;
        }

        $optimized = 0;
        $files = glob(rtrim($directory, '/') . '/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE) ?: [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $marker = $file . '.optimized';
            $mtime = (string) @filemtime($file);
            if (is_file($marker) && trim((string) @file_get_contents($marker)) === $mtime) {
                continue;
            }

            try {
                if (self::optimizeFile($file, $maxDimension)) {
                    $optimized++;
                }
                @file_put_contents($marker, (string) @filemtime($file), LOCK_EX);
                @chmod($marker, 0644);
            } catch (Throwable $e) {
                error_log('[tienda-natacion][images] ' . $e->getMessage());
            }
        }

        return $optimized;
    }

    private static function optimizeFile(string $file, int $maxDimension): bool
    {
        $info = @getimagesize($file);
        if (!is_array($info) || empty($info[0]) || empty($info[1]) || empty($info['mime'])) {
            return false;
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        $mime = (string) $info['mime'];
        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($file),
            'image/png' => @imagecreatefrompng($file),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
            default => false,
        };
        if (!$source instanceof GdImage) {
            return false;
        }

        $scale = min(1, $maxDimension / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$target instanceof GdImage) {
            imagedestroy($source);
            return false;
        }

        if (in_array($mime, ['image/png', 'image/webp'], true)) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
        } else {
            $white = imagecolorallocate($target, 255, 255, 255);
            imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $white);
        }

        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height
        );

        $temporary = $file . '.tmp-' . bin2hex(random_bytes(4));
        $saved = match ($mime) {
            'image/jpeg' => imagejpeg($target, $temporary, 82),
            'image/png' => imagepng($target, $temporary, 7),
            'image/webp' => function_exists('imagewebp') ? imagewebp($target, $temporary, 82) : false,
            default => false,
        };

        imagedestroy($source);
        imagedestroy($target);

        if (!$saved || !is_file($temporary)) {
            @unlink($temporary);
            return false;
        }

        if (!@rename($temporary, $file)) {
            @unlink($temporary);
            return false;
        }

        @chmod($file, 0644);
        return true;
    }
}
