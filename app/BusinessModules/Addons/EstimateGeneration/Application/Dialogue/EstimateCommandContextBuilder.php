<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelValueFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendation;
use RuntimeException;

final class EstimateCommandContextBuilder
{
    private const MAX_FACTS = 100;

    private const MAX_RECOMMENDATIONS = 50;

    private const MAX_ROWS = 200;

    private const MAX_EVIDENCE = 100;

    private const MAX_OPTIONS = 200;

    private const MAX_BYTES = 65536;

    private const MAX_ESTIMATED_TOKENS = 16000;

    private const MAX_DEPTH = 12;

    public function __construct(private readonly ?ProjectModelRepository $models = null) {}

    /** @return array<string, mixed> */
    public function build(EstimateGenerationSession $session): array
    {
        $analysis = is_array($session->analysis_payload) ? $session->analysis_payload : [];
        $draft = is_array($session->draft_payload) ? $session->draft_payload : [];
        $facts = $this->lists($analysis, ['facts', 'building_model.facts']);
        $recommendations = $this->lists($analysis, ['technology_recommendations', 'planning.technology_recommendations', 'planning.recommendations']);
        if ($this->models !== null) {
            $facts = array_map(
                $this->repositoryFact(...),
                $this->models->currentFacts(
                    (int) $session->organization_id,
                    (int) $session->project_id,
                    (int) $session->getKey(),
                    limit: self::MAX_FACTS + 1,
                ),
            );
            $run = $this->models->currentTechnologyRecommendations(
                (int) $session->organization_id,
                (int) $session->project_id,
                (int) $session->getKey(),
            );
            $recommendations = $run === null ? [] : array_map(
                fn (TechnologyRecommendation $recommendation): array => $this->repositoryRecommendation($recommendation, $run),
                array_values(array_filter($run['recommendations'] ?? [], static fn (mixed $item): bool => $item instanceof TechnologyRecommendation)),
            );
        }
        $evidence = $this->lists($analysis, ['evidence', 'building_model.evidence']);
        $rows = $this->draftRows($draft);
        $facts = $this->scoped($facts, $session);
        $recommendations = $this->scoped($recommendations, $session);
        $evidence = $this->scoped($evidence, $session);
        $rows = $this->scoped($rows, $session);

        $this->assertBudget($facts, self::MAX_FACTS, 'facts');
        $this->assertBudget($recommendations, self::MAX_RECOMMENDATIONS, 'recommendations');
        $this->assertBudget($rows, self::MAX_ROWS, 'draft_rows');
        $this->assertBudget($evidence, self::MAX_EVIDENCE, 'evidence');
        $this->assertBudget(array_merge(...array_map(
            static fn (array $recommendation): array => is_array($recommendation['options'] ?? null) ? $recommendation['options'] : [],
            $recommendations,
        )), self::MAX_OPTIONS, 'options');

        $context = [
            'scope' => [
                'organization_id' => (int) $session->organization_id,
                'project_id' => (int) $session->project_id,
                'session_id' => (int) $session->getKey(),
            ],
            'snapshot' => ['state_version' => (int) $session->state_version],
            'facts' => array_map($this->fact(...), $facts),
            'recommendations' => array_map($this->recommendation(...), $recommendations),
            'draft_rows' => array_map($this->row(...), $rows),
            'evidence' => array_map($this->evidence(...), $evidence),
        ];
        $context['allowed_references'] = [
            'fact_keys' => array_values(array_filter(array_column($context['facts'], 'stable_key'), 'is_string')),
            'decision_keys' => array_values(array_filter(array_column($context['recommendations'], 'decision_key'), 'is_string')),
            'option_ids' => array_values(array_unique(array_merge(...array_map(
                static fn (array $item): array => array_values(array_filter(array_column($item['options'], 'id'), 'is_string')),
                $context['recommendations'],
            )))),
            'evidence_ids' => array_values(array_filter(array_column($context['evidence'], 'id'), 'is_string')),
        ];
        if ($context['allowed_references']['fact_keys'] === []
            && $context['allowed_references']['decision_keys'] === []
            && $context['draft_rows'] === []) {
            throw new RuntimeException('estimate_generation.command_context_review_required:empty');
        }
        $canonical = $this->canonicalJson($context);
        if (strlen($canonical) > self::MAX_BYTES || (int) ceil(strlen($canonical) / 4) > self::MAX_ESTIMATED_TOKENS) {
            throw new RuntimeException('estimate_generation.command_context_review_required');
        }
        $context['fingerprint'] = 'sha256:'.hash('sha256', $canonical);

        return $context;
    }

    /** @return array<string, mixed> */
    private function repositoryFact(Fact $fact): array
    {
        return [
            'organization_id' => $fact->organizationId,
            'project_id' => $fact->projectId,
            'session_id' => $fact->sessionId,
            'stable_key' => $fact->id,
            'assertion_stable_key' => $fact->id,
            'label' => $this->factLabel($fact->type),
            'type' => $fact->type,
            'value' => $fact->value,
            'unit' => $fact->unit,
            'status' => $fact->status,
            'version' => $fact->version,
            'source_version' => $fact->sourceVersion,
            'value_fingerprint' => ProjectModelValueFingerprint::for($fact->value),
            'decision_version' => $fact->version,
            'evidence_ids' => $fact->evidenceIds,
        ];
    }

