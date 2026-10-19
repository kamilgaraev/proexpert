<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Factory;

use App\BusinessModules\Addons\EstimateGeneration\Application\Review\EstimatePrerequisiteBacklog;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final readonly class FactoryPlanningInspector
{
    public function __construct(private EstimatePrerequisiteBacklog $backlog) {}

    public function inspect(EstimateGenerationSession $session, string $sourceCheckpointId, array $capture): array
    {
        $result = self::stop('unavailable', $sourceCheckpointId, (int) $session->state_version);
        if (! $capture['within_budget']) {
            return [...$result, 'limitations' => $capture['limitations']];
        }
        $technology = $capture['technology'];
        $completeness = $capture['completeness'];
        $catalogs = $capture['catalog_refs'];
        if ($technology === null || $completeness === null
            || $technology['input_fingerprint'] !== $capture['model_token']
            || $completeness['input_fingerprint'] !== $capture['model_token']
            || $technology['catalog_version'] !== $catalogs['technology_version']
            || $technology['catalog_hash'] !== $catalogs['technology_hash']
            || $completeness['catalog_version'] !== $catalogs['technology_version']
            || $completeness['catalog_hash'] !== $catalogs['technology_hash']
            || $completeness['rule_catalog_version'] !== $catalogs['rules_version']
            || $completeness['rule_catalog_hash'] !== $catalogs['rules_hash']) {
            return [...$result, 'limitations' => ['planning_projection_not_current']];
        }
        $backlog = $this->backlog->current($session);
        if ($backlog['needs_refresh'] || ! $backlog['native_questions']['available']) {
            return [...$result, 'limitations' => ['planning_projection_not_current']];
        }
        $refs = [];
        foreach ($backlog['native_questions']['questions'] as $question) {
            $refs[] = ['key' => $question['key'], 'version' => $question['answer']['version'],
                'entity_id' => $question['answer']['entity_id'], 'parameter' => $question['answer']['parameter'],
                'can_answer' => $question['can_answer'], 'finding_key' => null];
        }
        $total = $backlog['native_questions']['total_questions'];
        $partial = $backlog['native_questions']['truncated'];
        foreach ($backlog['groups'] as $group) {
            $total += $group['total'];
            $partial = $partial || $group['partial'];
            foreach ($group['questions'] as $question) {
                $refs[] = ['key' => $question['key'], 'version' => $group['version'],
                    'entity_id' => $question['target']['entity_id'] ?? null, 'parameter' => $question['input_fact_type'],
                    'can_answer' => $question['can_answer'], 'finding_key' => $group['finding_key']];
            }
        }
        if ($total > 50 || count($refs) !== $total || $partial) {
            return [...$result, 'limitations' => ['planning_question_budget_exceeded']];
        }
        $limitations = ['material_coverage_not_checked', 'work_coverage_not_checked', 'price_coverage_not_checked'];
        $sourceReview = $technology['limitations'] !== [] || $completeness['limitations'] !== []
            || array_diff($backlog['native_questions']['limitations'], ['questions_truncated']) !== [];
        $findings = array_filter($completeness['findings'], static fn ($finding): bool => ! in_array($finding->status, ['satisfied', 'not_applicable', 'excluded'], true));
        if ($sourceReview || $findings !== []) {
            $limitations[] = 'planning_review_required';
        }
        $answerable = array_filter($refs, static fn (array $ref): bool => $ref['can_answer']);

        return [...$result, 'outcome' => $refs !== [] ? 'awaiting_input' : ($sourceReview || $findings !== [] ? 'source_review_required' : 'planning_required'),
            'question_refs' => $refs, 'total_questions' => $total,
            'planning_refs' => ['technology_run_id' => $technology['run_id'], 'completeness_run_id' => $completeness['run_id'],
                'input_fingerprint' => $capture['model_token'], 'catalogs' => $catalogs,
                'recommendations_count' => count($technology['recommendations']), 'findings_count' => count($completeness['findings'])],
            'next_action' => $answerable !== [] ? 'answer_prerequisite'
                : ($refs !== [] || $sourceReview || $findings !== [] ? 'review_source' : 'stop'),
            'limitations' => $limitations];
    }

    public static function stop(string $outcome, string $sourceCheckpointId, int $stateVersion, array $limitations = []): array
    {
        return ['step' => 'inspect_plan', 'outcome' => $outcome, 'source_checkpoint_id' => $sourceCheckpointId,
            'result_state_version' => $stateVersion, 'planning_refs' => null, 'question_refs' => [], 'total_questions' => 0,
            'next_action' => 'stop', 'limitations' => $limitations, 'estimate_complete' => false,
            'paid_actions_enabled' => false, 'cost_units' => '0'];
    }
}
