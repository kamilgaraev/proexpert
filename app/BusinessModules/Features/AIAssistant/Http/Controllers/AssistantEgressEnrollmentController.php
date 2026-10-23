<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Controllers;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Http\Requests\AssistantEgressEnrollmentRequest;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEnrollmentService;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantExceptionBoundary;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class AssistantEgressEnrollmentController extends AbstractAssistantApiController
{
    public function __construct(private readonly OrdinaryAssistantEnrollmentService $enrollments) {}

    public function capability(Request $request): JsonResponse
    {
        try {
            if ($this->hasClientInput($request)) {
                throw new AssistantEgressDenied('unsupported_purpose');
            }
            $response = $this->success($request, $this->enrollments->capability($this->actor($request), $this->organizationId($request)));
        } catch (AssistantEgressDenied|AccessDeniedHttpException|AuthorizationException) {
            $response = $this->error($request, 403);
        } catch (AuthenticationException) {
            $response = $this->error($request, 401, 'errors.unauthorized');
        } catch (Throwable $failure) {
            report(OrdinaryAssistantExceptionBoundary::sanitize($failure));
            $response = $this->error($request, 500, 'ai_assistant.request_failed');
        }

        return $response->header('Cache-Control', 'private, no-store');
    }

    public function assign(AssistantEgressEnrollmentRequest $request): JsonResponse
    {
        try {
            $operator = $this->actor($request);
            $data = $request->validated();
            $this->enrollments->assertIssuer($operator, $data['organization_id']);
            $cohort = $this->enrollments->assign($operator, $data['organization_id'], User::query()->where('current_organization_id', $data['organization_id'])->findOrFail($data['actor_id']), $data['project_id'], $data['tools'],
                $data['policy_version'] ?? \App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceProjection::VERSION);

            return $this->success($request, ['cohort_id' => $cohort->id, 'status' => $cohort->status, 'policy_version' => $cohort->policy_version,
                'expires_at' => $cohort->expires_at->toISOString()], 201);
        } catch (AssistantEgressDenied) {
            return $this->error($request, 403);
        } catch (Throwable $failure) {
            return $this->safeFailure($request, $failure);
        }
    }

    public function preview(AssistantEgressEnrollmentRequest $request): JsonResponse
    {
        try {
            $owner = $this->actor($request);
            $organizationId = $this->organizationId($request);
            $this->enrollments->assertOrganizationManager((int) $owner->id, $organizationId);
            $data = $request->validated();

            return $this->success($request, $this->enrollments->preview($owner, $organizationId, $data['cohort_id'], $data['quote_id'], $data['input'], $data['sources']), 201);
        } catch (AssistantEgressDenied) {
            return $this->error($request, 403);
        } catch (Throwable $failure) {
            return $this->safeFailure($request, $failure);
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
        } catch (Throwable $failure) {
            return $this->safeFailure($request, $failure);
        }
    }

    public function revoke(Request $request, string $enrollment): JsonResponse
    {
        try {
            $this->enrollments->revoke($this->actor($request), $this->organizationId($request), $enrollment);

            return $this->success($request);
        } catch (AssistantEgressDenied) {
            return $this->error($request, 403);
        } catch (Throwable $failure) {
            return $this->safeFailure($request, $failure);
        }
    }

    public function revokeCohort(Request $request, string $cohort): JsonResponse
    {
        try {
            $this->enrollments->revokeCohort($this->actor($request), $cohort);

            return $this->success($request);
        } catch (AssistantEgressDenied) {
            return $this->error($request, 403);
        } catch (Throwable $failure) {
            return $this->safeFailure($request, $failure);
        }
    }

    private function safeFailure(Request $request, Throwable $failure): JsonResponse
    {
        if ($failure instanceof AccessDeniedHttpException || $failure instanceof AuthorizationException) {
            return $this->error($request, 403);
        }
        if ($failure instanceof AuthenticationException || $failure instanceof ModelNotFoundException
            || $failure instanceof ValidationException || $failure instanceof NotFoundHttpException) {
            throw $failure;
        }
        report(OrdinaryAssistantExceptionBoundary::sanitize($failure));

        return $this->error($request, 500, 'ai_assistant.request_failed');
    }

    private function hasClientInput(Request $request): bool
    {
        if ((string) $request->server->get('QUERY_STRING', '') !== '' || $request->files->all() !== []) {
            return true;
        }
        $content = $request->getContent();
        if ($content === '') {
            return false;
        }
        if (! $request->isJson() || strlen($content) > 256) {
            return true;
        }
        try {
            return json_decode($content, true, 2, JSON_THROW_ON_ERROR) !== [];
        } catch (JsonException) {
            return true;
        }
    }
}
