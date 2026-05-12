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
use Illuminate\Support\Str;

use function trans_message;

/**
 * Контроллер личных файлов пользователя
 */
class PersonalFileController extends Controller
{
    public function __construct(
        protected FileService $fileService
    ) {
    }

    /**
     * Получить список личных файлов
     */
    public function index(ListFilesRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $params = $request->validated();
            $sortBy = $params['sort_by'] ?? 'created_at';
            $sortDir = $params['sort_dir'] ?? 'desc';
            $perPage = (int)($params['per_page'] ?? 15);

            $query = PersonalFile::where('user_id', $user->id)
                ->where('is_folder', false);

            if (isset($params['folder'])) {
                $query->where('path', 'like', $user->id . '/' . $params['folder'] . '%');
            }

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
                    Log::warning('[PersonalFileController] Failed to create temporary URL', [
                        'file_id' => $file->id
                    ]);
                }
                return $file;
            });

            return AdminResponse::paginated(
                $paginator->items(),
                [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
                trans_message('files.files_loaded')
            );
        } catch (\Throwable $e) {
            Log::error('[PersonalFileController] Error loading personal files', [
                'error' => $e->getMessage()
            ]);
            return AdminResponse::error(trans_message('files.load_failed'), 500);
        }
    }

    /**
     * Удалить личный файл
     */
    public function createFolder(\Illuminate\Http\Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:120', 'not_regex:/[\\\\\\/]/'],
                'parent_path' => ['nullable', 'string', 'max:500'],
            ]);

            $user = $request->user();
            $parentPath = $this->normalizeRelativePath($validated['parent_path'] ?? '');
            $folderPath = $this->userPath((int) $user->id, trim($parentPath . '/' . $validated['name'], '/')) . '/';

            $folder = PersonalFile::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'path' => $folderPath,
                ],
                [
                    'filename' => $validated['name'],
                    'size' => 0,
                    'is_folder' => true,
                ]
            );

            return AdminResponse::success($folder, trans_message('files.folder_created'), 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('files.operation_failed'), 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('[PersonalFileController] Error creating personal folder', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(trans_message('files.operation_failed'), 500);
        }
    }

    public function upload(\Illuminate\Http\Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'file' => ['required', 'file', 'max:51200'],
                'parent_path' => ['nullable', 'string', 'max:500'],
            ]);

            $user = $request->user();
            $uploadedFile = $validated['file'];
            $parentPath = $this->normalizeRelativePath($validated['parent_path'] ?? $request->query('parent_path', ''));
            $extension = $uploadedFile->getClientOriginalExtension();
            $storedName = (string) Str::uuid() . ($extension ? '.' . $extension : '');
            $storagePath = $this->userPath((int) $user->id, trim($parentPath . '/' . $storedName, '/'));
            $org = OrganizationContext::getOrganization() ?? $user->currentOrganization;
            $storage = $this->fileService->disk($org);
            $content = file_get_contents($uploadedFile->getRealPath());

            if ($content === false) {
                return AdminResponse::error(trans_message('files.upload_failed'), 500);
            }

            $storage->put($storagePath, $content);

            $file = PersonalFile::query()->create([
                'user_id' => $user->id,
                'path' => $storagePath,
                'filename' => $uploadedFile->getClientOriginalName(),
                'size' => $uploadedFile->getSize() ?: 0,
                'is_folder' => false,
            ]);

            return AdminResponse::success($file, trans_message('files.uploaded'), 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('files.operation_failed'), 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('[PersonalFileController] Error uploading personal file', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(trans_message('files.upload_failed'), 500);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $user = request()->user();

            $file = PersonalFile::where('user_id', $user->id)
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
            Log::error('[PersonalFileController] Error deleting personal file', [
                'error' => $e->getMessage(),
                'file_id' => $id
            ]);
            return AdminResponse::error(trans_message('files.delete_failed'), 500);
        }
    }

    private function normalizeRelativePath(string $path): string
    {
        $segments = collect(explode('/', str_replace('\\', '/', $path)))
            ->map(static fn (string $segment): string => trim($segment))
            ->filter(static fn (string $segment): bool => $segment !== '' && $segment !== '.' && $segment !== '..')
            ->values()
            ->all();

        return implode('/', $segments);
    }

    private function userPath(int $userId, string $relativePath): string
    {
        $relativePath = $this->normalizeRelativePath($relativePath);

        return $relativePath === '' ? (string) $userId : $userId . '/' . $relativePath;
    }
}
