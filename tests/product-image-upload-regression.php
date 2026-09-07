<?php

declare(strict_types=1);

use HacheBase\Integrations\Upload\LocalUploadStorage;
use HacheBase\Integrations\Upload\PhpUploadAdapter;
use HacheBase\Integrations\Upload\UploadRejected;
use HacheBase\Integrations\Upload\UploadService;

require_once dirname(__DIR__) . '/config/bootstrap.php';

function uploadCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "PRODUCT_IMAGE_UPLOAD_FAIL {$message}\n");
        exit(1);
    }
}

/** @param string|list<string> $reason */
function uploadExpectRejected(string|array $reason, callable $callback): void
{
    $reasons = is_array($reason) ? $reason : [$reason];
    try {
        $callback();
    } catch (UploadRejected $error) {
        uploadCheck(in_array($error->reason, $reasons, true), 'unexpected rejection=' . $error->reason);
        return;
    }
    uploadCheck(false, 'expected rejection=' . implode('|', $reasons));
}

function uploadPngChunk(string $type, string $data): string
{
    $crc = crc32($type . $data);
    return pack('N', strlen($data)) . $type . $data . pack('N', $crc & 0xffffffff);
}

function uploadValidPng(int $width = 32, int $height = 24): string
{
    uploadCheck(function_exists('gzcompress'), 'zlib unavailable');
    $scanline = "\x00" . str_repeat("\x00", $width * 4);
    $compressed = gzcompress(str_repeat($scanline, $height), 9);
    uploadCheck(is_string($compressed) && $compressed !== '', 'could not build PNG fixture');

    return "\x89PNG\r\n\x1a\n"
        . uploadPngChunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
        . uploadPngChunk('IDAT', $compressed)
        . uploadPngChunk('IEND', '');
}

function uploadHeaderOnlyPng(): string
{
    return "\x89PNG\r\n\x1a\n"
        . uploadPngChunk('IHDR', pack('NNCCCCC', 32, 24, 8, 6, 0, 0, 0))
        . uploadPngChunk('IEND', '');
}

function uploadInflationBombPng(): string
{
    uploadCheck(function_exists('gzcompress'), 'zlib unavailable');
    $compressed = gzcompress(str_repeat("\x00", 1024 * 1024), 9);
    uploadCheck(is_string($compressed) && $compressed !== '', 'could not build inflation fixture');

    return "\x89PNG\r\n\x1a\n"
        . uploadPngChunk('IHDR', pack('NNCCCCC', 1, 1, 8, 6, 0, 0, 0))
        . uploadPngChunk('IDAT', $compressed)
        . uploadPngChunk('IEND', '');
}

function uploadValidWebp(): string
{
    $bytes = base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==', true);
    uploadCheck(is_string($bytes) && $bytes !== '', 'could not build WebP fixture');
    return $bytes;
}

function uploadHeaderOnlyWebp(): string
{
    $vp8xPayload = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";
    $chunk = 'VP8X' . pack('V', strlen($vp8xPayload)) . $vp8xPayload;
    return 'RIFF' . pack('V', 4 + strlen($chunk)) . 'WEBP' . $chunk;
}

$policy = ProductImageUploadPolicy::create();
uploadCheck($policy->maxBytes === 8 * 1024 * 1024, 'max bytes drift');
uploadCheck($policy->maxFiles === 6, 'max files drift');
uploadCheck($policy->maxImageWidth === 6000 && $policy->maxImageHeight === 6000, 'dimension limit drift');
uploadCheck($policy->maxImagePixels === 16000000, 'pixel limit drift');
uploadCheck($policy->scannerMode === 'disabled', 'scanner policy drift');
uploadCheck($policy->extensionForMime('image/jpeg') === 'jpg', 'jpeg allowlist missing');
uploadCheck($policy->extensionForMime('image/png') === 'png', 'png allowlist missing');
uploadCheck($policy->extensionForMime('image/webp') === 'webp', 'webp allowlist missing');
uploadCheck($policy->extensionForMime('application/x-php') === null, 'unexpected MIME allowed');

$tmp = sys_get_temp_dir() . '/tienda-upload-pilot-' . bin2hex(random_bytes(6));
$sourceDir = $tmp . '/source';
$storageDir = $tmp . '/storage';
mkdir($sourceDir, 0700, true);

