<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Controllers;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Http\Requests\AssistantEgressEnrollmentRequest;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEnrollmentService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AssistantEgressEnrollmentController extends AbstractAssistantApiController
{
    public function __construct(private readonly OrdinaryAssistantEnrollmentService $enrollments) {}

    public function assign(AssistantEgressEnrollmentRequest $request): JsonResponse
    {
        try {
            $operator = $this->actor($request);
            $this->enrollments->assertPlatform($operator);
            $data = $request->validated();
            $cohort = $this->enrollments->assign($operator, $data['organization_id'], User::query()->findOrFail($data['actor_id']), $data['project_id'], $data['tools']);

            return $this->success($request, ['cohort_id' => $cohort->id, 'status' => $cohort->status, 'policy_version' => $cohort->policy_version,
                'expires_at' => $cohort->expires_at->toISOString()], 201);
        } catch (AssistantEgressDenied) {
            return $this->error($request, 403);
        }
    }

    public function preview(AssistantEgressEnrollmentRequest $request): JsonResponse
    {
        try {
            $owner = $this->actor($request);
            $organizationId = $this->organizationId($request);
            $this->enrollments->assertOwner($owner, $organizationId);
            $data = $request->validated();

            return $this->success($request, $this->enrollments->preview($owner, $organizationId, $data['cohort_id'], $data['quote_id'], $data['input'], $data['sources']), 201);
        } catch (AssistantEgressDenied) {
            return $this->error($request, 403);
        }
    }

    public function confirm(AssistantEgressEnrollmentRequest $request, string $enrollment): JsonResponse
    {
        try {
            $data = $request->validated();
            $record = $this->enrollments->confirm($this->actor($request), $this->organizationId($request), $enrollment, $data['manifest_hash'], $data['declaration']);

            return $this->success($request, ['enrollment_id' => $record->id, 'status' => $record->status, 'input_hash' => $record->input_hash,
                'expires_at' => $record->expires_at->toISOString()]);
        } catch (AssistantEgressDenied) {
            return $this->error($request, 403);
        }
    }

    public function revoke(Request $request, string $enrollment): JsonResponse
    {
        try {
            $this->enrollments->revoke($this->actor($request), $this->organizationId($request), $enrollment);

            return $this->success($request);
        } catch (AssistantEgressDenied) {
            return $this->error($request, 403);
        }
    }

    public function revokeCohort(Request $request, string $cohort): JsonResponse
    {
        try {
            $this->enrollments->revokeCohort($this->actor($request), $cohort);

            return $this->success($request);
        } catch (AssistantEgressDenied) {
            return $this->error($request, 403);
        }
    }
}
