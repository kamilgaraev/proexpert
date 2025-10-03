<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomReport;
use App\Services\Report\CustomReportBuilderService;
use App\Services\Report\CustomReportExecutionService;
use App\Http\Requests\Api\V1\Admin\CustomReport\CreateCustomReportRequest;
use App\Http\Requests\Api\V1\Admin\CustomReport\UpdateCustomReportRequest;
use App\Http\Requests\Api\V1\Admin\CustomReport\ExecuteCustomReportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomReportController extends Controller
{
    public function __construct(
        protected CustomReportBuilderService $builderService,
        protected CustomReportExecutionService $executionService
    ) {
        $this->middleware('can:view-reports');
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $query = CustomReport::forOrganization($organizationId)
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere('is_shared', true);
            });

        if ($request->filled('category')) {
            $query->byCategory($request->category);
        }

        if ($request->boolean('is_favorite')) {
            $query->where('user_id', $user->id)->where('is_favorite', true);
        }

        if ($request->boolean('is_shared')) {
            $query->shared();
        }

        $reports = $query->with('user')
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $reports->items(),
            'meta' => [
                'current_page' => $reports->currentPage(),
                'per_page' => $reports->perPage(),
                'total' => $reports->total(),
                'last_page' => $reports->lastPage(),
            ],
        ]);
    }

    public function store(CreateCustomReportRequest $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $data = $request->validated();
        
        $errors = $this->builderService->validateReportConfig($data);
        
        if (!empty($errors)) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации конфигурации отчета',
                'errors' => $errors,
            ], 422);
        }

        $report = CustomReport::create([
            ...$data,
            'user_id' => $user->id,
            'organization_id' => $organizationId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Отчет успешно создан',
            'data' => $report->load('user'),
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $report = CustomReport::find($id);

        if (!$report || !$report->canBeViewedBy($user->id, $organizationId)) {
            return response()->json([
                'success' => false,
                'message' => 'Отчет не найден',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $report->load(['user', 'executions' => fn($q) => $q->recent()->limit(10)]),
        ]);
    }

    public function update(UpdateCustomReportRequest $request, int $id): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $report = CustomReport::find($id);

        if (!$report || !$report->canBeEditedBy($user->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Отчет не найден или у вас нет прав на редактирование',
            ], 403);
        }

        $data = $request->validated();
        
        if (isset($data['data_sources']) || isset($data['columns_config'])) {
            $configToValidate = array_merge($report->toArray(), $data);
            $errors = $this->builderService->validateReportConfig($configToValidate);
            
            if (!empty($errors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибка валидации конфигурации отчета',
                    'errors' => $errors,
                ], 422);
            }
        }

        $report->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Отчет успешно обновлен',
            'data' => $report->fresh(['user']),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $report = CustomReport::find($id);

        if (!$report || !$report->canBeEditedBy($user->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Отчет не найден или у вас нет прав на удаление',
            ], 403);
        }

        $report->delete();

        return response()->json([
            'success' => true,
            'message' => 'Отчет успешно удален',
        ]);
    }

    public function clone(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $report = CustomReport::find($id);

        if (!$report || !$report->canBeViewedBy($user->id, $organizationId)) {
            return response()->json([
                'success' => false,
                'message' => 'Отчет не найден',
            ], 404);
        }

        $newReport = $this->builderService->cloneReport($report, $user->id, $organizationId);

        return response()->json([
            'success' => true,
            'message' => 'Отчет успешно склонирован',
            'data' => $newReport->load('user'),
        ], 201);
    }

    public function toggleFavorite(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $report = CustomReport::find($id);

        if (!$report || !$report->canBeViewedBy($user->id, $organizationId)) {
            return response()->json([
                'success' => false,
                'message' => 'Отчет не найден',
            ], 404);
        }

        if ($report->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Только владелец может изменять статус избранного',
            ], 403);
        }

        $report->update(['is_favorite' => !$report->is_favorite]);

        return response()->json([
            'success' => true,
            'data' => ['is_favorite' => $report->is_favorite],
        ]);
    }

    public function updateSharing(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $report = CustomReport::find($id);

        if (!$report || !$report->canBeEditedBy($user->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Отчет не найден или у вас нет прав',
            ], 403);
        }

        $request->validate(['is_shared' => 'required|boolean']);

        $report->update(['is_shared' => $request->is_shared]);

        return response()->json([
            'success' => true,
            'message' => $request->is_shared 
                ? 'Отчет теперь доступен всей организации' 
                : 'Отчет теперь приватный',
            'data' => ['is_shared' => $report->is_shared],
        ]);
    }

    public function execute(ExecuteCustomReportRequest $request, int $id): JsonResponse|StreamedResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $report = CustomReport::find($id);

        if (!$report || !$report->canBeViewedBy($user->id, $organizationId)) {
            return response()->json([
                'success' => false,
                'message' => 'Отчет не найден',
            ], 404);
        }

        $filters = $request->input('filters', []);
        $exportFormat = $request->input('export');

        return $this->executionService->executeReport(
            $report,
            $organizationId,
            $filters,
            $exportFormat,
            $user->id
        );
    }

    public function executions(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $report = CustomReport::find($id);

        if (!$report || !$report->canBeViewedBy($user->id, $organizationId)) {
            return response()->json([
                'success' => false,
                'message' => 'Отчет не найден',
            ], 404);
        }

        $executions = $this->executionService->getExecutionHistory(
            $report,
            $request->input('limit', 50)
        );

        return response()->json([
            'success' => true,
            'data' => $executions,
        ]);
    }
}

