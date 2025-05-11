<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Material\MaterialService; // Используем существующий сервис
use App\Http\Resources\Api\V1\Mobile\Material\MobileMaterialResource;
use App\Http\Resources\Api\V1\Mobile\Material\MobileMaterialBalanceResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Exceptions\BusinessLogicException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use App\Models\Project;

class MaterialController extends Controller
{
    protected MaterialService $materialService;

    public function __construct(MaterialService $materialService)
    {
        $this->materialService = $materialService;
    }

    /**
     * Получить список материалов для текущей организации пользователя (прораба).
     * Возвращает все активные материалы, не пагинированные.
     */
    public function index(Request $request): AnonymousResourceCollection | JsonResponse
    {
        try {
            $user = $request->user();
            Log::info('[Mobile\MaterialController@index] User requested material list.', [
                'user_id' => $user?->id,
                'organization_id' => $user?->current_organization_id,
                'params' => $request->all(), // Можно добавить параметры запроса, если они есть и важны
            ]);

            $materials = $this->materialService->getAllActive($request); // Используем существующий метод getAllActive
            
            return MobileMaterialResource::collection($materials);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('[Mobile\MaterialController@index] Error fetching materials for mobile', [
                'user_id' => $request->user()?->id,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return response()->json(['success' => false, 'message' => 'Внутренняя ошибка сервера при получении списка материалов.'], 500);
        }
    }

    /**
     * Получить балансы материалов для указанного проекта.
     */
    public function getMaterialBalances(Request $request, int $projectId): AnonymousResourceCollection | JsonResponse
    {
        try {
            // Проверка, что проект существует и пользователь к нему привязан
            $user = $request->user();
            $project = Project::where('id', $projectId)
                              ->whereHas('users', function ($query) use ($user) {
                                  $query->where('user_id', $user->id);
                              })
                              ->first();
            if (!$project) {
                throw new BusinessLogicException('Проект не найден или недоступен пользователю.', 404);
            }

            $organizationId = $project->organization_id; // Получаем ID организации из проекта
            
            $balances = $this->materialService->getMaterialBalancesForProject($organizationId, $projectId);
            return MobileMaterialBalanceResource::collection($balances);

        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('[Mobile\MaterialController@getMaterialBalances] Error fetching material balances', [
                'user_id' => $request->user()?->id,
                'project_id' => $projectId,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return response()->json(['success' => false, 'message' => 'Внутренняя ошибка сервера при получении балансов материалов.'], 500);
        }
    }
} 