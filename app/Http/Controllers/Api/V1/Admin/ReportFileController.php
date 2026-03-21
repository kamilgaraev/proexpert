<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\File\ListFilesRequest;
use App\Http\Responses\AdminResponse;
use App\Models\PersonalFile;
use App\Services\Organization\OrganizationContext;
use App\Services\Storage\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use function trans_message;

class ReportFileController extends Controller
{
    private const REPORT_FILES_FOLDER = 'reports';

    public function __construct(
        protected FileService $fileService
    ) {}

    public function index(ListFilesRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $params = $request->validated();
            $sortBy = in_array($params['sort_by'] ?? 'created_at', ['created_at', 'size', 'filename'], true)
                ? $params['sort_by']
                : 'created_at';
            $sortDir = ($params['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
            $perPage = (int) ($params['per_page'] ?? 15);
            $reportFilesPath = $this->reportFilesPathForUser((int) $user->id);

            $query = PersonalFile::query()
                ->where('user_id', $user->id)
                ->where('path', 'like', $reportFilesPath . '%')
                ->where('is_folder', false);

            if (!empty($params['filename'])) {
                $query->where('filename', 'like', '%' . $params['filename'] . '%');
            }

            if (!empty($params['date_from'])) {
                $query->whereDate('created_at', '>=', $params['date_from']);
            }

            if (!empty($params['date_to'])) {
                $query->whereDate('created_at', '<=', $params['date_to']);
            }

            $paginator = $query->orderBy($sortBy, $sortDir)->paginate($perPage);
            $storage = $this->fileService->disk(OrganizationContext::getOrganization() ?? $user->currentOrganization);

            $items = collect($paginator->items())->map(function (PersonalFile $file) use ($storage) {
                $payload = $file->toArray();
                $payload['download_url'] = null;

                try {
                    if ($storage->exists($file->path)) {
                        $payload['download_url'] = $storage->temporaryUrl($file->path, now()->addHours(1));
                    }
                } catch (\Throwable $e) {
                    Log::warning('report_files.temporary_url_failed', [
                        'file_id' => $file->id,
                        'message' => $e->getMessage(),
                    ]);
                }

                return $payload;
            })->values();

            return AdminResponse::paginated(
                $items,
                [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
                trans_message('files.files_loaded')
            );
        } catch (\Throwable $e) {
            Log::error('report_files.index.error', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('files.load_failed'), 500);
        }
    }

    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'filename' => ['required', 'string', 'max:255'],
            ]);

            $user = $request->user();
            $file = $this->findReportFile($id, (int) $user->id);
            $file->filename = $validated['filename'];
            $file->save();

            return AdminResponse::success($file->fresh(), trans_message('files.updated'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('files.operation_failed'), 422, $e->errors());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('files.not_found'), 404);
        } catch (\Throwable $e) {
            Log::error('report_files.update.error', [
                'user_id' => $request->user()?->id,
                'file_id' => $id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('files.operation_failed'), 500);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $file = $this->findReportFile($id, (int) $user->id);
            $storage = $this->fileService->disk(OrganizationContext::getOrganization() ?? $user->currentOrganization);

            if ($storage->exists($file->path)) {
                $storage->delete($file->path);
            }

            $file->delete();

            return AdminResponse::success(null, trans_message('files.deleted'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('files.not_found'), 404);
        } catch (\Throwable $e) {
            Log::error('report_files.destroy.error', [
                'user_id' => $request->user()?->id,
                'file_id' => $id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('files.delete_failed'), 500);
        }
    }

    private function findReportFile(string $id, int $userId): PersonalFile
    {
        return PersonalFile::query()
            ->where('id', $id)
            ->where('user_id', $userId)
            ->where('path', 'like', $this->reportFilesPathForUser($userId) . '%')
            ->where('is_folder', false)
            ->firstOrFail();
    }

    private function reportFilesPathForUser(int $userId): string
    {
        return $userId . '/' . self::REPORT_FILES_FOLDER . '/';
    }
}
