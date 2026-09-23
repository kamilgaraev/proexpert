<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Controllers;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests\ApplyExecutiveApprovedListRequest;
use App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests\StoreExecutiveApprovedListRequest;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentApprovedListService;
use App\Http\Responses\AdminResponse;
use App\Models\Organization;
use App\Services\Storage\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ExecutiveDocumentApprovedListController extends \App\Http\Controllers\Controller
{
    public function __construct(private readonly ExecutiveDocumentApprovedListService $lists, private readonly FileService $files) {}

    public function index(Request $request, int $projectId): JsonResponse
    {
        $lists = $this->lists->forProject($projectId, $request->user());

        return AdminResponse::success(array_map(fn ($list): array => $this->payload($list), $lists));
    }

    public function store(StoreExecutiveApprovedListRequest $request, int $projectId): JsonResponse
    {
        $data = $request->validated();
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

    public function apply(ApplyExecutiveApprovedListRequest $request, ExecutiveDocumentSet $set): JsonResponse
    {
        $data = $request->validated();
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
