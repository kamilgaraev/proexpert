<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Features\Notifications\Services\NotificationService;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

use function trans_message;

class EstimateGenerationNotificationService
{
    private const CHANNELS = ['in_app', 'websocket'];

    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function notifyFinished(EstimateGenerationSession $session): void
    {
        $session->loadMissing(['project', 'user']);

        if (! $session->user instanceof User) {
            return;
        }

        $isBlocked = in_array('quality_blocked', $session->problem_flags ?? [], true);
        $isReviewRequired = $session->status === EstimateGenerationStatus::EstimateReviewRequired;
        $type = $isReviewRequired ? 'estimate_generation_review_required' : 'estimate_generation_completed';
        $titleKey = $isBlocked
            ? 'estimate_generation.notification_blocked_title'
            : ($isReviewRequired ? 'estimate_generation.notification_review_title' : 'estimate_generation.notification_completed_title');
        $messageKey = $isBlocked
            ? 'estimate_generation.notification_blocked_message'
            : ($isReviewRequired ? 'estimate_generation.notification_review_message' : 'estimate_generation.notification_completed_message');

        $this->send(
            $session,
            $type,
            $titleKey,
            $messageKey,
            $isBlocked ? 'high' : 'normal',
            $isBlocked ? 'warning' : 'info'
        );
    }

    public function notifyFailed(EstimateGenerationSession $session, ?Throwable $exception = null): void
    {
        $session->loadMissing(['project', 'user']);

        if (! $session->user instanceof User) {
            return;
        }

        $this->send(
            $session,
            'estimate_generation_failed',
            'estimate_generation.notification_failed_title',
            'estimate_generation.notification_failed_message',
            'high',
            'error',
            [
                'error' => $exception?->getMessage() ?? $session->last_error,
            ]
        );
    }

    private function send(
        EstimateGenerationSession $session,
        string $type,
        string $titleKey,
        string $messageKey,
        string $priority,
        string $category,
        array $context = [],
    ): void {
        $route = "/projects/{$session->project_id}/estimates/ai-workspace/{$session->id}";
        $projectName = $session->project?->name;

        try {
            $this->notificationService->send(
                $session->user,
                $type,
                [
                    'type' => $type,
                    'force_send' => true,
                    'title' => trans_message($titleKey),
                    'message' => trans_message($messageKey, [
                        'project' => $projectName ?? trans_message('estimate_generation.notification_project_fallback'),
                    ]),
                    'category' => $category,
                    'interface' => 'admin',
                    'project_id' => $session->project_id,
                    'project_name' => $projectName,
                    'estimate_generation_session_id' => $session->id,
                    'entity_type' => 'estimate_generation_session',
                    'entity_id' => $session->id,
                    'target_route' => $route,
                    'context' => [
                        'status' => $session->status->value,
                        'processing_stage' => $session->processing_stage,
                        ...$context,
                    ],
                    'actions' => [
                        [
                            'label' => trans_message('estimate_generation.notification_open_action'),
                            'route' => $route,
                            'style' => 'primary',
                        ],
                    ],
                ],
                'custom',
                $priority,
                self::CHANNELS,
                $session->organization_id
            );
        } catch (Throwable $exception) {
            Log::warning('[EstimateGeneration] Failed to send generation notification', [
                'session_id' => $session->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
