<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\System;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Services\Monitoring\GitHubIssueService;
use App\Services\Monitoring\GlitchTipOrchestratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

use function trans_message;

class GlitchTipController extends Controller
{
    public function __construct(
        private readonly GlitchTipOrchestratorService $glitchTipOrchestratorService,
        private readonly GitHubIssueService $gitHubIssueService
    ) {
    }

    public function webhook(Request $request): JsonResponse
    {
        try {
            if (!$this->glitchTipOrchestratorService->validateWebhookSecret($this->resolveWebhookSecret($request))) {
                return AdminResponse::error(trans_message('glitchtip.webhook_unauthorized'), 401);
            }

            $incident = $this->glitchTipOrchestratorService->normalizeWebhookPayload($request->all());
            $this->glitchTipOrchestratorService->storeLatestIncident($incident);
            $githubIssue = $this->glitchTipOrchestratorService->syncIncidentToGitHub($incident, $this->gitHubIssueService);

            return AdminResponse::success([
                'incident' => $incident,
                'github_issue' => $githubIssue,
            ], trans_message('glitchtip.webhook_received'));
        } catch (Throwable $exception) {
            Log::error('glitchtip.webhook.failed', [
                'message' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('glitchtip.webhook_failed'), 500);
        }
    }

    public function status(Request $request): JsonResponse
    {
        try {
            if (!$this->glitchTipOrchestratorService->validateInternalToken($this->resolveInternalToken($request))) {
                return AdminResponse::error(trans_message('glitchtip.internal_unauthorized'), 401);
            }

            return AdminResponse::success(
                $this->glitchTipOrchestratorService->getStatus(),
                trans_message('glitchtip.status_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('glitchtip.status.failed', [
                'message' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('glitchtip.status_failed'), 500);
        }
    }

    public function issues(Request $request): JsonResponse
    {
        try {
            if (!$this->glitchTipOrchestratorService->validateInternalToken($this->resolveInternalToken($request))) {
                return AdminResponse::error(trans_message('glitchtip.internal_unauthorized'), 401);
            }

            $limit = $request->integer('limit');
            $issues = $this->glitchTipOrchestratorService->fetchProjectIssues($limit);

            return AdminResponse::success($issues, trans_message('glitchtip.issues_loaded'));
        } catch (Throwable $exception) {
            Log::error('glitchtip.issues.failed', [
                'message' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('glitchtip.issues_failed'), 500);
        }
    }

    private function resolveWebhookSecret(Request $request): ?string
    {
        return $request->header('X-GlitchTip-Webhook-Secret')
            ?? $request->header('X-Webhook-Secret')
            ?? $this->extractBearerToken($request);
    }

    private function resolveInternalToken(Request $request): ?string
    {
        return $request->header('X-Internal-Token')
            ?? $request->header('X-GlitchTip-Internal-Token')
            ?? $this->extractBearerToken($request);
    }

    private function extractBearerToken(Request $request): ?string
    {
        $authorizationHeader = $request->header('Authorization');

        if (!is_string($authorizationHeader) || !str_starts_with($authorizationHeader, 'Bearer ')) {
            return null;
        }

        return trim(substr($authorizationHeader, 7));
    }
}
