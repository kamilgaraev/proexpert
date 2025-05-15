<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\RateCoefficient\RateCoefficientService;
use App\Http\Requests\Api\V1\Admin\RateCoefficient\StoreRateCoefficientRequest;
use App\Http\Requests\Api\V1\Admin\RateCoefficient\UpdateRateCoefficientRequest;
use App\Http\Resources\Api\V1\Admin\RateCoefficient\RateCoefficientResource;
use App\Http\Resources\Api\V1\Admin\RateCoefficient\RateCoefficientCollection;
use App\Models\RateCoefficient;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Exceptions\BusinessLogicException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpFoundation\Response;

class RateCoefficientController extends Controller
{
    protected RateCoefficientService $coefficientService;

    public function __construct(RateCoefficientService $coefficientService)
    {
        $this->coefficientService = $coefficientService;
        // Middleware для проверки прав доступа можно добавить здесь или в роутах
        // $this->middleware('can:manage-rate-coefficients'); 
    }

    /**
     * @OA\Get(
     *     path="/api/v1/admin/rate-coefficients",
     *     summary="Получить список коэффициентов",
     *     tags={"Admin/RateCoefficients"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="page", in="query", @OA\Schema(type="integer", default=1)),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15)),
     *     @OA\Parameter(name="name", description="Фильтр по названию", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="code", description="Фильтр по коду", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="type", description="Фильтр по типу", in="query", @OA\Schema(type="string", enum={"percentage", "fixed_addition"})),
     *     @OA\Parameter(name="applies_to", description="Фильтр по назначению", in="query", @OA\Schema(type="string", enum={"material_norms", "work_costs", "labor_hours", "general"})),
     *     @OA\Parameter(name="scope", description="Фильтр по области применения", in="query", @OA\Schema(type="string", enum={"global_org", "project", "work_type_category", "work_type", "material_category", "material"})),
     *     @OA\Parameter(name="is_active", description="Фильтр по активности (true/false)", in="query", @OA\Schema(type="boolean")),
     *     @OA\Parameter(name="sort_by", description="Поле для сортировки", in="query", @OA\Schema(type="string", default="created_at")),
     *     @OA\Parameter(name="sort_direction", description="Направление сортировки (asc/desc)", in="query", @OA\Schema(type="string", default="desc")),
     *     @OA\Response(response=200, description="Успешный ответ", @OA\JsonContent(ref="#/components/schemas/RateCoefficientCollection")),
     *     @OA\Response(response=401, description="Неавторизован"),
     *     @OA\Response(response=500, description="Внутренняя ошибка сервера")
     * )
     */
    public function index(Request $request): RateCoefficientCollection
    {
        $perPage = $request->input('per_page', 15);
        $coefficients = $this->coefficientService->getAllCoefficients($request, (int)$perPage);
        return new RateCoefficientCollection($coefficients);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/admin/rate-coefficients",
     *     summary="Создать новый коэффициент",
     *     tags={"Admin/RateCoefficients"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/StoreRateCoefficientRequest")
     *     ),
     *     @OA\Response(response=201, description="Коэффициент успешно создан", @OA\JsonContent(ref="#/components/schemas/RateCoefficientResource")),
     *     @OA\Response(response=422, description="Ошибка валидации"),
     *     @OA\Response(response=401, description="Неавторизован")
     * )
     */
    public function store(StoreRateCoefficientRequest $request): JsonResponse
    {
        try {
            $dto = $request->toDto();
            $coefficient = $this->coefficientService->createCoefficient($dto, $request);
            return response()->json(new RateCoefficientResource($coefficient), Response::HTTP_CREATED);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/admin/rate-coefficients/{rate_coefficient}",
     *     summary="Получить информацию о коэффициенте",
     *     tags={"Admin/RateCoefficients"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="rate_coefficient", in="path", required=true, @OA\Schema(type="integer"), description="ID коэффициента"),
     *     @OA\Response(response=200, description="Успешный ответ", @OA\JsonContent(ref="#/components/schemas/RateCoefficientResource")),
     *     @OA\Response(response=404, description="Коэффициент не найден"),
     *     @OA\Response(response=401, description="Неавторизован")
     * )
     */
    public function show(Request $request, int $id): JsonResponse // Используем явный int $id
    {
        $coefficient = $this->coefficientService->findCoefficientById($id, $request);
        if (!$coefficient) {
            return response()->json(['success' => false, 'message' => 'Коэффициент не найден.'], Response::HTTP_NOT_FOUND);
        }
        return response()->json(new RateCoefficientResource($coefficient));
    }

    /**
     * @OA\Put(
     *     path="/api/v1/admin/rate-coefficients/{rate_coefficient}",
     *     summary="Обновить коэффициент",
     *     tags={"Admin/RateCoefficients"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="rate_coefficient", in="path", required=true, @OA\Schema(type="integer"), description="ID коэффициента"),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/UpdateRateCoefficientRequest")
     *     ),
     *     @OA\Response(response=200, description="Коэффициент успешно обновлен", @OA\JsonContent(ref="#/components/schemas/RateCoefficientResource")),
     *     @OA\Response(response=404, description="Коэффициент не найден"),
     *     @OA\Response(response=422, description="Ошибка валидации"),
     *     @OA\Response(response=401, description="Неавторизован")
     * )
     */
    public function update(UpdateRateCoefficientRequest $request, int $id): JsonResponse // Явный int $id
    {
        try {
            // Laravel автоматически выполнит поиск модели RateCoefficient по $id 
            // если использовать (UpdateRateCoefficientRequest $request, RateCoefficient $rate_coefficient)
            // но для toDto в UpdateRateCoefficientRequest мы передаем $this->route('rate_coefficient'), 
            // так что оставим $id и ручной поиск в сервисе для единообразия с show().
            // Или можно переделать toDto для приема модели.
            
            $dto = $request->toDto(); // toDto теперь должен сам доставать модель через $this->route('rate_coefficient')
            $coefficient = $this->coefficientService->updateCoefficient($id, $dto, $request);
            return response()->json(new RateCoefficientResource($coefficient));
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        } catch (ModelNotFoundException $e) { // Если вдруг findCoefficientById в сервисе не найдет
            return response()->json(['success' => false, 'message' => 'Коэффициент не найден.'], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/admin/rate-coefficients/{rate_coefficient}",
     *     summary="Удалить коэффициент",
     *     tags={"Admin/RateCoefficients"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="rate_coefficient", in="path", required=true, @OA\Schema(type="integer"), description="ID коэффициента"),
     *     @OA\Response(response=204, description="Коэффициент успешно удален"),
     *     @OA\Response(response=404, description="Коэффициент не найден"),
     *     @OA\Response(response=401, description="Неавторизован")
     * )
     */
    public function destroy(Request $request, int $id): JsonResponse // Явный int $id
    {
        try {
            $deleted = $this->coefficientService->deleteCoefficient($id, $request);
            if ($deleted) {
                return response()->json(null, Response::HTTP_NO_CONTENT);
            }
            // Эта ветка по идее не должна выполниться, т.к. сервис бросит Exception, если не найдет
            return response()->json(['success' => false, 'message' => 'Не удалось удалить коэффициент.'], Response::HTTP_NOT_FOUND); 
        } catch (BusinessLogicException $e) {
             return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: Response::HTTP_NOT_FOUND);
        }
    }
} 