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

        /** @var \Illuminate\Filesystem\FilesystemAdapter|\Illuminate\Contracts\Filesystem\Cloud $storage */
        $storage = Storage::disk('reports');
        $allPaths = $storage->allFiles();

        $items = collect($allPaths)->map(function (string $path) use ($storage) {
            $lastModified = Carbon::createFromTimestamp($storage->lastModified($path));
            return [
                'id'          => $this->encodeKey($path),
                'path'        => $path,
                'filename'    => basename($path),
                'type'        => Str::before($path, '/'),
                'size'        => $storage->size($path),
                'created_at'  => $lastModified->toIso8601String(),
                'expires_at'  => $lastModified->copy()->addYear()->toIso8601String(),
                'download_url'=> $storage->temporaryUrl($path, now()->addMinutes(5)),
            ];
        });

        // Применяем фильтры
        $items = $items->filter(function (array $file) use ($typeFilter, $filenameFilter, $dateFrom, $dateTo) {
            if ($typeFilter && $file['type'] !== $typeFilter) {
                return false;
            }
            if ($filenameFilter && !Str::contains(Str::lower($file['filename']), $filenameFilter)) {
                return false;
            }
            $created = Carbon::parse($file['created_at']);
            if ($dateFrom && $created->lt($dateFrom)) {
                return false;
            }
            if ($dateTo && $created->gt($dateTo)) {
                return false;
            }
            return true;
        });

        // Сортировка
        $items = $items->sortBy($sortBy, SORT_REGULAR, $sortDir === 'desc')->values();

        // Пагинация вручную, т.к. элементы уже в коллекции
        $page    = LengthAwarePaginator::resolveCurrentPage();
        $total   = $items->count();
        $results = $items->forPage($page, $perPage)->values();
        $paginator = new LengthAwarePaginator($results, $total, $perPage, $page, [
            'path' => $request->url(),
            'query'=> $request->query(),
        ]);

        return response()->json($paginator);
    }

    /**
     * Удаление файла отчёта.
     */
    public function destroy(string $key): JsonResponse
    {
        $path = $this->decodeKey($key);
        /** @var \Illuminate\Filesystem\FilesystemAdapter|\Illuminate\Contracts\Filesystem\Cloud $storage */
        $storage = Storage::disk('reports');

        if (!$storage->exists($path)) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        $storage->delete($path);

        return response()->json(['message' => 'File deleted.']);
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