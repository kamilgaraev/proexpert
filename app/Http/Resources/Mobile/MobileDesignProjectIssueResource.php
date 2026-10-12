<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile;

use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignProjectIssueResource;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Services\Mobile\MobileDesignManagementAccess;
use App\Services\Storage\FileService;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MobileDesignProjectIssueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $issue = $this->resource;
        assert($issue instanceof QualityDefect);
        $data = (new DesignProjectIssueResource($issue))->resolve($request);
        $data['available_actions'] = array_map(static function (array $action): array {
            if ($action['key'] === 'blocking_flag') {
                $action['key'] = 'blocking';
            }

            return $action;
        }, $data['available_actions']);
        $organization = $issue->relationLoaded('organization') ? $issue->getRelation('organization') : null;
        $photos = $issue->relationLoaded('photos') ? $issue->getRelation('photos') : collect();
        $history = $issue->relationLoaded('statusHistory') ? $issue->getRelation('statusHistory') : collect();
        $data['photos'] = $photos->filter(fn ($photo): bool => (int) $photo->organization_id === (int) $issue->organization_id
            && str_starts_with((string) $photo->url, 'org-'.$issue->organization_id.'/'))
            ->map(fn ($photo): array => [
                'id' => (int) $photo->id, 'type' => $photo->type, 'caption' => $photo->caption,
                'url' => app(FileService::class)->temporaryUrl($photo->url, 60, $organization),
                'mime_type' => $photo->mime_type, 'size_bytes' => $photo->size_bytes,
                'created_at' => $photo->created_at?->toIso8601String(),
            ])->values()->all();
        $data['history'] = $history->filter(static fn ($event): bool => (int) $event->organization_id === (int) $issue->organization_id)->map(fn ($event): array => [
            'id' => (int) $event->id, 'from_status' => $this->value($event->from_status),
            'to_status' => $this->value($event->to_status), 'comment' => $event->comment,
            'changed_by' => $event->changed_by, 'author' => $event->relationLoaded('changedBy') ? $event->changedBy?->name : null,
            'changed_at' => $event->changed_at?->toIso8601String(), 'evidence_refs' => $event->reporting_evidence_refs ?? [],
        ])->values()->all();
        $actor = $request->user();
        $canReview = $actor !== null && app(MobileDesignManagementAccess::class)->can($actor, (int) $issue->organization_id, (int) $issue->project_id, 'design-management.review');
        $data['available_actions'][] = ['key' => 'snapshot', 'label' => trans_message('mobile_design.actions.snapshot'), 'enabled' => $canReview];
        $data['available_actions'][] = ['key' => 'photos', 'label' => trans_message('mobile_design.actions.photos'), 'enabled' => $canReview];

        return $data;
    }

    private function value(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
