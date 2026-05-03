<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\DTOs\Agent;

final readonly class AssistantArtifact
{
    public function __construct(
        public string $type,
        public string $url,
        public string $filename,
        public ?string $sourceTool = null,
        public ?string $storageDisk = null,
        public ?string $storagePath = null,
        public ?string $expiresAt = null,
    ) {}

    /**
     * @return array{
     *     type: string,
     *     url: string,
     *     filename: string,
     *     source_tool: string|null,
     *     storage_disk: string|null,
     *     storage_path: string|null,
     *     expires_at: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'url' => $this->url,
            'filename' => $this->filename,
            'source_tool' => $this->sourceTool,
            'storage_disk' => $this->storageDisk,
            'storage_path' => $this->storagePath,
            'expires_at' => $this->expiresAt,
        ];
    }
}
