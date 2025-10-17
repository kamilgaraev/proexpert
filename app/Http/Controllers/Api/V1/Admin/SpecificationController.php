<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Contract\SpecificationService;
use App\Http\Requests\Api\V1\Admin\Specification\StoreSpecificationRequest;
use App\Http\Requests\Api\V1\Admin\Specification\UpdateSpecificationRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SpecificationController extends Controller
{
    public function __construct(private SpecificationService $service) {}

    public function index(Request $request)
    {
        // Получаем project_id из URL (обязательный параметр для project-based маршрутов)
        $projectId = $request->route('project');
        $perPage = $request->query('per_page', 15);
        
        // TODO: Добавить фильтрацию по project_id в SpecificationService
        // Пока возвращаем все спецификации (требует доработки сервиса)
        return $this->service->paginate($perPage);
    }

    public function store(StoreSpecificationRequest $request)
    {
        $spec = $this->service->create($request->toDto());
        return response()->json($spec, Response::HTTP_CREATED);
    }

    public function show(int $id)
    {
        $spec = $this->service->getById($id);
        if (!$spec) {
            return response()->json(['message' => 'Not found'], Response::HTTP_NOT_FOUND);
        }
        return $spec;
    }

    public function update(UpdateSpecificationRequest $request, int $id)
    {
        $this->service->update($id, $request->toDto());
        return response()->json($this->service->getById($id));
    }

    public function destroy(int $id)
    {
        $this->service->delete($id);
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
} 