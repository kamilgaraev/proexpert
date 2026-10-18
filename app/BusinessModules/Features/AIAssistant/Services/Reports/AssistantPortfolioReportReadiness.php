<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthSourceReader;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthSourceTupleAssembler;
use App\Support\Reporting\StableReportingSourceView;

final readonly class AssistantPortfolioReportReadiness
{
    private const REASONS = [
        'mandatory_owner_source_missing' => 'missing',
        'ambiguous_owner_source' => 'ambiguous',
        'owner_source_scope_incompatible' => 'scope_incompatible',
        'owner_source_integrity_invalid' => 'integrity_invalid',
        'owner_source_integrity_ambiguous' => 'integrity_invalid',
        'owner_source_cohort_mismatch' => 'cohort_mismatch',
        'liquidity_source_scope_invalid' => 'scope_incompatible',
        'liquidity_source_unavailable' => 'unavailable',
        'liquidity_source_gap' => 'incomplete',
        'liquidity_source_integrity_invalid' => 'integrity_invalid',
    ];

    public function __construct(private ProjectPortfolioHealthSourceReader $sources, private StableReportingSourceView $stableView) {}

    public function inspect(ReportExecutionContext $context, ReportQuery $query, string $watermark): array
    {
        return $this->stableView->capture(function () use ($context, $query, $watermark): array {
            $read = $this->sources->read($context, $query);
            $tuple = (new ProjectPortfolioHealthSourceTupleAssembler)->assemble($read['components'], $read['gaps']);
            if (! hash_equals($watermark, $tuple->watermark)) {
                return [];
            }
            $reasons = [];
            foreach ($tuple->gaps as $gap) {
                $reasons[$gap->kind][] = self::REASONS[$gap->code] ?? 'unavailable';
            }

            return array_map(static fn (string $kind): array => ['kind' => $kind, 'ready' => ! isset($reasons[$kind]),
                'reasons' => array_values(array_unique($reasons[$kind] ?? []))], ProjectPortfolioHealthSourceTupleAssembler::REQUIRED_KINDS);
        }, 5);
    }
}
