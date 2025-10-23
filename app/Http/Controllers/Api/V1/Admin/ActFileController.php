<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;
use App\Models\PersonalFile;
use App\Services\Storage\FileService;
use App\Services\Organization\OrganizationContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Exception;

class ActFileController extends Controller
{
    const ACT_FILES_FOLDER = 'acts';

    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            $validator = Validator::make($request->all(), [
                'filename' => ['sometimes', 'string'],
                'date_from' => ['sometimes', 'date'],
                'date_to' => ['sometimes', 'date'],
                'sort_by' => ['sometimes', 'in:created_at,size,filename'],
                'sort_dir' => ['sometimes', 'in:asc,desc'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $params = $validator->validated();
            $sortBy = $params['sort_by'] ?? 'created_at';
            $sortDir = $params['sort_dir'] ?? 'desc';
            $perPage = (int)($params['per_page'] ?? 15);

            $actFilesPath = $user->id . '/' . self::ACT_FILES_FOLDER . '/';

            $query = PersonalFile::where('user_id', $user->id)
                ->where('path', 'like', $actFilesPath . '%')
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

            $fs = app(FileService::class);
            $org = OrganizationContext::getOrganization() ?? Auth::user()?->currentOrganization;
            $storage = $fs->disk($org);

            $paginator->getCollection()->transform(function (PersonalFile $file) use ($storage) {
                $downloadUrl = null;
                try {
                    if ($storage->exists($file->path)) {
                        $downloadUrl = $storage->temporaryUrl($file->path, now()->addHours(1));
                    }
                } catch (Exception $e) {
                    Log::warning('Не удалось создать временный URL для файла акта', [
                        'file_id' => $file->id,
                        'error' => $e->getMessage()
                    ]);
                }

                return [
                    'id' => $file->id,
                    'filename' => $file->filename,
                    'size' => $file->size,
                    'created_at' => $file->created_at?->toIso8601String(),
                    'download_url' => $downloadUrl,
                ];
            });

            return response()->json($paginator);

        } catch (Exception $e) {
            Log::error('Ошибка получения файлов актов', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'error' => 'Ошибка при получении файлов актов',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function download(Request $request, string $id)
    {
        try {
            $user = $request->user();

            $file = PersonalFile::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$file) {
                return response()->json(['error' => 'Файл не найден'], 404);
            }

            $actFilesPath = $user->id . '/' . self::ACT_FILES_FOLDER . '/';
            if (!str_starts_with($file->path, $actFilesPath)) {
                return response()->json(['error' => 'Файл не является файлом акта'], 403);
            }

            $fs = app(FileService::class);
            $org = OrganizationContext::getOrganization() ?? Auth::user()?->currentOrganization;
            $storage = $fs->disk($org);

            if (!$storage->exists($file->path)) {
                return response()->json(['error' => 'Файл не найден в хранилище'], 404);
            }

            $contents = $storage->get($file->path);
            
            $mimeType = $storage->mimeType($file->path) ?? 'application/octet-stream';
            
            return response($contents, 200, [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'attachment; filename="' . $file->filename . '"',
                'Content-Length' => strlen($contents)
            ]);

        } catch (Exception $e) {
            Log::error('Ошибка скачивания файла акта из личного хранилища', [
                'file_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'error' => 'Ошибка при скачивании файла',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();

            $file = PersonalFile::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$file) {
                return response()->json(['error' => 'Файл не найден'], 404);
            }

            $actFilesPath = $user->id . '/' . self::ACT_FILES_FOLDER . '/';
            if (!str_starts_with($file->path, $actFilesPath)) {
                return response()->json(['error' => 'Файл не является файлом акта'], 403);
            }

            $fs = app(FileService::class);
            $org = OrganizationContext::getOrganization() ?? Auth::user()?->currentOrganization;
            $storage = $fs->disk($org);

            try {
                if ($storage->exists($file->path)) {
                    $storage->delete($file->path);
                }
            } catch (Exception $e) {
                Log::warning('Не удалось удалить файл из хранилища', [
                    'file_id' => $file->id,
                    'path' => $file->path,
                    'error' => $e->getMessage()
                ]);
            }

            $file->delete();

            return response()->json(['message' => 'Файл успешно удален']);

        } catch (Exception $e) {
            Log::error('Ошибка удаления файла акта из личного хранилища', [
                'file_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'error' => 'Ошибка при удалении файла',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}

