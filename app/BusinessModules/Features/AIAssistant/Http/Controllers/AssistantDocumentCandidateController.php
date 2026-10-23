<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Controllers;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Http\Requests\AssistantDocumentCandidateRequest;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCandidateService;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantExceptionBoundary;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

final class AssistantDocumentCandidateController extends AbstractAssistantApiController
{
    public function __construct(private readonly AssistantDocumentCandidateService $candidates) {}

    public function index(AssistantDocumentCandidateRequest $request): JsonResponse
    {
        try {
            $input = $request->validated();

            $response = $this->success($request, $this->candidates->page($this->actor($request), $this->organizationId($request),
                isset($input['document_id']) ? (int) $input['document_id'] : null,
                isset($input['after_id']) ? (int) $input['after_id'] : null));
        } catch (AssistantEgressDenied|AuthorizationException|AccessDeniedHttpException) {
            $response = $this->error($request, 403);
        } catch (Throwable $failure) {
            report(OrdinaryAssistantExceptionBoundary::sanitize($failure));

            $response = $this->error($request, 500, 'ai_assistant.request_failed');
        }

        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
