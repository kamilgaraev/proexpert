<?php

declare(strict_types=1);

namespace App\DTOs\Epm;

use BackedEnum;

final readonly class ProjectMarginAttributionLine
{
    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $confirmation
     * @param array<string, mixed> $freshness
     * @param array<string, mixed> $reconciliation
     * @param array<int, BackedEnum|string> $problemFlags
     * @param array<int, BackedEnum|string> $riskFlags
     * @param array<string, mixed> $permissions
     */
    public function __construct(
        public string $lineId,
        public string $component,
        public string $direction,
        public int $organizationId,
        public ?int $projectId,
        public ?string $stageId,
        public ?int $contractId,
        public ?int $actId,
        public ?string $budgetArticleId,
        public ?string $responsibilityCenterId,
        public ?int $counterpartyId,
        public string $period,
        public string $recognitionDate,
        public string $recognitionEvent,
        public string $attributionRule,
        public string $currency,
        public float $amountWithoutVat,
        public ?float $vatAmount,
        public ?float $managementAmount,
        public ?string $managementCurrency,
        public string $sourceType,
        public int|string|null $sourceId,
        public int|string|null $sourceLineId,
        public ?string $sourceDocumentNumber,
        public ?string $documentDate,
        public array $source,
        public array $confirmation,
        public array $freshness,
        public array $reconciliation,
        public string $qualityStatus,
        public string $confirmationStatus,
        public string $freshnessStatus,
        public string $reconciliationStatus,
        public array $problemFlags = [],
        public array $riskFlags = [],
        public array $drillDown = [],
        public array $permissions = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'line_id' => $this->lineId,
            'component' => $this->component,
            'direction' => $this->direction,
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
            'stage_id' => $this->stageId,
            'contract_id' => $this->contractId,
            'act_id' => $this->actId,
            'budget_article_id' => $this->budgetArticleId,
            'responsibility_center_id' => $this->responsibilityCenterId,
            'counterparty_id' => $this->counterpartyId,
            'period' => $this->period,
            'recognition_date' => $this->recognitionDate,
            'recognition_event' => $this->recognitionEvent,
            'attribution_rule' => $this->attributionRule,
            'currency' => $this->currency,
            'amount_without_vat' => $this->amountWithoutVat,
            'vat_amount' => $this->vatAmount,
            'management_amount' => $this->managementAmount,
            'management_currency' => $this->managementCurrency,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'source_line_id' => $this->sourceLineId,
            'source_document_number' => $this->sourceDocumentNumber,
            'document_date' => $this->documentDate,
            'source' => $this->source,
            'confirmation' => $this->confirmation,
            'freshness' => $this->freshness,
            'reconciliation' => $this->reconciliation,
            'quality_status' => $this->qualityStatus,
            'confirmation_status' => $this->confirmationStatus,
            'freshness_status' => $this->freshnessStatus,
            'reconciliation_status' => $this->reconciliationStatus,
            'problem_flags' => $this->normalizeFlags($this->problemFlags),
            'risk_flags' => $this->normalizeFlags($this->riskFlags),
            'drill_down' => $this->drillDown,
            'permissions' => $this->permissions,
        ];
    }

    /**
     * @param array<int, BackedEnum|string> $flags
     * @return array<int, string>
     */
    private function normalizeFlags(array $flags): array
    {
        $values = [];

        foreach ($flags as $flag) {
            $values[] = $flag instanceof BackedEnum ? (string) $flag->value : $flag;
        }

        return $values;
    }
}
