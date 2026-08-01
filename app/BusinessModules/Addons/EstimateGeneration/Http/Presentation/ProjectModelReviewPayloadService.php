<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Presentation;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelCorrectionChainProjector;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use stdClass;

/** Read-only, version-pinned presentation of the auditable project-model graph. */
final readonly class ProjectModelReviewPayloadService
{
    private const TYPE_LABELS = [
        'room' => 'Помещение', 'wall' => 'Стена', 'opening' => 'Проём', 'dimension' => 'Размер',
        'table' => 'Таблица', 'structural_element' => 'Конструктивный элемент', 'quantity' => 'Объём',
        'area' => 'Площадь', 'room_purpose' => 'Назначение помещения',
    ];

    public function __construct(
        private DatabaseManager $database,
        private EstimateGenerationDocumentPreviewService $previews,
        private ProjectModelCorrectionChainProjector $correctionChain = new ProjectModelCorrectionChainProjector,
    ) {}

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function handle(EstimateGenerationSession $session, User $user, array $filters): array
    {
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->getKey()];
        $model = $this->database->table('estimate_generation_building_models')
            ->where('organization_id', $scope[0])->where('project_id', $scope[1])->where('session_id', $scope[2])
            ->latest('id')->first(['id', 'content_version']);
        $stateVersion = (int) $session->state_version;
        $requestedVersion = array_key_exists('state_version', $filters) ? (int) $filters['state_version'] : null;
        if (! $model instanceof stdClass) {
            return $this->empty($stateVersion, $requestedVersion);
        }

        $cursor = $this->cursor($filters['cursor'] ?? null);
        $perPage = (int) ($filters['per_page'] ?? 50);
        $entitiesQuery = $this->database->table('estimate_generation_project_model_entities')
            ->where('building_model_id', $model->id)->where('organization_id', $scope[0])->where('project_id', $scope[1])
            ->where('session_id', $scope[2])->where('source_version', (string) $model->content_version)
            ->orderBy('stable_key');
        if (is_string($filters['entity_kind'] ?? null)) $entitiesQuery->where('entity_kind', $filters['entity_kind']);
        if ($cursor !== null) $entitiesQuery->where('stable_key', '>', $cursor);
        if (is_string($filters['query'] ?? null) && trim($filters['query']) !== '') {
            $needle = mb_strtolower(trim($filters['query']));
            $entitiesQuery->where('stable_key', 'like', '%'.$this->escapeLike($needle).'%');
        }
        $entityRows = $entitiesQuery->limit($perPage + 1)->get(['id', 'stable_key', 'entity_kind', 'payload', 'confidence']);
        $hasMore = $entityRows->count() > $perPage;
        $entityRows = $entityRows->take($perPage)->values();
        $entityIds = $entityRows->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $assertions = $this->assertions($model, $scope, $entityIds);
        $corrections = $this->corrections($model, $scope, $entityIds);
        $evidence = $this->evidence($model, $scope, $entityIds);
        $documents = $this->documents($scope, $filters);
        $documentMap = $documents->keyBy('id')->all();
        $sheets = $this->sheets($scope, $filters, $documents->pluck('id')->all());
        $sheetMap = $sheets->keyBy('id')->all();
        $previewUrls = $this->previewUrls($session, $documents->pluck('id')->all(), $user);
        $byAssertion = $this->group($assertions, 'entity_id');
        $byCorrection = $this->group($corrections, 'assertion_id');
        $byEvidence = $this->group($evidence, 'entity_id');
        $effective = $this->effectiveCorrections($corrections);
        $entities = [];
        foreach ($entityRows as $entity) {
            $entityAssertions = $byAssertion[(int) $entity->id] ?? [];
            $presentedAssertions = array_map(fn (stdClass $assertion): array => $this->assertion(
                $assertion,
                $byCorrection[(int) $assertion->id] ?? [],
                $byEvidence[(int) $entity->id] ?? [],
                $effective,
                $documentMap,
                $sheetMap,
                $previewUrls,
            ), $entityAssertions);
            $conflicts = $this->conflicts($presentedAssertions);
            $status = $conflicts === [] ? $this->entityStatus($presentedAssertions) : 'conflict';
            if (! $this->matches($status, $presentedAssertions, $filters)) continue;
            $entities[] = [
                'stable_key' => (string) $entity->stable_key,
                'kind' => (string) $entity->entity_kind,
                'kind_display' => self::TYPE_LABELS[(string) $entity->entity_kind] ?? 'Элемент',
                'confidence' => $this->confidence($entity->confidence),
                'status' => $status,
                'needs_action' => $status !== 'confirmed',
                'payload' => $this->safePayload($this->json($entity->payload)),
                'assertions' => $presentedAssertions,
                'conflicts' => $conflicts,
                'dependency_impacts' => $this->impacts($model, $scope, (int) $entity->id),
            ];
        }
        $hasMore = $hasMore && $entities !== [];
        $next = $hasMore ? $this->encodeCursor((string) end($entities)['stable_key']) : null;

        return [
            'state_version' => $stateVersion,
            'content_version' => (string) $model->content_version,
            'stale' => $requestedVersion !== null && $requestedVersion !== $stateVersion,
            'summary' => $this->summary($entities),
            'documents' => $documents->map(fn (stdClass $row): array => $this->document($row, $previewUrls[(int) $row->id] ?? null))->values()->all(),
            'sheets' => $sheets->map(fn (stdClass $row): array => $this->sheet($row))->values()->all(),
            'entities' => $entities,
            'page' => ['per_page' => $perPage, 'next_cursor' => $next, 'has_more' => $hasMore],
        ];
    }

    /** @param list<stdClass> $corrections @return array<string,array<string,mixed>> */
    private function effectiveCorrections(array $corrections): array
    {
        if ($corrections === []) return [];
        $rows = [];
        foreach ($corrections as $row) {
            $rows[] = ['correction_stable_key' => (string) $row->stable_key, 'correction_payload' => $this->json($row->payload), 'assertion_stable_key' => (string) $row->assertion_stable_key, 'assertion_type' => (string) $row->assertion_type, 'assertion_payload' => $this->json($row->assertion_payload), 'entity_stable_key' => (string) $row->entity_stable_key];
        }
        $result = [];
        foreach ($this->correctionChain->project($rows) as $value) $result[$value['assertion_stable_key']] = $value;
        return $result;
    }

    /** @param list<stdClass> $corrections @param list<stdClass> $evidence @param array<string,array<string,mixed>> $effective @param array<int,stdClass> $documents @param array<int,stdClass> $sheets @param array<int,string|null> $previewUrls */
    private function assertion(stdClass $row, array $corrections, array $evidence, array $effective, array $documents, array $sheets, array $previewUrls): array
    {
        $payload = $this->json($row->payload);
        $source = (string) ($payload['source'] ?? 'ai_candidate');
        unset($payload['source']);
        $active = $effective[(string) $row->stable_key] ?? null;
        $current = is_array($active['value'] ?? null) ? $active['value'] : $payload;
        $candidates = [[
            'stable_key' => (string) $row->stable_key, 'source' => $source, 'source_display' => $this->sourceLabel($source),
            'value' => $payload, 'confidence' => $this->confidence($row->confidence), 'confirmed' => $this->hasEvidence($evidence, (int) $row->id, null),
        ]];
        foreach ($corrections as $correction) {
            $value = $this->json($correction->payload)['canonical_value'] ?? [];
            $candidates[] = ['stable_key' => (string) $correction->stable_key, 'source' => 'manual_correction', 'source_display' => $this->sourceLabel('manual_correction'), 'value' => is_array($value) ? $value : [], 'confidence' => '1.000000', 'confirmed' => true];
        }
        $anchors = array_values(array_filter(array_map(fn (stdClass $binding): ?array => $this->anchor($binding, $documents, $sheets, $previewUrls), $evidence)));
        $confirmed = $active !== null || $candidates[0]['confirmed'];
        $status = $confirmed ? 'confirmed' : ($source === 'ai_candidate' ? 'needs_action' : 'unconfirmed');
        return ['stable_key' => (string) $row->stable_key, 'type' => (string) $row->assertion_type, 'type_display' => self::TYPE_LABELS[(string) $row->assertion_type] ?? 'Свойство', 'current_value' => $current, 'confidence' => $this->confidence($row->confidence), 'status' => $status, 'needs_action' => $status !== 'confirmed', 'candidates' => $candidates, 'evidence_ids' => array_values(array_unique(array_map(static fn (stdClass $item): int => (int) $item->evidence_id, $evidence))), 'viewer_anchors' => $anchors, 'latest_correction' => $corrections === [] ? null : ['stable_key' => (string) end($corrections)->stable_key, 'reason' => (string) end($corrections)->reason, 'created_at' => (string) end($corrections)->created_at], 'conflicts' => []];
    }

    private function anchor(stdClass $row, array $documents, array $sheets, array $previewUrls): ?array
    {
        $locator = $this->json($row->locator);
        $documentId = filter_var($locator['document_id'] ?? null, FILTER_VALIDATE_INT);
        $page = filter_var($locator['page'] ?? $locator['unit_index'] ?? null, FILTER_VALIDATE_INT);
        if ($documentId === false || $documentId < 1 || ! isset($documents[$documentId])) return null;
        $sheetId = isset($locator['sheet_id']) && is_numeric($locator['sheet_id']) ? (int) $locator['sheet_id'] : null;
        if ($sheetId === null && $page !== false && $page > 0) foreach ($sheets as $sheet) if ((int) $sheet->document_id === $documentId && (int) $sheet->page_number === $page) { $sheetId = (int) $sheet->id; break; }
        $polygon = $this->polygon($locator['bbox'] ?? $locator['polygon'] ?? null);
        return ['document_id' => $documentId, 'document_name' => (string) $documents[$documentId]->filename, 'sheet_id' => $sheetId, 'page_number' => $page !== false && $page > 0 ? $page : null, 'polygon' => $polygon, 'evidence_id' => (int) $row->evidence_id, 'source_label' => $this->sourceLabel((string) $row->candidate_source), 'viewer' => ['preview_available' => ($previewUrls[$documentId] ?? null) !== null, 'sheet_available' => $page !== false && $page > 0, 'preview_url' => $previewUrls[$documentId] ?? null]];
    }

    /** @return list<array{0:float,1:float}>|null */
    private function polygon(mixed $raw): ?array
    {
        if (! is_array($raw) || ! array_is_list($raw) || count($raw) < 3 || count($raw) > 32) return null;
        $polygon = [];
        foreach ($raw as $point) {
            if (! is_array($point) || ! array_is_list($point) || count($point) !== 2 || ! is_numeric($point[0]) || ! is_numeric($point[1])) return null;
            $polygon[] = [round((float) $point[0], 4), round((float) $point[1], 4)];
        }
        return $polygon;
    }

    /** @return list<stdClass> */
    private function assertions(stdClass $model, array $scope, array $entityIds): array { if ($entityIds === []) return []; return $this->database->table('estimate_generation_project_model_assertions')->where('building_model_id', $model->id)->where('organization_id', $scope[0])->where('project_id', $scope[1])->where('session_id', $scope[2])->where('source_version', $model->content_version)->whereIn('entity_id', $entityIds)->orderBy('id')->get()->all(); }
    /** @return list<stdClass> */
    private function corrections(stdClass $model, array $scope, array $entityIds): array { if ($entityIds === []) return []; return $this->database->table('estimate_generation_project_model_corrections as c')->join('estimate_generation_project_model_assertions as a', 'a.id', '=', 'c.assertion_id')->join('estimate_generation_project_model_entities as e', 'e.id', '=', 'a.entity_id')->where('c.building_model_id', $model->id)->where('c.organization_id', $scope[0])->where('c.project_id', $scope[1])->where('c.session_id', $scope[2])->where('c.source_version', $model->content_version)->whereIn('a.entity_id', $entityIds)->orderBy('c.id')->get(['c.*', 'a.stable_key as assertion_stable_key', 'a.assertion_type', 'a.payload as assertion_payload', 'e.stable_key as entity_stable_key'])->all(); }
    /** @return list<stdClass> */
    private function evidence(stdClass $model, array $scope, array $entityIds): array { if ($entityIds === []) return []; return $this->database->table('estimate_generation_project_model_evidence_bindings as b')->join('estimate_generation_evidence as ev', 'ev.id', '=', 'b.evidence_id')->where('b.building_model_id', $model->id)->where('b.organization_id', $scope[0])->where('b.project_id', $scope[1])->where('b.session_id', $scope[2])->where('b.source_version', $model->content_version)->whereIn('b.entity_id', $entityIds)->whereNull('ev.invalidated_at')->orderBy('b.id')->get(['b.entity_id', 'b.assertion_id', 'b.correction_id', 'b.evidence_id', 'b.candidate_source', 'ev.locator'])->all(); }
    private function documents(array $scope, array $filters): Collection { $query = $this->database->table('estimate_generation_documents')->where('organization_id', $scope[0])->where('project_id', $scope[1])->where('session_id', $scope[2])->orderBy('id'); if (isset($filters['document_id'])) $query->where('id', (int) $filters['document_id']); return $query->limit(100)->get(['id','filename','mime_type','status','source_version']); }
    private function sheets(array $scope, array $filters, array $documentIds): Collection { if ($documentIds === []) return collect(); $query = $this->database->table('estimate_generation_document_pages')->where('organization_id', $scope[0])->where('project_id', $scope[1])->where('session_id', $scope[2])->whereIn('document_id', $documentIds)->orderBy('document_id')->orderBy('page_number'); if (isset($filters['sheet_id'])) $query->where('id', (int) $filters['sheet_id']); return $query->limit(500)->get(['id','document_id','page_number','status']); }
    /** @return array<int,list<stdClass>> */ private function group(array $rows, string $key): array { $result=[]; foreach($rows as $row) $result[(int) $row->{$key}][]=$row; return $result; }
    private function hasEvidence(array $items, int $assertionId, ?int $correctionId): bool { foreach($items as $item) if ((int)($item->assertion_id ?? 0)===$assertionId && ($correctionId===null || (int)($item->correction_id ?? 0)===$correctionId)) return true; return false; }
    private function entityStatus(array $assertions): string { if ($assertions===[]) return 'unconfirmed'; if (array_filter($assertions, static fn(array $a): bool=>$a['status']==='needs_action')!==[]) return 'needs_action'; if (array_filter($assertions, static fn(array $a): bool=>$a['status']==='unconfirmed')!==[]) return 'unconfirmed'; return 'confirmed'; }
    private function matches(string $status,array $assertions,array $filters): bool { if (($filters['status']??null)!==$status && isset($filters['status'])) return false; if (isset($filters['needs_action']) && (bool)$filters['needs_action']!==($status!=='confirmed')) return false; if (isset($filters['document_id']) || isset($filters['sheet_id'])) { $anchors=[]; foreach($assertions as $assertion) foreach($assertion['viewer_anchors'] as $anchor) $anchors[]=$anchor; if (isset($filters['document_id']) && array_filter($anchors, fn(array $anchor):bool=>(int)$anchor['document_id']===(int)$filters['document_id'])===[]) return false; if (isset($filters['sheet_id']) && array_filter($anchors, fn(array $anchor):bool=>(int)($anchor['sheet_id']??0)===(int)$filters['sheet_id'])===[]) return false; } return true; }
    private function conflicts(array $assertions): array { $byType=[]; foreach($assertions as $assertion) if($assertion['status']==='confirmed') $byType[$assertion['type']][]=$assertion; $conflicts=[]; foreach($byType as $type=>$items){$values=[]; foreach($items as $item)$values[json_encode($item['current_value'],JSON_THROW_ON_ERROR)][]=$item['stable_key']; if(count($values)>1){$keys=[];foreach($values as $itemKeys)foreach($itemKeys as $key)$keys[]=$key;sort($keys,SORT_STRING);$conflicts[]=['code'=>$type.'_conflict','type'=>$type,'type_display'=>self::TYPE_LABELS[$type]??'Свойство','assertion_stable_keys'=>$keys];}} return $conflicts; }
    private function impacts(stdClass $model,array $scope,int $entityId): array { return $this->database->table('estimate_generation_project_model_relations as r')->join('estimate_generation_project_model_entities as target', function($join) use($entityId){$join->on('target.id','=','r.to_entity_id')->orOn('target.id','=','r.from_entity_id');})->where('r.building_model_id',$model->id)->where('r.organization_id',$scope[0])->where('r.project_id',$scope[1])->where('r.session_id',$scope[2])->where('r.source_version',$model->content_version)->where(function($q) use($entityId){$q->where('r.from_entity_id',$entityId)->orWhere('r.to_entity_id',$entityId);})->where('target.id','<>',$entityId)->limit(100)->get(['target.stable_key','target.entity_kind','r.relation_type'])->map(fn(stdClass $row):array=>['stable_key'=>(string)$row->stable_key,'kind'=>(string)$row->entity_kind,'kind_display'=>self::TYPE_LABELS[(string)$row->entity_kind]??'Элемент','relation_type'=>(string)$row->relation_type])->all(); }
    private function document(stdClass $row, ?string $previewUrl): array { return ['id'=>(int)$row->id,'filename'=>(string)$row->filename,'mime_type'=>(string)$row->mime_type,'status'=>(string)$row->status,'source_version'=>(string)$row->source_version,'viewer'=>['preview_available'=>$previewUrl!==null,'preview_url'=>$previewUrl]]; }
    /** @param list<int> $documentIds @return array<int,string|null> */ private function previewUrls(EstimateGenerationSession $session,array $documentIds,User $user): array { if($documentIds===[])return []; return EstimateGenerationDocument::query()->with('session')->where('organization_id',$session->organization_id)->where('project_id',$session->project_id)->where('session_id',$session->getKey())->whereIn('id',$documentIds)->get()->mapWithKeys(fn(EstimateGenerationDocument $document):array=>[(int)$document->getKey()=> $this->previews->forDocument($document,$user)])->all(); }
    private function sheet(stdClass $row): array { return ['id'=>(int)$row->id,'document_id'=>(int)$row->document_id,'page_number'=>(int)$row->page_number,'status'=>(string)$row->status]; }
    private function summary(array $entities): array { $counts=['total'=>count($entities),'confirmed'=>0,'needs_action'=>0,'unconfirmed'=>0,'conflict'=>0]; foreach($entities as $entity) $counts[$entity['status']]++; $counts['actionable']=$counts['needs_action']+$counts['unconfirmed']+$counts['conflict']; return $counts; }
    private function sourceLabel(string $source): string { return ['manual_correction'=>'Ручная правка','cad'=>'CAD-чертёж','table'=>'Таблица','explicit_dimension'=>'Явный размер','reconciled_geometry'=>'Согласованная геометрия','ai_candidate'=>'AI-кандидат'][$source]??'Источник'; }
    private function confidence(mixed $value): string { return number_format(max(0,min(1,(float)$value)),6,'.',''); }
    private function json(mixed $value): array { $value=is_array($value)?$value:(is_string($value)?json_decode($value,true):[]); return is_array($value)&&!array_is_list($value)?$value:[]; }
    private function safePayload(array $payload): array { unset($payload['storage_path'],$payload['raw_locator'],$payload['locator'],$payload['path']); return $payload; }
    private function cursor(mixed $raw): ?string { if(!is_string($raw)||$raw==='') return null; $value=base64_decode(strtr($raw,'-_','+/'),true); $decoded=is_string($value)?json_decode($value,true):null; return is_array($decoded)&&is_string($decoded['k']??null)&&preg_match('/^[a-z][a-z0-9:_-]{0,191}$/',$decoded['k'])===1?$decoded['k']:null; }
    private function encodeCursor(string $key): string { return rtrim(strtr(base64_encode(json_encode(['k'=>$key],JSON_THROW_ON_ERROR)),'+/','-_'),'='); }
    private function escapeLike(string $value): string { return str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$value); }
    private function empty(int $stateVersion, ?int $requestedVersion): array { return ['state_version'=>$stateVersion,'content_version'=>null,'stale'=>$requestedVersion!==null&&$requestedVersion!==$stateVersion,'summary'=>['total'=>0,'confirmed'=>0,'needs_action'=>0,'unconfirmed'=>0,'conflict'=>0,'actionable'=>0],'documents'=>[],'sheets'=>[],'entities'=>[],'page'=>['per_page'=>50,'next_cursor'=>null,'has_more'=>false]]; }
}
