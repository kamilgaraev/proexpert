<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

use App\BusinessModules\Core\Mdm\Models\MdmDuplicateGroup;
use App\BusinessModules\Core\Mdm\Models\MdmMergeRun;
use App\BusinessModules\Core\Mdm\Models\MdmRecord;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MdmMergeService
{
    public function __construct(
        private readonly MdmMergePlanner $planner,
        private readonly MdmEntityRegistry $registry,
        private readonly MdmChangeLogService $changeLogService
    ) {}

    public function plan(MdmDuplicateGroup $group, int $masterEntityId): MdmMergeRun
    {
        return DB::transaction(function () use ($group, $masterEntityId): MdmMergeRun {
            $master = $this->registry->query((string) $group->entity_type, (int) $group->organization_id)
                ->whereKey($masterEntityId)->sharedLock()->first(['id']);
            $member = $group->members()->where('entity_type', $group->entity_type)
                ->where('entity_id', $masterEntityId)->sharedLock()->first(['id']);

            if ($master === null || $member === null) {
                throw ValidationException::withMessages([
                    'master_entity_id' => [trans_message('mdm.validation.merge_master_not_found')],
                ]);
            }

            $plan = $this->planner->plan($group, $masterEntityId);

            return MdmMergeRun::query()->create([
                'organization_id' => $group->organization_id,
                'duplicate_group_id' => $group->id,
                'entity_type' => $group->entity_type,
                'master_entity_id' => $masterEntityId,
                'duplicate_entity_ids' => $plan['duplicate_entity_ids'],
                'dry_run_plan' => $plan,
                'status' => 'planned',
            ]);
        });
    }

    public function apply(MdmDuplicateGroup $group, int $masterEntityId, ?int $userId): MdmMergeRun
    {
        return DB::transaction(function () use ($group, $masterEntityId, $userId): MdmMergeRun {
            $run = $this->plan($group, $masterEntityId);
            $plan = $run->dry_run_plan;

            foreach ($plan['references'] as $reference) {
                DB::table($reference['table'])
                    ->whereIn('id', $reference['affected_ids'])
                    ->update([$reference['column'] => $masterEntityId]);
            }

            foreach ($plan['duplicate_entity_ids'] as $duplicateId) {
                $model = $this->registry->query($group->entity_type, (int) $group->organization_id)->find($duplicateId);
                if ($model !== null && in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
                    $model->delete();
                }
            }

            MdmRecord::query()
                ->where('organization_id', $group->organization_id)
                ->where('entity_type', $group->entity_type)
                ->whereIn('entity_id', $plan['duplicate_entity_ids'])
                ->update(['status' => 'merged', 'archived_at' => now()]);

            $group->update([
                'status' => 'merged',
                'suggested_master_entity_id' => $masterEntityId,
                'resolved_by_user_id' => $userId,
                'resolved_at' => now(),
            ]);

            $run->update([
                'status' => 'applied',
                'applied_by_user_id' => $userId,
                'applied_at' => now(),
            ]);

            $this->changeLogService->log(
                (int) $group->organization_id,
                $group->entity_type,
                $masterEntityId,
                'duplicate_merged',
                null,
                $plan,
                $userId,
                ['duplicate_group_id' => $group->id, 'merge_run_id' => $run->id]
            );

            return $run->refresh();
        });
    }
}
