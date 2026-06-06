<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Enums\DesignCompletenessStatusEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignPackageStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignWorkflowEvent;
use App\BusinessModules\Features\DesignManagement\Support\DesignPackageWorkflow;
use BackedEnum;
use DomainException;
use Illuminate\Support\Facades\DB;

final class DesignWorkflowService
{
    public function __construct(
        private readonly DesignCompletenessService $completenessService,
    ) {
    }

    public function transition(DesignPackage $package, int $userId, string $action, ?string $comment = null): DesignPackage
    {
        return DB::transaction(function () use ($package, $userId, $action, $comment): DesignPackage {
            $lockedPackage = DesignPackage::forOrganization((int) $package->organization_id)
                ->whereKey($package->id)
                ->with($this->relations())
                ->lockForUpdate()
                ->first();

            if (!$lockedPackage instanceof DesignPackage) {
                throw new DomainException(trans_message('design_management.errors.package_not_found'));
            }

            if (DesignPackageWorkflow::isCompletedAction($lockedPackage, $action)) {
                return $lockedPackage->fresh($this->relations());
            }

            $this->assertWorkflowGuards($lockedPackage, $action, $userId);
            $nextStatus = DesignPackageWorkflow::nextStatus($lockedPackage, $action);

            if (!$nextStatus instanceof DesignPackageStatusEnum) {
                throw new DomainException(trans_message('design_management.errors.workflow_action_not_available'));
            }

            $fromStatus = $this->value($lockedPackage->status);
            $metadata = $lockedPackage->metadata ?? [];
            $history = is_array($metadata['workflow_history'] ?? null)
                ? array_values($metadata['workflow_history'])
                : [];
            $history[] = [
                'action' => $action,
                'from_status' => $fromStatus,
                'to_status' => $nextStatus->value,
                'user_id' => $userId,
                'comment' => $comment,
                'at' => now()->toISOString(),
            ];
            $metadata['workflow_history'] = array_slice($history, -100);

            $update = [
                'status' => $nextStatus,
                'updated_by' => $userId,
                'metadata' => $metadata,
            ];

            if ($nextStatus === DesignPackageStatusEnum::ISSUED) {
                $update['issued_at'] = now();
                $update['issued_by'] = $userId;
            }

            $lockedPackage->update($update);

            DesignWorkflowEvent::query()->create([
                'organization_id' => $lockedPackage->organization_id,
                'project_id' => $lockedPackage->project_id,
                'package_id' => $lockedPackage->id,
                'actor_id' => $userId,
                'action' => $action,
                'from_status' => $fromStatus,
                'to_status' => $nextStatus->value,
                'comment' => $comment,
                'metadata' => [
                    'latest_completeness_check_id' => $lockedPackage->latestCompletenessCheck?->id,
                ],
            ]);

            return $lockedPackage->fresh($this->relations());
        });
    }

    private function assertWorkflowGuards(DesignPackage $package, string $action, int $userId): void
    {
        if (in_array($action, [
            DesignPackageWorkflow::SUBMIT_NORM_CONTROL,
            DesignPackageWorkflow::SUBMIT_CUSTOMER_REVIEW,
            DesignPackageWorkflow::APPROVE,
            DesignPackageWorkflow::ISSUE,
        ], true)) {
            $check = $package->latestCompletenessCheck;

            if ($check === null) {
                $check = $this->completenessService->run($package, $userId);
                $package->setRelation('latestCompletenessCheck', $check);
            }

            $status = $this->value($check->status);

            if ($status === DesignCompletenessStatusEnum::BLOCKED->value) {
                throw new DomainException(trans_message('design_management.errors.completeness_blocked'));
            }
        }
    }

    private function relations(): array
    {
        return [
            'project:id,name,organization_id',
            'artifacts.currentVersion.readyDerivative',
            'artifacts.currentVersion.sheets',
            'artifacts.versions.derivatives',
            'sections.artifacts.currentVersion.sheets',
            'sections.artifacts.versions.sheets',
            'reviewComments',
            'workflowEvents',
            'latestCompletenessCheck',
        ];
    }

    private function value(mixed $value): string
    {
        return $value instanceof BackedEnum ? $value->value : (string) $value;
    }
}