    /** @param array<string, mixed> $run @return array<string, mixed> */
    private function repositoryRecommendation(TechnologyRecommendation $recommendation, array $run): array
    {
        return [
            'organization_id' => $recommendation->organizationId,
            'project_id' => $recommendation->projectId,
            'session_id' => $recommendation->sessionId,
            'decision_key' => $recommendation->decisionKey,
            'label' => $recommendation->question,
            'question' => $recommendation->question,
            'rationale' => $recommendation->recommended?->explanation,
            'planning_run_id' => (int) ($run['run_id'] ?? 0),
            'decision_version' => 1,
            'source_version' => $recommendation->sourceVersion,
            'selected_option' => $recommendation->recommended?->system->id,
            'options' => array_map(static fn ($option): array => [
                'id' => $option->system->id,
                'label' => $option->label,
                'applicable' => $option->applicabilityStatus === 'applicable',
                'availability' => $option->applicabilityStatus === 'applicable' ? 'available' : 'unavailable',
                'work_packages' => $option->system->costPreview['work_packages'] ?? [],
            ], $recommendation->options),
        ];
    }

    private function factLabel(string $type): string
    {
        return match ($type) {
            'area', 'room_area' => 'Площадь помещения',
            'roof_system' => 'Система кровли',
            default => 'Характеристика проекта',
        };
    }

    /** @param array<string, mixed> $payload @param string[] $paths @return array<int, array<string, mixed>> */
    private function lists(array $payload, array $paths): array
    {
        foreach ($paths as $path) {
            $value = $payload;
            foreach (explode('.', $path) as $segment) {
                $value = is_array($value) ? ($value[$segment] ?? null) : null;
            }
            if (is_array($value) && array_is_list($value)) {
                return array_values(array_filter($value, 'is_array'));
            }
        }

        return [];
    }

    /** @return array<int, array<string, mixed>> */
    private function draftRows(array $draft): array
    {
        $rows = [];
        foreach ($draft['local_estimates'] ?? [] as $estimate) {
            foreach (is_array($estimate) ? ($estimate['sections'] ?? []) : [] as $section) {
                foreach (is_array($section) ? ($section['work_items'] ?? []) : [] as $row) {
                    if (is_array($row)) {
                        $rows[] = $row;
                    }
                }
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function fact(array $fact): array
    {
        return array_intersect_key($fact, array_flip([
            'stable_key', 'assertion_stable_key', 'label', 'type', 'value', 'unit', 'status', 'version',
            'source_version', 'value_fingerprint', 'decision_version', 'evidence_ids',
        ]));
    }

    /** @return array<string, mixed> */
    private function recommendation(array $recommendation): array
    {
        $result = array_intersect_key($recommendation, array_flip([
            'decision_key', 'label', 'question', 'rationale', 'planning_run_id', 'decision_version',
            'source_version', 'applicability', 'evidence_ids', 'selected_option',
        ]));
        $options = is_array($recommendation['options'] ?? null) ? $recommendation['options'] : ($recommendation['alternatives'] ?? []);
        $result['options'] = array_values(array_map(function (array $option): array {
            $summary = array_intersect_key($option, array_flip([
                'id', 'key', 'label', 'name', 'applicable', 'availability',
            ]));
            $summary['work_packages'] = $this->packageSummaries($option['work_packages'] ?? []);

            return $summary;
        }, array_filter(is_array($options) ? $options : [], 'is_array')));

        return $result;
    }

    /** @return array<string, mixed> */
    private function row(array $row): array
    {
        $result = array_intersect_key($row, array_flip(['key', 'name', 'quantity', 'unit', 'total_cost', 'pricing_status', 'pricing_blocker']));
        $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
        $result['dependency_keys'] = array_values(array_filter(array_map('strval', is_array($metadata['dependency_keys'] ?? null) ? $metadata['dependency_keys'] : [])));

        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    private function packageSummaries(mixed $packages): array
    {
        $packages = is_array($packages) ? array_values(array_filter($packages, 'is_array')) : [];
        $this->assertBudget($packages, self::MAX_ROWS, 'work_packages');

        return array_map(static function (array $package): array {
            $summary = array_intersect_key($package, array_flip([
                'id', 'key', 'name', 'quantity', 'unit', 'norm_id', 'norm_code', 'price', 'total_cost', 'pricing_status',
            ]));
            $summary['work_item_count'] = count(is_array($package['work_items'] ?? null) ? $package['work_items'] : []);

            return $summary;
        }, $packages);
    }

    /** @return array<string, mixed> */
    private function evidence(array $evidence): array
    {
        return array_intersect_key($evidence, array_flip(['id', 'artifact_id', 'source_version', 'page', 'sheet', 'region', 'native_reference']));
    }

    private function assertBudget(array $items, int $limit, string $kind): void
    {
        if (count($items) > $limit) {
            throw new RuntimeException('estimate_generation.command_context_review_required:'.$kind);
        }
    }

    /** @param array<int, array<string, mixed>> $items @return array<int, array<string, mixed>> */
    private function scoped(array $items, EstimateGenerationSession $session): array
    {
        return array_values(array_filter($items, static function (array $item) use ($session): bool {
            $checks = [
                'organization_id' => (int) $session->organization_id,
                'project_id' => (int) $session->project_id,
                'session_id' => (int) $session->getKey(),
            ];
            foreach ($checks as $field => $expected) {
                if (array_key_exists($field, $item) && (int) $item[$field] !== $expected) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function canonicalJson(array $value): string
    {
        if ($this->depth($value) > self::MAX_DEPTH) {
            throw new RuntimeException('estimate_generation.command_context_review_required:depth');
        }
        $sort = static function (array &$item) use (&$sort): void {
            if (! array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as &$nested) {
                if (is_array($nested)) {
                    $sort($nested);
                }
            }
        };
        $sort($value);

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function depth(array $value, int $current = 1): int
    {
        $maximum = $current;
        foreach ($value as $item) {
            if (is_array($item)) {
                $maximum = max($maximum, $this->depth($item, $current + 1));
            }
        }

        return $maximum;
    }
}
