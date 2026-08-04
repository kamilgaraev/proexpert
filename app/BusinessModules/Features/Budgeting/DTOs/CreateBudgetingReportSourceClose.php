<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CreateBudgetingReportSourceClose
{
    /**
     * @param  list<BudgetingReportSourceWatermark>  $sourceWatermarks
     * @param  array<string, mixed>  $sourceManifest
     */
    public function __construct(
        public string $closeId,
        public string $reportCode,
        public BudgetingReportSourceCloseIdentity $identity,
        public array $sourceWatermarks,
        public string $formulaVersion,
        public array $sourceManifest,
        public string $contentHash,
        public int $approvedBy,
        public DateTimeImmutable $approvedAt,
        public DateTimeImmutable $retainedUntil,
        public ?string $restatesCloseId = null,
    ) {
        if (! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $this->closeId)
            || preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $this->reportCode) !== 1
            || trim($this->formulaVersion) === ''
            || ! preg_match('/^[a-f0-9]{64}$/', $this->contentHash)
            || $this->approvedBy <= 0
            || $this->sourceManifest === []) {
            throw new InvalidArgumentException('budgeting_report_source_close_input_invalid');
        }

        if ($this->retainedUntil <= $this->approvedAt) {
            throw new InvalidArgumentException('budgeting_report_source_close_retention_invalid');
        }

        if ($this->restatesCloseId !== null && ! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $this->restatesCloseId)) {
            throw new InvalidArgumentException('budgeting_report_source_close_restatement_invalid');
        }

        $sources = [];
        foreach ($this->sourceWatermarks as $watermark) {
            if (! $watermark instanceof BudgetingReportSourceWatermark || isset($sources[$watermark->source])) {
                throw new InvalidArgumentException('budgeting_report_source_close_watermarks_invalid');
            }
            $sources[$watermark->source] = true;
        }

        if ($sources === []) {
            throw new InvalidArgumentException('budgeting_report_source_close_watermarks_invalid');
        }

        if (! hash_equals(self::contentHashFor($this->reportCode, $this->identity, $this->sourceWatermarks, $this->formulaVersion, $this->sourceManifest), $this->contentHash)) {
            throw new InvalidArgumentException('budgeting_report_source_close_hash_invalid');
        }
    }

    public function calculateContentHash(): string
    {
        return self::contentHashFor($this->reportCode, $this->identity, $this->sourceWatermarks, $this->formulaVersion, $this->sourceManifest);
    }

    /**
     * @param  list<BudgetingReportSourceWatermark>  $sourceWatermarks
     * @param  array<string, mixed>  $sourceManifest
     */
    public static function contentHashFor(
        string $reportCode,
        BudgetingReportSourceCloseIdentity $identity,
        array $sourceWatermarks,
        string $formulaVersion,
        array $sourceManifest,
    ): string {
        $watermarks = array_map(static fn (BudgetingReportSourceWatermark $watermark): array => $watermark->toArray(), $sourceWatermarks);
        usort($watermarks, static fn (array $left, array $right): int => $left['source'] <=> $right['source']);

        return hash('sha256', json_encode([
            'report_code' => $reportCode,
            'identity' => $identity->toArray(),
            'formula_version' => $formulaVersion,
            'source_manifest' => self::canonicalize($sourceManifest),
            'source_watermarks' => $watermarks,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed> */
    public function canonicalContent(): array
    {
        $watermarks = array_map(static fn (BudgetingReportSourceWatermark $watermark): array => $watermark->toArray(), $this->sourceWatermarks);
        usort($watermarks, static fn (array $left, array $right): int => $left['source'] <=> $right['source']);

        return [
            'report_code' => $this->reportCode,
            'identity' => $this->identity->toArray(),
            'formula_version' => $this->formulaVersion,
            'source_manifest' => self::canonicalize($this->sourceManifest),
            'source_watermarks' => $watermarks,
        ];
    }

    /** @param array<string, mixed>|list<mixed> $value */
    private static function canonicalize(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => is_array($item) ? self::canonicalize($item) : $item, $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = is_array($item) ? self::canonicalize($item) : $item;
        }

        return $value;
    }
}
