<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantLegalBusinessMetadata as Metadata;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\Models\Project;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class LegalBusinessRagSource implements RagSourceCollectorInterface
{
    public function sourceType(): string { return 'legal_business'; }
    public function enabled(): bool { return true; }
    public function entities(): array
    {
        $result = [];
        foreach (Metadata::entityDefinitions() as $type => [$source,$class]) {
            if ($source === $this->sourceType()) { $result[$type] = ['model'=>$class,'fields'=>Metadata::fields()[$type]]; }
        }
        return $result;
    }
    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        foreach ($this->entities() as $type => $record) {
            foreach ($this->query($type,$organizationId,$projectId)->lazyById(50) as $model) { yield $this->chunk($model,$type,$organizationId); }
        }
    }
    public function collectEntity(int $organizationId,string $entityType,string|int $entityId): iterable
    {
        if (! isset($this->entities()[$entityType])) { return []; }
        $model = $this->query($entityType,$organizationId,null)->whereKey($entityId)->first();
        return $model === null ? [] : [$this->chunk($model,$entityType,$organizationId)];
    }
    private function definitions(): array
    {
        return Metadata::entityDefinitions() + [
            'executive_document' => ['',\App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument::class],
            'executive_document_set' => ['',\App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet::class],
            'acceptance_scope' => ['',\App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope::class],
            'project_location' => ['',\App\BusinessModules\Features\HandoverAcceptance\Models\ProjectLocation::class],
            'handover_package' => ['',\App\BusinessModules\Features\HandoverAcceptance\Models\HandoverPackage::class],
            'completed_work' => ['',\App\Models\CompletedWork::class], 'contract' => ['',\App\Models\Contract::class],
            'change_request' => ['',\App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest::class],
            'change_claim' => ['',\App\BusinessModules\Features\ChangeManagement\Models\ChangeClaim::class],
            'change_management_rfi' => ['',\App\BusinessModules\Features\ChangeManagement\Models\ChangeManagementRfi::class],
        ];
    }
    private function projectColumn(string $type,Model $model): ?string
    {
        if ($type === 'legal_document') { return 'primary_project_id'; }
        return in_array('project_id',Metadata::fields()[$type] ?? $model->getFillable(),true) ? 'project_id' : null;
    }
    private function query(string $type,int $organizationId,?int $projectId): Builder
    {
        $class = $this->definitions()[$type][1]; $query = $class::query(); $model = $query->getModel(); $table = $model->getTable();
        $organizationColumn = array_key_exists($type,Metadata::organizationColumns()) ? Metadata::organizationColumns()[$type] : 'organization_id';
        if ($organizationColumn !== null) { $query->where($table.'.'.$organizationColumn,$organizationId); }
        $projectColumn = $this->projectColumn($type,$model);
        if ($projectColumn !== null) {
            $projects = Project::query()->where(static fn (Builder $scope): Builder => $scope->where('organization_id',$organizationId)
                ->orWhereIn('id',DB::table('project_organization')->where('organization_id',$organizationId)->select('project_id')))->select('id');
            $query->where(static fn (Builder $scope): Builder => $scope->whereNull($table.'.'.$projectColumn)->orWhereIn($table.'.'.$projectColumn,$projects));
            if ($projectId !== null) { $query->where($table.'.'.$projectColumn,$projectId); }
        }
        $parents = Metadata::parentColumns()[$type] ?? [];
        if ($projectId !== null && $projectColumn === null && $parents === []) { $query->whereRaw('1 = 0'); }
        foreach ($parents as $column => $parent) {
            $parentQuery = $this->query($parent['type'],$organizationId,$projectColumn === null ? $projectId : null);
            $parentTable = $parentQuery->getModel()->getTable();
            foreach ($parent['matches'] ?? [] as $parentColumn => $childColumn) { $parentQuery->whereColumn($parentTable.'.'.$parentColumn,$table.'.'.$childColumn); }
            $parentProject = $this->projectColumn($parent['type'],$parentQuery->getModel());
            if ($parentProject !== null && $projectColumn !== null) { $parentQuery->whereRaw($parentTable.'.'.$parentProject.' IS NOT DISTINCT FROM '.$table.'.'.$projectColumn); }
            $query->where(static function (Builder $scope) use ($column,$parent,$parentQuery,$parentTable,$table): void {
                if ($parent['nullable']) { $scope->whereNull($table.'.'.$column)->orWhereIn($table.'.'.$column,$parentQuery->select($parentTable.'.id')); }
                else { $scope->whereIn($table.'.'.$column,$parentQuery->select($parentTable.'.id')); }
            });
        }
        if (isset(Metadata::safeSelectColumns()[$type])) {
            $query->select(array_map(static fn (string $column): string => $table.'.'.$column,Metadata::safeSelectColumns()[$type]));
        }
        return $query;
    }
    private function projectId(Model $model,string $type,int $organizationId): ?int
    {
        $column = $this->projectColumn($type,$model);
        if ($column !== null && is_numeric($model->getAttribute($column))) { return (int) $model->getAttribute($column); }
        foreach (Metadata::parentColumns()[$type] ?? [] as $parentColumn => $parent) {
            $id = $model->getAttribute($parentColumn);
            if ($id === null) { continue; }
            $parentModel = $this->query($parent['type'],$organizationId,null)->whereKey($id)->first();
            if ($parentModel === null) { continue; }
            $project = $this->projectId($parentModel,$parent['type'],$organizationId);
            if ($project !== null) { return $project; }
        }
        return null;
    }
    private function chunk(Model $model,string $type,int $organizationId): RagChunkData
    {
        $data = array_intersect_key($model->attributesToArray(),array_flip(Metadata::fields()[$type]));
        $projectId = $this->projectId($model,$type,$organizationId);
        if ($projectId !== null) { $data['project_id'] = $projectId; }
        $title = (string) ($data['title'] ?? $data['name'] ?? $data['label'] ?? $data['document_number'] ?? $data['original_name'] ?? $model->getKey());
        $updated = null;
        foreach (Metadata::versionColumns()[$type] ?? [] as $column) {
            $value = $model->getAttribute($column);
            if ($value !== null) { $updated = $value instanceof DateTimeInterface ? $value : CarbonImmutable::parse((string) $value); break; }
        }
        return new RagChunkData($organizationId,$projectId,$this->sourceType(),$type,$model->getKey(),$title,
            $title."\n".json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$data,$updated);
    }
}
