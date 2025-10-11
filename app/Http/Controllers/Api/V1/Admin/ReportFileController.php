<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use App\Models\ReportFile;
use Illuminate\Support\Facades\Auth;
use App\Services\Storage\FileService;
use App\Services\Organization\OrganizationContext;

class ReportFileController extends Controller
{
    /**
     * Список файлов отчётов с поддержкой фильтров и пагинации.
     */
    public function index(Request $request): JsonResponse
    {
        // Валидация входных параметров
        $validator = Validator::make($request->all(), [
            'type'      => ['sometimes', 'string'],
            'date_from' => ['sometimes', 'date'],
            'date_to'   => ['sometimes', 'date'],
            'filename'  => ['sometimes', 'string'],
            'sort_by'   => ['sometimes', 'in:created_at,size,filename'],
            'sort_dir'  => ['sometimes', 'in:asc,desc'],
            'per_page'  => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $params    = $validator->validated();
        $sortBy    = $params['sort_by'] ?? 'created_at';
        $sortDir   = $params['sort_dir'] ?? 'desc';
        $perPage   = (int)($params['per_page'] ?? 15);
        $typeFilter= $params['type'] ?? null;
        $filenameFilter = isset($params['filename']) ? Str::lower($params['filename']) : null;
        $dateFrom  = isset($params['date_from']) ? Carbon::parse($params['date_from'])->startOfDay() : null;
        $dateTo    = isset($params['date_to']) ? Carbon::parse($params['date_to'])->endOfDay() : null;

        // Получаем текущую организацию
        $org = OrganizationContext::getOrganization() ?? Auth::user()?->currentOrganization;

        // Формируем запрос к БД
        $query = ReportFile::query()->where('organization_id', $org->id);
        if ($typeFilter) {
            $query->where('type', $typeFilter);
        }
        if ($filenameFilter) {
            $query->whereRaw('LOWER(filename) LIKE ?', ['%' . $filenameFilter . '%']);
        }
        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $query->orderBy($sortBy, $sortDir);

        /** @var \Illuminate\Pagination\LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage);

        // Дополняем download_url для каждого элемента
        /** @var FileService $fs */
        $fs = app(FileService::class);
        $org = OrganizationContext::getOrganization() ?? Auth::user()?->currentOrganization;
        $storage = $fs->disk($org);
        $paginator->getCollection()->transform(function (ReportFile $file) use ($storage) {
            return [
                'id'          => $this->encodeKey($file->path),
                'path'        => $file->path,
                'filename'    => $file->filename,
                'name'        => $file->name ?? $file->filename,
                'type'        => $file->type,
                'size'        => $file->size,
                'created_at'  => $file->created_at?->toIso8601String(),
                'expires_at'  => $file->expires_at?->toIso8601String(),
                'download_url'=> $storage->temporaryUrl($file->path, now()->addMinutes(5)),
            ];
        });

        return response()->json($paginator);
    }

    /**
     * Удаление файла отчёта.
     */
    public function destroy(string $key): JsonResponse
    {
        $path = $this->decodeKey($key);
        /** @var FileService $fs */
        $fs = app(FileService::class);
        $org = OrganizationContext::getOrganization() ?? Auth::user()?->currentOrganization;

        // Проверяем, что файл принадлежит текущей организации
        $file = ReportFile::where('path', $path)->where('organization_id', $org->id)->first();
        if (!$file) {
            return response()->json(['message' => 'File not found or access denied.'], 404);
        }

        $storage = $fs->disk($org);

        if (!$storage->exists($path)) {
            // всё равно пытаемся удалить запись из БД
            $file->delete();
            return response()->json(['message' => 'File not found.'], 404);
        }

        $storage->delete($path);
        $file->delete();

        return response()->json(['message' => 'File deleted.']);
    }

    /**
     * Обновление читаемого названия файла.
     */
    public function update(Request $request, string $key): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $path = $this->decodeKey($key);
        $org = OrganizationContext::getOrganization() ?? Auth::user()?->currentOrganization;

        $file = ReportFile::where('path', $path)->where('organization_id', $org->id)->first();
        if (!$file) {
            return response()->json(['message' => 'File not found or access denied.'], 404);
        }

        $file->name = $validator->validated()['name'];
        $file->save();

        return response()->json(['message' => 'Name updated.']);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function encodeKey(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decodeKey(string $encoded): string
    {
        $encoded = strtr($encoded, '-_', '+/');
        $padding = 4 - (strlen($encoded) % 4);
        if ($padding < 4) {
            $encoded .= str_repeat('=', $padding);
        }
        return base64_decode($encoded);
    }
} 