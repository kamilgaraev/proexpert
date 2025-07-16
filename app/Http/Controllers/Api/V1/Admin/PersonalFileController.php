<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use App\Models\PersonalFile;
use App\Services\Storage\FileService;
use App\Services\Organization\OrganizationContext;
use Illuminate\Support\Facades\Auth;

class PersonalFileController extends Controller
{
    /**
     * Список файлов и папок пользователя (по префиксу path, по умолчанию корень).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $prefix = $request->get('path', ''); // '' значит корень
        $prefix = trim($prefix, '/');
        if ($prefix !== '') {
            $prefix .= '/';
        }

        $basePrefix = $user->id . '/';
        $fullPrefix = $basePrefix . $prefix; // если $prefix пустой – получится "<uid>/"

        $query = PersonalFile::where('user_id', $user->id)
            ->where('path', 'like', $fullPrefix . '%');

        /** @var FileService $fs */
        $fs = app(FileService::class);
        $org = OrganizationContext::getOrganization() ?? Auth::user()?->currentOrganization;
        $storage = $fs->disk($org);
        $items = $query->orderBy('is_folder', 'desc')->orderBy('filename')->get()->map(function (PersonalFile $file) use ($storage) {
            return [
                'id'       => $file->id,
                'path'     => $file->path,
                'filename' => $file->filename,
                'size'     => $file->size,
                'is_folder'=> (bool) $file->is_folder,
                'download_url' => $file->is_folder ? null : $storage->temporaryUrl($file->path, now()->addMinutes(30)),
            ];
        });

        return response()->json($items);
    }

    /**
     * Создание папки.
     */
    public function createFolder(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'parent_path' => ['sometimes', 'string'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $user = $request->user();
        $data = $validator->validated();
        $parent = isset($data['parent_path']) ? trim($data['parent_path'], '/') . '/' : '';
        $folderName = trim($data['name'], '/');
        $path = $user->id . '/' . $parent . $folderName . '/';

        if (PersonalFile::where('path', $path)->exists()) {
            return response()->json(['message' => 'Folder already exists.'], 409);
        }

        // Создаём zero-byte объект для папки (S3 не хранит папки реально)
        $fs = app(FileService::class);
        $storage = $fs->disk(OrganizationContext::getOrganization() ?? Auth::user()?->currentOrganization);
        $storage->put($path, '');

        PersonalFile::create([
            'user_id'  => $user->id,
            'path'     => $path,
            'filename' => $folderName,
            'size'     => 0,
            'is_folder'=> true,
        ]);

        return response()->json(['message' => 'Folder created.']);
    }

    /**
     * Загрузка файла (Multipart form-data: file, parent_path).
     */
    public function upload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'max:10240'], // 10 MB default limit
            'parent_path' => ['sometimes', 'string'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $user = $request->user();
        $data = $validator->validated();
        $parent = isset($data['parent_path']) ? trim($data['parent_path'], '/') . '/' : '';
        $uploaded = $request->file('file');
        $filename = $uploaded->getClientOriginalName();
        $path = $user->id . '/' . $parent . $filename;

        $fs = app(FileService::class);
        $storage = $fs->disk(OrganizationContext::getOrganization() ?? Auth::user()?->currentOrganization);
        if ($storage->exists($path)) {
            return response()->json(['message' => 'File already exists.'], 409);
        }

        $fs = app(FileService::class);
        $storage = $fs->disk(OrganizationContext::getOrganization() ?? Auth::user()?->currentOrganization);
        $storage->put($path, file_get_contents($uploaded->getRealPath()));

        PersonalFile::create([
            'user_id'  => $user->id,
            'path'     => $path,
            'filename' => $filename,
            'size'     => $uploaded->getSize(),
            'is_folder'=> false,
        ]);

        return response()->json(['message' => 'File uploaded.']);
    }

    /**
     * Удаление файла или папки.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $file = PersonalFile::where('id', $id)->where('user_id', $user->id)->first();
        if (!$file) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $fs = app(FileService::class);
        $storage = $fs->disk(OrganizationContext::getOrganization() ?? Auth::user()?->currentOrganization);

        if ($file->is_folder) {
            // удаляем все объекты с этим префиксом
            $objects = $storage->allFiles($file->path);
            foreach ($objects as $obj) {
                $storage->delete($obj);
                PersonalFile::where('path', $obj)->delete();
            }
            // удаляем саму папку-объект, если он есть
            $storage->delete($file->path);
        } else {
            $storage->delete($file->path);
        }

        $file->delete();

        return response()->json(['message' => 'Deleted.']);
    }
} 