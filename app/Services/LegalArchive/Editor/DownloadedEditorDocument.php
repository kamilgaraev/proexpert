<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Editor;

final readonly class DownloadedEditorDocument
{
    public function __construct(
        public string $path,
        public string $filename,
        public string $mimeType,
        public int $size,
        public string $sha256,
    ) {}

    public function cleanup(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
