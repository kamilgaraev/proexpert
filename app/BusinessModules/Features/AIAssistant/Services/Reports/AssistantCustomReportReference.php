<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceIdentity;
use App\Models\User;
use Throwable;

final readonly class AssistantCustomReportReference
{
    public const TYPE = 'assistant_custom_report_snapshot';

    public function __construct(private AssistantCustomReportSource $sources, private AssistantDataAccessPolicy $policy) {}

    public function create(User $actor, int $organizationId, array $scope, array $fields, array $ids, string $dataHash, string $fetchedAt): array
    {
        $reference = ['entity_type' => self::TYPE, 'entity_id' => bin2hex(random_bytes(16)),
            'display_label' => $scope['plan']['title'] ?? trans_message('ai_custom_reports.snapshot_source'),
            'organization_id' => $organizationId, 'project_id' => $scope['context_project_id'] ?? $scope['project_id'] ?? null,
            'source_type' => self::TYPE, 'content_scope' => 'structured', 'fetched_at' => $fetchedAt,
            'source_version' => $dataHash, 'checked_fields' => $fields, '_report_actor' => (string) $actor->id,
            '_report_scope' => $scope, '_report_ids' => array_values(array_map('strval', $ids))];
        $reference['_report_proof'] = $this->sign($reference);

        return $reference;
    }

    public function canReadReference(User $actor, int $organizationId, array $reference): bool
    {
        if (($reference['entity_type'] ?? null) !== self::TYPE || ($reference['organization_id'] ?? null) !== $organizationId
            || ($reference['_report_actor'] ?? null) !== (string) $actor->id || ! is_string($reference['_report_proof'] ?? null)) {
            return false;
        }
        $proof = $reference['_report_proof'];
        unset($reference['_report_proof']);
        if (! hash_equals($this->sign($reference), $proof)) {
            return false;
        }
        try {
            return $this->policy->withCurrentChecks($actor, $organizationId, function () use ($actor, $organizationId, $reference): bool {
                $scope = $reference['_report_scope'];
                $source = $this->sources->resolve($actor, $organizationId, $scope['domain'], $scope['entity_type'], $scope['project_id']);
                $this->sources->assertFields($reference['checked_fields'], $source['fields']);
                if ($scope['entity_type'] === 'completed_work') {
                    if (! $this->canReadWorkDependencies($actor, $organizationId, $scope['work_projection_dependencies'] ?? null)) {
                        return false;
                    }
                } elseif (array_key_exists('work_projection_dependencies', $scope)) {
                    return false;
                }
                $ids = $reference['_report_ids'];
                if (! is_array($ids) || count($ids) > 1000 || count(array_unique($ids)) !== count($ids)) {
                    return false;
                }
                $paymentContracts = $scope['entity_type'] === 'payment_document'
                    && array_intersect(['contract_id', 'contract_number'], $reference['checked_fields']) !== [];
                if ($paymentContracts) {
                    if (! $this->canReadPaymentContracts($source['query'], $scope['contract_links'] ?? null, $ids)) {
                        return false;
                    }
                } elseif (array_key_exists('contract_links', $scope)) {
                    return false;
                }
                if (array_key_exists('customer_project_ids', $scope)) {
                    $projectIds = $scope['customer_project_ids'];
                    if ($scope['entity_type'] !== 'contract' || ! in_array('counterparty_name', $reference['checked_fields'], true)
                        || ! is_array($projectIds) || count($projectIds) > 1000 || count(array_unique($projectIds)) !== count($projectIds)
                        || array_filter($projectIds, static fn (mixed $id): bool => ! is_int($id) || $id < 1) !== []) {
                        return false;
                    }
                    $projects = $this->sources->resolve($actor, $organizationId, 'projects', 'project', null);
                    if ($projects['query']->whereKey($projectIds)->count() !== count($projectIds)) {
                        return false;
                    }
                }
                if (array_key_exists('assigned_user_ids', $scope)) {
                    $assigneeIds = $scope['assigned_user_ids'];
                    if ($scope['entity_type'] !== 'schedule_task' || ! in_array('assigned_user_name', $reference['checked_fields'], true)
                        || ! is_array($assigneeIds) || count($assigneeIds) > 1000 || count(array_unique($assigneeIds)) !== count($assigneeIds)
                        || array_filter($assigneeIds, static fn (mixed $id): bool => ! is_int($id) || $id < 1) !== []
                        || User::query()->whereKey($assigneeIds)->where('is_active', true)->whereHas('organizations',
                            static fn ($organizations) => $organizations->where('organizations.id', $organizationId)->where('organization_user.is_active', true))->count() !== count($assigneeIds)) {
                        return false;
                    }
                }

                return $ids === [] || $source['query']->whereKey($ids)->count() === count($ids);
            }, true);
        } catch (Throwable) {
            return false;
        }
    }

    private function canReadWorkDependencies(User $actor, int $organizationId, mixed $dependencies): bool
    {
        $types = array_keys(AssistantCompletedWorkReportProjection::DEPENDENCY_COLUMNS);
        if (! is_array($dependencies) || count($dependencies) !== count($types) || array_diff($types, array_keys($dependencies)) !== []) {
            return false;
        }
        foreach ($dependencies as $entityType => $ids) {
            if (! is_array($ids) || ! array_is_list($ids) || count($ids) > 4000 || count(array_unique($ids)) !== count($ids)
                || array_filter($ids, static fn (mixed $id): bool => ! is_int($id) || $id < 1) !== []) {
                return false;
            }
            if ($ids !== []) {
                $query = $this->policy->entityQuery($actor, $organizationId, $entityType);
                if ($query === null || $query->whereKey($ids)->count() !== count($ids)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function canReadPaymentContracts(\Illuminate\Database\Eloquent\Builder $query, mixed $links, array $ids): bool
    {
        if (! is_array($links) || ! array_is_list($links) || count($links) !== count($ids)) {
            return false;
        }
        $expected = [];
        foreach ($links as $link) {
            if (! is_array($link) || count($link) !== 2 || array_diff(['document_id', 'contract_id'], array_keys($link)) !== []
                || ! is_int($link['document_id']) || $link['document_id'] < 1
                || ($link['contract_id'] !== null && (! is_int($link['contract_id']) || $link['contract_id'] < 1))
                || array_key_exists($link['document_id'], $expected)) {
                return false;
            }
            $expected[$link['document_id']] = $link['contract_id'];
        }
        $expectedIds = array_map('strval', array_keys($expected));
        $sourceIds = array_map('strval', $ids);
        sort($expectedIds, SORT_STRING);
        sort($sourceIds, SORT_STRING);
        if ($expectedIds !== $sourceIds) {
            return false;
        }
        $table = $query->getModel()->getTable();
        $current = [];
        foreach ($query->whereKey($ids)->get([$table.'.id', $table.'.contract_id']) as $row) {
            $current[(int) $row->getKey()] = $row->getRawOriginal('contract_id') === null ? null : (int) $row->getRawOriginal('contract_id');
        }
        ksort($expected, SORT_NUMERIC);
        ksort($current, SORT_NUMERIC);

        return $expected === $current;
    }

    private function sign(array $payload): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new \RuntimeException('assistant_report_signing_key_missing');
        }

        $persisted = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return hash_hmac('sha256', AssistantSourceReferenceIdentity::key($persisted), $key);
    }
}