try {
    $valid = $sourceDir . '/client-name.php.png';
    file_put_contents($valid, uploadValidPng());
    $validSize = filesize($valid);
    uploadCheck(is_int($validSize), 'valid fixture size unavailable');

    $candidates = PhpUploadAdapter::candidates([
        'name' => ['../../evil.php'],
        'type' => ['application/x-php'],
        'tmp_name' => [$valid],
        'error' => [UPLOAD_ERR_OK],
        'size' => [$validSize],
    ], $policy, static fn (string $path): bool => $path === $valid);
    uploadCheck(count($candidates) === 1, 'candidate normalization failed');
    uploadCheck(array_keys($candidates[0]) === ['path', 'declared_size'], 'client metadata leaked into candidate');

    $service = new UploadService($policy, new LocalUploadStorage($storageDir, 0644));
    $receipt = $service->store($candidates[0]);
    uploadCheck($receipt['mime'] === 'image/png', 'stored MIME drift');
    uploadCheck($receipt['extension'] === 'png', 'server extension drift');
    uploadCheck($receipt['scanner_status'] === 'disabled', 'scanner status drift');
    uploadCheck(is_file($receipt['stored']['path']), 'stored file missing');
    uploadCheck(hash_file('sha256', $valid) === $receipt['stored']['sha256'], 'stored bytes differ from source');
    uploadCheck(!str_contains(basename($receipt['stored']['path']), 'evil'), 'client filename reached storage');
    $service->delete($receipt);
    uploadCheck(!file_exists($receipt['stored']['path']), 'cleanup failed');

    $truncated = $sourceDir . '/header-only.png';
    file_put_contents($truncated, uploadHeaderOnlyPng());
    $truncatedSize = filesize($truncated);
    uploadCheck(is_int($truncatedSize), 'truncated fixture size unavailable');
    uploadCheck(is_array(@getimagesize($truncated)), 'fixture must demonstrate getimagesize header gap');
    uploadExpectRejected('invalid_image_content', static function () use ($truncated, $truncatedSize, $storageDir, $policy): void {
        (new UploadService($policy, new LocalUploadStorage($storageDir, 0644)))->store([
            'path' => $truncated,
            'declared_size' => $truncatedSize,
        ]);
    });

    $inflation = $sourceDir . '/inflation.png';
    file_put_contents($inflation, uploadInflationBombPng());
    $inflationSize = filesize($inflation);
    uploadCheck(is_int($inflationSize), 'inflation fixture size unavailable');
    $inflationInfo = @getimagesize($inflation);
    uploadCheck(is_array($inflationInfo) && (int) $inflationInfo[0] === 1 && (int) $inflationInfo[1] === 1, 'inflation fixture must advertise tiny dimensions');
    uploadExpectRejected('invalid_image_content', static function () use ($inflation, $inflationSize, $storageDir, $policy): void {
        (new UploadService($policy, new LocalUploadStorage($storageDir, 0644)))->store([
            'path' => $inflation,
            'declared_size' => $inflationSize,
        ]);
    });

    $fakeWebp = $sourceDir . '/header-only.webp';
    file_put_contents($fakeWebp, uploadHeaderOnlyWebp());
    $fakeWebpSize = filesize($fakeWebp);
    uploadCheck(is_int($fakeWebpSize), 'header-only webp size unavailable');
    $fakeWebpInfo = @getimagesize($fakeWebp);
    uploadCheck(is_array($fakeWebpInfo) && ($fakeWebpInfo['mime'] ?? null) === 'image/webp', 'header-only webp fixture must demonstrate metadata gap');
    uploadExpectRejected(['invalid_image_content', 'image_decoder_unavailable'], static function () use ($fakeWebp, $fakeWebpSize, $storageDir, $policy): void {
        (new UploadService($policy, new LocalUploadStorage($storageDir, 0644)))->store([
            'path' => $fakeWebp,
            'declared_size' => $fakeWebpSize,
        ]);
    });

    $webp = $sourceDir . '/valid.webp';
    file_put_contents($webp, uploadValidWebp());
    $webpSize = filesize($webp);
    uploadCheck(is_int($webpSize), 'webp fixture size unavailable');
    $webpInfo = @getimagesize($webp);
    uploadCheck(is_array($webpInfo) && ($webpInfo['mime'] ?? null) === 'image/webp', 'webp fixture not recognized');
    $webpService = new UploadService($policy, new LocalUploadStorage($storageDir, 0644));
    if (function_exists('imagecreatefromwebp')) {
        $webpReceipt = $webpService->store(['path' => $webp, 'declared_size' => $webpSize]);
        uploadCheck($webpReceipt['mime'] === 'image/webp' && $webpReceipt['extension'] === 'webp', 'webp acceptance drift');
        $webpService->delete($webpReceipt);
    } else {
        uploadExpectRejected('image_decoder_unavailable', static function () use ($webpService, $webp, $webpSize): void {
            $webpService->store(['path' => $webp, 'declared_size' => $webpSize]);
        });
    }

    uploadExpectRejected('too_many_files', static function () use ($valid, $validSize, $policy): void {
        PhpUploadAdapter::candidates([
            'tmp_name' => array_fill(0, 7, $valid),
            'error' => array_fill(0, 7, UPLOAD_ERR_OK),
            'size' => array_fill(0, 7, $validSize),
        ], $policy, static fn (): bool => true);
    });

    uploadCheck(
        ProductImageUploadPolicy::userMessage(new UploadRejected('invalid_image_content'))
            === 'Solo se permiten imágenes JPG, PNG o WebP válidas y completas.',
        'friendly invalid-image message drift'
    );
    uploadCheck(
        str_contains(ProductImageUploadPolicy::userMessage(new UploadRejected('image_decoder_unavailable')), 'no puede validar'),
        'friendly decoder-unavailable message missing'
    );

    echo "PRODUCT_IMAGE_UPLOAD_OK\n";
} finally {
    if (is_dir($tmp)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || $entry->isFile()) {
                @unlink($entry->getPathname());
            } elseif ($entry->isDir()) {
                @rmdir($entry->getPathname());
            }
        }
        @rmdir($tmp);
    }
}
