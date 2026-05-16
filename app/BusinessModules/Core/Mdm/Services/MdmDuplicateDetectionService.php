<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

use App\BusinessModules\Core\Mdm\Models\MdmDuplicateGroup;
use App\BusinessModules\Core\Mdm\Models\MdmDuplicateMember;
use App\BusinessModules\Core\Mdm\Models\MdmRecord;

class MdmDuplicateDetectionService
{
    public function __construct(
        private readonly MdmRecordService $recordService,
        private readonly MdmEntityRegistry $registry,
        private readonly MdmChangeLogService $changeLogService
    ) {
    }

    public function scanOrganization(int $organizationId, ?string $entityType = null, ?int $userId = null): array
    {
        $this->recordService->syncOrganization($organizationId, $entityType, $userId);

        $types = $entityType === null ? array_keys($this->registry->all()) : [$entityType];
        $groupsCreated = 0;
        $membersLinked = 0;

        foreach ($types as $type) {
            $duplicates = MdmRecord::query()
                ->where('organization_id', $organizationId)
                ->where('entity_type', $type)
                ->where('status', 'active')
                ->whereNotNull('normalized_key')
                ->get()
                ->groupBy('normalized_key')
                ->filter(static fn ($records): bool => $records->count() > 1);

            foreach ($duplicates as $normalizedKey => $records) {
                $group = MdmDuplicateGroup::query()->firstOrCreate(
                    [
                        'organization_id' => $organizationId,
                        'entity_type' => $type,
                        'fingerprint' => hash('sha256', $type . '|' . $normalizedKey),
                    ],
                    [
                        'status' => 'open',
                        'confidence' => 100,
                        'suggested_master_entity_id' => $records->sortByDesc('quality_score')->first()->entity_id,
                    ]
                );

                if ($group->wasRecentlyCreated) {
                    $groupsCreated++;
                }

                foreach ($records as $record) {
                    $member = MdmDuplicateMember::query()->updateOrCreate(
                        [
                            'duplicate_group_id' => $group->id,
                            'entity_type' => $type,
                            'entity_id' => $record->entity_id,
                        ],
                        [
                            'role' => $record->entity_id === $group->suggested_master_entity_id ? 'master' : 'candidate',
                            'score' => $record->quality_score,
                            'evidence' => [
                                'normalized_key' => $normalizedKey,
                                'quality_score' => $record->quality_score,
                            ],
                        ]
                    );

                    if ($member->wasRecentlyCreated) {
                        $membersLinked++;
                    }
                }
            }
        }

        return [
            'groups_created' => $groupsCreated,
            'members_linked' => $membersLinked,
        ];
    }

    public function resolve(MdmDuplicateGroup $group, string $decision, ?int $masterEntityId, ?int $userId, ?string $note): MdmDuplicateGroup
    {
        $before = $group->getAttributes();
        $group->fill([
            'status' => $decision,
            'suggested_master_entity_id' => $masterEntityId ?? $group->suggested_master_entity_id,
            'resolved_by_user_id' => $userId,
            'resolved_at' => now(),
            'resolution_note' => $note,
        ]);
        $group->save();

        foreach ($group->members as $member) {
            $member->update([
                'role' => $member->entity_id === $group->suggested_master_entity_id ? 'master' : 'candidate',
            ]);
        }

        $this->changeLogService->log(
            (int) $group->organization_id,
            $group->entity_type,
            (int) ($group->suggested_master_entity_id ?? 0),
            'duplicate_' . $decision,
            $before,
            $group->getAttributes(),
            $userId,
            ['duplicate_group_id' => $group->id, 'note' => $note]
        );

        return $group->refresh();
    }
}
