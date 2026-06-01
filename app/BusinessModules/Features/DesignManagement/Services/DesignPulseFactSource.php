<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\AIAssistant\Contracts\ProjectPulse\ProjectPulseFactSourceInterface;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseFact;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\Concerns\BuildsProjectPulseFacts;
use App\Modules\Core\AccessController;
use Illuminate\Support\Collection;

final class DesignPulseFactSource implements ProjectPulseFactSourceInterface
{
    use BuildsProjectPulseFacts;

    public function __construct(
        private readonly AccessController $accessController,
    ) {
    }

    public function key(): string
    {
        return 'design_management';
    }

    public function collect(ProjectPulseContext $context): Collection
    {
        if (!$this->accessController->hasModuleAccess($context->organizationId, 'design-management')) {
            return $this->empty();
        }

        if (!$this->hasTable('design_packages') || !$this->hasTable('design_artifact_versions')) {
            return $this->empty();
        }

        return collect()
            ->merge($this->missingViewerDerivatives($context))
            ->merge($this->overduePackages($context))
            ->merge($this->failedViewerPreparation($context))
            ->take($this->limit())
            ->values();
    }

    private function missingViewerDerivatives(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('design_artifacts') || !$this->hasTable('design_model_derivatives') || !$this->hasTable('projects')) {
            return $this->empty();
        }

