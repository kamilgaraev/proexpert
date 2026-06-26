<?php

declare(strict_types=1);

namespace App\Services\ErpControls;

use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventData;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRecorder;
use App\BusinessModules\Core\Mdm\Models\MdmChangeRequest;
use App\BusinessModules\Core\Mdm\Services\MdmDiffService;
use App\Services\ErpControls\DTO\ErpControlDecision;
use Illuminate\Validation\ValidationException;

use function trans_message;

final class ErpControlDecisionService
{
    public function __construct(
        private readonly ErpControlRegistry $registry,
        private readonly ImmutableAuditRecorder $auditRecorder,
        private readonly MdmDiffService $mdmDiffService,
    ) {
    }

    public function check(
        int $organizationId,
        ?int $actorUserId,
        string $operationCode,
        ?string $entityType = null,
        int|string|null $entityId = null,
        array $scope = [],
        ?string $reason = null,
        bool $recordAudit = true,
    ): ErpControlDecision {
        $operation = $this->registry->operation($operationCode);

        if ($operation === null) {
            $decision = new ErpControlDecision(
                allowed: false,
                riskLevel: 'medium',
                operation: $operationCode,
                decision: 'blocked',
                message: trans_message('erp_controls.messages.operation_not_supported'),
                blockers: [[
                    'code' => 'operation_not_supported',
                    'severity' => 'blocking',
                    'message' => trans_message('erp_controls.messages.operation_not_supported'),
                ]],
                requiredActions: ['select_supported_operation'],
                context: [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'scope' => $scope,
                ],
            );

            if ($recordAudit) {
                $this->recordDecision($organizationId, $actorUserId, $decision, $entityType, $entityId, $scope, $reason);
            }

            return $decision;
        }

        $blockers = [];
        $warnings = [];

        if ($operationCode === 'mdm.change_requests.apply' && $entityType === 'mdm_change_request' && $entityId !== null) {
            [$blockers, $warnings] = $this->evaluateMdmChangeRequestApply($organizationId, $actorUserId, (int) $entityId);
        }

        $decision = $this->buildDecision($operation, $blockers, $warnings, $entityType, $entityId, $scope);

        if ($recordAudit) {
            $this->recordDecision($organizationId, $actorUserId, $decision, $entityType, $entityId, $scope, $reason);
        }

        return $decision;
    }

    public function assertAllowed(
        int $organizationId,
        ?int $actorUserId,
        string $operationCode,
        ?string $entityType = null,
        int|string|null $entityId = null,
        array $scope = [],
        ?string $reason = null,
    ): ErpControlDecision {
        $decision = $this->check(
            organizationId: $organizationId,
            actorUserId: $actorUserId,
            operationCode: $operationCode,
            entityType: $entityType,
            entityId: $entityId,
            scope: $scope,
            reason: $reason,
        );

        if ($decision->allowed) {
            return $decision;
        }

        throw ValidationException::withMessages([
            'erp_controls' => [$decision->message],
        ]);
    }

