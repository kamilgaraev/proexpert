<?php

declare(strict_types=1);

namespace App\Jobs\Auth;

use App\Models\AuthRegistrationAttempt;
use App\Models\Organization;
use App\Models\User;
use App\Services\Contractor\ContractorSyncService;
use App\Services\Project\ProjectParticipantInvitationService;
use App\Services\Security\ContractorAutoVerificationService;
use App\Services\Security\ContractorRegistrationNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CompleteRegistrationSideEffects implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $userId,
        public readonly int $organizationId,
    ) {}

    public function handle(
        ProjectParticipantInvitationService $invitations,
        ContractorAutoVerificationService $verification,
        ContractorSyncService $contractors,
        ContractorRegistrationNotificationService $notifications,
    ): void {
        $attempt = AuthRegistrationAttempt::query()
            ->where('user_id', $this->userId)
            ->where('status', 'completed')
            ->latest('id')
            ->first();
        $user = User::query()->find($this->userId);
        $organization = Organization::query()->find($this->organizationId);

        if (! $attempt instanceof AuthRegistrationAttempt || ! $user instanceof User || ! $organization instanceof Organization) {
            return;
        }

        $this->runOnce($attempt, 'invitations', static function () use ($invitations, $user, $organization): void {
            $invitations->acceptMatchingForOrganization($user, $organization);
        });

        $this->runOnce($attempt, 'contractor_sync', function () use (
            $organization,
            $verification,
            $contractors,
            $notifications,
        ): void {
            $this->synchronizeContractors($organization, $verification, $contractors, $notifications);
        });

        $this->runOnce($attempt, 'email_verification', static function () use ($user): void {
            if (! $user->hasVerifiedEmail()) {
                $user->sendFrontendEmailVerificationNotification((string) config('app.frontend_url'));
            }
        });
    }

    public function uniqueId(): string
    {
        return 'registration:'.$this->userId;
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function failed(Throwable $exception): void
    {
        Log::error('auth.registration_side_effects_failed', [
            'user_id' => $this->userId,
            'organization_id' => $this->organizationId,
            'exception_class' => $exception::class,
        ]);
    }

    private function synchronizeContractors(
        Organization $organization,
        ContractorAutoVerificationService $verification,
        ContractorSyncService $contractors,
        ContractorRegistrationNotificationService $notifications,
    ): void {
        if ($organization->tax_number === null || $organization->tax_number === '') {
            return;
        }

        $verificationResult = $verification->verifyAndSetAccess($organization);
        $unsynced = $contractors->findContractorsByInn($organization->tax_number, true);

        if ($unsynced->isNotEmpty()) {
            $contractors->syncContractorWithOrganization($organization);
        }

        $all = $contractors->findContractorsByInn($organization->tax_number, false);

        if ($all->isNotEmpty()) {
            $notifications->notifyCustomersAboutRegistration($organization, $all, $verificationResult);
        }
    }

    private function runOnce(AuthRegistrationAttempt $attempt, string $step, callable $effect): void
    {
        if (! $this->claim($attempt, $step)) {
            return;
        }

        try {
            $effect();
            $this->setState($attempt, $step, 'completed');
        } catch (Throwable $exception) {
            $this->setState($attempt, $step, 'pending');

            throw $exception;
        }
    }

    private function claim(AuthRegistrationAttempt $attempt, string $step): bool
    {
        return DB::transaction(function () use ($attempt, $step): bool {
            $locked = AuthRegistrationAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $state = is_array($locked->side_effects) ? $locked->side_effects : [];

            if (in_array($state[$step] ?? null, ['executing', 'completed'], true)) {
                return false;
            }

            $state[$step] = 'executing';
            $locked->update(['side_effects' => $state]);

            return true;
        });
    }

    private function setState(AuthRegistrationAttempt $attempt, string $step, string $value): void
    {
        DB::transaction(function () use ($attempt, $step, $value): void {
            $locked = AuthRegistrationAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $state = is_array($locked->side_effects) ? $locked->side_effects : [];
            $state[$step] = $value;
            $locked->update(['side_effects' => $state]);
        });
    }
}
