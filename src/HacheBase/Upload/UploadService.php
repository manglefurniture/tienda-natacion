<?php

declare(strict_types=1);

namespace HacheBase\Integrations\Upload;

final class UploadService
{
    public function __construct(
        private readonly UploadPolicy $policy,
        private readonly UploadStorage $storage,
        private readonly ?UploadScanner $scanner = null,
    ) {
    }

    /**
     * @param array{path:string,declared_size:int} $candidate
     * @return array{
     *   mime:string,extension:string,size:int,width:?int,height:?int,
     *   scanner_status:string,stored:array{id:string,path:string,size:int,sha256:string}
     * }
     */
    public function store(array $candidate): array
    {
        $path = $candidate['path'] ?? null;
        $declaredSize = $candidate['declared_size'] ?? null;
        if (!is_string($path) || $path === '' || !is_int($declaredSize) || $declaredSize < 0) {
            throw new UploadRejected('malformed_candidate');
        }
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new UploadRejected('invalid_source');
        }

        $actualSize = filesize($path);
        if ($actualSize === false) {
            throw new UploadRejected('size_unavailable');
        }
        if ($actualSize !== $declaredSize) {
            throw new UploadRejected('size_mismatch');
        }
        if ($actualSize < 1) {
            throw new UploadRejected('empty_file');
        }
        if ($actualSize > $this->policy->maxBytes) {
            throw new UploadRejected('too_large');
        }
        $sourceSha = hash_file('sha256', $path);
        if ($sourceSha === false) {
            throw new UploadRejected('fingerprint_unavailable');
        }
        $sourceSha = strtolower($sourceSha);

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower(trim((string) $finfo->file($path)));
        $extension = $this->policy->extensionForMime($mime);
        if ($extension === null) {
            throw new UploadRejected('mime_not_allowed');
        }

        $width = null;
        $height = null;
        if (str_starts_with($mime, 'image/')) {
            $image = @getimagesize($path);
            if (!is_array($image) || !isset($image[0], $image[1]) || (int) $image[0] < 1 || (int) $image[1] < 1) {
                throw new UploadRejected('invalid_image_content');
            }
            $imageMime = strtolower(trim((string) ($image['mime'] ?? '')));
            if ($imageMime !== $mime) {
                throw new UploadRejected('image_mime_mismatch');
            }
            $width = (int) $image[0];
            $height = (int) $image[1];

            if ($width > (int) $this->policy->maxImageWidth || $height > (int) $this->policy->maxImageHeight) {
                throw new UploadRejected('image_dimensions_exceeded');
            }
            $pixelLimit = (int) $this->policy->maxImagePixels;
            if ($height > 0 && $width > intdiv($pixelLimit, $height)) {
                throw new UploadRejected('image_pixels_exceeded');
            }

            $this->assertImageContentDecodable($path, $mime);
        }

        $scannerStatus = $this->scan($path, $mime);
        $stored = $this->storage->store($path, $extension);
        $storedSize = $stored['size'] ?? null;
        $storedSha = $stored['sha256'] ?? null;
        if ($storedSize !== $actualSize || !is_string($storedSha) || !hash_equals($sourceSha, strtolower($storedSha))) {
            try {
                $this->storage->delete($stored);
            } catch (\Throwable) {
            }
            throw new \RuntimeException('stored upload byte drift');
        }

