<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarItem;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ProjectPortfolioHealthImmutableSourceSelection
{
    private array $projectReferences;

    public function __construct(
        public ProjectPortfolioHealthSourceTuple $tuple,
        public array $ownerPayloads,
        public array $calendar,
        private array $rowCounts,
        array $projectReferences,
    ) {
        if (! $tuple->isReady()
            || ! isset(
                $ownerPayloads['project_margin']['rows'],
                $ownerPayloads['wip_completion_forecast']['rows'],
                $ownerPayloads['budget_plan_fact']['rows'],
            )) {
            throw new InvalidArgumentException('project_portfolio_health_source_selection_invalid');
        }
        foreach (['project_margin', 'wip_completion_forecast', 'budget_plan_fact'] as $kind) {
            if (! is_array($ownerPayloads[$kind]['rows'])
                || ! array_is_list($ownerPayloads[$kind]['rows'])
                || $ownerPayloads[$kind]['rows'] === []
                || ! is_int($rowCounts[$kind] ?? null)
                || $rowCounts[$kind] < 1) {
                throw new InvalidArgumentException('project_portfolio_health_source_selection_invalid');
            }
        }
        if (! array_is_list($calendar)) {
            throw new InvalidArgumentException('project_portfolio_health_source_selection_invalid');
        }
        foreach ($calendar as $item) {
            if (! $item instanceof PaymentCalendarItem) {
                throw new InvalidArgumentException('project_portfolio_health_source_selection_invalid');
            }
        }
        $this->projectReferences = $this->normalizeProjectReferences($projectReferences);
        $this->projects();
    }

    public function sourceHash(): Sha256Hash
    {
        return new Sha256Hash(hash('sha256', CanonicalJson::encode([
            'project_dimensions' => $this->projectReferences,
            'source_tuple' => $this->tuple->watermark,
        ])));
    }

    public function sourceRefs(): array
    {
        $refs = [];
        foreach ($this->tuple->components as $component) {
            $rowCount = $component->kind === 'portfolio_liquidity'
                ? count($this->calendar)
                : $this->rowCounts[$component->kind];
            $refs[] = new ReportSourceRef(
                source: $component->kind,
                snapshotKind: $component->kind,
                snapshotId: $this->identifier($component->snapshotId, 'snapshot'),
                schemaVersion: $this->identifier($component->version, 'schema'),
                watermark: 'watermark_'.substr($component->sourceHash, 0, 24),
                rowCount: $rowCount,
                hash: new Sha256Hash($component->sourceHash),
            );
        }
        $projectDimensionsHash = $this->projectDimensionsHash();
        $refs[] = new ReportSourceRef(
            source: 'project_dimensions',
            snapshotKind: 'project_dimensions',
            snapshotId: 'project_dimensions_'.substr($projectDimensionsHash, 0, 24),
            schemaVersion: 'project_dimensions_v1',
            watermark: 'watermark_'.substr($projectDimensionsHash, 0, 24),
            rowCount: count($this->projectReferences),
            hash: new Sha256Hash($projectDimensionsHash),
        );

        return $refs;
    }

    public function watermarks(): array
    {
        $watermarks = [
            'project_dimensions' => $this->projectDimensionsHash(),
            'source_tuple' => $this->tuple->watermark,
        ];
        foreach ($this->tuple->components as $component) {
            $watermarks[$component->kind] = $component->sourceHash;
        }
        ksort($watermarks, SORT_STRING);

        return $watermarks;
    }

    public function projects(): array
    {
        $projects = [];
        foreach ($this->ownerPayloads as $payload) {
            foreach ($payload['rows'] as $row) {
                $project = is_array($row['project'] ?? null) ? $row['project'] : [];
                $id = $project['id'] ?? null;
                $name = $project['name'] ?? null;
                if (! is_int($id) || $id < 1 || ! is_string($name) || trim($name) === '') {
                    throw new InvalidArgumentException('project_portfolio_health_source_selection_invalid');
                }
                if (isset($projects[$id]) && $projects[$id]['name'] !== $name) {
                    throw new InvalidArgumentException('project_portfolio_health_source_selection_invalid');
                }
                $projects[$id] = ['id' => $id, 'name' => $name];
            }
        }
        if ($projects === []) {
            throw new InvalidArgumentException('project_portfolio_health_source_selection_invalid');
        }
        ksort($projects, SORT_NUMERIC);
        if (array_keys($projects) !== array_column($this->projectReferences, 'id')) {
            throw new InvalidArgumentException('project_portfolio_health_source_selection_invalid');
        }
        foreach ($this->projectReferences as $reference) {
            $projects[$reference['id']]['status'] = $reference['status'];
            $projects[$reference['id']]['manager_ids'] = $reference['manager_ids'];
        }

        return $projects;
    }

    private function normalizeProjectReferences(array $references): array
    {
        if (! array_is_list($references) || $references === []) {
            throw new InvalidArgumentException('project_portfolio_health_source_selection_invalid');
        }
        $normalized = [];
        foreach ($references as $reference) {
            if (! is_array($reference)
                || ! is_int($reference['id'] ?? null)
                || $reference['id'] < 1
                || ! is_string($reference['status'] ?? null)
                || trim($reference['status']) === ''
                || ! is_array($reference['manager_ids'] ?? null)
                || ! array_is_list($reference['manager_ids'])) {
                throw new InvalidArgumentException('project_portfolio_health_source_selection_invalid');
            }
            $managerIds = [];
            foreach ($reference['manager_ids'] as $managerId) {
                if (! is_int($managerId) || $managerId < 1) {
                    throw new InvalidArgumentException('project_portfolio_health_source_selection_invalid');
                }
                $managerIds[$managerId] = $managerId;
            }
            ksort($managerIds, SORT_NUMERIC);
            $id = $reference['id'];
            if (isset($normalized[$id])) {
                throw new InvalidArgumentException('project_portfolio_health_source_selection_invalid');
            }
            $normalized[$id] = [
                'id' => $id,
                'status' => trim($reference['status']),
                'manager_ids' => array_values($managerIds),
            ];
        }
        ksort($normalized, SORT_NUMERIC);

        return array_values($normalized);
    }

    private function projectDimensionsHash(): string
    {
        return hash('sha256', CanonicalJson::encode($this->projectReferences));
    }

    private function identifier(string $value, string $prefix): string
    {
        $normalized = preg_replace('/[^a-z0-9_]/', '_', mb_strtolower(trim($value)));
        if (! is_string($normalized) || $normalized === '' || preg_match('/^[a-z]/D', $normalized) !== 1) {
            $normalized = $prefix.'_'.substr(hash('sha256', $value), 0, 24);
        }

        return substr($normalized, 0, 64);
    }
}
