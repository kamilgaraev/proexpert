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
    ) {
    }

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

        if (!$attempt instanceof AuthRegistrationAttempt || !$user instanceof User || !$organization instanceof Organization) {
            return;
        }

        if (!$this->isCompleted($attempt, 'invitations')) {
            $invitations->acceptMatchingForOrganization($user, $organization);
            $this->markCompleted($attempt, 'invitations');
        }

        if (!$this->isCompleted($attempt, 'contractor_sync')) {
            $this->synchronizeContractors($organization, $verification, $contractors, $notifications);
            $this->markCompleted($attempt, 'contractor_sync');
        }

        if (!$this->isCompleted($attempt, 'email_verification')) {
            if (!$user->hasVerifiedEmail()) {
                $user->sendFrontendEmailVerificationNotification((string) config('app.frontend_url'));
            }
            $this->markCompleted($attempt, 'email_verification');
        }
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

    private function isCompleted(AuthRegistrationAttempt $attempt, string $step): bool
    {
        $attempt->refresh();

        return ($attempt->side_effects[$step] ?? null) === 'completed';
    }

    private function markCompleted(AuthRegistrationAttempt $attempt, string $step): void
    {
        DB::transaction(function () use ($attempt, $step): void {
            $locked = AuthRegistrationAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $state = is_array($locked->side_effects) ? $locked->side_effects : [];
            $state[$step] = 'completed';
            $locked->update(['side_effects' => $state]);
        });
    }
}
