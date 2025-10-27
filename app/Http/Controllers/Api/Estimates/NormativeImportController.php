<?php

namespace App\Http\Controllers\Api\Estimates;

use App\Http\Controllers\Controller;
use App\Jobs\ImportNormativeBaseJob;
use App\Models\NormativeImportLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class NormativeImportController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,dbf,csv,xml|max:51200',
            'base_type_code' => 'required|string|exists:normative_base_types,code',
        ]);

        $file = $request->file('file');
        $filePath = $file->store('normative-imports', 'local');
        
        $importLog = NormativeImportLog::create([
            'user_id' => $request->user()->id,
            'organization_id' => $request->user()->current_organization_id,
            'base_type_code' => $request->input('base_type_code'),
            'file_path' => $filePath,
            'original_filename' => $file->getClientOriginalName(),
            'status' => 'queued',
        ]);

        ImportNormativeBaseJob::dispatch(
            $filePath,
            $request->input('base_type_code'),
            $request->user()->id,
            $request->user()->current_organization_id,
            $importLog->id
        );

        return response()->json([
            'message' => 'Файл загружен и поставлен в очередь на импорт',
            'import_log_id' => $importLog->id,
            'import_log' => $importLog,
        ], 202);
    }

    public function status(int $importLogId): JsonResponse
    {
        $importLog = NormativeImportLog::findOrFail($importLogId);

        return response()->json($importLog);
    }

    public function history(Request $request): JsonResponse
    {
        $organizationId = $request->user()->current_organization_id;

        $logs = NormativeImportLog::where('organization_id', $organizationId)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($logs);
    }

    public function retry(int $importLogId): JsonResponse
    {
        $importLog = NormativeImportLog::findOrFail($importLogId);

        if ($importLog->status === 'processing') {
            return response()->json(['error' => 'Импорт уже выполняется'], 400);
        }

        if (!Storage::disk('local')->exists($importLog->file_path)) {
            return response()->json(['error' => 'Файл не найден'], 404);
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

        return response()->json([
            'message' => 'Импорт повторно поставлен в очередь',
            'import_log' => $importLog->fresh(),
        ]);
    }
}
