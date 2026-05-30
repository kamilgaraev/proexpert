<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\Concerns\FormatsRagSourceContent;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceChecklist;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceChecklistItem;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceFinding;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceSession;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceSignoff;
use App\BusinessModules\Features\HandoverAcceptance\Models\HandoverPackage;
use App\BusinessModules\Features\HandoverAcceptance\Models\HandoverPackageDocument;
use App\BusinessModules\Features\HandoverAcceptance\Models\ProjectLocation;
use DateTimeInterface;

final class HandoverAcceptanceRagSource implements RagSourceCollectorInterface
{
    use FormatsRagSourceContent;

    public function sourceType(): string
    {
        return 'handover_acceptance';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        foreach ($this->locations($organizationId, $projectId) as $location) {
            yield $this->locationChunk($location);
        }

        foreach ($this->scopes($organizationId, $projectId) as $scope) {
            yield $this->scopeChunk($scope);
        }

        foreach ($this->sessions($organizationId, $projectId) as $session) {
            yield $this->sessionChunk($session);
        }

        foreach ($this->checklists($organizationId, $projectId) as $checklist) {
            yield $this->checklistChunk($checklist);
        }

        foreach ($this->checklistItems($organizationId, $projectId) as $item) {
            yield $this->checklistItemChunk($item);
        }

        foreach ($this->findings($organizationId, $projectId) as $finding) {
            yield $this->findingChunk($finding);
        }

        foreach ($this->signoffs($organizationId, $projectId) as $signoff) {
            yield $this->signoffChunk($signoff);
        }

        foreach ($this->packages($organizationId, $projectId) as $package) {
            yield $this->packageChunk($package);
        }

        foreach ($this->documents($organizationId, $projectId) as $document) {
            yield $this->documentChunk($document);
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        return match ($entityType) {
            'project_location' => $this->singleLocation($organizationId, $entityId),
            'acceptance_scope' => $this->singleScope($organizationId, $entityId),
            'acceptance_session' => $this->singleSession($organizationId, $entityId),
            'acceptance_checklist' => $this->singleChecklist($organizationId, $entityId),
            'acceptance_checklist_item' => $this->singleChecklistItem($organizationId, $entityId),
            'acceptance_finding' => $this->singleFinding($organizationId, $entityId),
            'acceptance_signoff' => $this->singleSignoff($organizationId, $entityId),
            'handover_package' => $this->singlePackage($organizationId, $entityId),
            'handover_package_document' => $this->singleDocument($organizationId, $entityId),
            default => [],
        };
    }

    private function locationChunk(ProjectLocation $location): RagChunkData
    {
        $content = $this->lines([
            'Локация проекта: '.$this->stringValue($location->name),
            'Проект: '.$this->stringValue($location->project?->name),
            'Тип: '.$this->stringValue($location->location_type),
            'Код: '.$this->stringValue($location->code),
            'Путь: '.$this->stringValue($location->path),
            'Уровень: '.$this->numberValue($location->level, 0),
            'Родитель: '.$this->stringValue($location->parent?->name),
        ]);

        return $this->chunk(
            $location->organization_id,
            $location->project_id,
            'project_location',
            $location->id,
            'Локация: '.$this->stringValue($location->name),
            $content,
            [
                'project_id' => $location->project_id,
                'parent_id' => $location->parent_id,
                'location_type' => $this->scalarValue($location->location_type),
                'code' => $this->scalarValue($location->code),
            ],
            $location->updated_at
        );
    }

    private function scopeChunk(AcceptanceScope $scope): RagChunkData
    {
        $content = $this->lines([
            'Объем приемки: '.$this->stringValue($scope->title),
            'Проект: '.$this->stringValue($scope->project?->name),
            'Локация: '.$this->stringValue($scope->location?->name),
            'Статус: '.$this->stringValue($scope->status),
            'Плановая приемка: '.$this->dateValue($scope->planned_acceptance_date),
            'Принято: '.$this->dateTimeValue($scope->accepted_at),
            'Передано: '.$this->dateTimeValue($scope->handed_over_at),
            'Чек-листы: '.$this->numberValue($scope->checklists_count ?? 0, 0),
            'Замечания: '.$this->numberValue($scope->findings_count ?? 0, 0),
            'Подписания: '.$this->numberValue($scope->signoffs_count ?? 0, 0),
            'Описание: '.$this->stringValue($scope->description),
        ]);

        return $this->chunk(
            $scope->organization_id,
            $scope->project_id,
            'acceptance_scope',
            $scope->id,
            'Приемка: '.$this->stringValue($scope->title),
            $content,
            [
                'status' => $this->scalarValue($scope->status),
                'project_id' => $scope->project_id,
                'project_location_id' => $scope->project_location_id,
                'planned_acceptance_date' => $this->dateValue($scope->planned_acceptance_date),
            ],
            $scope->updated_at
        );
    }

    private function sessionChunk(AcceptanceSession $session): RagChunkData
    {
        $scope = $session->scope;
        $content = $this->lines([
            'Сессия приемки: '.$this->stringValue($scope?->title),
            'Проект: '.$this->stringValue($scope?->project?->name),
            'Локация: '.$this->stringValue($scope?->location?->name),
            'Статус: '.$this->stringValue($session->status),
            'Запланировано: '.$this->dateTimeValue($session->scheduled_at),
            'Начато: '.$this->dateTimeValue($session->started_at),
            'Завершено: '.$this->dateTimeValue($session->completed_at),
            'Участники: '.$this->arrayValue($session->participant_user_ids),
            'Замечания: '.$this->numberValue($session->findings_count ?? 0, 0),
            'Итог: '.$this->stringValue($session->summary),
        ]);

        return $this->chunk(
            $session->organization_id,
            $session->project_id,
            'acceptance_session',
            $session->id,
            'Сессия приемки: '.$this->dateTimeValue($session->scheduled_at),
            $content,
            [
                'status' => $this->scalarValue($session->status),
                'project_id' => $session->project_id,
                'acceptance_scope_id' => $session->acceptance_scope_id,
                'scheduled_at' => $this->dateTimeValue($session->scheduled_at),
            ],
            $session->updated_at
        );
    }

    private function checklistChunk(AcceptanceChecklist $checklist): RagChunkData
    {
        $scope = $checklist->scope;
        $content = $this->lines([
            'Чек-лист приемки: '.$this->stringValue($checklist->title),
            'Проект: '.$this->stringValue($scope?->project?->name),
            'Локация: '.$this->stringValue($scope?->location?->name),
            'Объем приемки: '.$this->stringValue($scope?->title),
            'Статус: '.$this->stringValue($checklist->status),
            'Пунктов: '.$this->numberValue($checklist->items_count ?? 0, 0),
        ]);

        return $this->chunk(
            $checklist->organization_id,
            $checklist->project_id,
            'acceptance_checklist',
            $checklist->id,
            'Чек-лист приемки: '.$this->stringValue($checklist->title),
            $content,
            [
                'status' => $this->scalarValue($checklist->status),
                'project_id' => $checklist->project_id,
                'acceptance_scope_id' => $checklist->acceptance_scope_id,
                'items_count' => (int) ($checklist->items_count ?? 0),
            ],
            $checklist->updated_at
        );
    }

    private function checklistItemChunk(AcceptanceChecklistItem $item): RagChunkData
    {
        $checklist = $item->checklist;
        $scope = $checklist?->scope;
        $content = $this->lines([
            'Пункт чек-листа: '.$this->stringValue($item->title),
            'Чек-лист: '.$this->stringValue($checklist?->title),
            'Проект: '.$this->stringValue($scope?->project?->name),
            'Локация: '.$this->stringValue($scope?->location?->name),
            'Статус: '.$this->stringValue($item->status),
            'Обязательный: '.$this->boolValue($item->is_required),
            'Комментарий: '.$this->stringValue($item->comment),
        ]);

        return $this->chunk(
            (int) ($checklist?->organization_id ?? 0),
            $checklist?->project_id !== null ? (int) $checklist->project_id : null,
            'acceptance_checklist_item',
            $item->id,
            'Пункт чек-листа: '.$this->stringValue($item->title),
            $content,
            [
                'status' => $this->scalarValue($item->status),
                'project_id' => $checklist?->project_id,
                'acceptance_checklist_id' => $item->acceptance_checklist_id,
                'acceptance_scope_id' => $checklist?->acceptance_scope_id,
                'is_required' => (bool) $item->is_required,
            ],
            $item->updated_at
        );
    }

    private function findingChunk(AcceptanceFinding $finding): RagChunkData
    {
        $scope = $finding->scope;
        $content = $this->lines([
            'Замечание приемки: '.$this->stringValue($finding->title),
            'Проект: '.$this->stringValue($scope?->project?->name),
            'Локация: '.$this->stringValue($scope?->location?->name),
            'Объем приемки: '.$this->stringValue($scope?->title),
            'Серьезность: '.$this->stringValue($finding->severity),
            'Статус: '.$this->stringValue($finding->status),
            'Дефект качества: '.$this->stringValue($finding->qualityDefect?->defect_number),
            'Описание: '.$this->stringValue($finding->description),
            'Решение: '.$this->stringValue($finding->resolution_comment),
            'Решено: '.$this->dateTimeValue($finding->resolved_at),
        ]);

        return $this->chunk(
            $finding->organization_id,
            $finding->project_id,
            'acceptance_finding',
            $finding->id,
            'Замечание приемки: '.$this->stringValue($finding->title),
            $content,
            [
                'status' => $this->scalarValue($finding->status),
                'severity' => $this->scalarValue($finding->severity),
                'project_id' => $finding->project_id,
                'acceptance_scope_id' => $finding->acceptance_scope_id,
                'acceptance_session_id' => $finding->acceptance_session_id,
                'quality_defect_id' => $finding->quality_defect_id,
            ],
            $finding->updated_at
        );
    }

    private function signoffChunk(AcceptanceSignoff $signoff): RagChunkData
    {
        $scope = $signoff->scope;
        $content = $this->lines([
            'Подписание приемки: '.$this->stringValue($scope?->title),
            'Проект: '.$this->stringValue($scope?->project?->name),
            'Локация: '.$this->stringValue($scope?->location?->name),
            'Статус: '.$this->stringValue($signoff->status),
            'Подписано: '.$this->dateTimeValue($signoff->signed_at),
            'Комментарий: '.$this->stringValue($signoff->comment),
        ]);

        return $this->chunk(
            $signoff->organization_id,
            $signoff->project_id,
            'acceptance_signoff',
            $signoff->id,
            'Подписание приемки: '.$this->stringValue($scope?->title),
            $content,
            [
                'status' => $this->scalarValue($signoff->status),
                'project_id' => $signoff->project_id,
                'acceptance_scope_id' => $signoff->acceptance_scope_id,
                'signed_at' => $this->dateTimeValue($signoff->signed_at),
            ],
            $signoff->updated_at
        );
    }

    private function packageChunk(HandoverPackage $package): RagChunkData
    {
        $scope = $package->scope;
        $content = $this->lines([
            'Пакет сдачи: '.$this->stringValue($package->title),
            'Проект: '.$this->stringValue($scope?->project?->name),
            'Локация: '.$this->stringValue($scope?->location?->name),
            'Объем приемки: '.$this->stringValue($scope?->title),
            'Статус: '.$this->stringValue($package->status),
            'Документов: '.$this->numberValue($package->documents_count ?? 0, 0),
        ]);

        return $this->chunk(
            $package->organization_id,
            $package->project_id,
            'handover_package',
            $package->id,
            'Пакет сдачи: '.$this->stringValue($package->title),
            $content,
            [
                'status' => $this->scalarValue($package->status),
                'project_id' => $package->project_id,
                'acceptance_scope_id' => $package->acceptance_scope_id,
                'documents_count' => (int) ($package->documents_count ?? 0),
            ],
            $package->updated_at
        );
    }

    private function documentChunk(HandoverPackageDocument $document): RagChunkData
    {
        $package = $document->package;
        $scope = $package?->scope;
        $content = $this->lines([
            'Документ пакета сдачи: '.$this->stringValue($document->title),
            'Пакет: '.$this->stringValue($package?->title),
            'Проект: '.$this->stringValue($scope?->project?->name),
            'Тип документа: '.$this->stringValue($document->document_type),
            'Статус: '.$this->stringValue($document->status),
            'Обязательный: '.$this->boolValue($document->is_required),
            'Утвержден: '.$this->dateTimeValue($document->approved_at),
            'Внешняя ссылка: '.$this->stringValue($document->external_url),
        ]);

        return $this->chunk(
            (int) ($package?->organization_id ?? 0),
            $package?->project_id !== null ? (int) $package->project_id : null,
            'handover_package_document',
            $document->id,
            'Документ сдачи: '.$this->stringValue($document->title),
            $content,
            [
                'status' => $this->scalarValue($document->status),
                'project_id' => $package?->project_id,
                'handover_package_id' => $document->handover_package_id,
                'document_type' => $this->scalarValue($document->document_type),
                'is_required' => (bool) $document->is_required,
            ],
            $document->updated_at
        );
    }

    private function locations(int $organizationId, ?int $projectId): iterable
    {
        return ProjectLocation::query()
            ->with(['project', 'parent'])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function scopes(int $organizationId, ?int $projectId): iterable
    {
        return AcceptanceScope::query()
            ->with(['project', 'location'])
            ->withCount(['checklists', 'findings', 'signoffs'])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function sessions(int $organizationId, ?int $projectId): iterable
    {
        return AcceptanceSession::query()
            ->with(['scope.project', 'scope.location'])
            ->withCount('findings')
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function checklists(int $organizationId, ?int $projectId): iterable
    {
        return AcceptanceChecklist::query()
            ->with(['scope.project', 'scope.location'])
            ->withCount('items')
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function checklistItems(int $organizationId, ?int $projectId): iterable
    {
        return AcceptanceChecklistItem::query()
            ->with(['checklist.scope.project', 'checklist.scope.location'])
            ->whereHas('checklist', function ($query) use ($organizationId, $projectId): void {
                $query->where('organization_id', $organizationId)
                    ->when($projectId !== null, static fn ($checklistQuery) => $checklistQuery->where('project_id', $projectId));
            })
            ->orderBy('id')
            ->cursor();
    }

    private function findings(int $organizationId, ?int $projectId): iterable
    {
        return AcceptanceFinding::query()
            ->with(['scope.project', 'scope.location', 'qualityDefect'])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function signoffs(int $organizationId, ?int $projectId): iterable
    {
        return AcceptanceSignoff::query()
            ->with(['scope.project', 'scope.location'])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function packages(int $organizationId, ?int $projectId): iterable
    {
        return HandoverPackage::query()
            ->with(['scope.project', 'scope.location'])
            ->withCount('documents')
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id')
            ->cursor();
    }

    private function documents(int $organizationId, ?int $projectId): iterable
    {
        return HandoverPackageDocument::query()
            ->with(['package.scope.project', 'package.scope.location'])
            ->whereHas('package', function ($query) use ($organizationId, $projectId): void {
                $query->where('organization_id', $organizationId)
                    ->when($projectId !== null, static fn ($packageQuery) => $packageQuery->where('project_id', $projectId));
            })
            ->orderBy('id')
            ->cursor();
    }

    private function singleLocation(int $organizationId, string|int $entityId): array
    {
        $location = ProjectLocation::query()->with(['project', 'parent'])->where('organization_id', $organizationId)->find($entityId);

        return $location instanceof ProjectLocation ? [$this->locationChunk($location)] : [];
    }

    private function singleScope(int $organizationId, string|int $entityId): array
    {
        $scope = AcceptanceScope::query()->with(['project', 'location'])->withCount(['checklists', 'findings', 'signoffs'])->where('organization_id', $organizationId)->find($entityId);

        return $scope instanceof AcceptanceScope ? [$this->scopeChunk($scope)] : [];
    }

    private function singleSession(int $organizationId, string|int $entityId): array
    {
        $session = AcceptanceSession::query()->with(['scope.project', 'scope.location'])->withCount('findings')->where('organization_id', $organizationId)->find($entityId);

        return $session instanceof AcceptanceSession ? [$this->sessionChunk($session)] : [];
    }

    private function singleChecklist(int $organizationId, string|int $entityId): array
    {
        $checklist = AcceptanceChecklist::query()->with(['scope.project', 'scope.location'])->withCount('items')->where('organization_id', $organizationId)->find($entityId);

        return $checklist instanceof AcceptanceChecklist ? [$this->checklistChunk($checklist)] : [];
    }

    private function singleChecklistItem(int $organizationId, string|int $entityId): array
    {
        $item = AcceptanceChecklistItem::query()->with(['checklist.scope.project', 'checklist.scope.location'])->whereHas('checklist', static fn ($query) => $query->where('organization_id', $organizationId))->find($entityId);

        return $item instanceof AcceptanceChecklistItem ? [$this->checklistItemChunk($item)] : [];
    }

    private function singleFinding(int $organizationId, string|int $entityId): array
    {
        $finding = AcceptanceFinding::query()->with(['scope.project', 'scope.location', 'qualityDefect'])->where('organization_id', $organizationId)->find($entityId);

        return $finding instanceof AcceptanceFinding ? [$this->findingChunk($finding)] : [];
    }

    private function singleSignoff(int $organizationId, string|int $entityId): array
    {
        $signoff = AcceptanceSignoff::query()->with(['scope.project', 'scope.location'])->where('organization_id', $organizationId)->find($entityId);

        return $signoff instanceof AcceptanceSignoff ? [$this->signoffChunk($signoff)] : [];
    }

    private function singlePackage(int $organizationId, string|int $entityId): array
    {
        $package = HandoverPackage::query()->with(['scope.project', 'scope.location'])->withCount('documents')->where('organization_id', $organizationId)->find($entityId);

        return $package instanceof HandoverPackage ? [$this->packageChunk($package)] : [];
    }

    private function singleDocument(int $organizationId, string|int $entityId): array
    {
        $document = HandoverPackageDocument::query()->with(['package.scope.project', 'package.scope.location'])->whereHas('package', static fn ($query) => $query->where('organization_id', $organizationId))->find($entityId);

        return $document instanceof HandoverPackageDocument ? [$this->documentChunk($document)] : [];
    }

    private function chunk(
        int $organizationId,
        ?int $projectId,
        string $entityType,
        int $entityId,
        string $title,
        string $content,
        array $metadata,
        ?DateTimeInterface $updatedAt
    ): RagChunkData {
        return new RagChunkData(
            organizationId: $organizationId,
            projectId: $projectId,
            sourceType: $this->sourceType(),
            entityType: $entityType,
            entityId: $entityId,
            title: $title,
            content: $content,
            metadata: $metadata,
            updatedAt: $updatedAt
        );
    }
}
