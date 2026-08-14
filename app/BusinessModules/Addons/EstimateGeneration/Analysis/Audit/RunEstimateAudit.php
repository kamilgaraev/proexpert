<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\AiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunFailure;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class RunEstimateAudit
{
    public const PROMPT_CONTRACT = 'estimate-auditor:v1';

    public function __construct(
        private AiRoleRunRepository $runs,
        private EstimateAuditModel $model,
        private string $modelName,
    ) {}

    /** @return array{accepted:bool,findings:list<array<string,mixed>>} */
    public function run(EstimateAuditInput $input): array
    {
        $runInput = new AiRoleRunInput(
            $input->organizationId,
            $input->projectId,
            $input->sessionId,
            null,
            null,
            'estimate_audit_cycle',
            $input->sessionId.':'.$input->cycle,
            'audit-cycle:'.$input->cycle.':'.$input->snapshotToken,
            AiAnalysisRole::EstimateAuditor,
            $this->modelName,
            self::PROMPT_CONTRACT,
            $input->fingerprint(),
        );
        $owner = AiOperationContext::deterministicId('estimate-auditor-owner|'.$input->fingerprint().'|'.bin2hex(random_bytes(16)));
        $claim = $this->runs->claim($runInput, $owner);
        if ($claim->disposition === 'replay' && $claim->result !== null) {
            return $this->validate($claim->result->payload, $input);
        }
        if ($claim->disposition !== 'owned' || $claim->ownerUuid === null) {
            throw new RuntimeException('estimate_auditor_role_run_'.$claim->disposition);
        }
        $physicalAttemptId = null;
        try {
            $raw = $this->model->audit($input, function (string $attemptId) use ($claim, &$physicalAttemptId): void {
                $this->runs->startPhysicalAttempt($claim->runId, $claim->ownerUuid, $attemptId);
                $physicalAttemptId = $attemptId;
            });
            if ($physicalAttemptId === null) {
                throw new UsageInvariantViolation('Estimate auditor returned without a physical attempt identity.');
            }
            $validated = $this->validate($raw, $input);
            $this->runs->complete($claim->runId, $claim->ownerUuid, new AiRoleRunResult([
                'accepted' => $validated['accepted'],
                'findings' => $validated['findings'],
            ], $physicalAttemptId));

            return $validated;
        } catch (Throwable $exception) {
            $this->runs->fail($claim->runId, $claim->ownerUuid, new AiRoleRunFailure(
                'estimate_auditor_failed', $physicalAttemptId !== null, $physicalAttemptId,
            ));
            throw $exception;
        }
    }

    /** @return array{accepted:bool,findings:list<array<string,mixed>>} */
    private function validate(mixed $payload, EstimateAuditInput $input): array
    {
        if (! is_array($payload) || array_keys($payload) !== ['accepted', 'findings']
            || ! is_bool($payload['accepted']) || ! is_array($payload['findings']) || ! array_is_list($payload['findings'])
            || count($payload['findings']) > 1000) {
            throw new InvalidArgumentException('estimate_audit_result_shape_invalid');
        }
        if ($payload['accepted'] !== ($payload['findings'] === [])) {
            throw new InvalidArgumentException('estimate_audit_acceptance_invalid');
        }
        $allowedFacts = array_fill_keys(array_column($input->facts, 'id'), true);
        $allowedLocators = [];
        foreach ($input->evidence as $evidence) {
            $allowedLocators[$evidence['fact_id'].'|'.$this->locatorFingerprint($evidence['locator'])] = true;
        }
        $unique = [];
        foreach ($payload['findings'] as $record) {
            if (! is_array($record)) {
                throw new InvalidArgumentException('estimate_audit_finding_shape_invalid');
            }
            $finding = EstimateAuditFinding::fromArray($record);
            if (isset($unique[$finding->findingId])) {
                throw new InvalidArgumentException('estimate_audit_finding_duplicate');
            }
            foreach ($finding->sourceFactIds as $factId) {
                if (! isset($allowedFacts[$factId])) {
                    throw new InvalidArgumentException('estimate_audit_source_fact_invalid');
                }
            }
            $locatorAllowed = false;
            foreach ($finding->sourceFactIds as $factId) {
                if (isset($allowedLocators[$factId.'|'.$this->locatorFingerprint($finding->sourceLocator)])) {
                    $locatorAllowed = true;
                    break;
                }
            }
            if (! $locatorAllowed) {
                throw new InvalidArgumentException('estimate_audit_source_locator_invalid');
            }
            $unique[$finding->findingId] = $finding->toArray();
        }

        return ['accepted' => $payload['accepted'], 'findings' => array_values($unique)];
    }

    private function locatorFingerprint(array $locator): string
    {
        $canonicalize = function (mixed $value) use (&$canonicalize): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $item) {
                $value[$key] = $canonicalize($item);
            }

            return $value;
        };

        return hash('sha256', json_encode($canonicalize($locator), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
