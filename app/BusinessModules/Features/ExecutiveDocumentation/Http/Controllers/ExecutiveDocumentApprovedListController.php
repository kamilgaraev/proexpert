<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Controllers;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentApprovedListService;
use App\Http\Responses\AdminResponse;
use App\Models\Organization;
use App\Services\Storage\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;

final class ExecutiveDocumentApprovedListController extends \App\Http\Controllers\Controller
{
    public function __construct(private readonly ExecutiveDocumentApprovedListService $lists, private readonly FileService $files) {}

    public function index(Request $request, int $projectId): JsonResponse
    {
        $lists = $this->lists->forProject($projectId, $request->user());

        return AdminResponse::success(array_map(fn ($list): array => $this->payload($list), $lists));
    }

    public function store(Request $request, int $projectId): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', File::types(['pdf', 'doc', 'docx', 'xls', 'xlsx'])->min(1)->max(25 * 1024)],
            'approved_by_party' => ['required', 'string', 'max:255'],
            'approved_at' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.key' => ['required', 'string', 'max:128', 'distinct'],
            'items.*.profile_type' => ['required', 'string', 'max:100'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.stage' => ['sometimes', 'string', 'max:64'],
            'items.*.work_type_id' => ['sometimes', 'nullable', 'integer'],
            'items.*.completed_work_id' => ['sometimes', 'nullable', 'integer'],
            'items.*.project_location_id' => ['sometimes', 'nullable', 'integer'],
        ]);
        $list = $this->lists->create($projectId, $request->user(), $data, $request->file('file'));

        return AdminResponse::success($this->payload($list), null, 201);
    }

    public function download(Request $request, int $projectId, int $listId): JsonResponse
    {
        $list = $this->lists->find($projectId, $listId, $request->user());
        $organization = Organization::query()->findOrFail($list->organization_id);
        $url = $this->files->temporaryUrl($list->file_url, 5, $organization);
        if ($url === null) {
            throw ValidationException::withMessages(['file' => 'Файл перечня временно недоступен.']);
        }

        return AdminResponse::success(['url' => $url]);
    }

    public function apply(Request $request, ExecutiveDocumentSet $set): JsonResponse
    {
        $data = $request->validate([
            'approved_list_id' => ['required', 'integer', 'min:1'],
            'item_keys' => ['required', 'array', 'min:1', 'max:500'],
            'item_keys.*' => ['required', 'string', 'max:128', 'distinct'],
        ]);
        $list = $this->lists->find((int) $set->project_id, (int) $data['approved_list_id'], $request->user());
        $this->lists->applyToSet($set, $list, $data['item_keys'], $request->user());

        return AdminResponse::success(['approved_list_id' => $list->id]);
    }

    private function payload(\App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentApprovedList $list): array
    {
        return [
            'id' => $list->id,
            'project_id' => $list->project_id,
            'revision' => $list->revision,
            'approved_by_party' => $list->approved_by_party,
            'approved_at' => $list->approved_at?->format('Y-m-d'),
            'file_hash' => $list->file_hash,
            'original_name' => $list->original_name,
            'items' => $list->items,
            'created_at' => $list->created_at?->toIso8601String(),
        ];
    }
}
