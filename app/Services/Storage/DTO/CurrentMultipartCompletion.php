<?php

declare(strict_types=1);

namespace App\Services\Storage\DTO;

use InvalidArgumentException;

final readonly class CurrentMultipartCompletion
{
    public function __construct(
        public string $key,
        public string $etag,
        public int $sizeBytes,
        public string $mime,
    ) {
        if (
            preg_match('#^org-[1-9][0-9]*/[^\\\\\x00-\x1F\x7F]+$#D', $key) !== 1
            || strlen($key) > 1024
            || str_contains($key, '://')
            || preg_match('#(?:^|/)\.\.(?:/|$)#', $key) === 1
            || $etag === ''
            || strlen($etag) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $etag) === 1
            || $sizeBytes < 1
            || $mime === ''
            || strlen($mime) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $mime) === 1
        ) {
            throw new InvalidArgumentException('multipart_completion_invalid');
        }
    }
}
