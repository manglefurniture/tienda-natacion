<?php

declare(strict_types=1);

namespace HacheBase\Integrations\Upload;

final class PhpUploadAdapter
{
    /**
     * Normalize one PHP $_FILES field. Client filenames/types are deliberately
     * ignored; MIME and server extension are derived later from the temp bytes.
     *
     * @param array<string, mixed> $field
     * @param null|callable(string):bool $isUploadedFile Transport checker; null uses is_uploaded_file().
     * @return list<array{path:string,declared_size:int}>
     */
    public static function candidates(array $field, UploadPolicy $policy, ?callable $isUploadedFile = null): array
    {
        $checker = $isUploadedFile ?? static fn (string $path): bool => is_uploaded_file($path);
        $errors = $field['error'] ?? UPLOAD_ERR_NO_FILE;
        $paths = $field['tmp_name'] ?? '';
        $sizes = $field['size'] ?? 0;

        if (!is_array($errors)) {
            $errors = [$errors];
            $paths = [$paths];
            $sizes = [$sizes];
        } elseif (!is_array($paths) || !is_array($sizes)) {
            throw new UploadRejected('malformed_transport');
        }

        $active = 0;
        foreach ($errors as $error) {
            if ((int) $error !== UPLOAD_ERR_NO_FILE) {
                $active++;
            }
        }
        if ($active > $policy->maxFiles) {
            throw new UploadRejected('too_many_files');
        }

        $result = [];
        foreach ($errors as $index => $errorRaw) {
            $error = (int) $errorRaw;
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new UploadRejected('too_large');
            }
            if ($error !== UPLOAD_ERR_OK) {
                throw new UploadRejected('transport_error');
            }

            $path = $paths[$index] ?? null;
            $size = $sizes[$index] ?? null;
            if (!is_string($path) || $path === '' || !is_numeric($size) || (int) $size < 0) {
                throw new UploadRejected('malformed_transport');
            }
            if (!$checker($path)) {
                throw new UploadRejected('invalid_transport_origin');
            }

            $result[] = ['path' => $path, 'declared_size' => (int) $size];
        }

        return $result;
    }
}
