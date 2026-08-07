<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarItem;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\Models\ProjectFinanceSnapshot;
use App\Support\Reporting\StableReportingSourceView;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ProjectPortfolioHealthImmutableSource
{
    public function __construct(
        private ProjectPortfolioHealthSourceReader $sources,
        private ProjectPortfolioHealthImmutableOwnerPayloadBuilder $payloads,
        private StableReportingSourceView $stableView,
    ) {}

    public function load(
        ReportExecutionContext $context,
        ReportQuery $query,
    ): ProjectPortfolioHealthImmutableSourceSelection {
        return $this->stableView->capture(fn (): ProjectPortfolioHealthImmutableSourceSelection => $this->capture(
            $context,
            $query,
        ));
    }

    private function capture(
        ReportExecutionContext $context,
        ReportQuery $query,
    ): ProjectPortfolioHealthImmutableSourceSelection {
        try {
            $read = $this->sources->read($context, $query);
        } catch (InvalidArgumentException) {
            $this->unavailable();
        }
        if (! is_array($read['components'] ?? null)
            || ! array_is_list($read['components'])
            || ! is_array($read['gaps'] ?? null)
            || ! array_is_list($read['gaps'])
            || ! is_array($read['calendar'] ?? null)
            || ! array_is_list($read['calendar'])
            || ! is_array($read['projects'] ?? null)
            || ! array_is_list($read['projects'])) {
            $this->unavailable();
        }
        foreach ($read['calendar'] as $item) {
            if (! $item instanceof PaymentCalendarItem) {
                $this->unavailable();
            }
        }
        try {
            $tuple = (new ProjectPortfolioHealthSourceTupleAssembler)->assemble(
                $read['components'],
                $read['gaps'],
            );
        } catch (InvalidArgumentException) {
            $this->unavailable();
        }
        if (! isset($tuple) || ! $tuple->isReady()) {
            $this->unavailable();
        }

        $rowsByKind = [];
        $rowCounts = [];
        foreach ($tuple->components as $component) {
            if ($component->kind === 'portfolio_liquidity') {
                continue;
            }
            $snapshot = ProjectFinanceSnapshot::query()
                ->whereKey($component->snapshotId)
                ->where('organization_id', $context->scope->organizationId)
                ->where('report_code', $component->kind)
                ->where('source_hash', $component->sourceHash)
                ->first();
            if (! $snapshot instanceof ProjectFinanceSnapshot) {
                $this->unavailable();
            }
            $rows = $snapshot->rows()
                ->where('organization_id', $context->scope->organizationId)
                ->where('report_code', $component->kind)
                ->orderBy('row_key')
                ->get();
            $asOf = $snapshot->as_of?->getTimestamp();
            $expectedAsOf = (new DateTimeImmutable($component->asOf))->getTimestamp();
            $version = trim((string) $snapshot->formula_version)
                .'|'.trim((string) $snapshot->source_schema_version);
            if ($asOf !== $expectedAsOf
                || $version !== $component->version
                || (string) $snapshot->quality_status !== 'complete'
                || (int) $snapshot->row_count !== $rows->count()
                || (int) $snapshot->coverage_numerator !== $rows->count()
                || (int) $snapshot->coverage_denominator !== $rows->count()
                || $rows->isEmpty()) {
                $this->unavailable();
            }
            $rowsByKind[$component->kind] = $rows
                ->map(static fn (object $row): array => $row->toArray())
                ->all();
            $rowCounts[$component->kind] = $rows->count();
        }

        try {
            $ownerPayloads = $this->payloads->build($rowsByKind);

            return new ProjectPortfolioHealthImmutableSourceSelection(
                $tuple,
                $ownerPayloads,
                $read['calendar'],
                $rowCounts,
                $read['projects'],
            );
        } catch (InvalidArgumentException) {
            $this->unavailable();
        }
    }

    private function unavailable(): never
    {
        throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
    }
}
