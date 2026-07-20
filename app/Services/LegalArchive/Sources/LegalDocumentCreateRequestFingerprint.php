<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Sources;

use Illuminate\Http\UploadedFile;
use RuntimeException;

final class LegalDocumentCreateRequestFingerprint
{
    public static function fromRequest(
        int $organizationId,
        ?int $actorId,
        array $data,
        ?UploadedFile $file,
    ): string {
        $payload = [
            'organization_id' => $organizationId,
            'actor_id' => $actorId,
            'idempotency_key' => $data['source_idempotency_key'] ?? null,
            'source' => [
                'type' => $data['source_type'] ?? null,
                'id' => isset($data['source_id']) ? (string) $data['source_id'] : null,
            ],
            'document' => self::documentPayload($data),
            'file' => self::filePayload($file),
        ];

        return hash('sha256', json_encode(self::canonicalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private static function documentPayload(array $data): array
    {
        $excluded = ['source_idempotency_key', 'source_type', 'source_id', 'file'];
        foreach ($excluded as $key) {
            unset($data[$key]);
        }

        return $data;
    }

    private static function filePayload(?UploadedFile $file): ?array
    {
        if (! $file instanceof UploadedFile) {
            return null;
        }
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw new RuntimeException('legal_document_request_file_unavailable');
        }
        $hash = hash_file('sha256', $path);
        if (! is_string($hash)) {
            throw new RuntimeException('legal_document_request_file_hash_failed');
        }

        return [
            'sha256' => $hash,
            'size' => (int) ($file->getSize() ?: filesize($path)),
            'name' => $file->getClientOriginalName(),
            'client_mime' => $file->getClientMimeType(),
            'detected_mime' => $file->getMimeType(),
        ];
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
