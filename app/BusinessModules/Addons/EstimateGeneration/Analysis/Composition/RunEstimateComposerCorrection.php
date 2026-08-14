<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\AiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunFailure;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class RunEstimateComposerCorrection
{
    public const PROMPT_CONTRACT = 'estimate-composer-correction:v1';

    public function __construct(
        private AiRoleRunRepository $runs,
        private EstimateComposerCorrectionModel $model,
        private string $modelName,
    ) {}

    /** @return list<array<string,mixed>> */
    public function run(EstimateComposerCorrectionInput $input): array
    {
        $scope = $input->audit;
        $runInput = new AiRoleRunInput(
            $scope->organizationId,
            $scope->projectId,
            $scope->sessionId,
            null,
            null,
            'estimate_correction_cycle',
            $scope->sessionId.':'.$scope->cycle,
            'composer-correction:'.$scope->cycle.':'.$scope->snapshotToken,
            AiAnalysisRole::EstimateComposer,
            $this->modelName,
            self::PROMPT_CONTRACT,
            $input->fingerprint(),
        );
        $owner = AiOperationContext::deterministicId('estimate-correction-owner|'.$input->fingerprint().'|'.bin2hex(random_bytes(16)));
        $claim = $this->runs->claim($runInput, $owner);
        if ($claim->disposition === 'replay' && $claim->result !== null) {
            return $this->validate($claim->result->payload['corrections'] ?? null, $input);
        }
        if ($claim->disposition !== 'owned' || $claim->ownerUuid === null) {
            throw new RuntimeException('estimate_composer_correction_role_run_'.$claim->disposition);
        }
        $attemptId = null;
        try {
            $raw = $this->model->correct($input, function (string $id) use ($claim, &$attemptId): void {
                $this->runs->startPhysicalAttempt($claim->runId, $claim->ownerUuid, $id);
                $attemptId = $id;
            });
            if ($attemptId === null || array_keys($raw) !== ['corrections']) {
                throw new InvalidArgumentException('estimate_composer_correction_result_invalid');
            }
            $corrections = $this->validate($raw['corrections'], $input);
            $this->runs->complete($claim->runId, $claim->ownerUuid, new AiRoleRunResult([
                'schema_version' => 1,
                'corrections' => $corrections,
            ], $attemptId));

            return $corrections;
        } catch (Throwable $exception) {
            $this->runs->fail($claim->runId, $claim->ownerUuid, new AiRoleRunFailure(
                'estimate_composer_correction_failed', $attemptId !== null, $attemptId,
            ));
            throw $exception;
        }
    }

    /** @return list<array<string,mixed>> */
    private function validate(mixed $payload, EstimateComposerCorrectionInput $input): array
    {
        if (! is_array($payload) || ! array_is_list($payload) || count($payload) > 100) {
            throw new InvalidArgumentException('estimate_composer_correction_result_invalid');
        }
        $findingIds = array_fill_keys(array_column($input->findings, 'finding_id'), true);
        $factIds = array_fill_keys(array_column($input->audit->facts, 'id'), true);
        $quantityIds = array_fill_keys(array_column($input->audit->derivedQuantities, 'id'), true);
        $items = [];
        foreach ($input->audit->draft['local_estimates'] ?? [] as $estimate) {
            foreach (is_array($estimate) ? ($estimate['sections'] ?? []) : [] as $section) {
                foreach (is_array($section) ? ($section['work_items'] ?? []) : [] as $item) {
                    if (is_array($item) && is_string($item['key'] ?? null)) {
                        $items[$item['key']] = self::itemFingerprint($item);
                    }
                }
            }
        }
        $validated = [];
        foreach ($payload as $correction) {
            if (! is_array($correction) || ! isset($findingIds[$correction['finding_id'] ?? ''])) {
                throw new InvalidArgumentException('estimate_composer_correction_finding_invalid');
            }
            $operation = $correction['operation'] ?? null;
            if ($operation === 'add_work') {
                $this->validateAddWork($correction, $factIds, $quantityIds, $items);
            } elseif (in_array($operation, ['replace_quantity', 'replace_unit'], true)) {
                $this->validateReplacement($correction, $quantityIds, $items);
            } else {
                throw new InvalidArgumentException('estimate_composer_correction_operation_invalid');
            }
            $validated[] = $correction;
        }

        return $validated;
    }

    private function validateAddWork(array $correction, array $factIds, array $quantityIds, array $items): void
    {
        $expected = ['operation', 'finding_id', 'work_key', 'name', 'derived_quantity_id', 'source_fact_ids'];
        if ($this->sortedKeys($correction) !== $this->sortedKeys(array_fill_keys($expected, null))
            || ! $this->identifier($correction['work_key'] ?? null) || isset($items[$correction['work_key']])
            || ! is_string($correction['name'] ?? null) || trim($correction['name']) === ''
            || mb_strlen($correction['name']) > 300 || preg_match('/\p{Cyrillic}/u', $correction['name']) !== 1
            || ! isset($quantityIds[$correction['derived_quantity_id'] ?? ''])
            || ! is_array($correction['source_fact_ids'] ?? null) || $correction['source_fact_ids'] === []) {
            throw new InvalidArgumentException('estimate_composer_correction_add_invalid');
        }
        foreach ($correction['source_fact_ids'] as $factId) {
            if (! isset($factIds[$factId])) {
                throw new InvalidArgumentException('estimate_composer_correction_source_invalid');
            }
        }
    }

    private function validateReplacement(array $correction, array $quantityIds, array $items): void
    {
        $expected = ['operation', 'finding_id', 'target_item_key', 'expected_target_fingerprint', 'derived_quantity_id'];
        if ($this->sortedKeys($correction) !== $this->sortedKeys(array_fill_keys($expected, null))
            || ! isset($items[$correction['target_item_key'] ?? ''])
            || ! hash_equals($items[$correction['target_item_key']], (string) ($correction['expected_target_fingerprint'] ?? ''))
            || ! isset($quantityIds[$correction['derived_quantity_id'] ?? ''])) {
            throw new InvalidArgumentException('estimate_composer_correction_replacement_invalid');
        }
    }

    /** @param array<string,mixed> $item */
    private static function itemFingerprint(array $item): string
    {
        return \App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle::itemFingerprint($item);
    }

    private function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$/D', $value) === 1;
    }

    private function sortedKeys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);

        return $keys;
    }
}
