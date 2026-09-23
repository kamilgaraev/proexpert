<?php

declare(strict_types=1);

namespace Tests\Support;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentApprovedList;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentRequirementsService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

final class ExecutiveDocumentRequirementFixture
{
    public static function cover(ExecutiveDocumentSet $set, ExecutiveDocumentVersion $version, User $actor): void
    {
        $service = app(ExecutiveDocumentRequirementsService::class);
        $authorization = app(AuthorizationService::class);
        $type = $version->document->document_type->value;
        $list = $set->approvedList()->first();
        if ($list === null || ! collect($list->items)->contains('profile_type', $type)) {
            $items = $list?->items ?? $set->requirements()->whereNull('superseded_at')->get()
                ->map(static fn ($requirement): array => [
                    'key' => $requirement->requirement_key, 'profile_type' => $requirement->profile_type,
                    'title' => $requirement->title, 'stage' => $requirement->stage,
                ])->all();
            if (! collect($items)->contains('profile_type', $type)) {
                $items[] = ['key' => $type, 'profile_type' => $type, 'title' => $type, 'stage' => 'document_review'];
            }
            $revision = ($list?->revision ?? 0) + 1;
            $contents = 'Test approved list '.$set->id.' revision '.$revision;
            $path = 'org-'.$set->organization_id.'/executive-documentation/test-approved-list-'.$set->id.'-'.$revision.'.pdf';
            Storage::disk('s3')->put($path, $contents);
            $list = ExecutiveDocumentApprovedList::query()->create([
                'organization_id' => $set->organization_id, 'project_id' => $set->project_id,
                'revision' => $revision, 'approved_by_party' => 'Технический заказчик', 'approved_at' => now()->toDateString(),
                'file_url' => $path, 'file_hash' => hash('sha256', $contents), 'original_name' => 'approved-list.pdf',
                'items' => $items, 'uploaded_by' => $actor->id,
            ]);
            $set->forceFill(['approved_list_id' => $list->id])->save();
            $set->requirements()->whereNull('superseded_at')->update(['source_revision' => 'approved-list-'.$list->id]);
        }
        $requirement = $set->requirements()->whereNull('superseded_at')->where('profile_type', $type)->first();
        if ($requirement === null) {
            $payload = [
                'requirement_key' => $type, 'profile_type' => $type, 'source' => 'Утверждённый перечень ИД тестового проекта',
                'source_revision' => 'approved-list-'.$list->id, 'coverage_scope' => ['project_id' => (int) $set->project_id],
            ];
            if (in_array($type, ['hidden_work_act', 'axis_layout_act', 'geodetic_base_acceptance_act', 'responsible_structure_act', 'engineering_network_section_act'], true)) {
                $payload['conditions'] = ['designer_supervision' => false, 'separate_executor' => false];
            }
            $requirement = $service->create($set, $payload, $actor, $authorization);
        }
        $service->attachEvidence($requirement, $version->id, ['project_id' => (int) $set->project_id], $actor, $authorization);
    }
}
