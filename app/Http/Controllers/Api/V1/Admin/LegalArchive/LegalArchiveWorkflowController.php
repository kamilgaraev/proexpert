<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowInstance;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowStep;
use App\Http\Requests\Api\V1\Admin\LegalArchive\DecideLegalArchiveWorkflowRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\SubmitLegalArchiveWorkflowRequest;
use App\Http\Resources\Api\V1\Admin\LegalArchive\LegalArchiveWorkflowResource;
use App\Http\Responses\AdminResponse;
use App\Services\LegalArchive\LegalArchiveRegistryService;
use App\Services\LegalArchive\Workflow\DTO\WorkflowDecisionInput;
use App\Services\LegalArchive\Workflow\DTO\WorkflowOverride;
use App\Services\LegalArchive\Workflow\LegalDocumentWorkflowService;
use Illuminate\Http\JsonResponse;
use Throwable;

use function trans_message;

final class LegalArchiveWorkflowController extends LegalArchiveApiController
{
    public function __construct(
        private readonly LegalArchiveRegistryService $registry,
        private readonly LegalDocumentWorkflowService $workflow,
    ) {}

    public function submit(SubmitLegalArchiveWorkflowRequest $request, string $legalDocument): JsonResponse
    {
        try {
            $owner = $this->registry->findForOrganization($this->organizationId($request), (int) $legalDocument);
            if ($owner === null) {
                throw new \Illuminate\Auth\Access\AuthorizationException;
            }
            $instance = $this->workflow->submit(
                $owner,
                (int) $request->validated('document_version_id'),
                $this->actor($request),
                new WorkflowOverride(
                    idempotencyKey: (string) $request->validated('idempotency_key'),
                    templateId: $request->validated('template_id'),
                    stepOverrides: (array) ($request->validated('step_overrides') ?? []),
                    additionalSteps: (array) ($request->validated('additional_steps') ?? []),
                    expectedDocumentLockVersion: (int) $request->validated('lock_version'),
                ),
            );

            return $this->etag(AdminResponse::success(new LegalArchiveWorkflowResource($instance), trans_message('legal_archive.messages.workflow_submitted'), 201, [
                'document_lock_version' => (int) $owner->fresh()->lock_version,
                'idempotency_key' => (string) $request->validated('idempotency_key'),
            ]), $owner->fresh());
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'workflow_submit', ['document_id' => $legalDocument]);
        }
    }

    public function approve(DecideLegalArchiveWorkflowRequest $request, string $legalWorkflowStep): JsonResponse
    {
        try {
            return $this->decide($request, $legalWorkflowStep, 'approve');
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'workflow_approve', ['step_id' => $legalWorkflowStep]);
        }
    }

    public function reject(DecideLegalArchiveWorkflowRequest $request, string $legalWorkflowStep): JsonResponse
    {
        try {
            return $this->decide($request, $legalWorkflowStep, 'reject');
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'workflow_reject', ['step_id' => $legalWorkflowStep]);
        }
    }

    public function returnForRevision(DecideLegalArchiveWorkflowRequest $request, string $legalWorkflowStep): JsonResponse
    {
        try {
            return $this->decide($request, $legalWorkflowStep, 'return');
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'workflow_return', ['step_id' => $legalWorkflowStep]);
        }
    }

    public function reassign(DecideLegalArchiveWorkflowRequest $request, string $legalWorkflowStep): JsonResponse
    {
        try {
            return $this->decide($request, $legalWorkflowStep, 'reassign');
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'workflow_reassign', ['step_id' => $legalWorkflowStep]);
        }
    }

    public function cancel(DecideLegalArchiveWorkflowRequest $request, string $legalWorkflowInstance): JsonResponse
    {
        try {
            $workflow = LegalWorkflowInstance::query()->forOrganization($this->organizationId($request))->whereKey((int) $legalWorkflowInstance)->first();
            if (! $workflow instanceof LegalWorkflowInstance) {
                throw new \Illuminate\Auth\Access\AuthorizationException;
            }
            $updated = $this->workflow->cancel($workflow, $this->actor($request), $this->decisionInput($request, 'cancel'));

            return $this->etag(AdminResponse::success(new LegalArchiveWorkflowResource($updated), trans_message('legal_archive.messages.workflow_cancelled'), 200, [
                'idempotency_key' => (string) $request->validated('idempotency_key'),
            ]), $workflow->document()->firstOrFail()->fresh());
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'workflow_cancel', ['instance_id' => $legalWorkflowInstance]);
        }
    }

    private function decide(DecideLegalArchiveWorkflowRequest $request, string $id, string $action): JsonResponse
    {
        $step = LegalWorkflowStep::query()->where('organization_id', $this->organizationId($request))->whereKey((int) $id)->first();
        if (! $step instanceof LegalWorkflowStep) {
            throw new \Illuminate\Auth\Access\AuthorizationException;
        }
        $instance = $this->workflow->decide($step, $this->actor($request), $this->decisionInput($request, $action));

        return $this->etag(AdminResponse::success(new LegalArchiveWorkflowResource($instance), trans_message('legal_archive.messages.workflow_decided'), 200, [
            'idempotency_key' => (string) $request->validated('idempotency_key'),
        ]), $instance->document()->firstOrFail()->fresh());
    }

    private function decisionInput(DecideLegalArchiveWorkflowRequest $request, string $action): WorkflowDecisionInput
    {
        return new WorkflowDecisionInput(
            action: $action,
            idempotencyKey: (string) $request->validated('idempotency_key'),
            expectedInstanceLockVersion: (int) $request->validated('instance_lock_version'),
            expectedStepLockVersion: (int) $request->validated('step_lock_version'),
            comment: $request->validated('comment'),
            reason: $request->validated('reason'),
            reassignActorType: $request->validated('target_actor_type'),
            reassignActorReference: $request->validated('target_actor_id'),
            dueAt: $request->validated('due_at'),
        );
    }
}
