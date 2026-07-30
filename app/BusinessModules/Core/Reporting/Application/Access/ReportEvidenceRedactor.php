<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

final readonly class ReportEvidenceRedactor
{
    public function reference(
        array $reference,
        string $sourceType,
        int|string $sourceId,
        string $availability,
    ): array {
        if ($availability === 'available') {
            return [
                'row_key' => $sourceType.':'.$sourceId,
                ...$reference,
                'availability' => 'available',
            ];
        }

        return [
            'row_key' => 'redacted:'.hash('sha256', $sourceType.':'.$sourceId),
            'source_type' => $sourceType,
            'availability' => 'redacted',
        ];
    }

    public function event(array $reference, string $availability): array
    {
        $eventId = (string) ($reference['event_id'] ?? '');
        if ($availability === 'available') {
            return [
                'row_key' => $eventId,
                'event_id' => $eventId,
                'event_type' => (string) ($reference['event_type'] ?? ''),
                'availability' => 'available',
            ];
        }

        return [
            'row_key' => 'redacted:'.hash('sha256', $eventId),
            'availability' => 'redacted',
        ];
    }
}
