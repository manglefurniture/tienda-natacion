<?php

declare(strict_types=1);

namespace HacheBase\Integrations\Upload;

interface UploadScanner
{
    public const CLEAN = 'clean';
    public const INFECTED = 'infected';
    public const UNAVAILABLE = 'unavailable';

    /**
     * Return one of CLEAN, INFECTED or UNAVAILABLE.
     * Implementations must not return raw antivirus output containing file data,
     * credentials or other sensitive context.
     */
    public function scan(string $path, string $mime): string;
}
