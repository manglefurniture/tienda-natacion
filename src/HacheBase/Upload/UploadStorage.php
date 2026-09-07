<?php

declare(strict_types=1);

namespace HacheBase\Integrations\Upload;

interface UploadStorage
{
    /**
     * Store validated bytes using only a server-controlled extension.
     *
     * @return array{id:string,path:string,size:int,sha256:string}
     */
    public function store(string $sourcePath, string $extension): array;

    /** @param array{id:string,path:string,size:int,sha256:string} $stored */
    public function delete(array $stored): void;
}
