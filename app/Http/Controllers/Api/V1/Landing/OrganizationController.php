<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
// use App\Services\Organization\OrganizationService; // Удаляем зависимость
use App\Http\Requests\Api\V1\Landing\Organization\StoreOrganizationRequest; // Уточняем путь и добавляем Store
use App\Http\Requests\Api\V1\Landing\Organization\UpdateOrganizationRequest;
use App\Http\Resources\Api\V1\Landing\OrganizationResource;
use App\Http\Responses\Api\V1\ErrorResponse;
use App\Http\Responses\Api\V1\NotFoundResponse;
use App\Http\Responses\Api\V1\SuccessResourceResponse;
// use App\Http\Resources\Api\V1\Landing\OrganizationSummaryResource; // Пока комментируем, ресурс нужно создать
use App\Models\Organization;
use App\Models\User; // Добавляем User для type hinting
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection; // Для коллекции
use Illuminate\Http\Resources\Json\JsonResource; // Возвращаемый тип для index
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\Request; // Используем базовый Request для store пока

class OrganizationController extends Controller
{
    // Удаляем конструктор и свойство $organizationService
    // public function __construct(OrganizationService $organizationService)
    // {
    //     $this->organizationService = $organizationService;
    // }

    /**
     * Получить список организаций, к которым принадлежит пользователь.
     * GET /api/v1/landing/user/organizations
     * (Этот маршрут должен быть определен в users.php или здесь)
     */
    public function index(): AnonymousResourceCollection // Возвращаем коллекцию ресурсов
    {
        /** @var User $user */
        $user = Auth::user();
        $organizations = $user->organizations()->get(); // Получаем все организации пользователя

        // TODO: Создать и использовать OrganizationSummaryResource
        // return OrganizationSummaryResource::collection($organizations);
        // Пока возвращаем стандартный ресурс
        return OrganizationResource::collection($organizations);
    }

     /**
     * Создать новую организацию.
     * POST /api/v1/landing/organizations
     */
    // public function store(StoreOrganizationRequest $request): Responsable // Используем базовый Request пока
    public function store(Request $request): Responsable
    {
        /** @var User $user */
        $user = Auth::user();
        
        // TODO: Добавить валидацию (создать StoreOrganizationRequest)
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $organization = Organization::create([
            'name' => $validated['name'],
            'owner_id' => $user->id,
        ]);

        // Привязываем пользователя к созданной организации (если не сделано через observer/event)
        if (!$user->organizations()->where('organization_id', $organization->id)->exists()) {
            $user->organizations()->attach($organization->id, [
                'is_owner' => true,
                'is_active' => true
            ]);
        }
        $user->current_organization_id = $organization->id;
        $user->save();

        return new SuccessResourceResponse(
            new OrganizationResource($organization->fresh()), // Ресурс как первый аргумент
            statusCode: Response::HTTP_CREATED,
            message: 'Organization created successfully'
        );
    }

    /**
     * Получить данные конкретной организации.
     * GET /api/v1/landing/organizations/{organization}
     */
    public function show(Organization $organization): Responsable
    {
        /** @var User $user */
        $user = Auth::user();
        // Проверяем, принадлежит ли пользователь к этой организации
        if (!Auth::user()->organizations()->where('organization_id', $organization->id)->exists()) {
             // Или можно использовать Gate/Policy: Gate::authorize('view', $organization);
            return new ErrorResponse('Access denied to this organization.', Response::HTTP_FORBIDDEN);
        }

        return new SuccessResourceResponse(new OrganizationResource($organization));
    }

    /**
     * Обновить данные конкретной организации.
     * PUT /api/v1/landing/organizations/{organization}
     */
    public function update(UpdateOrganizationRequest $request, Organization $organization): Responsable
    {
        /** @var User $user */ // Добавляем подсказку типа
        $user = Auth::user();
        // TODO: Заменить на Gate::authorize('update', $organization);
        if ($user->id !== $organization->owner_id) { // Используем $user->id
             return new ErrorResponse('You do not have permission to update this organization.', Response::HTTP_FORBIDDEN);
        }

        $organization->update($request->validated());

        return new SuccessResourceResponse(
            new OrganizationResource($organization), // Ресурс как первый аргумент
            message: 'Organization updated successfully'
        );
    }
    
    // Можно добавить метод destroy, если нужно удаление
} 