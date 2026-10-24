<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Egress;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Models\Organization;
use App\Models\User;
use Throwable;

final readonly class OrdinaryAssistantSourceCatalog
{
    public function __construct(private AIToolRegistry $tools, private AIPermissionChecker $permissions,
        private OrdinaryAssistantSourceProjection $projection) {}

    public function freshReviewer(?int $reviewerId, int $organizationId): User
    {
        try {
            $reviewer = $reviewerId === null || $reviewerId < 1 ? null : User::query()->whereKey($reviewerId)
                ->where('is_active', true)->where('current_organization_id', $organizationId)->first();
            if ($reviewer === null || ! $reviewer->organizations()->where('organizations.id', $organizationId)
                ->wherePivot('is_active', true)->exists()) {
                throw new AssistantEgressDenied('access_denied');
            }

            return $reviewer;
        } catch (AssistantEgressDenied $denied) {
            throw $denied;
        } catch (Throwable $failure) {
            throw OrdinaryAssistantExceptionBoundary::sanitize($failure);
        }
    }

    public function captureForReview(User $actor, ?int $reviewerId, int $organizationId, int $projectId, string $name, array $arguments): array
    {
        try {
            $reviewer = $this->freshReviewer($reviewerId, $organizationId);
            $sameActor = (int) $actor->getKey() === (int) $reviewer->getKey();
            $capture = $this->capture($sameActor ? $reviewer : $actor, $organizationId, $projectId, $name, $arguments);
            if (! $sameActor) {
                $review = $this->capture($reviewer, $organizationId, $projectId, $name, $arguments);
                if (! hash_equals($capture['proof']['projection_hash'], $review['proof']['projection_hash'])) {
                    throw new AssistantEgressDenied('access_denied');
                }
            }

            return $capture;
        } catch (AssistantEgressDenied $denied) {
            throw $denied;
        } catch (Throwable $failure) {
            throw OrdinaryAssistantExceptionBoundary::sanitize($failure);
        }
    }

    public function capture(User $actor, int $organizationId, int $projectId, string $name, array $arguments): array
    {
        $documentSource = $name === OrdinaryAssistantDocumentProjection::TOOL;
        $arguments = $documentSource ? app(OrdinaryAssistantDocumentProjection::class)->selection($arguments, $projectId)
            : $this->projection->arguments($name, $arguments, $projectId);
        if ((int) $actor->current_organization_id !== $organizationId || ! $actor->is_active
            || ! app(AssistantDataAccessPolicy::class)->withCurrentChecks($actor, $organizationId,
                fn (): bool => $this->permissions->canExposeTool($actor, $name, fresh: false)
                    && $this->permissions->canExecuteTool($actor, $name, $arguments, fresh: false), fresh: true)) {
            throw new AssistantEgressDenied('access_denied');
        }
        $tool = $this->tools->getTool($name);
        $organization = Organization::query()->find($organizationId);
        if ($tool === null || $organization === null) {
            throw new AssistantEgressDenied('source_unknown');
        }
        if ($documentSource) {
            return app(OrdinaryAssistantDocumentProjection::class)->capture($actor, $organizationId, $projectId, $arguments);
        }
        $result = $this->projection->project($name, $tool->execute($arguments, $actor, $organization), $organizationId, (int) $actor->id, $projectId);
        $provider = $this->projection->forProvider($result);

        return ['proof' => ['tool' => $name, 'arguments' => $arguments,
            'projection_version' => OrdinaryAssistantSourceProjection::VERSION,
            'source_versions' => array_column($result['source_refs'], 'source_version'),
            'projection_hash' => OrdinaryAssistantSourceProjection::hash($provider)], 'preview' => $provider];
    }
}
