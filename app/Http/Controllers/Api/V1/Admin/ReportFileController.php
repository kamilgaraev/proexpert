<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Http\Requests\Api\V1\Admin\File\ListFilesRequest;
use Illuminate\Http\JsonResponse;
use App\Models\PersonalFile;
use App\Services\Storage\FileService;
use App\Services\Organization\OrganizationContext;
use Illuminate\Support\Facades\Log;

use function trans_message;

/**
 * Контроллер файлов отчётов
 */
class ReportFileController extends Controller
{
    private const REPORT_FILES_FOLDER = 'reports';

    public function __construct(
        protected FileService $fileService
    ) {
    }

    /**
     * Получить список файлов отчётов
     */
    public function index(ListFilesRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $params = $request->validated();
            $sortBy = $params['sort_by'] ?? 'created_at';
            $sortDir = $params['sort_dir'] ?? 'desc';
            $perPage = (int)($params['per_page'] ?? 15);

            $reportFilesPath = $user->id . '/' . self::REPORT_FILES_FOLDER . '/';

            $query = PersonalFile::where('user_id', $user->id)
                ->where('path', 'like', $reportFilesPath . '%')
                ->where('is_folder', false);

            if (isset($params['filename'])) {
                $query->where('filename', 'like', '%' . $params['filename'] . '%');
            }

            if (isset($params['date_from'])) {
                $query->whereDate('created_at', '>=', $params['date_from']);
            }

            if (isset($params['date_to'])) {
                $query->whereDate('created_at', '<=', $params['date_to']);
            }

            $query->orderBy($sortBy, $sortDir);
            $paginator = $query->paginate($perPage);

            $org = OrganizationContext::getOrganization() ?? $user->currentOrganization;
            $storage = $this->fileService->disk($org);

            $paginator->getCollection()->transform(function (PersonalFile $file) use ($storage) {
                $file->download_url = null;
                try {
                    if ($storage->exists($file->path)) {
                        $file->download_url = $storage->temporaryUrl($file->path, now()->addHours(1));
                    }
                } catch (\Exception $e) {
                    Log::warning('[ReportFileController] Failed to create temporary URL', [
                        'file_id' => $file->id
                    ]);
                }
                return $file;
            });

            return AdminResponse::success(
                $paginator->items(),
                trans_message('files.files_loaded'),
                200,
                [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ]
            );
        } catch (\Throwable $e) {
            Log::error('[ReportFileController] Error loading report files', [
                'error' => $e->getMessage()
            ]);
            return AdminResponse::error(trans_message('files.load_failed'), 500);
        }
    }

    /**
     * Удалить файл отчёта
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $user = request()->user();
            $reportFilesPath = $user->id . '/' . self::REPORT_FILES_FOLDER . '/';

            $file = PersonalFile::where('user_id', $user->id)
                ->where('path', 'like', $reportFilesPath . '%')
                ->where('id', $id)
                ->firstOrFail();

            $org = OrganizationContext::getOrganization() ?? $user->currentOrganization;
            $storage = $this->fileService->disk($org);

            if ($storage->exists($file->path)) {
                $storage->delete($file->path);
            }

            $file->delete();

            return AdminResponse::success(null, trans_message('files.deleted'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('files.not_found'), 404);
        } catch (\Throwable $e) {
            Log::error('[ReportFileController] Error deleting report file', [
                'error' => $e->getMessage(),
                'file_id' => $id
            ]);
            return AdminResponse::error(trans_message('files.delete_failed'), 500);
        }
    }
}
