<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentApprovedList;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Support\ExecutiveDocumentProfileRegistry;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use App\Services\Project\UserProjectAccessService;
use App\Services\Storage\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ExecutiveDocumentApprovedListService
{
    public function __construct(
        private readonly FileService $files,
        private readonly AuthorizationService $authorization,
        private readonly UserProjectAccessService $projectAccess,
        private readonly ExecutiveDocumentProfileRegistry $profiles,
        private readonly ExecutiveDocumentRequirementsService $requirements,
    ) {}

    public function forProject(int $projectId, User $actor): array
    {
        $project = $this->project($projectId, $actor, 'executive-documentation.view');

        return ExecutiveDocumentApprovedList::query()->where('project_id', $project->id)
            ->where('organization_id', $project->organization_id)->orderByDesc('revision')->get()->all();
    }

    public function find(int $projectId, int $listId, User $actor): ExecutiveDocumentApprovedList
    {
        $project = $this->project($projectId, $actor, 'executive-documentation.view');

        return ExecutiveDocumentApprovedList::query()->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)->findOrFail($listId);
    }

    public function create(int $projectId, User $actor, array $data, UploadedFile $file): ExecutiveDocumentApprovedList
    {
        $project = $this->project($projectId, $actor, 'executive-documentation.approve');
        $items = $this->normalizeItems((array) $data['items'], $project);
        $organization = Organization::query()->findOrFail($project->organization_id);
        $path = $this->files->upload($file, "executive-documentation/project-{$project->id}/approved-lists", null, 'private', $organization);
        if (! is_string($path)) {
            throw ValidationException::withMessages(['file' => 'Не удалось сохранить утверждённый перечень.']);
        }

        try {
            return DB::transaction(function () use ($project, $actor, $data, $file, $items, $path): ExecutiveDocumentApprovedList {
                Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
                $revision = (int) ExecutiveDocumentApprovedList::query()->where('project_id', $project->id)->max('revision') + 1;

                return ExecutiveDocumentApprovedList::query()->create([
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'revision' => $revision,
                    'approved_by_party' => trim((string) $data['approved_by_party']),
                    'approved_at' => $data['approved_at'],
                    'file_url' => $path,
                    'file_hash' => hash_file('sha256', $file->getRealPath()),
                    'original_name' => $file->getClientOriginalName(),
                    'items' => $items,
                    'uploaded_by' => $actor->id,
                ]);
            });
        } catch (\Throwable $exception) {
            $this->files->delete($path, $organization);
            throw $exception;
        }
    }

    public function applyToSet(ExecutiveDocumentSet $set, ExecutiveDocumentApprovedList $list, array $itemKeys, User $actor): void
    {
        $project = $this->project((int) $set->project_id, $actor, 'executive-documentation.edit');
        if ((int) $list->project_id !== (int) $project->id || (int) $list->organization_id !== (int) $set->organization_id) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.not_found'), 404);
        }
        if ($set->status->value !== 'draft') {
            throw ValidationException::withMessages(['approved_list_id' => trans_message('executive_documentation.requirements.frozen')]);
        }
        $selected = collect($list->items)->keyBy('key')->only($itemKeys)->values();
        if ($selected->count() !== count(array_unique($itemKeys)) || $selected->isEmpty()) {
            throw ValidationException::withMessages(['item_keys' => 'Выберите пункты утверждённого перечня этого объекта.']);
        }
        $requirements = $selected->map(static fn (array $item): array => [
            'profile_type' => $item['profile_type'],
            'requirement_key' => $item['key'],
            'title' => $item['title'],
            'stage' => $item['stage'],
            'source' => 'Утверждённый перечень ИД объекта',
            'source_revision' => 'approved-list-'.$list->id,
            'work_type_id' => $item['work_type_id'] ?? null,
            'completed_work_id' => $item['completed_work_id'] ?? null,
            'project_location_id' => $item['project_location_id'] ?? null,
        ])->all();

        DB::transaction(function () use ($set, $list, $requirements, $actor): void {
            $this->requirements->replaceForSet($set, $requirements, $actor, $this->authorization);
            $set->forceFill(['approved_list_id' => $list->id])->save();
        });
    }

    private function normalizeItems(array $items, Project $project): array
    {
        $keys = [];
        foreach ($items as $index => &$item) {
            $key = trim((string) ($item['key'] ?? ''));
            $profile = (string) ($item['profile_type'] ?? '');
            if ($key === '' || isset($keys[$key]) || $this->profiles->find($profile) === null) {
                throw ValidationException::withMessages(["items.{$index}" => 'Проверьте ключ и вид документа.']);
            }
            $keys[$key] = true;
            foreach (['work_type_id' => WorkType::class, 'completed_work_id' => CompletedWork::class] as $field => $model) {
                if (! empty($item[$field]) && ! $model::query()->where('organization_id', $project->organization_id)
                    ->when($field === 'completed_work_id', static fn ($query) => $query->where('project_id', $project->id))
                    ->whereKey((int) $item[$field])->exists()) {
                    throw ValidationException::withMessages(["items.{$index}.{$field}" => 'Запись не принадлежит объекту.']);
                }
            }
            $item = array_filter([
                'key' => $key,
                'profile_type' => $profile,
                'title' => trim((string) ($item['title'] ?? '')),
                'stage' => (string) ($item['stage'] ?? 'document_review'),
                'work_type_id' => isset($item['work_type_id']) ? (int) $item['work_type_id'] : null,
                'completed_work_id' => isset($item['completed_work_id']) ? (int) $item['completed_work_id'] : null,
                'project_location_id' => isset($item['project_location_id']) ? (int) $item['project_location_id'] : null,
            ], static fn ($value) => $value !== null);
        }
        unset($item);

        return array_values($items);
    }

    private function project(int $projectId, User $actor, string $permission): Project
    {
        $project = Project::query()->findOrFail($projectId);
        $organizationId = (int) $project->organization_id;
        if ((int) $actor->current_organization_id !== $organizationId
            || ! $actor->belongsToOrganization($organizationId)
            || ! $this->projectAccess->canAccessProject($actor, $project, $organizationId)) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.not_found'), 404);
        }
        if (! $this->authorization->can($actor, $permission, [
            'organization_id' => $organizationId, 'project_id' => $projectId, 'strict_project_scope' => true,
        ])) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.forbidden'), 403);
        }

        return $project;
    }
}
