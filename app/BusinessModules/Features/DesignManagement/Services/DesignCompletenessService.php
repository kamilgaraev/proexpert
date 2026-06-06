<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Enums\DesignCompletenessStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignCompletenessCheck;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessRule;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessRuleResult;
use App\BusinessModules\Features\DesignManagement\Support\Rules\AllowedFileFormatRule;
use App\BusinessModules\Features\DesignManagement\Support\Rules\OpenBlockingCommentsRule;
use App\BusinessModules\Features\DesignManagement\Support\Rules\RequiredCurrentArtifactRule;
use App\BusinessModules\Features\DesignManagement\Support\Rules\RequiredSectionRule;
use App\BusinessModules\Features\DesignManagement\Support\Rules\RevisionSequenceRule;
use App\BusinessModules\Features\DesignManagement\Support\Rules\SheetRegistryRule;
use BackedEnum;
use Illuminate\Support\Facades\DB;

final class DesignCompletenessService
{
    /**
     * @var DesignCompletenessRule[]
     */
    private array $rules;

    public function __construct()
    {
        $this->rules = [
            new RequiredSectionRule(),
            new RequiredCurrentArtifactRule(),
            new AllowedFileFormatRule(),
            new RevisionSequenceRule(),
            new SheetRegistryRule(),
            new OpenBlockingCommentsRule(),
        ];
    }

    public function run(DesignPackage $package, int $userId): DesignCompletenessCheck
    {
        return DB::transaction(function () use ($package, $userId): DesignCompletenessCheck {
            $package = DesignPackage::forOrganization((int) $package->organization_id)
                ->whereKey($package->id)
                ->with($this->relations())
                ->lockForUpdate()
                ->firstOrFail();

            $results = [];

            foreach ($this->rules as $rule) {
                foreach ($rule->check($package) as $result) {
                    if ($result instanceof DesignCompletenessRuleResult) {
                        $results[] = $result->toArray();
                    }
                }
            }

            $blockingCount = count(array_filter(
                $results,
                static fn (array $result): bool => ($result['status'] ?? null) === DesignCompletenessStatusEnum::BLOCKED->value
            ));
            $warningCount = count(array_filter(
                $results,
                static fn (array $result): bool => ($result['status'] ?? null) === DesignCompletenessStatusEnum::WARNING->value
            ));
            $status = $blockingCount > 0
                ? DesignCompletenessStatusEnum::BLOCKED
                : ($warningCount > 0 ? DesignCompletenessStatusEnum::WARNING : DesignCompletenessStatusEnum::READY);

            return DesignCompletenessCheck::query()->create([
                'organization_id' => $package->organization_id,
                'project_id' => $package->project_id,
                'package_id' => $package->id,
                'created_by' => $userId,
                'status' => $status,
                'profile_code' => $package->normative_profile_code,
                'project_stage' => $this->value($package->project_stage),
                'object_type' => $this->value($package->object_type),
                'checked_at' => now(),
                'blocking_count' => $blockingCount,
                'warning_count' => $warningCount,
                'summary' => [
                    'sections_count' => $package->sections->count(),
                    'artifacts_count' => $package->artifacts->count(),
                    'current_documents_count' => $package->artifacts->filter(static fn ($artifact): bool => $artifact->currentVersion !== null)->count(),
                ],
                'results' => $results,
                'metadata' => [
                    'rule_count' => count($this->rules),
                ],
            ]);
        });
    }

    public function latestForPackage(DesignPackage $package): ?DesignCompletenessCheck
    {
        return DesignCompletenessCheck::query()
            ->where('package_id', $package->id)
            ->latest('checked_at')
            ->first();
    }

    public function packageWithRelations(DesignPackage $package): DesignPackage
    {
        return $package->fresh($this->relations());
    }

    private function relations(): array
    {
        return [
            'project:id,name,organization_id',
            'sections.artifacts.currentVersion.sheets',
            'sections.artifacts.versions.sheets',
            'sections.reviewComments',
            'artifacts.currentVersion.sheets',
            'artifacts.versions.sheets',
            'reviewComments',
            'latestCompletenessCheck',
        ];
    }

    private function value(mixed $value): ?string
    {
        return $value instanceof BackedEnum ? $value->value : ($value !== null ? (string) $value : null);
    }
}
