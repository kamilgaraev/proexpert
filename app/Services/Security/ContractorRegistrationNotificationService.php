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
            try {
                // Находим проекты, где этот подрядчик участвует через контракты
                $projectsWithContractor = \App\Models\Contract::where('contractor_id', $contractor->id)
                    ->with('project.organization') // Загружаем проект и его владельца
                    ->get()
                    ->pluck('project')
                    ->filter()
                    ->unique('id');
                
                if ($projectsWithContractor->isEmpty()) {
                    Log::warning('[ContractorNotification] No projects found for contractor', [
                        'contractor_id' => $contractor->id,
                        'contractor_name' => $contractor->name
                    ]);
                    continue;
                }
                
                // Отправляем уведомления владельцам проектов (а не заказчикам по контракту!)
                foreach ($projectsWithContractor as $project) {
                    $projectOwner = $project->organization;
                    
                    if (!$projectOwner) {
                        Log::warning('[ContractorNotification] Project has no owner organization', [
                            'project_id' => $project->id,
                            'contractor_id' => $contractor->id
                        ]);
                        continue;
                    }
                    
                    $admins = $this->getOrganizationAdmins($projectOwner);
                    
                    if ($admins->isEmpty()) {
                        Log::warning('[ContractorNotification] No admins found for project owner', [
                            'contractor_id' => $contractor->id,
                            'project_id' => $project->id,
                            'project_owner_id' => $projectOwner->id
                        ]);
                        continue;
                    }
                    
                    $verification = $this->createOrUpdateVerificationRequest($contractor, $registeredOrg, $score);
                    
                    foreach ($admins as $admin) {
                        try {
                            $this->sendNotificationToLK($admin, $contractor, $registeredOrg, $score, $verification, $project);
                            $this->sendNotificationToAdmin($admin, $contractor, $registeredOrg, $score, $verification, $project);
                        } catch (\Exception $notifEx) {
                            Log::error('[ContractorNotification] Failed to send notification to admin', [
                                'admin_id' => $admin->id,
                                'contractor_id' => $contractor->id,
                                'project_id' => $project->id,
                                'error' => $notifEx->getMessage()
                            ]);
                            // Продолжаем для других админов
                        }
                    }

                    Log::info('[ContractorNotification] Notifications sent to project owner', [
                        'contractor_id' => $contractor->id,
                        'project_id' => $project->id,
                        'project_owner_id' => $projectOwner->id,
                        'admins_notified' => $admins->count()
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('[ContractorNotification] Failed to process contractor notification', [
                    'contractor_id' => $contractor->id,
                    'registered_org_id' => $registeredOrg->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                // Продолжаем для других подрядчиков
            }
        }
    }

    private function getOrganizationAdmins(Organization $organization): Collection
    {
        // Получаем контекст организации для новой системы авторизации
        $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organization->id);
        
        // Получаем всех пользователей с ролями owner или admin в новой системе
        $adminsByRoles = \App\Models\User::whereHas('roleAssignments', function($q) use ($context) {
            $q->whereIn('role_slug', ['organization_owner', 'organization_admin'])
              ->where('context_id', $context->id)
              ->where('is_active', true);
        })->get();
        
        // Также получаем владельцев через pivot таблицу (для совместимости)
        $ownersByPivot = $organization->users()
            ->wherePivot('is_owner', true)
            ->get();
        
        // Объединяем и убираем дубликаты
        return $adminsByRoles->merge($ownersByPivot)->unique('id');
    }

    private function createOrUpdateVerificationRequest(
        Contractor $contractor,
        Organization $registeredOrg,
        int $score
    ): ContractorVerification {
        // Используем updateOrCreate чтобы избежать дубликатов при повторной регистрации
        return ContractorVerification::updateOrCreate(
            [
                'contractor_id' => $contractor->id,
                'registered_organization_id' => $registeredOrg->id,
            ],
            [
                'customer_organization_id' => $contractor->organization_id,
                'status' => $score < 70 ? 'pending_customer_confirmation' : 'auto_approved',
                'verification_score' => $score,
                'verified_at' => $score >= 70 ? now() : null,
                'expires_at' => now()->addDays(7),
            ]
        );
    }

    private function sendNotificationToLK(
        User $admin,
        Contractor $contractor,
        Organization $registeredOrg,
        int $score,
        ContractorVerification $verification,
        ?\App\Models\Project $project = null
    ): void {
        $priority = $this->getPriority($score);
        $channels = $this->getChannels($score);
        $message = $this->buildMessage($contractor, $registeredOrg, $score, $project);

        $notificationData = [
            'title' => $project ? "Подрядчик зарегистрировался в проекте «{$project->name}»" : 'Подрядчик зарегистрировался в системе',
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
            'project' => $project ? [
                'id' => $project->id,
                'name' => $project->name,
            ] : null,
            'verification' => [
                'id' => $verification->id,
                'token' => $verification->verification_token,
                'requires_action' => true, // 🔒 ВСЕГДА требуется подтверждение от владельца проекта
            ],
            'actions' => $this->buildActionsLK($contractor, $registeredOrg, $score, $verification),
            'force_send' => true, // 🔥 КРИТИЧЕСКОЕ УВЕДОМЛЕНИЕ - игнорируем настройки пользователя
        ];

        try {
            Notify::send(
                $admin,
                'contractor_registered_lk',
                $notificationData,
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
                'priority' => $priority,
                'channels' => $channels
            ]);
        } catch (\Exception $e) {
            Log::channel('security')->error('CRITICAL: Failed to send LK notification', [
                'admin_user_id' => $admin->id,
                'contractor_id' => $contractor->id,
                'registered_org_id' => $registeredOrg->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e; // Пробрасываем выше для повторной попытки
        }
    }

    private function sendNotificationToAdmin(
        User $admin,
        Contractor $contractor,
        Organization $registeredOrg,
        int $score,
        ContractorVerification $verification,
        ?\App\Models\Project $project = null
    ): void {
        $priority = $this->getPriority($score);
        $channels = $this->getChannels($score);
        $message = $this->buildMessage($contractor, $registeredOrg, $score, $project);

        $notificationData = [
            'title' => $project ? "Подрядчик зарегистрировался в проекте «{$project->name}»" : 'Подрядчик зарегистрировался в системе',
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
            'project' => $project ? [
                'id' => $project->id,
                'name' => $project->name,
            ] : null,
            'verification' => [
                'id' => $verification->id,
                'token' => $verification->verification_token,
                'requires_action' => true, // 🔒 ВСЕГДА требуется подтверждение от владельца проекта
            ],
            'actions' => $this->buildActionsAdmin($contractor, $registeredOrg, $score, $verification),
            'force_send' => true, // 🔥 КРИТИЧЕСКОЕ УВЕДОМЛЕНИЕ - игнорируем настройки пользователя
        ];

        try {
            Notify::send(
                $admin,
                'contractor_registered_admin',
                $notificationData,
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
                'priority' => $priority,
                'channels' => $channels
            ]);
        } catch (\Exception $e) {
            Log::channel('security')->error('CRITICAL: Failed to send Admin notification', [
                'admin_user_id' => $admin->id,
                'contractor_id' => $contractor->id,
                'registered_org_id' => $registeredOrg->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e; // Пробрасываем выше для повторной попытки
        }
    }

    private function buildMessage(Contractor $contractor, Organization $org, int $score, ?\App\Models\Project $project = null): string
    {
        // 🔒 СТРОГИЙ РЕЖИМ: всегда требуется подтверждение от ВЛАДЕЛЬЦА ПРОЕКТА, независимо от рейтинга
        
        $projectInfo = $project ? " в проекте «{$project->name}»" : '';
        
        if ($score >= 90) {
            return "🔒 Подрядчик «{$contractor->name}» зарегистрировался{$projectInfo} и верифицирован через ЕГРЮЛ (рейтинг: {$score}/100). " .
                   "Как владелец проекта, подтвердите, что это ваш подрядчик, чтобы предоставить ему полный доступ к контрактам.";
        }

        if ($score >= 70) {
            return "🔒 Подрядчик «{$contractor->name}» зарегистрировался{$projectInfo} и частично верифицирован (рейтинг: {$score}/100). " .
                   "Как владелец проекта, подтвердите, что это ваш подрядчик, чтобы предоставить ему доступ.";
        }

        return "⚠️ Подрядчик «{$contractor->name}» зарегистрировался{$projectInfo} с низким рейтингом верификации ({$score}/100). " .
               "Как владелец проекта, пожалуйста, подтвердите, что это действительно ваш подрядчик, прежде чем он получит доступ к данным проекта.";
    }

    private function buildActionsLK(
        Contractor $contractor,
        Organization $org,
        int $score,
        ContractorVerification $verification
    ): array {
        $actions = [];

        // 🔒 ВСЕГДА показываем кнопки подтверждения, независимо от рейтинга
        if (true) { // Изменено с: if ($score < 70)
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

        // 🔒 ВСЕГДА показываем кнопки подтверждения, независимо от рейтинга
        if (true) { // Изменено с: if ($score < 70)
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

