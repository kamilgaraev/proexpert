<?php

declare(strict_types=1);

namespace App\DTOs\ActReport;

final readonly class OfficialFormsDownloadResult
{
    public function __construct(
        public string $path,
        public string $filename,
        public string $mimeType,
    ) {}

    public function cleanup(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }
}
