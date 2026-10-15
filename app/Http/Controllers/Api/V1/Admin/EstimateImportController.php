<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Exceptions\ImportSessionStateException;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Exceptions\UnsupportedEstimateImportFormatException;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportAccessContext;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\StagingAreaService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\VoiceCommandService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Estimate\UploadEstimateImportRequest;
use App\Http\Requests\Admin\Estimate\DetectEstimateTypeRequest;
use App\Http\Requests\Admin\Estimate\DetectEstimateImportRequest;
use App\Http\Requests\Admin\Estimate\MapEstimateImportRequest;
use App\Http\Requests\Admin\Estimate\ExecuteEstimateImportRequest;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateImportPreviewResource;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateImportHistoryResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class EstimateImportController extends Controller
{
    public function __construct(
        private EstimateImportService $importService,
        private StagingAreaService $stagingArea,
        private VoiceCommandService $voiceCommandService
    ) {}

    public function upload(UploadEstimateImportRequest $request): JsonResponse
    {
        try {
            $context = $this->context($request);
            $file = $request->file('file');
            if (! $file instanceof \Illuminate\Http\UploadedFile) {
                return AdminResponse::error(trans_message('estimate.import_no_file'), 400);
            }
            $id = $this->importService->uploadFile($file, (int) $context->actor->id, $context->organizationId, $context);

            return AdminResponse::success([
                'file_id' => $id, 'file_name' => $file->getClientOriginalName(), 'file_size' => $file->getSize(),
                'expires_at' => now()->addHours(24)->toIso8601String(),
            ]);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'import_upload_error', 500);
        }
    }

    public function detectType(DetectEstimateTypeRequest $request): JsonResponse
    {
        try {
            $detection = $this->importService->detectEstimateType((string) $request->input('file_id'), $this->context($request));
            $metadata = $detection->metadata;

            return AdminResponse::success([
                'detected_type' => $detection->detectedType, 'type_description' => $detection->getTypeDescription(),
                'confidence' => $detection->confidence, 'is_high_confidence' => $detection->isHighConfidence(0.9),
                'is_template' => $detection->detectedType === 'most_template' || (bool) ($metadata['is_template'] ?? false),
                'format_slug' => $metadata['format_slug'] ?? null, 'label' => $metadata['label'] ?? null,
                'requires_confirmation' => (bool) ($metadata['requires_confirmation'] ?? false),
                'warnings' => $metadata['warnings'] ?? [], 'indicators' => $detection->indicators,
                'candidates' => $detection->candidates, 'metadata' => $metadata,
            ]);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'import_detect_type_error', 500);
        }
    }

    public function detect(DetectEstimateImportRequest $request): JsonResponse
    {
        try {
            return AdminResponse::success($this->importService->detectFormat(
                (string) $request->input('file_id'), $request->filled('suggested_header_row') ? (int) $request->input('suggested_header_row') : null, $this->context($request),
                $request->safe()->only(['worksheet_index'])
            ));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'import_detect_format_error', 422);
        }
    }

    public function map(MapEstimateImportRequest $request): JsonResponse
    {
        try {
            $preview = $this->importService->preview(
                (string) $request->input('file_id'), $request->input('column_mapping'), $this->context($request),
                $request->safe()->only(['worksheet_index', 'worksheet_mappings', 'manual_mapping'])
            );

            return AdminResponse::success([
                'preview' => new EstimateImportPreviewResource($preview),
                'validation' => $preview->validationSummary ?: ['errors' => [], 'warnings' => []],
            ]);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'import_preview_error', 422);
        }
    }

    public function match(Request $request): JsonResponse
    {
        $request->validate(['file_id' => ['required', 'string', 'uuid']]);
        try {
            $context = $this->context($request);

            return AdminResponse::success($this->importService->analyzeMatches((string) $request->input('file_id'), $context->organizationId, $context));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'import_match_error', 422);
        }
    }

    public function execute(ExecuteEstimateImportRequest $request): JsonResponse
    {
        try {
            return AdminResponse::success($this->importService->execute(
                (string) $request->input('file_id'), $request->input('matching_config'), $request->input('estimate_settings'),
                $request->boolean('validate_only'), $this->context($request), $request->boolean('retry')
            ));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'import_execute_error', 422);
        }
    }

    public function status(Request $request, mixed $project, ?string $jobId = null): JsonResponse
    {
        $jobId ??= $request->input('jobId');
        if (! $jobId) {
            return AdminResponse::error(trans_message('estimate.import_status_job_required'), 400);
        }
        if (! Uuid::isValid($jobId)) {
            return AdminResponse::error(trans_message('estimate.import_status_job_invalid'), 400);
        }
        try {
            return AdminResponse::success($this->importService->getImportStatus($jobId, $this->context($request)));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'import_status_error', 500);
        }
    }

    public function history(Request $request): JsonResponse
    {
        try {
            $context = $this->context($request);
            $history = $this->importService->getImportHistory($context->organizationId, max(1, min(100, (int) $request->input('limit', 50))), $context);

            return AdminResponse::success(EstimateImportHistoryResource::collection($history));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'import_status_error', 500);
        }
    }

    public function staging(Request $request): JsonResponse
    {
        $request->validate(['session_id' => ['required', 'string', 'uuid']]);
        try {
            $context = $this->context($request);
            return AdminResponse::success($this->stagingArea->buildPreview((string) $request->input('session_id'), $context->organizationId, $context));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'import_staging_error', 500);
        }
    }

    public function voiceCommand(Request $request): JsonResponse
    {
        $request->validate(['text' => ['required', 'string', 'max:1000'], 'rows' => ['sometimes', 'array']]);
        try {
            $this->context($request)->authorize();
            $rows = $request->input('rows', []);
            $parsed = $this->voiceCommandService->parseCommand($request->input('text'), $rows);
            if (! $parsed['success']) {
                return AdminResponse::error(trans_message('estimate.import_voice_command_unrecognized'), 422);
            }
            $updated = $this->voiceCommandService->executeCommand($parsed['command'], $parsed['params'], $rows);

            return AdminResponse::success([
                'command' => $parsed['command'], 'description' => $parsed['description'], 'params' => $parsed['params'],
                'rows' => $updated, 'rows_before' => count($rows), 'rows_after' => count($updated),
            ]);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'import_voice_command_error', 500);
        }
    }

    public function downloadTemplate(Request $request): StreamedResponse|JsonResponse
    {
        try {
            return $this->importService->downloadTemplate($this->context($request));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'import_template_error', 500);
        }
    }

    private function context(Request $request): ImportAccessContext
    {
        $actor = $request->user();
        if (! $actor instanceof \App\Models\User) {
            throw new AuthorizationException;
        }
        $project = $request->route('project');

        return new ImportAccessContext($actor, (int) $actor->current_organization_id, (int) (is_object($project) ? $project->id : $project));
    }

    private function failure(Throwable $exception, string $key, int $status): JsonResponse
    {
        $reasonCode = $key;
        if ($exception instanceof ModelNotFoundException) {
            $key = 'import_status_not_found';
            $reasonCode = 'import_session_not_found';
            $status = 404;
        } elseif ($exception instanceof AuthorizationException) {
            $key = 'import_access_denied';
            $reasonCode = 'import_access_denied';
            $status = 403;
        } elseif ($exception instanceof ImportSessionStateException) {
            $key = substr($exception->translationKey, strlen('estimate.'));
            $reasonCode = $exception->reasonCode;
            $status = $exception->httpStatus;
        } elseif ($exception instanceof UnsupportedEstimateImportFormatException) {
            $key = 'import_unsupported_format';
            $reasonCode = 'import_unsupported_format';
            $status = 422;
        }
        $correlationId = (string) Str::uuid();
        Log::log($status >= 500 ? 'error' : 'warning', '[EstimateImport] Request failed', [
            'correlation_id' => $correlationId, 'reason_code' => $reasonCode, 'exception_class' => $exception::class,
            'exception_file' => basename($exception->getFile()), 'exception_line' => $exception->getLine(),
        ]);

        return AdminResponse::error(trans_message('estimate.'.$key), $status, null, ['code' => $reasonCode, 'correlation_id' => $correlationId]);
    }
}
