<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Estimates;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Jobs\ImportNormativeBaseJob;
use App\Models\NormativeImportLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class NormativeImportController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'file' => 'required|file|mimes:xlsx,xls,dbf,csv,xml|max:51200',
                'base_type_code' => 'required|string|exists:normative_base_types,code',
            ]);

            $user = $request->user();
            $organizationId = (int) $user->current_organization_id;
            $file = $request->file('file');
            $filePath = $file->store('normative-imports', 'local');

            $importLog = NormativeImportLog::query()->create([
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'base_type_code' => $validated['base_type_code'],
                'file_path' => $filePath,
                'original_filename' => $file->getClientOriginalName(),
                'status' => 'queued',
            ]);

            ImportNormativeBaseJob::dispatch(
                $filePath,
                $validated['base_type_code'],
                $user->id,
                $organizationId,
                $importLog->id
            );

            return AdminResponse::success([
                'import_log_id' => $importLog->id,
                'import_log' => $importLog,
            ], trans_message('normative_import.upload_queued'), Response::HTTP_ACCEPTED);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('normative_import.upload_failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('normative_import.upload_failed'),
                $this->resolveStatusCode((int) $e->getCode(), Response::HTTP_INTERNAL_SERVER_ERROR)
            );
        }
    }

    public function status(Request $request, int $importLogId): JsonResponse
    {
        try {
            $importLog = $this->findImportLog($importLogId, (int) $request->user()->current_organization_id);

            if (!$importLog) {
                return AdminResponse::error(trans_message('normative_import.not_found'), Response::HTTP_NOT_FOUND);
            }

            return AdminResponse::success($importLog, trans_message('normative_import.loaded'));
        } catch (\Throwable $e) {
            Log::error('normative_import.status_failed', [
                'import_log_id' => $importLogId,
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('normative_import.load_failed'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function history(Request $request): JsonResponse
    {
        try {
            $logs = NormativeImportLog::query()
                ->where('organization_id', (int) $request->user()->current_organization_id)
                ->orderByDesc('created_at')
                ->paginate(20);

            return AdminResponse::paginated(
                $logs->items(),
                [
                    'current_page' => $logs->currentPage(),
                    'from' => $logs->firstItem(),
                    'last_page' => $logs->lastPage(),
                    'links' => [],
                    'path' => $logs->path(),
                    'per_page' => $logs->perPage(),
                    'to' => $logs->lastItem(),
                    'total' => $logs->total(),
                ],
                trans_message('normative_import.history_loaded'),
                Response::HTTP_OK,
                null,
                [
                    'first' => $logs->url(1),
                    'last' => $logs->url($logs->lastPage()),
                    'prev' => $logs->previousPageUrl(),
                    'next' => $logs->nextPageUrl(),
                ]
            );
        } catch (\Throwable $e) {
            Log::error('normative_import.history_failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('normative_import.history_failed'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function retry(Request $request, int $importLogId): JsonResponse
    {
        try {
            $importLog = $this->findImportLog($importLogId, (int) $request->user()->current_organization_id);

            if (!$importLog) {
                return AdminResponse::error(trans_message('normative_import.not_found'), Response::HTTP_NOT_FOUND);
            }

            if ($importLog->status === 'processing') {
                return AdminResponse::error(trans_message('normative_import.already_processing'), Response::HTTP_BAD_REQUEST);
            }

            if (!Storage::disk('local')->exists($importLog->file_path)) {
                return AdminResponse::error(trans_message('normative_import.file_not_found'), Response::HTTP_NOT_FOUND);
            }

            $importLog->update([
                'status' => 'queued',
                'error_message' => null,
                'errors' => null,
            ]);

            ImportNormativeBaseJob::dispatch(
                $importLog->file_path,
                $importLog->base_type_code,
                $importLog->user_id,
                $importLog->organization_id,
                $importLog->id
            );

            return AdminResponse::success([
                'import_log' => $importLog->fresh(),
            ], trans_message('normative_import.retry_queued'));
        } catch (\Throwable $e) {
            Log::error('normative_import.retry_failed', [
                'import_log_id' => $importLogId,
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('normative_import.retry_failed'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function findImportLog(int $importLogId, int $organizationId): ?NormativeImportLog
    {
        return NormativeImportLog::query()
            ->where('organization_id', $organizationId)
            ->find($importLogId);
    }

    private function resolveStatusCode(int $code, int $fallback): int
    {
        return $code >= 100 && $code < 600 ? $code : $fallback;
    }
}