        return [
            'mime' => $mime,
            'extension' => $extension,
            'size' => $actualSize,
            'width' => $width,
            'height' => $height,
            'scanner_status' => $scannerStatus,
            'stored' => $stored,
        ];
    }

    /** @param array{stored:array{id:string,path:string,size:int,sha256:string}} $receipt */
    public function delete(array $receipt): void
    {
        if (!isset($receipt['stored']) || !is_array($receipt['stored'])) {
            throw new \InvalidArgumentException('upload receipt missing stored record');
        }
        $this->storage->delete($receipt['stored']);
    }

    private function assertImageContentDecodable(string $path, string $mime): void
    {
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') {
            throw new UploadRejected('invalid_image_content');
        }

        if (function_exists('imagecreatefromstring')) {
            $decoded = @imagecreatefromstring($bytes);
            if ($decoded === false) {
                throw new UploadRejected('invalid_image_content');
            }
            @imagedestroy($decoded);
            return;
        }

        $valid = match ($mime) {
            'image/png' => self::inspectPng($bytes),
            'image/jpeg' => self::inspectJpeg($bytes),
            'image/webp' => self::inspectWebp($bytes),
            'image/gif' => self::inspectGif($bytes),
            default => false,
        };

        if (!$valid) {
            throw new UploadRejected('invalid_image_content');
        }
    }

    private static function inspectPng(string $bytes): bool
    {
        $signature = "\x89PNG\r\n\x1a\n";
        if (!str_starts_with($bytes, $signature)) {
            return false;
        }

        $length = strlen($bytes);
        $offset = 8;
        $seenIhdr = false;
        $seenIdat = false;
        $idat = '';

        while ($offset + 12 <= $length) {
            $lengthData = unpack('Nvalue', substr($bytes, $offset, 4));
            if (!is_array($lengthData) || !isset($lengthData['value'])) {
                return false;
            }
            $chunkLength = (int) $lengthData['value'];
            $chunkEnd = $offset + 12 + $chunkLength;
            if ($chunkLength < 0 || $chunkEnd > $length) {
                return false;
            }

            $type = substr($bytes, $offset + 4, 4);
            $data = substr($bytes, $offset + 8, $chunkLength);
            $storedCrc = substr($bytes, $offset + 8 + $chunkLength, 4);
            $expectedCrc = hash('crc32b', $type . $data, true);
            if (strlen($type) !== 4 || strlen($storedCrc) !== 4 || !hash_equals($expectedCrc, $storedCrc)) {
                return false;
            }

            if (!$seenIhdr) {
                if ($type !== 'IHDR' || $chunkLength !== 13) {
                    return false;
                }
                $seenIhdr = true;
            } elseif ($type === 'IHDR') {
                return false;
            }

            if ($type === 'IDAT') {
                if ($chunkLength < 1) {
                    return false;
                }
                $seenIdat = true;
                $idat .= $data;
            }

            if ($type === 'IEND') {
                if ($chunkLength !== 0 || !$seenIhdr || !$seenIdat || $chunkEnd !== $length) {
                    return false;
                }
                if (function_exists('zlib_decode')) {
                    $inflated = @zlib_decode($idat);
                    if (!is_string($inflated) || $inflated === '') {
                        return false;
                    }
                }
                return true;
            }

            $offset = $chunkEnd;
        }

        return false;
    }

    private static function inspectJpeg(string $bytes): bool
    {
        $length = strlen($bytes);
        if ($length < 4 || substr($bytes, 0, 2) !== "\xff\xd8" || substr($bytes, -2) !== "\xff\xd9") {
            return false;
        }

        $hasStartOfScan = str_contains($bytes, "\xff\xda");
        $hasFrame = false;
        foreach (["\xff\xc0", "\xff\xc1", "\xff\xc2", "\xff\xc3", "\xff\xc5", "\xff\xc6", "\xff\xc7", "\xff\xc9", "\xff\xca", "\xff\xcb", "\xff\xcd", "\xff\xce", "\xff\xcf"] as $marker) {
            if (str_contains($bytes, $marker)) {
                $hasFrame = true;
                break;
            }
        }
        return $hasStartOfScan && $hasFrame;
    }

    private static function inspectWebp(string $bytes): bool
    {
        if (strlen($bytes) < 20 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
            return false;
        }
        $sizeData = unpack('Vvalue', substr($bytes, 4, 4));
        if (!is_array($sizeData) || !isset($sizeData['value']) || (int) $sizeData['value'] + 8 !== strlen($bytes)) {
            return false;
        }
        return str_contains($bytes, 'VP8 ') || str_contains($bytes, 'VP8L') || str_contains($bytes, 'VP8X');
    }

    private static function inspectGif(string $bytes): bool
    {
        $header = substr($bytes, 0, 6);
        return strlen($bytes) >= 14
            && in_array($header, ['GIF87a', 'GIF89a'], true)
            && substr($bytes, -1) === "\x3b";
    }

    private function scan(string $path, string $mime): string
    {
        if ($this->policy->scannerMode === 'disabled') {
            return 'disabled';
        }
        if ($this->scanner === null) {
            if ($this->policy->scannerMode === 'required') {
                throw new UploadRejected('scanner_required');
            }
            return 'not_configured';
        }

        try {
            $status = $this->scanner->scan($path, $mime);
        } catch (\Throwable) {
            $status = UploadScanner::UNAVAILABLE;
        }

        if ($status === UploadScanner::INFECTED) {
            throw new UploadRejected('scanner_rejected');
        }
        if ($status === UploadScanner::UNAVAILABLE) {
            if ($this->policy->scannerMode === 'required') {
                throw new UploadRejected('scanner_unavailable');
            }
            return UploadScanner::UNAVAILABLE;
        }
        if ($status !== UploadScanner::CLEAN) {
            throw new UploadRejected('scanner_invalid_result');
        }

        return UploadScanner::CLEAN;
    }
}
