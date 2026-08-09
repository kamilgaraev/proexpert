<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Support;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CompletedReportSourceLedgerBinding
{
    public static function capture(int $organizationId, array $sourceCodes): array
    {
        return self::captureGeneration($organizationId, $sourceCodes, false);
    }

    public static function captureWithDocumentedGaps(int $organizationId, array $sourceCodes): array
    {
        return self::captureGeneration($organizationId, $sourceCodes, true);
    }

    private static function captureGeneration(
        int $organizationId,
        array $sourceCodes,
        bool $allowDocumentedGaps,
    ): array
    {
        $codes = array_values(array_unique(array_map('strval', $sourceCodes)));
        sort($codes, SORT_STRING);
        $records = DB::table('report_source_sync_ledgers')
            ->where('organization_id', $organizationId)
            ->whereIn('source_code', $codes)
            ->orderBy('source_code')
            ->get()
            ->keyBy('source_code');
        if ($records->count() !== count($codes)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }

        try {
            $sources = [];
            $watermark = null;
            foreach ($codes as $code) {
                $record = $records->get($code);
                $cursor = self::json((string) $record->cursor);
                $targetCursor = self::json((string) $record->target_cursor);
                $gapCount = (int) $record->gap_count;
                $unknownCount = (int) $record->unknown_count;
                $acceptedStatuses = $allowDocumentedGaps ? ['ready', 'partial'] : ['ready'];
                if (! in_array($record->status, $acceptedStatuses, true)
                    || (! $allowDocumentedGaps && ($gapCount !== 0 || $unknownCount !== 0))
                    || $gapCount < 0
                    || $unknownCount < 0
                    || ! is_string($record->owner_checksum)
                    || ! is_string($record->completed_owner_checksum)
                    || ! hash_equals($record->owner_checksum, $record->completed_owner_checksum)
                    || CanonicalJson::encode($cursor) !== CanonicalJson::encode($targetCursor)
                    || $record->completed_at === null) {
                    throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
                }
                $sourceWatermark = $record->source_watermark === null
                    ? CarbonImmutable::parse((string) $record->completed_at)
                    : CarbonImmutable::parse((string) $record->source_watermark);
                $watermark = $watermark === null || $sourceWatermark > $watermark ? $sourceWatermark : $watermark;
                $source = [
                    'checksum' => $record->completed_owner_checksum,
                    'completed_at' => CarbonImmutable::parse((string) $record->completed_at)->toAtomString(),
                    'cursor' => $cursor,
                    'source_count' => (int) $record->source_count,
                    'source_watermark' => $record->source_watermark === null
                        ? null
                        : CarbonImmutable::parse((string) $record->source_watermark)->toAtomString(),
                    'target_cursor' => $targetCursor,
                ];
                if ($allowDocumentedGaps) {
                    $source += [
                        'gap_count' => $gapCount,
                        'status' => (string) $record->status,
                        'unknown_count' => $unknownCount,
                        'unknown_owner_keys' => self::nullableJson($record->unknown_owner_keys),
                    ];
                }
                $sources[$code] = $source;
            }
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_SOURCE_UNAVAILABLE,
                previous: $exception,
            );
        }

        if (! $allowDocumentedGaps) {
            return [
                'hash' => hash('sha256', CanonicalJson::encode($sources)),
                'sources' => $sources,
                'watermark' => $watermark?->toAtomString(),
            ];
        }

        return [
            'hash' => hash('sha256', CanonicalJson::encode([
                'integrity_mode' => 'documented_gaps',
                'sources' => $sources,
            ])),
            'integrity_mode' => 'documented_gaps',
            'sources' => $sources,
            'watermark' => $watermark?->toAtomString(),
        ];
    }

    public static function matches(int $organizationId, array $binding): bool
    {
        $sources = $binding['sources'] ?? null;
        if (! is_array($sources) || $sources === []) {
            return false;
        }

        try {
            return hash_equals(
                (string) ($binding['hash'] ?? ''),
                (string) self::captureGeneration(
                    $organizationId,
                    array_keys($sources),
                    ($binding['integrity_mode'] ?? null) === 'documented_gaps',
                )['hash'],
            );
        } catch (ReportContractException) {
            return false;
        }
    }

    public static function lockAndAssertOwnerGeneration(int $organizationId, array $binding): void
    {
        $sources = $binding['sources'] ?? null;
        if (! is_array($sources) || $sources === []) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }
        foreach ($sources as $sourceCode => $source) {
            if (! is_string($sourceCode) || ! is_array($source)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
            }
            $generation = ReportSourceOwnerGeneration::capture($organizationId, $sourceCode);
            if (! hash_equals((string) ($source['checksum'] ?? ''), (string) $generation['checksum'])
                || CanonicalJson::encode($source['target_cursor'] ?? null)
                    !== CanonicalJson::encode($generation['target_cursor'])) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
            }
        }
        if (! self::matches($organizationId, $binding)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }
    }

    private static function json(string $value): array
    {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }

        return $decoded;
    }

    private static function nullableJson(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return self::json((string) $value);
    }
}
