<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Exports;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportArtifactStream;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportRenderer;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfDocumentBuilder;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfDocumentRenderer;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfRenderBudget;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use Throwable;

final class PdfReportExportRenderer implements ReportExportRenderer
{
    public const MIME_TYPE = 'application/pdf';

    /** @var array<string, ReportPdfRenderBudget> */
    private array $budgets;

    public function __construct(
        private readonly ReportPdfDocumentBuilder $builder,
        private readonly ReportPdfDocumentRenderer $documentRenderer,
        array $budgets,
        private readonly ?string $definitionHash = null,
        private readonly ?string $rendererVersion = null,
        private readonly ?ReportPdfRenderBudget $budget = null,
    ) {
        $this->budgets = $this->normalizeBudgets($budgets);
    }

    public static function budgetKey(string $definitionHash, string $rendererVersion): string
    {
        return $definitionHash.'@'.$rendererVersion;
    }

    public function hasBudget(PublishedReportDefinition $definition): bool
    {
        return isset($this->budgets[self::budgetKey(
            $definition->definitionHash->value,
            $definition->definition->rendererVersion,
        )]);
    }

    public function forDefinition(PublishedReportDefinition $definition): self
    {
        $key = self::budgetKey(
            $definition->definitionHash->value,
            $definition->definition->rendererVersion,
        );
        $budget = $this->budgets[$key] ?? null;
        if (!$budget instanceof ReportPdfRenderBudget) {
            throw $this->limit();
        }

        return new self(
            $this->builder,
            $this->documentRenderer,
            $this->budgets,
            $definition->definitionHash->value,
            $definition->definition->rendererVersion,
            $budget,
        );
    }

    public function render(
        ReportRunExportSource $source,
        CreateReportExportData $data,
        iterable $chunks,
        ReportArtifactStream $stream,
    ): int {
        if ($data->format !== 'pdf'
            || !$this->budget instanceof ReportPdfRenderBudget
            || $this->definitionHash === null
            || $this->rendererVersion === null
            || !hash_equals($this->definitionHash, $source->run->definitionHash->value)
            || !hash_equals($this->rendererVersion, $source->rendererVersion)) {
            throw $this->limit();
        }

        $document = $this->builder->build($source, $data, $chunks, $this->budget, $stream);
        try {
            $bytes = $this->documentRenderer->render($document, $this->budget);
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_DEPENDENCY_FAILED,
                previous: $exception,
            );
        }

        if (strlen($bytes) > $this->budget->maxPdfBytes) {
            throw $this->limit();
        }

        try {
            $stream->write($bytes);
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_DEPENDENCY_FAILED,
                previous: $exception,
            );
        }

        return $document->detailRowCount();
    }

    /** @return array<string, ReportPdfRenderBudget> */
    private function normalizeBudgets(array $budgets): array
    {
        $normalized = [];
        foreach ($budgets as $key => $value) {
            if (is_string($key) && $value instanceof ReportPdfRenderBudget
                && preg_match('/^[a-f0-9]{64}@.+$/D', $key) === 1) {
                $normalized[$key] = $value;
                continue;
            }
            if (is_array($value)
                && array_keys($value) === ['definition_hash', 'renderer_version', 'budget']
                && is_string($value['definition_hash'])
                && is_string($value['renderer_version'])
                && $value['budget'] instanceof ReportPdfRenderBudget) {
                $normalized[self::budgetKey($value['definition_hash'], $value['renderer_version'])] = $value['budget'];
                continue;
            }

            throw new \InvalidArgumentException('report_pdf_budget_registry_invalid');
        }

        return $normalized;
    }

    private function limit(): ReportContractException
    {
        return ReportContractException::fromCode(ReportErrorCode::REPORT_EXPORT_LIMIT_EXCEEDED);
    }
}
