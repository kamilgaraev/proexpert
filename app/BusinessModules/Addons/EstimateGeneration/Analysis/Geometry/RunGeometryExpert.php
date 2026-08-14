<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\AiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunFailure;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
use RuntimeException;
use Throwable;

final readonly class RunGeometryExpert implements GeometryExpertRunner
{
    public const PROMPT_CONTRACT = 'geometry-expert:v1';

    public function __construct(
        private AiRoleRunRepository $runs,
        private GeometryExpertModel $model,
        private DeterministicGeometryCalculator $calculator,
        private string $modelName,
    ) {}

    public function run(GeometryExpertInput $input): GeometryExpertResult
    {
        $runInput = $this->runInput($input);
        $ownerUuid = AiOperationContext::deterministicId(implode('|', [
            'geometry-expert-owner', $runInput->inputFingerprint, bin2hex(random_bytes(16)),
        ]));
        $claim = $this->runs->claim($runInput, $ownerUuid);
        if ($claim->disposition === 'replay' && $claim->result !== null) {
            return GeometryExpertResult::fromArray($claim->result->payload['result'] ?? []);
        }
        if ($claim->disposition !== 'owned' || $claim->ownerUuid === null) {
            throw new RuntimeException('geometry_role_run_'.$claim->disposition);
        }
        $physicalAttemptId = null;
        try {
            $sheets = $this->model->interpret(
                $input,
                function (string $attemptId) use ($claim, &$physicalAttemptId): void {
                    $this->runs->startPhysicalAttempt($claim->runId, $claim->ownerUuid, $attemptId);
                    $physicalAttemptId = $attemptId;
                },
            );
            if ($physicalAttemptId === null) {
                throw new UsageInvariantViolation('Geometry provider returned without a physical attempt identity.');
            }
            $result = $this->calculator->calculate(new GeometryExpertInput(
                $input->organizationId,
                $input->projectId,
                $input->sessionId,
                $input->sourceVersion,
                $sheets,
            ));
            $stored = new AiRoleRunResult([
                'schema_version' => 1,
                'role' => AiAnalysisRole::GeometryExpert->value,
                'formula_version' => DeterministicGeometryCalculator::FORMULA_VERSION,
                'result' => $result->toArray(),
            ], $physicalAttemptId);
            $this->runs->complete($claim->runId, $claim->ownerUuid, $stored);

            return $result;
        } catch (Throwable $exception) {
            $this->runs->fail($claim->runId, $claim->ownerUuid, new AiRoleRunFailure(
                code: 'geometry_expert_failed',
                ambiguous: $physicalAttemptId !== null,
                physicalAttemptId: $physicalAttemptId,
            ));

            throw $exception;
        }
    }

    private function runInput(GeometryExpertInput $input): AiRoleRunInput
    {
        return new AiRoleRunInput(
            organizationId: $input->organizationId,
            projectId: $input->projectId,
            sessionId: $input->sessionId,
            documentId: null,
            pageId: null,
            subjectType: 'estimate_session',
            subjectId: (string) $input->sessionId,
            subjectVersion: $input->sourceVersion,
            role: AiAnalysisRole::GeometryExpert,
            model: $this->modelName,
            promptContractVersion: self::PROMPT_CONTRACT,
            inputFingerprint: hash('sha256', json_encode($input->fingerprintSheets(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        );
    }
}
