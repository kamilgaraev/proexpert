<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Services\Customer\Reporting\Sla\DTO\CustomerSlaPolicy;
use App\Services\Customer\Reporting\Sla\Models\CustomerSlaPolicyVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class CustomerSlaPolicyWriter
{
    public function append(
        int $organizationId,
        ?int $projectId,
        ?int $customerOrganizationId,
        ?string $workflowType,
        ?string $priority,
        string $timezone,
        array $weekdayIntervals,
        array $holidays,
        array $pauseStatuses,
        int $firstResponseTargetSeconds,
        int $resolutionTargetSeconds,
        CarbonImmutable $effectiveFrom,
        ?CarbonImmutable $effectiveTo = null,
    ): CustomerSlaPolicyVersion {
        if (
            $organizationId < 1
            || ($workflowType !== null && ! in_array($workflowType, ['issue', 'request'], true))
            || ($priority !== null && preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $priority) !== 1)
            || ($effectiveTo !== null && $effectiveTo <= $effectiveFrom)
        ) {
            throw new InvalidArgumentException('customer_sla_policy_invalid');
        }

        return DB::transaction(function () use (
            $organizationId,
            $projectId,
            $customerOrganizationId,
            $workflowType,
            $priority,
            $timezone,
            $weekdayIntervals,
            $holidays,
            $pauseStatuses,
            $firstResponseTargetSeconds,
            $resolutionTargetSeconds,
            $effectiveFrom,
            $effectiveTo,
        ): CustomerSlaPolicyVersion {
            if (! DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->exists()) {
                throw new InvalidArgumentException('customer_sla_policy_scope_invalid');
            }
            $this->assertScope($organizationId, $projectId, $customerOrganizationId);
            $last = CustomerSlaPolicyVersion::query()
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();
            $sequence = $last === null
                ? 1
                : ((int) preg_replace('/\D+/', '', (string) $last->version)) + 1;
            $version = 'customer-sla.v'.$sequence;
            new CustomerSlaPolicy(
                $timezone,
                $weekdayIntervals,
                $holidays,
                $pauseStatuses,
                $firstResponseTargetSeconds,
                $resolutionTargetSeconds,
                $version,
            );
            $payload = [
                'customer_organization_id' => $customerOrganizationId,
                'effective_from' => $effectiveFrom->toISOString(),
                'effective_to' => $effectiveTo?->toISOString(),
                'first_response_target_seconds' => $firstResponseTargetSeconds,
                'holidays' => $holidays,
                'organization_id' => $organizationId,
                'pause_statuses' => $pauseStatuses,
                'priority' => $priority,
                'project_id' => $projectId,
                'resolution_target_seconds' => $resolutionTargetSeconds,
                'timezone' => $timezone,
                'version' => $version,
                'weekday_intervals' => $weekdayIntervals,
                'workflow_type' => $workflowType,
            ];

            return CustomerSlaPolicyVersion::query()->create([
                ...$payload,
                'source_hash' => hash('sha256', CanonicalJson::encode($payload)),
            ]);
        }, 3);
    }

    private function assertScope(
        int $organizationId,
        ?int $projectId,
        ?int $customerOrganizationId,
    ): void {
        if ($projectId === null) {
            if ($customerOrganizationId !== null) {
                throw new InvalidArgumentException('customer_sla_policy_scope_invalid');
            }

            return;
        }

        $project = DB::table('projects')
            ->where('id', $projectId)
            ->where(static function ($builder) use ($organizationId): void {
                $builder
                    ->where('organization_id', $organizationId)
                    ->orWhereExists(static function ($participant) use ($organizationId): void {
                        $participant
                            ->selectRaw('1')
                            ->from('project_organization')
                            ->whereColumn('project_organization.project_id', 'projects.id')
                            ->where('project_organization.organization_id', $organizationId);
                    });
            })
            ->exists();
        $customer = $customerOrganizationId === null || DB::table('project_organization')
            ->where('project_id', $projectId)
            ->where('organization_id', $customerOrganizationId)
            ->where('is_active', true)
            ->where(static function ($builder): void {
                $builder
                    ->where('role_new', 'customer')
                    ->orWhere(static function ($fallback): void {
                        $fallback->whereNull('role_new')->where('role', 'customer');
                    });
            })
            ->exists();
        if (! $project || ! $customer) {
            throw new InvalidArgumentException('customer_sla_policy_scope_invalid');
        }
    }
}
