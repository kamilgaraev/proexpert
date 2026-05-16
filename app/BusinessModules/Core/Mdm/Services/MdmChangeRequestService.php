<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

use App\BusinessModules\Core\Mdm\Models\MdmChangeRequest;
use App\BusinessModules\Core\Mdm\Models\MdmRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class MdmChangeRequestService
{
    public function __construct(
        private readonly MdmEntityRegistry $registry,
        private readonly MdmRecordService $recordService,
        private readonly MdmChangeLogService $changeLogService
    ) {
    }

    public function submit(
        int $organizationId,
        string $entityType,
        string $action,
        array $proposedValues,
        ?int $entityId,
        ?int $userId
    ): MdmChangeRequest {
        $currentValues = null;

        if ($entityId !== null) {
            $currentValues = $this->registry->query($entityType, $organizationId)->findOrFail($entityId)->getAttributes();
        }

        return MdmChangeRequest::create([
            'organization_id' => $organizationId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'status' => 'pending',
            'current_values' => $currentValues,
            'proposed_values' => $proposedValues,
            'requested_by_user_id' => $userId,
        ]);
    }

    public function approve(MdmChangeRequest $request, ?int $userId, ?string $note): MdmChangeRequest
    {
        return DB::transaction(function () use ($request, $userId, $note): MdmChangeRequest {
            $model = $this->apply($request);
            $request->update([
                'status' => 'approved',
                'reviewed_by_user_id' => $userId,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            if ($model !== null) {
                $record = $this->recordService->syncModel($model, $request->entity_type, $userId);
                $this->changeLogService->log(
                    (int) $request->organization_id,
                    $request->entity_type,
                    (int) $model->getKey(),
                    'change_request_approved',
                    $request->current_values,
                    $request->proposed_values,
                    $userId,
                    ['change_request_id' => $request->id, 'note' => $note],
                    $record
                );
            }

            return $request->refresh();
        });
    }

    public function reject(MdmChangeRequest $request, ?int $userId, ?string $note): MdmChangeRequest
    {
        $request->update([
            'status' => 'rejected',
            'reviewed_by_user_id' => $userId,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        $this->changeLogService->log(
            (int) $request->organization_id,
            $request->entity_type,
            (int) ($request->entity_id ?? 0),
            'change_request_rejected',
            $request->current_values,
            $request->proposed_values,
            $userId,
            ['change_request_id' => $request->id, 'note' => $note]
        );

        return $request->refresh();
    }

    private function apply(MdmChangeRequest $request): ?Model
    {
        $modelClass = $this->registry->get($request->entity_type)['model'];
        $values = $request->proposed_values ?? [];
        $values['organization_id'] = $request->organization_id;

        if ($request->action === 'create') {
            return $modelClass::query()->create($values);
        }

        if ($request->entity_id === null) {
            return null;
        }

        $model = $this->registry->query($request->entity_type, (int) $request->organization_id)->findOrFail($request->entity_id);

        if ($request->action === 'update') {
            $model->fill($values);
            $model->save();

            return $model;
        }

        return $model;
    }

    public function assignOwner(MdmRecord $record, ?int $ownerUserId, ?int $changedByUserId): MdmRecord
    {
        $before = $record->getAttributes();
        $record->update([
            'owner_user_id' => $ownerUserId,
            'version' => ((int) $record->version) + 1,
        ]);

        $this->changeLogService->log(
            (int) $record->organization_id,
            $record->entity_type,
            (int) $record->entity_id,
            'owner_assigned',
            $before,
            $record->getAttributes(),
            $changedByUserId,
            ['owner_user_id' => $ownerUserId],
            $record
        );

        return $record->refresh();
    }
}
