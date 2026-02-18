<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Mobile\MobileProjectResource;
use App\Http\Responses\MobileResponse;
use App\Models\Project;
use App\Services\PerformanceMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    /**
     * Получить список проектов, доступных пользователю.
     * 
     * Логика:
     * - Если пользователь Админ/Владелец организации -> видит ВСЕ проекты организации.
     * - Иначе (Прораб/Мастер) -> видит только проекты, куда он назначен (через project_user).
     */
    public function index(Request $request): JsonResponse
    {
        return PerformanceMonitor::measure('mobile.projects.index', function() use ($request) {
            /** @var \App\Models\User $user */
            $user = auth()->user();
            $organizationId = $user->current_organization_id;

            if (!$organizationId) {
                return MobileResponse::error('Не выбрана организация', 400);
            }

            $query = Project::where('organization_id', $organizationId)
                ->orderBy('created_at', 'desc');

            // Если НЕ админ организации — фильтруем по назначениям
            if (!$user->isOrganizationAdmin($organizationId)) {
                $query->whereHas('users', function($q) use ($user) {
                    $q->where('users.id', $user->id);
                });
                // Подгружаем pivot, чтобы знать роль в проекте
                $query->with(['users' => function($q) use ($user) {
                    $q->where('users.id', $user->id);
                }]);
            }

            $projects = $query->get();

            return MobileResponse::success(MobileProjectResource::collection($projects));
        });
    }
}
