<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Services\Organization\OrganizationService; // Уточните реальный путь
use App\Http\Requests\Api\V1\Landing\Organization\UpdateOrganizationRequest; // Уточните реальный путь
use App\Http\Resources\Api\V1\Landing\OrganizationResource; // Уточните реальный путь
use App\Http\Responses\Api\V1\NotFoundResponse;
use App\Http\Responses\Api\V1\SuccessResourceResponse;
use Illuminate\Contracts\Support\Responsable; // Изменяем тип возврата
use Illuminate\Support\Facades\Auth;

class OrganizationController extends Controller
{
    protected OrganizationService $organizationService;

    public function __construct(OrganizationService $organizationService)
    {
        $this->organizationService = $organizationService;
        // TODO: Добавить Middleware для проверки роли 'organization_owner' на уровне маршрутов
    }

    /**
     * Получить данные текущей организации пользователя.
     * GET /api/v1/landing/organization
     */
    public function show(): Responsable // Используем Responsable
    {
        // Предполагаем, что сервис может определить организацию по ID пользователя
        $organization = $this->organizationService->getCurrentOrganization(Auth::id());
        if (!$organization) {
            return new NotFoundResponse('Organization not found for this user');
        }
        return new SuccessResourceResponse(new OrganizationResource($organization));
    }

    /**
     * Обновить данные текущей организации пользователя.
     * PUT /api/v1/landing/organization
     */
    public function update(UpdateOrganizationRequest $request): Responsable // Используем Responsable
    {
        $organization = $this->organizationService->updateOrganization(Auth::id(), $request->validated());
         if (!$organization) {
            // Сервис должен обработать случай, если организация не найдена или нет прав
            return new NotFoundResponse('Organization not found or update failed');
        }
        return new SuccessResourceResponse(
            new OrganizationResource($organization),
            message: 'Organization updated successfully'
        );
    }
} 