<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Presentation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use Closure;
use Illuminate\Database\DatabaseManager;

use function trans_message;

final readonly class AnalysisBasisPayloadService
{
    private Closure $translate;

    public function __construct(
        private DatabaseManager $database,
        private ProjectModelRepository $models,
        ?Closure $translate = null,
    ) {
        $this->translate = $translate ?? static fn (string $key): string => trans_message($key);
    }

    /** @return array<string, mixed>|null */
    public function handle(int $organizationId, int $projectId, int $sessionId, string $type, string $id): ?array
    {
        return match ($type) {
            'quantity' => $this->quantity($organizationId, $projectId, $sessionId, $id),
            'question' => $this->question($organizationId, $projectId, $sessionId, $id),
            default => null,
        };
    }

    /** @return array<string, mixed>|null */
    private function quantity(int $organizationId, int $projectId, int $sessionId, string $id): ?array
    {
        $row = $this->database->table('estimate_generation_project_model_derived_quantity_projections as projection')
            ->join(
                'estimate_generation_project_model_derived_quantities as quantity',
                'quantity.id',
                '=',
                'projection.derived_quantity_id',
            )
            ->where('projection.organization_id', $organizationId)
            ->where('projection.project_id', $projectId)
            ->where('projection.session_id', $sessionId)
            ->where('projection.logical_key', $id)
            ->first([
                'quantity.value',
                'quantity.unit',
                'quantity.formula_identity',
                'quantity.formula_version',
                'quantity.evidence_lineage',
            ]);
        if ($row === null) {
            return null;
        }
        $formula = is_string($row->formula_identity) ? $row->formula_identity : '';
        $formulaKey = match ($formula) {
            'floor_area' => 'estimate_generation.analysis_basis.formula.floor_area',
            'wall_net_area' => 'estimate_generation.analysis_basis.formula.wall_net_area',
            'sloped_roof_area' => 'estimate_generation.analysis_basis.formula.sloped_roof_area',
            default => 'estimate_generation.analysis_basis.formula.deterministic',
        };

        return [
            'type' => 'quantity',
            'id' => $id,
            'title' => ($this->translate)('estimate_generation.analysis_basis.quantity_title'),
            'explanation' => ($this->translate)($formulaKey),
            'value' => is_string($row->value) ? $row->value : null,
            'unit' => (string) $row->unit,
            'sources' => $this->sources(
                $organizationId,
                $projectId,
                $sessionId,
                $this->decodeList($row->evidence_lineage),
            ),
        ];
    }

    /** @return array<string, mixed>|null */
    private function question(int $organizationId, int $projectId, int $sessionId, string $id): ?array
    {
        $understanding = $this->models->currentUnderstanding($organizationId, $projectId, $sessionId);
        foreach (is_array($understanding['questions'] ?? null) ? $understanding['questions'] : [] as $question) {
            if (! is_array($question) || (string) ($question['conflict_id'] ?? $question['id'] ?? '') !== $id) {
                continue;
            }

            return [
                'type' => 'question',
                'id' => $id,
                'title' => ($this->translate)('estimate_generation.analysis_basis.question_title'),
                'explanation' => (string) ($question['reason'] ?? $question['subject'] ?? ''),
                'impact' => (string) ($question['impact'] ?? ''),
                'recommendation' => (string) ($question['recommendation'] ?? ''),
                'sources' => isset($question['source_locator']) && is_array($question['source_locator'])
                    ? [['locator' => $question['source_locator']]]
                    : [],
            ];
        }

        return null;
    }

    /** @param list<mixed> $evidenceIds @return list<array<string, mixed>> */
    private function sources(int $organizationId, int $projectId, int $sessionId, array $evidenceIds): array
    {
        $ids = array_values(array_filter(array_map(static function (mixed $id): ?int {
            if (! is_string($id) || preg_match('/^evidence:(\d+)$/D', $id, $matches) !== 1) {
                return null;
            }

            return (int) $matches[1];
        }, $evidenceIds), static fn (?int $id): bool => $id !== null && $id > 0));
        if ($ids === []) {
            return [];
        }

        return $this->database->table('estimate_generation_evidence')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->whereNull('invalidated_at')
            ->whereIn('id', array_slice(array_unique($ids), 0, 50))
            ->orderBy('id')
            ->get(['id', 'source_ref', 'locator'])
            ->map(fn ($source): array => [
                'id' => 'evidence:'.$source->id,
                'document_id' => $this->documentId((string) $source->source_ref),
                'locator' => $this->decodeObject($source->locator),
            ])->all();
    }

    private function documentId(string $sourceRef): ?int
    {
        if (ctype_digit($sourceRef)) {
            return (int) $sourceRef;
        }
        if (preg_match('/^document:(\d+)$/D', $sourceRef, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /** @return list<mixed> */
    private function decodeList(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }

    /** @return array<string, mixed> */
    private function decodeObject(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : [];
    }
}
