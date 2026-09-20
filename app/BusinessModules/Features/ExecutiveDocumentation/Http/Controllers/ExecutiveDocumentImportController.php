<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Controllers;

use App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests\ExecutiveDocumentImportRequest;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentImportService;
use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ExecutiveDocumentImportController extends Controller
{
    public function __construct(private readonly ExecutiveDocumentImportService $service) {}

    public function index(Request $request, int $set): JsonResponse
    {
        return $this->respond(fn () => $this->service->recent($this->set($request, $set), (int) $request->user()?->id));
    }

    public function show(Request $request, int $batch): JsonResponse
    {
        return $this->respond(fn () => $this->service->find($batch, (int) $request->attributes->get('current_organization_id'), (int) $request->user()?->id));
    }

    public function store(ExecutiveDocumentImportRequest $request, int $set): JsonResponse
    {
        return $this->respond(fn () => $this->service->create($this->set($request, $set), (int) $request->user()?->id, $request->validated('operation_key'), $request->validated('files')));
    }

    public function upload(ExecutiveDocumentImportRequest $request, int $batch, int $importItem): JsonResponse
    {
        return $this->respond(fn () => $this->service->upload($this->service->find($batch, (int) $request->attributes->get('current_organization_id'), (int) $request->user()?->id), (int) $request->user()?->id, $importItem, $request->file('file')));
    }

    public function map(ExecutiveDocumentImportRequest $request, int $batch): JsonResponse
    {
        return $this->respond(fn () => $this->service->map($this->service->find($batch, (int) $request->attributes->get('current_organization_id'), (int) $request->user()?->id), (int) $request->user()?->id, $request->validated('rows')));
    }

    public function start(ExecutiveDocumentImportRequest $request, int $batch): JsonResponse
    {
        return $this->respond(fn () => $this->service->start($this->service->find($batch, (int) $request->attributes->get('current_organization_id'), (int) $request->user()?->id), (int) $request->user()?->id, $request->boolean('retry_failed_only')));
    }

    private function set(Request $request, int $id): ExecutiveDocumentSet
    {
        return ExecutiveDocumentSet::query()->where('organization_id', (int) $request->attributes->get('current_organization_id'))->findOrFail($id);
    }

    private function respond(callable $action): JsonResponse
    {
        try {
            return AdminResponse::success($action());
        } catch (ValidationException $exception) {
            return AdminResponse::error(trans_message('executive_documentation.errors.profile_data_invalid'), 422, $exception->errors());
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('executive_documentation.errors.not_found'), 404);
        } catch (BusinessLogicException $exception) {
            $status = in_array($exception->getCode(), [403, 404, 409, 422, 503], true) ? $exception->getCode() : 422;
            return AdminResponse::error(trans_message('executive_documentation.errors.import_action_failed'), $status);
        } catch (\Throwable $exception) {
            report($exception);
            return AdminResponse::error(trans_message('executive_documentation.errors.import_action_failed'), 500);
        }
    }
}
