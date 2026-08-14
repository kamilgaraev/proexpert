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

    /** @return array{accepted:bool,findings:list<array<string,mixed>>,quarantined_findings:list<array{index:int,reason:string}>} */
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
            return $this->validate($claim->result->payload, $input, true);
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
                'quarantined_findings' => $validated['quarantined_findings'],
            ], $physicalAttemptId));

            return $validated;
        } catch (Throwable $exception) {
            $this->runs->fail($claim->runId, $claim->ownerUuid, new AiRoleRunFailure(
                'estimate_auditor_failed', $physicalAttemptId !== null, $physicalAttemptId,
            ));
            throw $exception;
        }
    }

    /** @return array{accepted:bool,findings:list<array<string,mixed>>,quarantined_findings:list<array{index:int,reason:string}>} */
    private function validate(mixed $payload, EstimateAuditInput $input, bool $stored = false): array
    {
        if (! is_array($payload)
            || ! is_array($payload['findings'] ?? null) || ! array_is_list($payload['findings'])
            || count($payload['findings']) > 1000) {
            throw new InvalidArgumentException('estimate_audit_result_shape_invalid');
        }
        $allowedFacts = array_fill_keys(array_column($input->facts, 'id'), true);
        $allowedLocators = [];
        $locatorByFact = [];
        foreach ($input->evidence as $evidence) {
            $allowedLocators[$evidence['fact_id'].'|'.$this->locatorFingerprint($evidence['locator'])] = true;
            $locatorByFact[$evidence['fact_id']] ??= $evidence['locator'];
        }
        $unique = [];
        $quarantined = $stored && is_array($payload['quarantined_findings'] ?? null)
            ? array_values($payload['quarantined_findings'])
            : [];
        if (count($quarantined) > 1000) {
            throw new InvalidArgumentException('estimate_audit_quarantine_invalid');
        }
        foreach ($quarantined as $item) {
            if (! is_array($item) || array_keys($item) !== ['index', 'reason']
                || ! is_int($item['index']) || $item['index'] < 0
                || ! is_string($item['reason']) || trim($item['reason']) === ''
                || mb_strlen($item['reason']) > 200) {
                throw new InvalidArgumentException('estimate_audit_quarantine_invalid');
            }
        }
        foreach ($payload['findings'] as $index => $record) {
            try {
                if (! is_array($record) || array_is_list($record)) {
                    throw new InvalidArgumentException('estimate_audit_finding_shape_invalid');
                }
                $sourceFactIds = $record['source_fact_ids'] ?? null;
                if (! is_array($sourceFactIds) || ! array_is_list($sourceFactIds) || $sourceFactIds === []) {
                    throw new InvalidArgumentException('estimate_audit_finding_invalid');
                }
                foreach ($sourceFactIds as $factId) {
                    if (! is_string($factId) || ! isset($allowedFacts[$factId])) {
                        throw new InvalidArgumentException('estimate_audit_source_fact_invalid');
                    }
                }
                $locator = $stored
                    ? ($record['source_locator'] ?? null)
                    : ($locatorByFact[$sourceFactIds[0]] ?? null);
                if (! is_array($locator) || $locator === []) {
                    throw new InvalidArgumentException('estimate_audit_source_locator_invalid');
                }
                $projected = $stored ? $record : [
                    'finding_id' => 'finding:'.substr(hash('sha256', json_encode([
                        $input->fingerprint(), $index, $record['type'] ?? null,
                        $sourceFactIds, $record['reason'] ?? null,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), 0, 32),
                    'type' => $record['type'] ?? null,
                    'severity' => $record['severity'] ?? null,
                    'item_key' => $record['item_key'] ?? null,
                    'source_fact_ids' => array_values($sourceFactIds),
                    'source_locator' => $locator,
                    'reason' => $record['reason'] ?? null,
                    'impact' => $record['impact'] ?? null,
                    'recommendation' => $record['recommendation'] ?? null,
                    'correction' => $record['correction'] ?? null,
                ];
                $finding = EstimateAuditFinding::fromArray($projected);
                if (isset($unique[$finding->findingId])) {
                    throw new InvalidArgumentException('estimate_audit_finding_duplicate');
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
            } catch (InvalidArgumentException $exception) {
                if ($stored) {
                    throw $exception;
                }
                $quarantined[] = ['index' => $index, 'reason' => $exception->getMessage()];
            }
        }

        return [
            'accepted' => $unique === [] && $quarantined === [],
            'findings' => array_values($unique),
            'quarantined_findings' => $quarantined,
        ];
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
