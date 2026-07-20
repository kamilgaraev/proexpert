<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Sources;

use App\Services\LegalArchive\CanonicalJson;
use App\Services\LegalArchive\Files\UploadedFileDescriptor;
use App\Services\LegalArchive\Files\VersionInput;
use Illuminate\Http\UploadedFile;

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
            'version_input' => VersionInput::fromCreateData($actorId, $data)->semanticPayload(),
        ];

        return CanonicalJson::fingerprint($payload);
    }

    private static function documentPayload(array $data): array
    {
        $excluded = [
            'source_idempotency_key', 'source_type', 'source_id', 'file',
            'version_number', 'version_label', 'version_metadata',
        ];
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

        return UploadedFileDescriptor::fromUpload($file)->toArray();
    }
}
