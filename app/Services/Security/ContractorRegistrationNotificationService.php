<?php

namespace App\Services\Security;

use App\Models\User;
use App\Models\Organization;
use App\Models\Contractor;
use App\Models\ContractorVerification;
use App\BusinessModules\Features\Notifications\Facades\Notify;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ContractorRegistrationNotificationService
{
    public function notifyCustomersAboutRegistration(
        Organization $registeredOrg,
        Collection $contractors,
        array $verificationResult
    ): void {
        $score = $verificationResult['verification_score'];
        
        Log::info('[ContractorNotification] Starting customer notifications', [
            'registered_org_id' => $registeredOrg->id,
            'contractors_count' => $contractors->count(),
            'verification_score' => $score
        ]);

        foreach ($contractors as $contractor) {
            $customer = $contractor->organization;
            $admins = $this->getOrganizationAdmins($customer);
            
            $verification = $this->createVerificationRequest($contractor, $registeredOrg, $score);
            
            foreach ($admins as $admin) {
                $this->sendNotificationToLK($admin, $contractor, $registeredOrg, $score, $verification);
                $this->sendNotificationToAdmin($admin, $contractor, $registeredOrg, $score, $verification);
            }

            Log::info('[ContractorNotification] Notifications sent', [
                'contractor_id' => $contractor->id,
                'customer_org_id' => $customer->id,
                'admins_notified' => $admins->count()
            ]);
        }
    }

    private function getOrganizationAdmins(Organization $organization): Collection
    {
        return $organization->users()
            ->where(function($query) use ($organization) {
                $query->wherePivot('is_owner', true)
                      ->orWhereHas('roleAssignments', function($q) {
                          $q->whereIn('role_slug', ['organization_owner', 'organization_admin']);
                      });
            })
            ->with(['roleAssignments'])
            ->get();
    }

    private function createVerificationRequest(
        Contractor $contractor,
        Organization $registeredOrg,
        int $score
    ): ContractorVerification {
        return ContractorVerification::create([
            'contractor_id' => $contractor->id,
            'registered_organization_id' => $registeredOrg->id,
            'customer_organization_id' => $contractor->organization_id,
            'status' => $score < 70 ? 'pending_customer_confirmation' : 'auto_approved',
            'verification_score' => $score,
            'verified_at' => $score >= 70 ? now() : null,
            'expires_at' => now()->addDays(7),
        ]);
    }

    private function sendNotificationToLK(
        User $admin,
        Contractor $contractor,
        Organization $registeredOrg,
        int $score,
        ContractorVerification $verification
    ): void {
        $priority = $this->getPriority($score);
        $channels = $this->getChannels($score);
        $message = $this->buildMessage($contractor, $registeredOrg, $score);

        Notify::send(
            $admin,
            'contractor_registered_lk',
            [
                'title' => 'Подрядчик зарегистрировался в системе',
                'message' => $message,
                'icon' => $this->getIcon($score),
                'color' => $this->getColor($score),
                'interface' => 'lk',
                'contractor' => [
                    'id' => $contractor->id,
                    'name' => $contractor->name,
                    'inn' => $contractor->inn,
                    'contracts_count' => $contractor->contracts()->count(),
                ],
                'registered_organization' => [
                    'id' => $registeredOrg->id,
                    'name' => $registeredOrg->name,
                    'verification_score' => $score,
                    'verification_status' => $registeredOrg->verification_status,
                ],
                'verification' => [
                    'id' => $verification->id,
                    'token' => $verification->verification_token,
                    'requires_action' => $score < 70,
                ],
                'actions' => $this->buildActionsLK($contractor, $registeredOrg, $score, $verification),
            ],
            'security',
            $priority,
            $channels,
            $contractor->organization_id
        );

        Log::channel('security')->info('Contractor registration notification sent to LK', [
            'admin_user_id' => $admin->id,
            'contractor_id' => $contractor->id,
            'registered_org_id' => $registeredOrg->id,
            'verification_score' => $score,
            'priority' => $priority
        ]);
    }

    private function sendNotificationToAdmin(
        User $admin,
        Contractor $contractor,
        Organization $registeredOrg,
        int $score,
        ContractorVerification $verification
    ): void {
        $priority = $this->getPriority($score);
        $channels = $this->getChannels($score);
        $message = $this->buildMessage($contractor, $registeredOrg, $score);

        Notify::send(
            $admin,
            'contractor_registered_admin',
            [
                'title' => 'Подрядчик зарегистрировался в системе',
                'message' => $message,
                'icon' => $this->getIcon($score),
                'color' => $this->getColor($score),
                'interface' => 'admin',
                'contractor' => [
                    'id' => $contractor->id,
                    'name' => $contractor->name,
                    'inn' => $contractor->inn,
                    'contracts_count' => $contractor->contracts()->count(),
                ],
                'registered_organization' => [
                    'id' => $registeredOrg->id,
                    'name' => $registeredOrg->name,
                    'verification_score' => $score,
                    'verification_status' => $registeredOrg->verification_status,
                ],
                'verification' => [
                    'id' => $verification->id,
                    'token' => $verification->verification_token,
                    'requires_action' => $score < 70,
                ],
                'actions' => $this->buildActionsAdmin($contractor, $registeredOrg, $score, $verification),
            ],
            'security',
            $priority,
            $channels,
            $contractor->organization_id
        );

        Log::channel('security')->info('Contractor registration notification sent to Admin', [
            'admin_user_id' => $admin->id,
            'contractor_id' => $contractor->id,
            'registered_org_id' => $registeredOrg->id,
            'verification_score' => $score,
            'priority' => $priority
        ]);
    }

    private function buildMessage(Contractor $contractor, Organization $org, int $score): string
    {
        if ($score >= 90) {
            return "Подрядчик «{$contractor->name}» успешно верифицирован через ЕГРЮЛ (рейтинг: {$score}/100). Доступ предоставлен автоматически.";
        }

        if ($score >= 70) {
            return "Подрядчик «{$contractor->name}» зарегистрировался и частично верифицирован (рейтинг: {$score}/100). Ограниченный доступ снимется автоматически через 3 дня.";
        }

        return "⚠️ Подрядчик «{$contractor->name}» зарегистрировался с низким рейтингом верификации ({$score}/100). Требуется ваше подтверждение для предоставления доступа.";
    }

    private function buildActionsLK(
        Contractor $contractor,
        Organization $org,
        int $score,
        ContractorVerification $verification
    ): array {
        $actions = [];

        if ($score < 70) {
            $actions[] = [
                'label' => '✅ Да, это мой подрядчик',
                'url' => "/api/v1/contractor-verifications/{$verification->verification_token}/confirm",
                'style' => 'success',
                'icon' => 'check-circle',
                'method' => 'POST',
                'confirm' => false,
            ];

            $actions[] = [
                'label' => '❌ Это НЕ мой подрядчик',
                'url' => "/api/v1/contractor-verifications/{$verification->verification_token}/reject",
                'style' => 'danger',
                'icon' => 'x-circle',
                'method' => 'POST',
                'confirm' => 'Вы уверены? Доступ будет заблокирован, и мы начнем расследование.',
            ];
        } else {
            $actions[] = [
                'label' => '⚠️ Сообщить о проблеме',
                'url' => "/api/v1/contractor-verifications/{$verification->verification_token}/dispute",
                'style' => 'warning',
                'icon' => 'alert-triangle',
                'method' => 'POST',
            ];
        }

        return $actions;
    }

    private function buildActionsAdmin(
        Contractor $contractor,
        Organization $org,
        int $score,
        ContractorVerification $verification
    ): array {
        $actions = [
            [
                'label' => 'Посмотреть контракты',
                'route' => "contractors.show",
                'params' => ['contractor' => $contractor->id],
                'style' => 'secondary',
                'icon' => 'file-text',
            ]
        ];

        if ($score < 70) {
            $actions[] = [
                'label' => '✅ Да, это мой подрядчик',
                'url' => "/api/v1/contractor-verifications/{$verification->verification_token}/confirm",
                'style' => 'success',
                'icon' => 'check-circle',
                'method' => 'POST',
                'confirm' => false,
            ];

            $actions[] = [
                'label' => '❌ Это НЕ мой подрядчик',
                'url' => "/api/v1/contractor-verifications/{$verification->verification_token}/reject",
                'style' => 'danger',
                'icon' => 'x-circle',
                'method' => 'POST',
                'confirm' => 'Вы уверены? Доступ будет заблокирован, и мы начнем расследование.',
            ];
        } else {
            $actions[] = [
                'label' => '⚠️ Сообщить о проблеме',
                'url' => "/api/v1/contractor-verifications/{$verification->verification_token}/dispute",
                'style' => 'warning',
                'icon' => 'alert-triangle',
                'method' => 'POST',
            ];
        }

        return $actions;
    }

    private function getPriority(int $score): string
    {
        return match(true) {
            $score >= 90 => 'normal',
            $score >= 70 => 'high',
            default => 'urgent'
        };
    }

    private function getChannels(int $score): array
    {
        if ($score < 70) {
            return ['in_app', 'websocket', 'email'];
        }

        return ['in_app', 'websocket'];
    }

    private function getIcon(int $score): string
    {
        return match(true) {
            $score >= 90 => 'check-circle',
            $score >= 70 => 'alert-circle',
            default => 'alert-triangle'
        };
    }

    private function getColor(int $score): string
    {
        return match(true) {
            $score >= 90 => 'success',
            $score >= 70 => 'warning',
            default => 'danger'
        };
    }
}

