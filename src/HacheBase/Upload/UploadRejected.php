<?php

declare(strict_types=1);

namespace HacheBase\Integrations\Upload;

final class UploadRejected extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Upload rejected: ' . $reason);
    }
}
