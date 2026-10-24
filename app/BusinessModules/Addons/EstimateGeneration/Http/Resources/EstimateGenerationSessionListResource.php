<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Resources;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BuildSessionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationSessionController;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;
use Monolog\Logger;
use Throwable;

final class EstimateGenerationSessionListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var EstimateGenerationSession $session */
        $session = $this->resource;

        return [
            ...app(BuildSessionSnapshot::class)->handle(
                session: $session,
                permissions: self::permissions($request, $session),
                readinessEvaluated: false,
            )->toArray(),
            'documents_count' => (int) $session->getAttribute('documents_count'),
        ];
    }

    /** @return list<string> */
    public static function permissions(Request $request, EstimateGenerationSession $session): array
    {
        $user = $request->user();
        if ($user === null) {
            return [];
        }

        $organizationId = $user->current_organization_id;
        if ($organizationId === null) {
            return [];
        }

        $context = [
            'organization_id' => (int) $organizationId,
            'project_id' => (int) $session->project_id,
        ];
        $cacheKey = 'estimate_generation_permissions_'.(int) $organizationId.'_'.(int) $session->project_id;
        $cached = $request->attributes->get($cacheKey);
        if (is_array($cached)) {
            $permissions = array_values(array_filter($cached, 'is_string'));
            self::observeMissingUpload($request, $session, (int) $user->getAuthIdentifier(), (int) $organizationId,
                true, in_array('estimate_generation.upload_documents', $permissions, true), 'not_checked', null);

            return $permissions;
        }

        $authorization = app(AuthorizationService::class);
        $permissions = [];
        $uploadOutcome = 'not_checked';
        $uploadExceptionClass = null;
        foreach ([
            'estimate_generation.upload_documents',
            'estimate_generation.generate',
            'estimate_generation.review',
            'estimate_generation.view',
            'estimate_generation.apply',
            'estimate_generation.export',
        ] as $permission) {
            try {
                $allowed = $authorization->can($user, $permission, $context);
                if ($permission === 'estimate_generation.upload_documents') {
                    $uploadOutcome = $allowed ? 'allowed' : 'denied';
                }
                if ($allowed) {
                    $permissions[] = $permission;
                }
            } catch (Throwable $exception) {
                if ($permission === 'estimate_generation.upload_documents') {
                    $uploadOutcome = 'exception';
                    $class = $exception::class;
                    $uploadExceptionClass = strlen($class) <= 192
                        && preg_match('~\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z~D', $class) === 1
                            ? $class : 'unclassified_exception';
                }
                continue;
            }
        }

        $request->attributes->set($cacheKey, $permissions);
        self::observeMissingUpload($request, $session, (int) $user->getAuthIdentifier(), (int) $organizationId,
            false, in_array('estimate_generation.upload_documents', $permissions, true), $uploadOutcome, $uploadExceptionClass);

        return $permissions;
    }

    private static function observeMissingUpload(
        Request $request,
        EstimateGenerationSession $session,
        int $actorId,
        int $organizationId,
        bool $memoHit,
        bool $permissionPresent,
        string $outcome,
        ?string $exceptionClass,
    ): void {
        try {
            if ($permissionPresent || ! $request->isMethod('GET')
                || $request->route()?->getActionName() !== EstimateGenerationSessionController::class.'@snapshot'
                || $session->status !== EstimateGenerationStatus::EstimateReviewRequired
                || $request->attributes->get('estimate_generation_upload_permission_observed') === true) {
                return;
            }
            $request->attributes->set('estimate_generation_upload_permission_observed', true);
            $configured = Log::channel('technical')->getLogger();
            if (! $configured instanceof Logger) {
                return;
            }
            $logger = clone $configured;
            $logger->setExceptionHandler(static function (): void {});
            $logger->warning('estimate_generation.snapshot_upload_permission_missing', [
                'schema_version' => 1,
                'actor_id' => $actorId,
                'actor_current_organization_id' => $organizationId,
                'organization_id' => (int) $session->organization_id,
                'project_id' => (int) $session->project_id,
                'session_id' => (int) $session->getKey(),
                'state_version' => (int) $session->state_version,
                'status' => EstimateGenerationStatus::EstimateReviewRequired->value,
                'permission' => 'estimate_generation.upload_documents',
                'outcome' => $outcome,
                'memo_hit' => $memoHit,
                'permission_present' => $permissionPresent,
                'exception_class' => $exceptionClass,
            ]);
        } catch (Throwable) {
        }
    }
}
