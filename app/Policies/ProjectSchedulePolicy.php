<?php

namespace App\Policies;

use App\Models\User;
use App\Models\ProjectSchedule;
use App\Domain\Authorization\Services\AuthorizationService;

class ProjectSchedulePolicy
{
    public function viewAny(User $user): bool
    {
        $organizationId = $user->current_organization_id ?? $user->organization_id;
        
        if (!$organizationId) {
            return false;
        }
        
        $authorizationService = app(AuthorizationService::class);
        
        return $authorizationService->can($user, 'schedule.view', [
            'organization_id' => $organizationId,
            'context_type' => 'organization'
        ]);
    }

    public function view(User $user, ProjectSchedule $schedule): bool
    {
        $organizationId = $user->current_organization_id ?? $user->organization_id;
        
        if ($schedule->organization_id !== $organizationId) {
            return false;
        }
        
        $authorizationService = app(AuthorizationService::class);
        
        return $authorizationService->can($user, 'schedule.view', [
            'organization_id' => $organizationId,
            'context_type' => 'organization'
        ]);
    }

    public function create(User $user): bool
    {
        $organizationId = $user->current_organization_id ?? $user->organization_id;
        
        if (!$organizationId) {
            return false;
        }
        
        $authorizationService = app(AuthorizationService::class);
        
        return $authorizationService->can($user, 'schedule.create', [
            'organization_id' => $organizationId,
            'context_type' => 'organization'
        ]);
    }

    public function update(User $user, ProjectSchedule $schedule): bool
    {
        $organizationId = $user->current_organization_id ?? $user->organization_id;
        
        if ($schedule->organization_id !== $organizationId) {
            return false;
        }
        
        $authorizationService = app(AuthorizationService::class);
        
        return $authorizationService->can($user, 'schedule.edit', [
            'organization_id' => $organizationId,
            'context_type' => 'organization'
        ]);
    }

    public function delete(User $user, ProjectSchedule $schedule): bool
    {
        $organizationId = $user->current_organization_id ?? $user->organization_id;
        
        if ($schedule->organization_id !== $organizationId) {
            return false;
        }
        
        $authorizationService = app(AuthorizationService::class);
        
        return $authorizationService->can($user, 'schedule.delete', [
            'organization_id' => $organizationId,
            'context_type' => 'organization'
        ]);
    }

    public function saveBaseline(User $user, ProjectSchedule $schedule): bool
    {
        $organizationId = $user->current_organization_id ?? $user->organization_id;
        
        if ($schedule->organization_id !== $organizationId) {
            return false;
        }
        
        $authorizationService = app(AuthorizationService::class);
        
        // Для сохранения baseline нужно право редактировать график
        return $authorizationService->can($user, 'schedule.edit', [
            'organization_id' => $organizationId,
            'context_type' => 'organization'
        ]);
    }

    public function clearBaseline(User $user, ProjectSchedule $schedule): bool
    {
        $organizationId = $user->current_organization_id ?? $user->organization_id;
        
        if ($schedule->organization_id !== $organizationId) {
            return false;
        }
        
        $authorizationService = app(AuthorizationService::class);
        
        // Для очистки baseline нужно право редактировать график
        return $authorizationService->can($user, 'schedule.edit', [
            'organization_id' => $organizationId,
            'context_type' => 'organization'
        ]);
    }

    public function calculateCriticalPath(User $user, ProjectSchedule $schedule): bool
    {
        $organizationId = $user->current_organization_id ?? $user->organization_id;
        
        if ($schedule->organization_id !== $organizationId) {
            return false;
        }
        
        $authorizationService = app(AuthorizationService::class);
        
        // Для расчета критического пути нужно право редактировать график
        return $authorizationService->can($user, 'schedule.edit', [
            'organization_id' => $organizationId,
            'context_type' => 'organization'
        ]);
    }
}

