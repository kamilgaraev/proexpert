<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Middleware\ProjectContextMiddleware;
use App\Enums\OrganizationCapability;
use App\Enums\ProjectOrganizationRole;

class ProjectContextController extends Controller
{
    /**
     * Получить полный context для текущего проекта и организации
     * 
     * GET /api/v1/admin/projects/{project}/context
     */
    public function getContext(Request $request): JsonResponse
    {
        $projectContext = ProjectContextMiddleware::getProjectContext($request);
        $project = ProjectContextMiddleware::getProject($request);
        
        if (!$projectContext) {
            return response()->json([
                'message' => 'Project context not available',
            ], 500);
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'context' => $projectContext->toArray(),
                'project' => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'address' => $project->address,
                    'status' => $project->status,
                    'start_date' => $project->start_date?->format('Y-m-d'),
                    'end_date' => $project->end_date?->format('Y-m-d'),
                    'budget_amount' => $project->budget_amount,
                ],
            ],
        ]);
    }
    
    /**
     * Получить метаданные для форм на основе роли
     * 
     * GET /api/v1/admin/projects/{project}/form-meta
     */
    public function getFormMeta(Request $request): JsonResponse
    {
        $projectContext = ProjectContextMiddleware::getProjectContext($request);
        
        if (!$projectContext) {
            return response()->json([
                'message' => 'Project context not available',
            ], 500);
        }
        
        $role = $projectContext->roleConfig->role;
        
        return response()->json([
            'success' => true,
            'data' => [
                'contractor_field' => $this->getContractorFieldMeta($role),
                'fields_visibility' => $this->getFieldsVisibility($role),
                'available_actions' => $this->getAvailableActions($projectContext),
                'ui_hints' => $this->getUIHints($role),
            ],
        ]);
    }
    
    /**
     * Получить список разрешений для текущего контекста
     * 
     * GET /api/v1/admin/projects/{project}/permissions
     */
    public function getPermissions(Request $request): JsonResponse
    {
        $projectContext = ProjectContextMiddleware::getProjectContext($request);
        
        if (!$projectContext) {
            return response()->json([
                'message' => 'Project context not available',
            ], 500);
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'permissions' => $projectContext->roleConfig->permissions,
                'role' => [
                    'value' => $projectContext->roleConfig->role->value,
                    'label' => $projectContext->roleConfig->displayLabel,
                ],
                'capabilities' => [
                    'can_manage_contracts' => $projectContext->roleConfig->canManageContracts,
                    'can_view_finances' => $projectContext->roleConfig->canViewFinances,
                    'can_manage_works' => $projectContext->roleConfig->canManageWorks,
                    'can_manage_warehouse' => $projectContext->roleConfig->canManageWarehouse,
                    'can_invite_participants' => $projectContext->roleConfig->canInviteParticipants,
                ],
            ],
        ]);
    }
    
    /**
     * Получить метаданные для поля contractor
     */
    private function getContractorFieldMeta(ProjectOrganizationRole $role): array
    {
        return match ($role) {
            ProjectOrganizationRole::OWNER,
            ProjectOrganizationRole::GENERAL_CONTRACTOR => [
                'mode' => 'visible_required',
                'editable' => true,
                'required' => true,
                'auto_fill' => false,
                'description' => 'Выберите организацию-подрядчика из списка участников проекта',
            ],
            
            ProjectOrganizationRole::CUSTOMER => [
                'mode' => 'visible_readonly',
                'editable' => false,
                'required' => false,
                'auto_fill' => false,
                'description' => 'Информация о подрядчике (только для просмотра)',
            ],
            
            ProjectOrganizationRole::CONTRACTOR,
            ProjectOrganizationRole::SUBCONTRACTOR => [
                'mode' => 'hidden_autofill',
                'editable' => false,
                'required' => true,
                'auto_fill' => true,
                'auto_fill_value' => 'current_organization',
                'description' => 'Подрядчик определяется автоматически (ваша организация)',
            ],
            
            default => [
                'mode' => 'hidden',
                'editable' => false,
                'required' => false,
                'auto_fill' => false,
                'description' => null,
            ],
        };
    }
    
    /**
     * Получить видимость полей по ролям
     */
    private function getFieldsVisibility(ProjectOrganizationRole $role): array
    {
        return match ($role) {
            ProjectOrganizationRole::OWNER,
            ProjectOrganizationRole::GENERAL_CONTRACTOR => [
                'contract_amount' => true,
                'subcontract_amount' => true,
                'gp_percentage' => true,
                'gp_calculation_type' => true,
                'payment_terms' => true,
                'advance_amount' => true,
                'contractor_selection' => true,
                'financial_details' => true,
            ],
            
            ProjectOrganizationRole::CUSTOMER => [
                'contract_amount' => true,
                'subcontract_amount' => false,
                'gp_percentage' => false,
                'gp_calculation_type' => false,
                'payment_terms' => true,
                'advance_amount' => true,
                'contractor_selection' => false,
                'financial_details' => true,
            ],
            
            ProjectOrganizationRole::CONTRACTOR => [
                'contract_amount' => true,
                'subcontract_amount' => true,
                'gp_percentage' => false,
                'gp_calculation_type' => false,
                'payment_terms' => true,
                'advance_amount' => true,
                'contractor_selection' => false,
                'financial_details' => true,
            ],
            
            ProjectOrganizationRole::SUBCONTRACTOR => [
                'contract_amount' => true,
                'subcontract_amount' => false,
                'gp_percentage' => false,
                'gp_calculation_type' => false,
                'payment_terms' => true,
                'advance_amount' => false,
                'contractor_selection' => false,
                'financial_details' => false,
            ],
            
            default => [
                'contract_amount' => false,
                'subcontract_amount' => false,
                'gp_percentage' => false,
                'gp_calculation_type' => false,
                'payment_terms' => false,
                'advance_amount' => false,
                'contractor_selection' => false,
                'financial_details' => false,
            ],
        };
    }
    
    /**
     * Получить доступные действия для роли
     */
    private function getAvailableActions($projectContext): array
    {
        return [
            'can_create_contract' => $projectContext->roleConfig->canManageContracts,
            'can_edit_contract' => $projectContext->roleConfig->canManageContracts,
            'can_delete_contract' => $projectContext->roleConfig->canManageContracts && $projectContext->isOwner,
            'can_create_work' => $projectContext->roleConfig->canManageWorks,
            'can_edit_work' => $projectContext->roleConfig->canManageWorks,
            'can_delete_work' => $projectContext->roleConfig->canManageWorks,
            'can_approve_work' => in_array($projectContext->roleConfig->role, [
                ProjectOrganizationRole::OWNER,
                ProjectOrganizationRole::GENERAL_CONTRACTOR,
                ProjectOrganizationRole::CUSTOMER,
                ProjectOrganizationRole::CONSTRUCTION_SUPERVISION,
            ]),
            'can_view_all_data' => !in_array($projectContext->roleConfig->role, [
                ProjectOrganizationRole::CONTRACTOR,
                ProjectOrganizationRole::SUBCONTRACTOR,
            ]),
            'can_invite_participants' => $projectContext->roleConfig->canInviteParticipants,
            'can_manage_warehouse' => $projectContext->roleConfig->canManageWarehouse,
        ];
    }
    
    /**
     * Получить UI hints для роли
     */
    private function getUIHints(ProjectOrganizationRole $role): array
    {
        return match ($role) {
            ProjectOrganizationRole::OWNER => [
                'welcome_message' => 'Вы владелец проекта. У вас полный доступ ко всем функциям.',
                'role_description' => 'Вы можете управлять всеми аспектами проекта, приглашать участников и контролировать финансы.',
                'limitations' => [],
            ],
            
            ProjectOrganizationRole::GENERAL_CONTRACTOR => [
                'welcome_message' => 'Вы генподрядчик. Управляйте работами и подрядчиками.',
                'role_description' => 'Вы можете назначать подрядчиков, создавать контракты и контролировать выполнение работ.',
                'limitations' => ['Некоторые настройки проекта доступны только владельцу'],
            ],
            
            ProjectOrganizationRole::CONTRACTOR => [
                'welcome_message' => 'Вы подрядчик. Создавайте работы и контракты для своей организации.',
                'role_description' => 'Вы можете создавать работы и контракты только для своей организации.',
                'limitations' => [
                    'Вы не можете назначать других подрядчиков',
                    'Видите только свои данные',
                ],
            ],
            
            ProjectOrganizationRole::SUBCONTRACTOR => [
                'welcome_message' => 'Вы субподрядчик. Выполняйте назначенные работы.',
                'role_description' => 'Вы можете создавать и отслеживать выполненные работы.',
                'limitations' => [
                    'Ограниченный доступ к финансовым данным',
                    'Видите только свои работы',
                ],
            ],
            
            ProjectOrganizationRole::CUSTOMER => [
                'welcome_message' => 'Вы заказчик. Отслеживайте прогресс и утверждайте работы.',
                'role_description' => 'Вы можете просматривать все данные проекта и утверждать выполненные работы.',
                'limitations' => ['Создание контрактов и работ доступно только подрядчикам'],
            ],
            
            default => [
                'welcome_message' => 'Добро пожаловать в проект.',
                'role_description' => 'Ваша роль: ' . $role->label(),
                'limitations' => [],
            ],
        };
    }
}
