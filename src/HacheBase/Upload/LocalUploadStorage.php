<?php

declare(strict_types=1);

namespace HacheBase\Integrations\Upload;

final class LocalUploadStorage implements UploadStorage
{
    private string $root;

    public function __construct(string $rootDirectory, private readonly int $fileMode = 0640)
    {
        $rootDirectory = rtrim(trim($rootDirectory), DIRECTORY_SEPARATOR);
        if ($rootDirectory === '' || $rootDirectory === DIRECTORY_SEPARATOR) {
            throw new \InvalidArgumentException('unsafe upload root');
        }
        if ($fileMode < 0 || $fileMode > 0777) {
            throw new \InvalidArgumentException('invalid file mode');
        }
        if (is_link($rootDirectory)) {
            throw new \InvalidArgumentException('upload root cannot be a symlink');
        }
        if (!is_dir($rootDirectory) && !@mkdir($rootDirectory, 0750, true) && !is_dir($rootDirectory)) {
            throw new \RuntimeException('cannot create upload root');
        }
        if (!is_writable($rootDirectory)) {
            throw new \RuntimeException('upload root is not writable');
        }

        $real = realpath($rootDirectory);
        if ($real === false || $real === DIRECTORY_SEPARATOR || is_link($real)) {
            throw new \InvalidArgumentException('upload root cannot be resolved safely');
        }
        $this->root = $real;
    }

    public function store(string $sourcePath, string $extension): array
    {
        if (!preg_match('/^[a-z0-9]{1,10}$/', $extension)) {
            throw new \InvalidArgumentException('invalid server extension');
        }
        if (!is_file($sourcePath) || is_link($sourcePath) || !is_readable($sourcePath)) {
            throw new \RuntimeException('upload source is not a readable regular file');
        }

        $source = @fopen($sourcePath, 'rb');
        if ($source === false) {
            throw new \RuntimeException('cannot open upload source');
        }

        try {
            for ($attempt = 0; $attempt < 8; $attempt++) {
                $id = bin2hex(random_bytes(16));
                $destination = $this->root . DIRECTORY_SEPARATOR . $id . '.' . $extension;
                $target = @fopen($destination, 'x+b');
                if ($target === false) {
                    continue;
                }

                $ok = false;
                try {
                    rewind($source);
                    $copied = stream_copy_to_stream($source, $target);
                    if ($copied === false) {
                        throw new \RuntimeException('cannot copy upload bytes');
                    }
                    if (!fflush($target)) {
                        throw new \RuntimeException('cannot flush upload bytes');
                    }
                    @chmod($destination, $this->fileMode);
                    $sha = hash_file('sha256', $destination);
                    $size = filesize($destination);
                    if ($sha === false || $size === false) {
                        throw new \RuntimeException('cannot fingerprint stored upload');
                    }
                    $ok = true;
                    return [
                        'id' => $id,
                        'path' => $destination,
                        'size' => $size,
                        'sha256' => strtolower($sha),
                    ];
                } finally {
                    fclose($target);
                    if (!$ok && (is_file($destination) || is_link($destination))) {
                        @unlink($destination);
                    }
                }
            }
        } finally {
            fclose($source);
        }

        throw new \RuntimeException('cannot allocate unique upload destination');
    }

    public function delete(array $stored): void
    {
        $path = $stored['path'] ?? null;
        if (!is_string($path) || $path === '') {
            throw new \InvalidArgumentException('stored upload path missing');
        }
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path)) {
            throw new \RuntimeException('refusing to delete symlink upload');
        }
        $real = realpath($path);
        if ($real === false || dirname($real) !== $this->root) {
            throw new \RuntimeException('refusing to delete upload outside storage root');
        }
        if (!@unlink($real)) {
            throw new \RuntimeException('cannot delete stored upload');
        }
    }
}
