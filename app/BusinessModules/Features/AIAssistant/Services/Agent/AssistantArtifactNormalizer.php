<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Agent;

use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantArtifact;

final class AssistantArtifactNormalizer
{
    private const URL_KEYS = [
        'pdf_url' => 'pdf',
        'excel_url' => 'excel',
        'download_url' => 'file',
        'file_url' => 'file',
    ];

    /**
     * @return array<int, array{
     *     type: string,
     *     url: string,
     *     filename: string,
     *     source_tool: string|null,
     *     storage_disk: string|null,
     *     storage_path: string|null,
     *     expires_at: string|null
     * }>
     */
    public function fromToolResult(string $toolName, mixed $toolResult): array
    {
        if (! is_array($toolResult)) {
            return [];
        }

        return array_map(
            static fn (AssistantArtifact $artifact): array => $artifact->toArray(),
            $this->collectArtifacts($toolName, $toolResult)
        );
    }

    /**
     * @param  array<mixed>  $data
     * @return AssistantArtifact[]
     */
    private function collectArtifacts(string $toolName, array $data): array
    {
        $artifacts = [];

        foreach (self::URL_KEYS as $urlKey => $type) {
            $url = $data[$urlKey] ?? null;

            if (! is_string($url) || ! $this->isTrustedUrl($url, $data)) {
                continue;
            }

            $artifacts[] = new AssistantArtifact(
                type: $type,
                url: $url,
                filename: $this->extractFilename($data, $url),
                sourceTool: $toolName,
                storageDisk: $this->optionalString($data['storage_disk'] ?? null),
                storagePath: $this->optionalString($data['storage_path'] ?? null),
                expiresAt: $this->optionalString($data['expires_at'] ?? null),
            );
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                array_push($artifacts, ...$this->collectArtifacts($toolName, $value));
            }
        }

        return $artifacts;
    }

    /**
     * @param  array<mixed>  $data
     */
    private function isTrustedUrl(string $url, array $data): bool
    {
        $url = trim($url);
        $normalizedUrl = mb_strtolower($url);

        foreach ([
            'реальный_pdf_url_из_данных',
            'тут_ссылка',
            'placeholder',
            'example.com/fake',
        ] as $placeholder) {
            if (str_contains($normalizedUrl, $placeholder)) {
                return false;
            }
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (($scheme !== 'http' && $scheme !== 'https') || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $storagePath = $this->optionalString($data['storage_path'] ?? null);
        if ($storagePath === null || ! str_starts_with($storagePath, 'org-') || ! str_contains($storagePath, '/reports/')) {
            return false;
        }

        return $this->optionalString($data['storage_disk'] ?? null) === 's3';
    }

    /**
     * @param  array<mixed>  $data
     */
    private function extractFilename(array $data, string $url): string
    {
        $filename = $this->optionalString($data['filename'] ?? null);

        if ($filename !== null && $filename !== '') {
            return basename($filename);
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (is_string($path) && $path !== '') {
            $basename = basename($path);

            if ($basename !== '' && $basename !== '.' && $basename !== '/') {
                return $basename;
            }
        }

        return 'report';
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return null;
    }
}