    private function evaluateMdmChangeRequestApply(int $organizationId, ?int $actorUserId, int $changeRequestId): array
    {
        $changeRequest = MdmChangeRequest::query()
            ->where('organization_id', $organizationId)
            ->whereKey($changeRequestId)
            ->first();

        if (! $changeRequest instanceof MdmChangeRequest) {
            return [[
                [
                    'code' => 'entity_not_found',
                    'severity' => 'blocking',
                    'message' => trans_message('erp_controls.messages.entity_not_found'),
                ],
            ], []];
        }

        $warnings = [];
        $blockers = [];
        $hasCriticalChanges = $this->mdmDiffService->hasCriticalChanges($changeRequest->diff ?? []);

        if (! $hasCriticalChanges) {
            $warnings[] = [
                'code' => 'non_critical_mdm_change',
                'severity' => 'warning',
                'message' => trans_message('erp_controls.messages.non_critical_mdm_change'),
            ];
        }

        if ($actorUserId !== null && $hasCriticalChanges && (int) $changeRequest->requested_by_user_id === $actorUserId) {
            $blockers[] = [
                'code' => 'same_actor_mdm_create_apply',
                'severity' => 'blocking',
                'related_operation' => 'mdm.change_requests.create',
                'related_actor' => [
                    'id' => $actorUserId,
                    'name' => null,
                ],
                'message' => trans_message('erp_controls.messages.same_actor_mdm_create_apply'),
            ];
        }

        if ($actorUserId !== null && $hasCriticalChanges && (int) $changeRequest->approver_user_id === $actorUserId) {
            $blockers[] = [
                'code' => 'same_actor_mdm_approve_apply',
                'severity' => 'blocking',
                'related_operation' => 'mdm.change_requests.approve',
                'related_actor' => [
                    'id' => $actorUserId,
                    'name' => null,
                ],
                'message' => trans_message('erp_controls.messages.same_actor_mdm_approve_apply'),
            ];
        }

        return [$blockers, $warnings];
    }

    private function buildDecision(
        array $operation,
        array $blockers,
        array $warnings,
        ?string $entityType,
        int|string|null $entityId,
        array $scope
    ): ErpControlDecision {
        if ($blockers !== []) {
            return new ErpControlDecision(
                allowed: false,
                riskLevel: (string) $operation['risk_level'],
                operation: (string) $operation['code'],
                decision: 'blocked',
                message: $blockers[0]['message'] ?? trans_message('erp_controls.messages.operation_blocked'),
                blockers: $blockers,
                warnings: $warnings,
                requiredActions: ['request_independent_approval'],
                overrideAvailable: false,
                context: [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'scope' => $scope,
                ],
            );
        }

        if ($warnings !== []) {
            return new ErpControlDecision(
                allowed: true,
                riskLevel: (string) $operation['risk_level'],
                operation: (string) $operation['code'],
                decision: 'warning',
                message: trans_message('erp_controls.messages.operation_allowed_with_warnings'),
                warnings: $warnings,
                requiredActions: ['review_warnings'],
                overrideAvailable: false,
                context: [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'scope' => $scope,
                ],
            );
        }

        return new ErpControlDecision(
            allowed: true,
            riskLevel: (string) $operation['risk_level'],
            operation: (string) $operation['code'],
            decision: 'allowed',
            message: trans_message('erp_controls.messages.operation_allowed'),
            context: [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'scope' => $scope,
            ],
        );
    }

    private function recordDecision(
        int $organizationId,
        ?int $actorUserId,
        ErpControlDecision $decision,
        ?string $entityType,
        int|string|null $entityId,
        array $scope,
        ?string $reason
    ): void {
        $operation = $this->registry->operation($decision->operation);

        $this->auditRecorder->record(new ImmutableAuditEventData(
            organizationId: $organizationId,
            projectId: isset($scope['project_id']) ? (int) $scope['project_id'] : null,
            domain: 'sod',
            eventType: 'erp_control.decision.'.$decision->decision,
            action: $decision->operation,
            source: 'erp_controls',
            result: $decision->decision,
            severity: $decision->riskLevel,
            actorType: $actorUserId === null ? 'system' : 'user',
            actorUserId: $actorUserId,
            subjectType: $entityType,
            subjectId: $entityId,
            subjectLabel: $decision->operation,
            reason: $reason,
            domainContext: [
                'operation' => $decision->operation,
                'domain' => $operation['domain'] ?? null,
                'risk_level' => $decision->riskLevel,
                'decision' => $decision->decision,
                'message' => $decision->message,
                'blockers' => $decision->blockers,
                'warnings' => $decision->warnings,
                'required_actions' => $decision->requiredActions,
                'override_available' => $decision->overrideAvailable,
                'scope' => $scope,
            ],
        ));
    }
}
