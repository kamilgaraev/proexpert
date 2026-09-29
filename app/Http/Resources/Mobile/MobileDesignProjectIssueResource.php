<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile;

use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignProjectIssueResource;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Models\Organization;
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
        $issue->loadMissing(['photos', 'statusHistory.changedBy']);
        $data = (new DesignProjectIssueResource($issue))->resolve($request);
        $data['available_actions'] = array_map(static function (array $action): array {
            if ($action['key'] === 'blocking_flag') {
                $action['key'] = 'blocking';
            }

            return $action;
        }, $data['available_actions']);
        $organization = Organization::query()->find($issue->organization_id);
        $data['photos'] = $issue->photos->filter(fn ($photo): bool => (int) $photo->organization_id === (int) $issue->organization_id
            && str_starts_with((string) $photo->url, 'org-'.$issue->organization_id.'/'))
            ->map(fn ($photo): array => [
                'id' => (int) $photo->id, 'type' => $photo->type, 'caption' => $photo->caption,
                'url' => app(FileService::class)->temporaryUrl($photo->url, 60, $organization),
                'mime_type' => $photo->mime_type, 'size_bytes' => $photo->size_bytes,
                'created_at' => $photo->created_at?->toIso8601String(),
            ])->values()->all();
        $data['history'] = $issue->statusHistory->map(fn ($event): array => [
            'id' => (int) $event->id, 'from_status' => $this->value($event->from_status),
            'to_status' => $this->value($event->to_status), 'comment' => $event->comment,
            'changed_by' => $event->changed_by, 'author' => $event->changedBy?->name,
            'changed_at' => $event->changed_at?->toIso8601String(),
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
