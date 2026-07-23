<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Presentation;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\AnalysisFloorAreaQuantityFactory;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\BuildingQuantityCalculator;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\NormalizedBuildingModelQuantityInputMapper;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use Brick\Math\BigDecimal;

final readonly class BuildingModelPayloadService
{
    public function __construct(
        private BuildingModelReadDataSource $data,
        private NormalizedBuildingModelQuantityInputMapper $mapper = new NormalizedBuildingModelQuantityInputMapper,
        private BuildingQuantityCalculator $calculator = new BuildingQuantityCalculator,
        private QuantityFormulaInputsPresenter $formulaInputs = new QuantityFormulaInputsPresenter,
        private AnalysisFloorAreaQuantityFactory $analysisFloorArea = new AnalysisFloorAreaQuantityFactory,
    ) {}

    /** @return array<string, mixed> */
    public function handle(EstimateGenerationSession $session, int $page = 1, int $perPage = 25): array
    {
        $organizationId = (int) $session->organization_id;
        $projectId = (int) $session->project_id;
        $sessionId = (int) $session->getKey();
        $head = $this->data->latestModel($organizationId, $projectId, $sessionId);
        if ($head === null) {
            return [
                'state_version' => (int) $session->state_version,
                'content_version' => null,
                'building_model' => null,
                'quantities' => ['data' => [], 'meta' => $this->meta(0, 1, $perPage)],
            ];
        }

        $model = NormalizedBuildingModelData::fromArray($head['model']);
        $contentVersion = (string) ($head['content_version'] ?? '');
        if (preg_match('/\Asha256:[a-f0-9]{64}\z/', $contentVersion) !== 1
            || ! hash_equals($model->contentVersion(), $contentVersion)) {
            throw new \UnexpectedValueException('Building model content version is invalid.');
        }
        $calculation = $this->calculator->calculate($this->mapper->map($model));
        $quantitiesByKey = $calculation->all();
        $totalArea = $this->data->totalArea($organizationId, $projectId, $sessionId);
        $documentArea = $this->analysisFloorArea->make([
            'normalized_building_model' => $model->toArray(),
            'document_total_area' => $totalArea,
        ]);
        if ($documentArea !== null) {
            $quantitiesByKey[$documentArea->key] = $documentArea;
        }
        $quantities = array_values($quantitiesByKey);
        $total = count($quantities);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $pageItems = array_slice($quantities, ($page - 1) * $perPage, $perPage);
        $evidenceIds = [];
        foreach ($pageItems as $quantity) {
            foreach ($quantity->evidenceIds as $id) {
                if (ctype_digit($id) && (int) $id > 0) {
                    $evidenceIds[] = (int) $id;
                }
            }
        }
        $evidence = $this->data->evidenceForIds($organizationId, $projectId, $sessionId, array_values(array_unique($evidenceIds)));

        return [
            'state_version' => (int) $session->state_version,
            'content_version' => $contentVersion,
            'building_model' => $model->toArray(),
            'quantities' => [
                'data' => array_map(fn (QuantityData $quantity): array => $this->quantity($quantity, $evidence, $model), $pageItems),
                'meta' => $this->meta($total, $page, $perPage),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public function evidence(EstimateGenerationSession $session, int $evidenceId): ?array
    {
        $organizationId = (int) $session->organization_id;
        $projectId = (int) $session->project_id;
        $sessionId = (int) $session->getKey();
        $row = $this->data->evidence($organizationId, $projectId, $sessionId, $evidenceId);
        if ($row === null) {
            return null;
        }
        if (($row['invalidated_at'] ?? null) !== null) {
            return null;
        }
        $locator = is_array($row['locator'] ?? null) ? $row['locator'] : [];
        $documentId = filter_var($locator['document_id'] ?? null, FILTER_VALIDATE_INT);
        $page = filter_var($locator['page'] ?? null, FILTER_VALIDATE_INT);
        $documents = $documentId !== false && $documentId > 0
            ? $this->data->documentNames($organizationId, $projectId, $sessionId, [$documentId])
            : [];

        return [
            'id' => (int) $row['id'],
            'type' => (string) $row['type'],
            'source_type' => (string) $row['source_type'],
            'document' => $documentId !== false && isset($documents[$documentId]) ? [
                'id' => $documentId,
                'filename' => $documents[$documentId],
                'page_number' => $page !== false && $page > 0 ? $page : null,
            ] : null,
            'source_value' => $this->sourceValue((string) $row['type'], is_array($row['value'] ?? null) ? $row['value'] : []),
            'confidence' => $this->confidence($row['confidence'] ?? 0),
            'transformation' => [
                'source_version' => (string) $row['source_version'],
                'producer_name' => (string) $row['producer_name'],
                'producer_version' => (string) $row['producer_version'],
            ],
            'invalidated' => $row['invalidated_at'] !== null,
            'preview' => null,
        ];
    }

    /** @param array<int, array<string, mixed>> $evidence @return array<string, mixed> */
    private function quantity(QuantityData $quantity, array $evidence, NormalizedBuildingModelData $model): array
    {
        if (! hash_equals($model->modelVersion, $quantity->modelVersion)) {
            throw new \UnexpectedValueException('Quantity building model version is invalid.');
        }
        $ids = array_values(array_map('intval', array_filter($quantity->evidenceIds, 'ctype_digit')));
        $rows = array_values(array_filter(
            array_intersect_key($evidence, array_flip($ids)),
            static fn (array $row): bool => ($row['invalidated_at'] ?? null) === null,
        ));
        $activeIds = array_values(array_map(static fn (array $row): int => (int) $row['id'], $rows));
        sort($activeIds, SORT_NUMERIC);
        $source = $quantity->source->value;
        if ($source === 'evidenced' && $ids !== [] && $rows === []) {
            $source = 'estimated';
        }
        if ($source === 'evidenced' && array_filter($rows, static fn (array $row): bool => ($row['source_type'] ?? null) === 'user_input'
            || (($row['value']['method'] ?? null) === 'user_confirmed')
        ) !== []) {
            $source = 'user_confirmed';
        }
        $confidence = $rows === []
            ? $model->metrics['minimum_confidence']
            : min(array_map(static fn (array $row): float => (float) ($row['confidence'] ?? 0), $rows));

        $reviewBlockers = $quantity->reviewBlockers;
        if ($ids !== [] && $rows === []) {
            $reviewBlockers[] = 'active_evidence_missing';
        }
        $reviewBlockers = array_values(array_unique($reviewBlockers));
        sort($reviewBlockers, SORT_STRING);

        return [
            'key' => $quantity->key,
            'amount' => $quantity->amount,
            'unit' => $quantity->unit,
            'source' => $source,
            'confidence' => $this->confidence($confidence),
            'status' => $reviewBlockers !== [] || $quantity->assumptions !== [] ? 'needs_review' : 'confirmed',
            'formula' => [
                'key' => $quantity->formulaKey,
                'version' => $quantity->formulaVersion,
                'inputs' => $this->formulaInputs->present($quantity->formulaInputs, $activeIds),
            ],
            'evidence_ids' => $activeIds,
            'assumptions' => $quantity->assumptions,
            'review_blockers' => $reviewBlockers,
            'model_version' => $quantity->modelVersion,
        ];
    }

    /** @return array<string, mixed> */
    private function sourceValue(string $type, array $value): array
    {
        return match ($type) {
            'source_fact' => $this->namedValue($value, 'fact_key', 'fact_value'),
            'extracted' => $this->namedValue($value, 'field_key', 'field_value'),
            'measured' => [
                'name' => 'quantity',
                'value' => $this->decimal($value['quantity'] ?? 0),
                'unit' => isset($value['unit']) ? (string) $value['unit'] : null,
                'method' => isset($value['method']) ? (string) $value['method'] : null,
            ],
            'inferred' => ['name' => 'result_code', 'value' => (string) ($value['result_code'] ?? ''), 'unit' => null, 'method' => null],
            'work_item' => ['name' => 'work_code', 'value' => (string) ($value['work_code'] ?? ''), 'unit' => $value['unit'] ?? null, 'method' => null],
            'normative_match' => ['name' => 'norm_key', 'value' => (string) ($value['norm_key'] ?? ''), 'unit' => null, 'method' => null],
            'price' => ['name' => 'amount', 'value' => $this->decimal($value['amount'] ?? 0), 'unit' => $value['currency'] ?? null, 'method' => null],
            default => ['name' => 'value', 'value' => '', 'unit' => null, 'method' => null],
        };
    }

    /** @return array{name: string, value: bool|string, unit: string|null, method: null} */
    private function namedValue(array $value, string $nameKey, string $valueKey): array
    {
        $raw = $value[$valueKey] ?? '';

        return [
            'name' => (string) ($value[$nameKey] ?? ''),
            'value' => is_bool($raw) ? $raw : (is_numeric($raw) ? $this->decimal($raw) : (string) $raw),
            'unit' => isset($value['unit']) ? (string) $value['unit'] : null,
            'method' => null,
        ];
    }

    private function decimal(mixed $value): string
    {
        try {
            return (string) BigDecimal::of((string) $value)->strippedOfTrailingZeros();
        } catch (\Throwable) {
            return '0';
        }
    }

    private function confidence(mixed $value): string
    {
        return number_format(max(0, min(1, (float) $value)), 6, '.', '');
    }

    /** @return array{total: int, current_page: int, per_page: int, last_page: int} */
    private function meta(int $total, int $page, int $perPage): array
    {
        return [
            'total' => $total,
            'current_page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }
}