        return $this->table($context, 'design_artifact_versions')
            ->join('design_artifacts', 'design_artifacts.id', '=', 'design_artifact_versions.artifact_id')
            ->join('design_packages', 'design_packages.id', '=', 'design_artifacts.package_id')
            ->leftJoin('design_model_derivatives as ready_derivatives', function ($join): void {
                $join->on('ready_derivatives.version_id', '=', 'design_artifact_versions.id')
                    ->where('ready_derivatives.viewer_provider', '=', 'thatopen')
                    ->where('ready_derivatives.derivative_format', '=', 'thatopen_frag')
                    ->where('ready_derivatives.status', '=', 'ready');
            })
            ->leftJoin('projects', 'projects.id', '=', 'design_artifact_versions.project_id')
            ->where('design_artifacts.artifact_type', 'model')
            ->where('design_artifact_versions.source_format', 'ifc')
            ->whereNull('ready_derivatives.id')
            ->orderByDesc('design_artifact_versions.created_at')
            ->limit($this->limit())
            ->get([
                'design_artifact_versions.id',
                'design_artifact_versions.project_id',
                'design_artifact_versions.title',
                'design_artifact_versions.version_number',
                'design_artifact_versions.created_at',
                'design_packages.id as package_id',
                'design_packages.title as package_title',
                'projects.name as project_name',
            ])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'design_model_version:' . $row->id . ':viewer_missing',
                type: 'design_model_derivative_missing',
                priority: 'warning',
                title: trans_message('design_management.project_pulse.derivative_missing_title'),
                text: trans_message('design_management.project_pulse.derivative_missing_text', [
                    'package' => (string) $row->package_title,
                    'title' => (string) $row->title,
                    'version' => (string) $row->version_number,
                ]),
                projectId: (int) $row->project_id,
                projectName: $row->project_name,
                relatedEntity: $this->entity('design_model_version', (int) $row->id, (string) $row->title, (int) $row->package_id),
                occurredAt: $this->dateString($row->created_at),
                source: $this->key(),
                category: 'documentation',
                status: 'missing_viewer',
                nextAction: trans_message('design_management.project_pulse.derivative_missing_next_action'),
                primaryAction: $this->action(
                    trans_message('design_management.project_pulse.derivative_missing_action'),
                    (int) $row->package_id,
                    'design-management.models.prepare_viewer'
                ),
                meta: [
                    'package_id' => (int) $row->package_id,
                    'version_number' => (string) $row->version_number,
                ],
            ))
            ->values();
    }

    private function overduePackages(ProjectPulseContext $context): Collection
    {
        return $this->table($context, 'design_packages')
            ->leftJoin('projects', 'projects.id', '=', 'design_packages.project_id')
            ->whereNotNull('design_packages.planned_issue_date')
            ->whereDate('design_packages.planned_issue_date', '<', $context->date->toDateString())
            ->whereNotIn('design_packages.status', ['issued', 'approved'])
            ->orderBy('design_packages.planned_issue_date')
            ->limit($this->limit())
            ->get([
                'design_packages.id',
                'design_packages.project_id',
                'design_packages.title',
                'design_packages.status',
                'design_packages.planned_issue_date',
                'projects.name as project_name',
            ])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'design_package:' . $row->id . ':overdue',
                type: 'design_package_overdue',
                priority: 'critical',
                title: trans_message('design_management.project_pulse.package_overdue_title'),
                text: trans_message('design_management.project_pulse.package_overdue_text', [
                    'package' => (string) $row->title,
                ]),
                projectId: (int) $row->project_id,
                projectName: $row->project_name,
                relatedEntity: $this->entity('design_package', (int) $row->id, (string) $row->title, (int) $row->id),
                source: $this->key(),
                category: 'documentation',
                status: (string) $row->status,
                nextAction: trans_message('design_management.project_pulse.package_overdue_next_action'),
                primaryAction: $this->action(
                    trans_message('design_management.project_pulse.package_overdue_action'),
                    (int) $row->id,
                    'design-management.view'
                ),
                deadline: (string) $row->planned_issue_date,
                ageDays: $this->ageDays($context, $row->planned_issue_date),
            ))
            ->values();
    }

    private function failedViewerPreparation(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('design_artifacts') || !$this->hasTable('design_model_derivatives') || !$this->hasTable('projects')) {
            return $this->empty();
        }

        return $this->table($context, 'design_model_derivatives')
            ->join('design_artifact_versions', 'design_artifact_versions.id', '=', 'design_model_derivatives.version_id')
            ->join('design_artifacts', 'design_artifacts.id', '=', 'design_artifact_versions.artifact_id')
            ->join('design_packages', 'design_packages.id', '=', 'design_artifacts.package_id')
            ->leftJoin('projects', 'projects.id', '=', 'design_model_derivatives.project_id')
            ->where('design_artifacts.artifact_type', 'model')
            ->where('design_model_derivatives.status', 'failed')
            ->orderByDesc('design_model_derivatives.updated_at')
            ->limit($this->limit())
            ->get([
                'design_model_derivatives.id',
                'design_model_derivatives.version_id',
                'design_model_derivatives.project_id',
                'design_model_derivatives.failed_reason',
                'design_model_derivatives.updated_at',
                'design_artifact_versions.title',
                'design_artifact_versions.version_number',
                'design_packages.id as package_id',
                'design_packages.title as package_title',
                'projects.name as project_name',
            ])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'design_model_derivative:' . $row->id . ':failed',
                type: 'design_model_preparation_failed',
                priority: 'critical',
                title: trans_message('design_management.project_pulse.derivative_failed_title'),
                text: trans_message('design_management.project_pulse.derivative_failed_text', [
                    'package' => (string) $row->package_title,
                    'title' => (string) $row->title,
                    'version' => (string) $row->version_number,
                ]),
                projectId: (int) $row->project_id,
                projectName: $row->project_name,
                relatedEntity: $this->entity('design_model_version', (int) $row->version_id, (string) $row->title, (int) $row->package_id),
                occurredAt: $this->dateString($row->updated_at),
                source: $this->key(),
                category: 'documentation',
                status: 'failed',
                nextAction: trans_message('design_management.project_pulse.derivative_failed_next_action'),
                primaryAction: $this->action(
                    trans_message('design_management.project_pulse.derivative_failed_action'),
                    (int) $row->package_id,
                    'design-management.models.prepare_viewer'
                ),
                meta: [
                    'package_id' => (int) $row->package_id,
                    'failed_reason' => $row->failed_reason,
                ],
            ))
            ->values();
    }

    private function entity(string $type, int $id, string $label, int $packageId): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'label' => $label,
            'route' => '/pir/packages/' . $packageId,
        ];
    }

    private function action(string $label, int $packageId, string $permission): array
    {
        return [
            'label' => $label,
            'route' => '/pir/packages/' . $packageId,
            'permission' => $permission,
        ];
    }
}
