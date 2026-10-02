<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Jobs\RegisterAssistantEntityFile;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class AssistantNativeDocumentDiscovery
{
    public function __construct(private readonly AssistantDataAccessPolicy $policy, private readonly AssistantDesignFileAdapter $design) {}

    public function discover(int $organizationId, ?int $approvedBy): int
    {
        $actor = $approvedBy === null ? null : User::query()->find($approvedBy);
        $count = $actor === null ? 0 : $this->discoverForActor($actor, $organizationId, true);
        $key = 'assistant-native-discovery-actor:'.$organizationId;
        $organization = Organization::query()->find($organizationId);
        $creator = $organization?->users()->wherePivot('is_active', true)->where('users.is_active', true)
            ->where('users.id', '>', (int) Cache::get($key, 0))->orderBy('users.id')->first();
        Cache::forever($key, $creator?->id ?? 0);
        if ($creator !== null && $creator->id !== $actor?->id) { $count += $this->discoverForActor($creator, $organizationId, false); }

        return $count;
    }

    private function discoverForActor(User $actor, int $organizationId, bool $approvedActor): int
    {
        if (! $this->policy->canReadDomain($actor, $organizationId, 'assistant')) { return 0; }
        $count = 0;
        foreach (['design_artifact_version'] as $type) {
            $query = $this->policy->entityQuery($actor, $organizationId, $type);
            if ($query === null) { continue; }
            $table = $query->getModel()->getTable();
            $query->whereNotNull($table.'.source_file_path');
            if (! $approvedActor) { $query->where($table.'.uploaded_by', $actor->id); }
            $cursorKey = 'assistant-native-discovery:'.$organizationId.':'.$actor->id.':'.$type;
            $rows = $query->where($table.'.id', '>', (int) Cache::get($cursorKey, 0))->orderBy($table.'.id')->limit(5)->get();
            foreach ($rows as $row) {
                if (! $this->policy->canReadEntityContent($actor, $organizationId, $type, $row->getKey())) { continue; }
                try {
                    $file = $this->design->map($actor, $organizationId, $row->getKey(), (string) $row->getAttribute('source_file_path'));
                    RegisterAssistantEntityFile::dispatch((int) $file->id)->afterCommit();
                    $count++;
                } catch (RuntimeException) {
                }
            }
            Cache::forever($cursorKey, $rows->count() === 5 ? $rows->last()->getKey() : 0);
        }

        return $count;
    }
}
