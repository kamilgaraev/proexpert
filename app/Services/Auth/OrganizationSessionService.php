<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTOs\Auth\WebAuthTokenPair;
use App\DTOs\Auth\WebAuthTokenPayload;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserAuthSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class OrganizationSessionService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly WebAuthTokenService $tokens,
        private readonly UserAuthSessionService $sessions,
    ) {}

    public function choices(User $user, string $audience): array
    {
        return $user->activeOrganizations()->orderBy('organizations.name')->get()
            ->filter(fn (Organization $organization): bool => $this->canEnter($user, $audience, (int) $organization->id))
            ->map(static fn (Organization $organization): array => [
                'id' => (int) $organization->id,
                'name' => $organization->name,
            ])->values()->all();
    }

    public function switch(User $user, WebAuthTokenPayload $payload, int $organizationId, string $csrfToken): WebAuthTokenPair
    {
        return Cache::lock("web_auth:refresh_lock:{$payload->audience}:{$payload->sessionUuid}", 10)
            ->block(5, function () use ($user, $payload, $organizationId, $csrfToken): WebAuthTokenPair {
                return DB::transaction(function () use ($user, $payload, $organizationId, $csrfToken): WebAuthTokenPair {
                    $actor = User::query()->lockForUpdate()->findOrFail($user->id);
                    $session = UserAuthSession::query()->where('session_uuid', $payload->sessionUuid)->lockForUpdate()->first();
                    if (! $actor->is_active || $payload->userId !== (int) $actor->id
                        || $session === null || ! $session->isActive() || (int) $session->user_id !== (int) $actor->id
                        || ! $this->tokens->matchesCurrentCsrfToken($payload, $csrfToken)) {
                        throw new RuntimeException('Inactive organization switch session.');
                    }

                    if (! $actor->activeOrganizations()->whereKey($organizationId)->exists()
                        || ! $this->canEnter($actor, $payload->audience, $organizationId)) {
                        throw new AuthorizationException;
                    }

                    $nextSession = $session->replicate();
                    $nextSession->session_uuid = (string) Str::uuid();
                    $nextSession->organization_id = $organizationId;
                    $nextSession->last_seen_at = now();
                    $nextSession->save();

                    $pair = $this->tokens->issue($actor, $payload->audience, $nextSession->session_uuid, $organizationId, $payload->remembered);
                    $actor->forceFill(['current_organization_id' => $organizationId])->save();
                    $this->sessions->revoke($session, 'organization_switched');
                    $this->tokens->invalidateRefreshSession($payload->audience, $payload->sessionUuid);

                    return $pair;
                });
            });
    }

    private function canEnter(User $user, string $audience, int $organizationId): bool
    {
        $context = AuthorizationContext::getOrganizationContext($organizationId);
        if (! $this->authorization->canAccessInterface($user, $audience, $context)
            && ! ($audience === 'admin' && $this->authorization->canAccessInterface($user, 'admin', AuthorizationContext::getSystemContext()))) {
            return false;
        }

        return $audience === 'lk' || ($audience === 'admin' && (
            $this->authorization->can($user, 'admin.access', ['context_type' => 'system'])
            || $this->authorization->can($user, 'admin.access', [
                'context_type' => 'organization', 'organization_id' => $organizationId,
            ])
        ));
    }
}
