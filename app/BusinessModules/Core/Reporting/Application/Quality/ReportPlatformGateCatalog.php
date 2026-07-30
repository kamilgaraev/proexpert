<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Quality;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode;
use JsonException;

final class ReportPlatformGateCatalog
{
    private const IDS = ['QG-01', 'QG-02', 'QG-03', 'QG-04', 'QG-05', 'QG-06', 'QG-07', 'QG-08', 'QG-09', 'QG-10', 'QG-11', 'QG-12', 'QG-13', 'QG-14'];

    public function __construct(private string $path)
    {
    }

    public function records(): array
    {
        $bytes = @file_get_contents($this->path);
        if (! is_string($bytes)) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::MISSING);
        }
        try {
            $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
        }
        $gates = is_array($document) ? ($document['gates'] ?? null) : null;
        if (! is_array($gates) || ! array_is_list($gates) || count($gates) !== 14) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::CATALOG_COUNT_MISMATCH);
        }
        foreach ($gates as $index => $gate) {
            if (! is_array($gate) || ($gate['id'] ?? null) !== self::IDS[$index]
                || ! is_string($gate['platform_status'] ?? null)
                || ! is_string($gate['release_owner'] ?? null)
                || ! is_string($gate['command'] ?? null)
                || ! is_int($gate['minimum_count'] ?? null)
                || ! is_string($gate['schema_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $gate['schema_sha256']) !== 1
                || ! is_array($gate['source_paths'] ?? null) || ! array_is_list($gate['source_paths'])) {
                throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
            }
        }

        return $gates;
    }

    public function hash(): string
    {
        $this->records();
        $bytes = file_get_contents($this->path);
        if (! is_string($bytes)) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::MISSING);
        }

        return hash('sha256', $bytes);
    }
}
