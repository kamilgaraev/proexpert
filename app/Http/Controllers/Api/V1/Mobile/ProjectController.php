<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Mobile\MobileProjectResource;
use App\Http\Responses\MobileResponse;
use App\Services\PerformanceMonitor;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use function trans_message;

class ProjectController extends Controller
{
    public function __construct(
        private readonly UserProjectAccessService $userProjectAccessService,
    ) {
    }

    /**
     * Получить список проектов, доступных пользователю.
     * 
     * Список формируется по режиму доступа пользователя и активному участию организации в проекте.
     */
    public function index(Request $request): JsonResponse
    {
        return PerformanceMonitor::measure('mobile.projects.index', function () {
            /** @var \App\Models\User $user */
            $user = auth()->user();
            $organizationId = $user->current_organization_id;

            if (!$organizationId) {
                return MobileResponse::error(trans_message('project.mobile_no_organization'), 400);
            }

            $query = $this->userProjectAccessService
                ->queryAccessibleProjects($user, $organizationId)
                ->with(['users' => function ($q) use ($user): void {
                    $q->where('users.id', $user->id);
                }])
                ->orderBy('created_at', 'desc');

            $projects = $query->get();

            return MobileResponse::success(MobileProjectResource::collection($projects));
        });
    }
}
