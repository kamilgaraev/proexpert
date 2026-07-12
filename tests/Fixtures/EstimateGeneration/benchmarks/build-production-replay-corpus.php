<?php
declare(strict_types=1);
require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedPort;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedBenchmarkCatalogData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedCatalogNormativeCandidateSource;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelAssembler;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\GeometryBuildingModelInputMapper;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\GeometryConfirmationData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedPortRequestHasher;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateDecisionContextData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateSetData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeRerankResultData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeHardGate;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeMatchingWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRetrievalService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeWorkIntentFactory;
use App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlanCompiler;
use App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlannerResponseData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\BuildingQuantityCalculator;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\NormalizedBuildingModelQuantityInputMapper;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking\NormativeCandidateRerankerInterface;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
use Tests\Support\EstimateGeneration\RecordedFixtureCaptureBuilder;
use Tests\Support\EstimateGeneration\RecordedVisionSourceTraceVerifier;

final class CapturingReranker implements NormativeCandidateRerankerInterface
{
 public WorkIntentData $intent;
 public NormativeCandidateDecisionContextData $context;
 public NormativeCandidateSetData $set;
 public array $payload=[];
 public function __construct(private readonly array $recordedDecision) {}
 public function rerank(WorkIntentData $workItem,NormativeCandidateDecisionContextData $context,NormativeCandidateSetData $candidateSet):NormativeRerankResultData
 {
  $this->intent=$workItem;$this->context=$context;$this->set=$candidateSet;
  $ids=array_map(static fn($candidate):string=>$candidate->id,$candidateSet->candidates);
  $selected=(string)($this->recordedDecision['selected_candidate_id']??'');
  $ordering=$this->recordedDecision['ordering']??[];
  if(!in_array($selected,$ids,true)||array_diff($ordering,$ids)!==[]||array_diff($ids,$ordering)!==[]){throw new RuntimeException('recorded reranker decision does not match candidate set');}
  $evidence=array_values(array_unique([...$workItem->sourceEvidence,...($this->recordedDecision['evidence_refs']??[])]));
  $this->payload=[...$this->recordedDecision,'evidence_refs'=>$evidence,'schema_version'=>'normative-rerank-v1'];
  return NormativeRerankResultData::fromProviderArray($this->payload,$ids,$evidence,'capture');
 }
}

$root = __DIR__;
$manifestSha = getenv('BENCHMARK_MANIFEST_SHA256') ?: str_repeat('0', 64);
$builder = new RecordedFixtureCaptureBuilder;
$specs = [
 ['vector-pdf-001', BenchmarkSourceType::VectorPdf, 'input.pdf', vectorPdf(), RecordedPort::DocumentExtraction, 'vector_pdf'],
 ['scanned-pdf-001', BenchmarkSourceType::ScannedPdf, 'input.pdf', scannedPdf(), RecordedPort::VisionExtraction, 'scanned_pdf'],
 ['dwg-layout-001', BenchmarkSourceType::Dwg, 'input.dwg', dwg(), RecordedPort::CadExtraction, 'dwg'],
 ['dimensioned-raster-001', BenchmarkSourceType::PhotoPlan, 'input.ppm', raster(), RecordedPort::VisionExtraction, 'dimensioned_raster'],
 ['freehand-review-001', BenchmarkSourceType::UndimensionedSketch, 'input.svg', freehand(), RecordedPort::VisionExtraction, 'freehand'],
 ['engineering-layout-001', BenchmarkSourceType::DimensionedSketch, 'input.svg', engineering(), RecordedPort::VisionExtraction, 'engineering'],
];
$inventory = [];
$recordingDescriptors = [];
foreach ($specs as [$slug, $type, $filename, $source, $port, $intent]) {
 if (($only=getenv('BUILD_PRODUCTION_REPLAY_CASE'))!==false && $only!=='' && $only!==$slug) { continue; }
 $id = 'reg-replay-'.$slug;
 $directory = "$root/regression/replay-$slug";
 is_dir($directory) || mkdir($directory, 0777, true);
 file_put_contents("$directory/$filename", $source);
 $sha = hash('sha256', $source);
 $case = new BenchmarkPredictionCaseData($id, BenchmarkDatasetType::Regression, $type,
  "regression/replay-$slug/$filename", $sha, ['production-replay', $intent],
  ['document_understanding', $port === RecordedPort::VisionExtraction ? 'vision' : 'geometry'], [], []);
 $payload = match ($type) {
  BenchmarkSourceType::VectorPdf => captureVectorPdf("$directory/$filename"),
  BenchmarkSourceType::Dwg => captureDwg("$directory/$filename"),
 default => visionPayload($intent, $sha),
 };
 if (in_array($intent, ['scanned_pdf', 'dimensioned_raster', 'engineering'], true)) {
  $polygons = ['scanned_pdf'=>[[0.12,0.16],[0.82,0.16],[0.82,0.72],[0.12,0.72]], 'dimensioned_raster'=>[[0.15,0.18],[0.85,0.18],[0.85,0.78],[0.15,0.78]], 'engineering'=>[[0.0875,0.12],[0.9,0.12],[0.9,0.86],[0.0875,0.86]]];
  $payload['elements'][0]['polygon'] = [$polygons[$intent][0], $polygons[$intent][1]];
 $payload['elements'][1]['polygon'] = $polygons[$intent];
 }
 if (in_array($intent, ['scanned_pdf', 'dimensioned_raster'], true)) {
  foreach ($payload['evidence'] as &$evidence) $evidence['locator']['coordinate_space'] = 'source_pixels_v1'; unset($evidence);
  $payload['elements'][0]['polygon'] = [[40,40],[360,40]];
  $payload['elements'][1]['polygon'] = [[40,40],[360,40],[360,260],[40,260]];
  $payload['scale_candidates'] = [['source'=>'dimension_text','meters_per_unit'=>0.025,'confidence'=>1.0,'evidence_ref'=>$payload['evidence'][0]['key'],'detail'=>'visible_dimension'],['source'=>'dimension_text','meters_per_unit'=>0.025,'confidence'=>1.0,'evidence_ref'=>$payload['evidence'][1]['key'],'detail'=>'visible_dimension']];
 }
 if ($intent === 'engineering') {
  foreach ($payload['evidence'] as &$evidence) $evidence['locator']['coordinate_space'] = 'source_units_v1'; unset($evidence);
  $payload['elements'][0]['polygon'] = [[70,60],[720,60]];
  $payload['elements'][1]['polygon'] = [[70,60],[720,60],[720,430],[70,430]];
  $payload['evidence'][] = ['key'=>'riser-110','locator'=>$payload['evidence'][0]['locator']];
  $payload['elements'][] = ['key'=>'engineering-riser-110','type'=>'engineering_element','label'=>'sewer_route','polygon'=>[[180,80],[180,410]],'confidence'=>1.0,'evidence_ref'=>'riser-110'];
  $payload['evidence'][] = ['key'=>'door-opening','locator'=>$payload['evidence'][0]['locator']];
  $payload['evidence'][] = ['key'=>'dimension-width','locator'=>$payload['evidence'][0]['locator']];
  $payload['evidence'][] = ['key'=>'dimension-height','locator'=>$payload['evidence'][0]['locator']];
  $payload['elements'][] = ['key'=>'engineering-door','type'=>'opening','label'=>'Дверной проём','polygon'=>[[350,60],[440,60]],'confidence'=>1.0,'evidence_ref'=>'door-opening','geometry'=>['wall_key'=>'engineering-wall','opening_type'=>'door','offset'=>280,'width'=>90,'height'=>210]];
  $payload['scale_candidates'] = [['source'=>'dimension_text','meters_per_unit'=>0.01,'confidence'=>1.0,'evidence_ref'=>'dimension-width','detail'=>'visible_dimension'],['source'=>'dimension_text','meters_per_unit'=>0.01,'confidence'=>1.0,'evidence_ref'=>'dimension-height','detail'=>'visible_dimension']];
 }
 if ($intent === 'freehand') {
  $payload['evidence'][0]['key'] = 'freehand-evidence';
  $payload['elements'][0]['evidence_ref'] = 'freehand-evidence';
  $payload['elements'][0]['polygon'] = [[0.116667,0.225],[0.85,0.1925],[0.875,0.825],[0.136667,0.8625]];
  $payload['evidence'][] = ['key'=>'uncertain-divider','locator'=>$payload['evidence'][0]['locator']];
  $payload['evidence'][] = ['key'=>'freehand-opening','locator'=>$payload['evidence'][0]['locator']];
  $payload['warnings'] = ['scale_missing','geometry_incomplete'];
 }
 if ($port === RecordedPort::VisionExtraction) {
  (new RecordedVisionSourceTraceVerifier)->verify(visionFormat($intent), $source, $payload, visionTrace($intent, $sha));
 }
 $metadata = [
  'schema_version' => 1, 'port' => $port->value, 'source_sha256' => $sha,
  'provider' => $type === BenchmarkSourceType::Dwg ? 'libredwg-independent-capture' : 'fixture-independent-capture',
  'model_version' => 'corpus-capture-2026-07', 'prompt_version' => 'geometry-capture:v1',
  'payload_schema_version' => $port === RecordedPort::VisionExtraction ? 'vision-analysis:v1' : 'vector-geometry:v1',
  'privacy_scanner' => 'most-fixture-privacy', 'privacy_scanner_version' => '1.0.0',
  'capture_kind' => 'contract_fixture', 'approval_kind' => 'maintainer_code_review',
  'approval_ref' => 'plan3-task11-corpus-v1', 'approved_at' => '2026-07-12T00:00:00Z',
  'manifest_sha256' => $manifestSha, 'privacy_result' => 'passed',
 ];
 $envelope = $builder->envelope($metadata, $payload, $builder->geometryDependency($case, $port, $source), $sha);
 $recording = "recordings/$slug-geometry.json";
 writeJson("$root/$recording", $envelope);
 if ($port === RecordedPort::VisionExtraction) writeJson("$root/recordings/$slug-source-trace.json", visionTrace($intent, $sha));
 if ($type === BenchmarkSourceType::Dwg) writeJson("$root/recordings/$slug-parser-proof.json", parserProof($source, $payload));
 $recordingDescriptors[] = ['case_id'=>$id,'port'=>$port->value,'locator'=>$recording,'sha256'=>hash_file('sha256',"$root/$recording")];
 $confirmationPayload = in_array($type, [BenchmarkSourceType::VectorPdf, BenchmarkSourceType::Dwg], true)
  ? geometryConfirmation($slug, $payload) : null;
 if (is_array($confirmationPayload)) {
  $confirmationMeta = [...$metadata, 'port'=>RecordedPort::GeometryConfirmation->value,
   'provider'=>'maintainer-confirmation-capture', 'model_version'=>'geometry-confirmation-2026-07',
   'prompt_version'=>'not-applicable:user-review', 'payload_schema_version'=>'geometry-confirmation:v1'];
  $confirmationEnvelope = $builder->envelope($confirmationMeta, $confirmationPayload,
   RecordedPortRequestHasher::geometryConfirmation($case, $envelope['payload_sha256'], $confirmationPayload), $sha);
  $confirmationRecording = "recordings/$slug-geometry-confirmation.json";
  writeJson("$root/$confirmationRecording", $confirmationEnvelope);
  $recordingDescriptors[] = ['case_id'=>$id,'port'=>RecordedPort::GeometryConfirmation->value,
   'locator'=>$confirmationRecording,'sha256'=>hash_file('sha256',"$root/$confirmationRecording")];
 }
 $caseSpec = authoredCaseSpec($slug);
 $catalog = authoredCatalog($caseSpec);
 $candidateIds = array_column($catalog['candidates'],'candidate_id');
 $catalogRef = "catalogs/$slug.json";
 writeJson("$root/$catalogRef", $catalog);
 if (getenv('BUILD_PRODUCTION_REPLAY_DOWNSTREAM') === '1' && $intent !== 'freehand') {
  [$model, $quantities, $evidence] = productionGeometry($payload, $port, $confirmationPayload);
  $quantity = $quantities->get($caseSpec['quantity_key']) ?? throw new RuntimeException(
   $caseSpec['quantity_key'].' missing; available: '.implode(', ', array_column($quantities->toArray()['quantities'], 'key'))
  );
  $quantityRefs = array_map('strval', $quantity->evidenceIds);
  $workKey = $caseSpec['work_key'];
  $plannerPayload = ['schema_version'=>'work-planner-v1','sections'=>[['section_key'=>$caseSpec['section_key'],'title'=>$caseSpec['section_title'],
   'scope_type'=>$caseSpec['scope_type'],'source_refs'=>$quantityRefs,'work_intents'=>[['intent_key'=>$workKey,
   'quantity_key'=>$caseSpec['quantity_key'],'name'=>$caseSpec['work_name'],'category'=>$caseSpec['scope_type'],'unit'=>$caseSpec['unit'],
   'quantity'=>$quantity->amount,'quantity_source_refs'=>$quantityRefs,'confidence'=>0.95,'work_intent'=>$caseSpec['work_intent']]]]]];
  $plannerMeta = [...$metadata, 'port'=>RecordedPort::WorkPlanningModel->value, 'provider'=>'planner-independent-capture',
   'model_version'=>'planner-model-2026-07','prompt_version'=>'planner-prompt:v1','payload_schema_version'=>'work-planner-v1'];
  $plannerEnvelope = $builder->envelope($plannerMeta, $plannerPayload,
   $builder->plannerDependency($model->toArray(), $quantities->toArray(), $evidence), $sha);
  $plannerRecording = "recordings/$slug-planner.json";
  writeJson("$root/$plannerRecording", $plannerEnvelope);
  $recordingDescriptors[]=['case_id'=>$id,'port'=>RecordedPort::WorkPlanningModel->value,'locator'=>$plannerRecording,
   'sha256'=>hash_file('sha256',"$root/$plannerRecording")];

  $capture = new CapturingReranker($caseSpec['reranker_decision']);
  $retrieval = new NormativeRetrievalService(
   new RecordedCatalogNormativeCandidateSource(RecordedBenchmarkCatalogData::fromArray($catalog)), new NormativeHardGate, 16, null);
  $workflow = new NormativeMatchingWorkflow($retrieval, $capture);
  $plan = app(WorkPlanCompiler::class)->compile(productionAnalysis($model->toArray(), $quantities->toArray(), $catalog),
   new WorkPlannerResponseData($plannerPayload['sections']));
  $item = $plan['local_estimates'][0]['sections'][0]['work_items'][0];
  $context=['organization_id'=>1,'project_id'=>1,'session_id'=>1,'checkpoint_claim_token'=>'018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c',
   'input_version'=>'sha256:'.$sha,'logical_attempt'=>1,'scope_type'=>$caseSpec['scope_type'],'local_estimate_title'=>$plan['local_estimates'][0]['title'],
   'section_title'=>$plan['local_estimates'][0]['sections'][0]['title'],'source_refs'=>$quantityRefs,
   'regional_context'=>productionAnalysis($model->toArray(), $quantities->toArray(), $catalog)['regional_context'],'applicability_date'=>'2026-07-12'];
  $factory=app(NormativeWorkIntentFactory::class);
  $capturedIntent=$factory->intent($item,$context,$catalog['dataset_version']);
  $workflowResult = $workflow->match($capturedIntent,$factory->decision($item,$context),true);
  if ($capture->payload === []) {
   throw new RuntimeException('reranker not invoked: '.json_encode([
    'status'=>$workflowResult->status,
    'blocking'=>$workflowResult->blockingIssues,
    'intent'=>get_object_vars($capturedIntent),
    'candidate_ids'=>array_map(static fn($candidate):string=>$candidate->id,$workflowResult->candidateSet->candidates),
    'rejected'=>array_map(static fn($candidate):array=>['id'=>$candidate->candidate->id,'reasons'=>$candidate->reasonCodes],$workflowResult->candidateSet->rejected),
   ], JSON_THROW_ON_ERROR));
  }
  $rerankerMeta=[...$metadata,'port'=>RecordedPort::NormativeReranker->value,'provider'=>'reranker-independent-capture',
   'model_version'=>'reranker-model-2026-07','prompt_version'=>'normative-rerank-prompt-v1','payload_schema_version'=>'normative-rerank-v1'];
  $rerankerEnvelope=$builder->envelope($rerankerMeta,$capture->payload,
   $builder->rerankerDependency($capture->intent,$capture->context,$capture->set),$sha);
  $rerankerRecording="recordings/$slug-reranker.json";
  writeJson("$root/$rerankerRecording",$rerankerEnvelope);
  $recordingDescriptors[]=['case_id'=>$id,'port'=>RecordedPort::NormativeReranker->value,'locator'=>$rerankerRecording,
   'sha256'=>hash_file('sha256',"$root/$rerankerRecording")];
 }
 $projection = [
  'schema_version' => 'recorded-replay-projection:v1', 'case_id' => $id, 'input_sha256' => $sha,
  'envelopes' => array_filter([
   $port->value => ['locator' => $recording, 'sha256' => hash_file('sha256', "$root/$recording")],
   RecordedPort::GeometryConfirmation->value => isset($confirmationRecording) ? ['locator'=>$confirmationRecording,'sha256'=>hash_file('sha256',"$root/$confirmationRecording")] : null,
   RecordedPort::WorkPlanningModel->value => isset($plannerRecording) ? ['locator'=>$plannerRecording,'sha256'=>hash_file('sha256',"$root/$plannerRecording")] : null,
   RecordedPort::NormativeReranker->value => isset($rerankerRecording) ? ['locator'=>$rerankerRecording,'sha256'=>hash_file('sha256',"$root/$rerankerRecording")] : null,
  ]),
  'catalog' => ['locator' => $catalogRef, 'sha256' => hash_file('sha256', "$root/$catalogRef")],
  'recording_manifest_sha256' => $manifestSha,
 ];
 $projectionRef = "projections/$slug.json";
 writeJson("$root/$projectionRef", $projection);
 $inventory[] = ['id'=>$id,'slug'=>$slug,'source_type'=>$type->value,'input_locator'=>"regression/replay-$slug/$filename",
  'input_sha256'=>$sha,'projection'=>$projectionRef,'projection_sha256'=>hash_file('sha256',"$root/$projectionRef")];
 unset($plannerRecording, $rerankerRecording, $confirmationRecording);
}
writeJson("$root/production-replay-corpus-inventory.json", ['schema_version'=>1,'cases'=>$inventory]);
refreshBaselineCatalog($root, $builder, [
 'case_id'=>'reg-replay-vector-wall-opening-001','slug'=>'vector','geometry'=>'vector-geometry.json','planner'=>'vector-planner.json',
 'reranker'=>'vector-reranker.json','catalog'=>'vector-wall-opening-v1.json','projection'=>'vector-wall-opening-v1.json',
 'port'=>RecordedPort::CadExtraction,'selected'=>'vector-floor-cast-b25','other'=>'vector-floor-cast-b30',
 'selected_name'=>'Устройство бетонного покрытия пола из смеси B25','other_name'=>'Устройство бетонного покрытия пола из смеси B30',
 'selected_resource_name'=>'Смесь бетонная B25','other_resource_name'=>'Смесь бетонная B30',
 'selected_evidence'=>'catalog:vector:cast-b25','other_evidence'=>'catalog:vector:cast-b30',
]);
refreshBaselineCatalog($root, $builder, [
 'case_id'=>'reg-replay-vision-sketch-001','slug'=>'vision','geometry'=>'vision-geometry.json','planner'=>'vision-planner.json',
 'reranker'=>'vision-reranker.json','catalog'=>'vision-sketch-v1.json','projection'=>'vision-sketch-v1.json',
 'port'=>RecordedPort::VisionExtraction,'selected'=>'vision-floor-cast-b25','other'=>'vision-floor-cast-fiber',
 'selected_name'=>'Устройство бетонного покрытия пола из смеси B25','other_name'=>'Устройство фибробетонного покрытия пола',
 'selected_resource_name'=>'Смесь бетонная B25','other_resource_name'=>'Смесь фибробетонная',
 'selected_evidence'=>'catalog:vision:cast-b25','other_evidence'=>'catalog:vision:cast-fiber',
]);
$recordingManifest=json_decode((string)file_get_contents("$root/recordings/manifest.json"),true,32,JSON_THROW_ON_ERROR);
$recordingManifest['fixtures']=array_map(static function(array $row)use($root):array{
 $path="$root/{$row['locator']}";
 return is_file($path)?[...$row,'sha256'=>hash_file('sha256',$path)]:$row;
},$recordingManifest['fixtures']);
$recordingManifest['fixtures']=array_values(array_filter($recordingManifest['fixtures'],static fn(array $row):bool=>!str_starts_with($row['case_id'],'reg-replay-')||in_array($row['case_id'],['reg-replay-vector-wall-opening-001','reg-replay-vision-sketch-001'],true)));
$recordingManifest['fixtures']=[...$recordingManifest['fixtures'],...$recordingDescriptors];
writeJson("$root/recordings/manifest.json",$recordingManifest);

function writeJson(string $path, array $data): void { file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)."\n"); }

function refreshBaselineCatalog(string $root, RecordedFixtureCaptureBuilder $builder, array $spec): void
{
 $catalogPath="$root/catalogs/{$spec['catalog']}";
 $catalog=json_decode((string)file_get_contents($catalogPath),true,64,JSON_THROW_ON_ERROR);
 foreach($catalog['candidates'] as &$candidate){
  if($candidate['candidate_id']===$spec['selected'])$candidate['name']=$spec['selected_name'];
  if($candidate['candidate_id']===$spec['other'])$candidate['name']=$spec['other_name'];
 }unset($candidate);
 foreach($catalog['resources'] as &$resource){
 if($resource['candidate_id']===$spec['selected'])$resource['name']=$spec['selected_name'];
 if($resource['candidate_id']===$spec['other'])$resource['name']=$spec['other_name'];
  $resource['resources']['materials'][0]['name']=$resource['candidate_id']===$spec['selected']
   ?$spec['selected_resource_name']:$spec['other_resource_name'];
 }unset($resource);
 writeJson($catalogPath,$catalog);

 $geometry=json_decode((string)file_get_contents("$root/recordings/{$spec['geometry']}"),true,64,JSON_THROW_ON_ERROR);
 [$model,$quantities,$evidence]=productionGeometry($geometry['payload'],$spec['port']);
 $planner=json_decode((string)file_get_contents("$root/recordings/{$spec['planner']}"),true,64,JSON_THROW_ON_ERROR);
 $plan=app(WorkPlanCompiler::class)->compile(productionAnalysis($model->toArray(),$quantities->toArray(),$catalog),
  new WorkPlannerResponseData($planner['payload']['sections']));
 $item=$plan['local_estimates'][0]['sections'][0]['work_items'][0];
 $quantityKey=$item['metadata']['quantity_key'];$quantity=$quantities->get($quantityKey)??throw new RuntimeException('baseline quantity missing');
 $refs=array_map('strval',$quantity->evidenceIds);$sha=$geometry['source_sha256'];
 $context=['organization_id'=>1,'project_id'=>1,'session_id'=>1,'checkpoint_claim_token'=>'018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c',
  'input_version'=>'sha256:'.$sha,'logical_attempt'=>1,'scope_type'=>'finishing','local_estimate_title'=>$plan['local_estimates'][0]['title'],
  'section_title'=>$plan['local_estimates'][0]['sections'][0]['title'],'source_refs'=>$refs,
  'regional_context'=>productionAnalysis($model->toArray(),$quantities->toArray(),$catalog)['regional_context'],'applicability_date'=>'2026-07-12'];
 $factory=app(NormativeWorkIntentFactory::class);$intent=$factory->intent($item,$context,$catalog['dataset_version']);
 $decision=$factory->decision($item,$context);$retrieval=new NormativeRetrievalService(
  new RecordedCatalogNormativeCandidateSource(RecordedBenchmarkCatalogData::fromArray($catalog)),new NormativeHardGate,16,null);
 $set=$retrieval->retrieve($intent);
 if(count($set->candidates)!==2)throw new RuntimeException('baseline candidate set invalid');
 $rerankerPath="$root/recordings/{$spec['reranker']}";
 $reranker=json_decode((string)file_get_contents($rerankerPath),true,64,JSON_THROW_ON_ERROR);
 $reranker['payload']['selected_candidate_id']=$spec['selected'];
 $reranker['payload']['ordering']=[$spec['selected'],$spec['other']];
 $reranker['payload']['evidence_refs']=array_values(array_unique([...$refs,$spec['selected_evidence']]));
 $dependency=$builder->rerankerDependency($intent,$decision,$set);
 $payload=$reranker['payload'];
 unset($reranker['input_dependency_sha256'],$reranker['payload'],$reranker['payload_sha256']);
 $reranker=$builder->envelope($reranker,$payload,$dependency,$geometry['source_sha256']);
 writeJson($rerankerPath,$reranker);
 $projectionPath="$root/projections/{$spec['projection']}";
 $projection=json_decode((string)file_get_contents($projectionPath),true,32,JSON_THROW_ON_ERROR);
 $projection['catalog']['sha256']=hash_file('sha256',$catalogPath);
 $projection['envelopes'][RecordedPort::NormativeReranker->value]['sha256']=hash_file('sha256',$rerankerPath);
 writeJson($projectionPath,$projection);
}
function visionPayload(string $intent,string $sha): array { $wall="$intent-wall-evidence";$room="$intent-room-evidence";$locator=['page_id'=>1,'page_number'=>1,'processing_unit_id'=>1,'source_version'=>"sha256:$sha",'coordinate_space'=>'normalized_source_v1'];$evidence=[['key'=>$wall,'locator'=>$locator]];$elements=[['key'=>"$intent-wall",'type'=>'wall','label'=>null,'polygon'=>[[0.1,0.1],[0.7,0.1]],'confidence'=>$intent==='freehand'?0.62:0.95,'evidence_ref'=>$wall]];if($intent!=='freehand'){$evidence[]=['key'=>$room,'locator'=>$locator];$elements[]=['key'=>"$intent-room",'type'=>'room','label'=>'Комната','polygon'=>[[0.1,0.1],[0.7,0.1],[0.7,0.5],[0.1,0.5]],'confidence'=>0.96,'evidence_ref'=>$room];}return ['schema_version'=>1,'sheet_type'=>'floor_plan','evidence'=>$evidence,'elements'=>$elements,'scale_candidates'=>$intent==='freehand'?[]:[['source'=>'dimension_text','meters_per_unit'=>10.0,'confidence'=>0.99,'evidence_ref'=>$wall,'detail'=>'visible_dimension'],['source'=>'manual_reference','meters_per_unit'=>10.0,'confidence'=>1.0,'evidence_ref'=>$room,'detail'=>'confirmed_control_dimension']],'warnings'=>$intent==='freehand'?['scale_missing']:[]]; }
function vectorPdf(): string { $s="2 w\n60 650 m 260 650 l S\n320 650 m 500 650 l 500 360 l 60 360 l 60 650 l S\n90 610 m 230 610 l 230 410 l 90 410 l h S\n60 500 m 260 500 l 260 650 l S\n55 680 m 505 680 l S\n55 675 m 55 685 l S\n505 675 m 505 685 l S\nBT /F1 14 Tf 235 700 Td (4400 mm) Tj ET\n530 355 m 530 655 l S\n525 355 m 535 355 l S\n525 655 m 535 655 l S\nBT /F1 14 Tf 540 490 Td (2900 mm) Tj ET\nBT /F1 12 Tf 270 665 Td (OPENING 600x2100 mm) Tj ET\nBT /F1 16 Tf 120 520 Td (ROOM A) Tj ET\n"; return makePdf(["<< /Type /Catalog /Pages 2 0 R >>","<< /Type /Pages /Kids [3 0 R] /Count 1 >>","<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>","<< /Length ".strlen($s)." >>\nstream\n$s"."endstream","<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>"]); }
function scannedPdf(): string { $i=planPixels(400,300,true); $s="q 500 0 0 375 45 300 cm /Im0 Do Q\n"; return makePdf(["<< /Type /Catalog /Pages 2 0 R >>","<< /Type /Pages /Kids [3 0 R] /Count 1 >>","<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Im0 5 0 R >> >> /Contents 4 0 R >>","<< /Length ".strlen($s)." >>\nstream\n$s"."endstream","<< /Type /XObject /Subtype /Image /Width 400 /Height 300 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Length ".strlen($i)." >>\nstream\n$i\nendstream"]); }
function makePdf(array $objects): string { $out="%PDF-1.4\n";$offsets=[];foreach($objects as $n=>$o){$offsets[]=strlen($out);$out.=($n+1)." 0 obj\n$o\nendobj\n";}$xref=strlen($out);$out.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";foreach($offsets as $offset){$out.=sprintf('%010d 00000 n ',$offset)."\n";}return $out."trailer << /Size ".(count($objects)+1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n"; }
function raster(): string { return "P6\n400 300\n255\n".planPixels(400,300,false); }
function planPixels(int $w,int $h,bool $scan): string {$p=str_repeat("\xff\xff\xff",$w*$h);$black=function(int $x,int $y)use(&$p,$w,$h):void{if($x<0||$y<0||$x>=$w||$y>=$h)return;$o=($y*$w+$x)*3;$p[$o]=$p[$o+1]=$p[$o+2]="\0";};for($t=0;$t<5;$t++){for($x=40;$x<=360;$x++){if($x<180||$x>220)$black($x,40+$t);$black($x,256+$t);}for($y=40;$y<=260;$y++){$black(40+$t,$y);$black(356+$t,$y);}}for($x=40;$x<=360;$x++)$black($x,282);for($y=275;$y<=289;$y++){$black(40,$y);$black(360,$y);}for($y=40;$y<=260;$y++)$black(382,$y);for($x=375;$x<=389;$x++){$black($x,40);$black($x,260);}drawBitmapText($black,$scan?130:120,270,'8.0 m');drawBitmapText($black,300,125,'5.5 m');return $p;}
function bitmapFont(): array {return ['8'=>['111','101','111','101','111'],'5'=>['111','100','111','001','111'],'0'=>['111','101','101','101','111'],'.'=>['0','0','0','0','1'],'m'=>['00000','11011','10101','10101','10101'],' '=>['0','0','0','0','0']];}
function bitmapPoints(string $text): array {$points=[];$cursor=0;foreach(str_split($text) as $char){$rows=bitmapFont()[$char];$width=strlen($rows[0]);foreach($rows as $y=>$row)foreach(str_split($row) as $x=>$on)if($on==='1')$points[]=[$cursor+$x,$y];$cursor+=$width+1;}return $points;}
function drawBitmapText(Closure $black,int $x,int $y,string $text): void {foreach(bitmapPoints($text) as [$dx,$dy])for($sy=0;$sy<2;$sy++)for($sx=0;$sx<2;$sx++)$black($x+$dx*2+$sx,$y+$dy*2+$sy);}
function freehand(): string { return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 400"><title>Неуверенный эскиз</title><path id="uncertain-outline" d="M70 90 L510 77 L525 330 L82 345 Z" fill="none" stroke="black" stroke-width="7"/><path id="uncertain-divider" d="M80 210 Q260 180 520 220" fill="none" stroke="black" stroke-width="5"/><path id="freehand-opening" d="M265 82 L330 80" fill="none" stroke="red" stroke-width="9"/><text id="review-question" x="245" y="375" font-size="26">? размер</text></svg>'; }
function engineering(): string { return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 500"><title>Инженерный план</title><rect id="room-outline" x="70" y="60" width="650" height="370" fill="none" stroke="black" stroke-width="4"/><path id="door-opening" d="M350 60 h90" stroke="white" stroke-width="10"/><line id="riser-110" x1="180" y1="80" x2="180" y2="410" stroke="blue" stroke-width="8"/><circle id="riser-node" cx="180" cy="245" r="24" fill="none" stroke="blue" stroke-width="6"/><text id="riser-label" x="215" y="250" font-size="26">Стояк 110</text><text id="dimension-width" x="300" y="470" font-size="22">6500 mm</text><text id="dimension-height" x="730" y="250" font-size="22">3700 mm</text></svg>'; }
function dwg(): string { $b=@file_get_contents(dirname(__DIR__).'/Vision/simple-house.dwg');if(is_string($b))return $b;throw new RuntimeException('dwg fixture missing'); }
function runCapture(array $command): array {$json=shell_exec(implode(' ',array_map('escapeshellarg',$command)));if(!is_string($json)||$json==='')throw new RuntimeException('geometry capture failed');return json_decode($json,true,128,JSON_THROW_ON_ERROR);}
function captureVectorPdf(string $path): array {return runCapture(['python',dirname(__DIR__,4).'/app/BusinessModules/Addons/EstimateGeneration/bin/pdf_geometry_extract.py','--input',$path,'--workspace',dirname($path),'--contract-vector']);}
function captureDwg(string $path): array {$binary=getenv('LIBREDWG_DWGREAD_BINARY')?:getenv('USERPROFILE').'/.cache/most-libredwg/0.13.4/win64/dwgread.exe';return runCapture(['python',dirname(__DIR__,4).'/app/BusinessModules/Addons/EstimateGeneration/bin/cad_geometry_extract.py','--input',$path,'--workspace',dirname($path),'--dwgread',$binary]);}
function parserProof(string $source,array $payload): array {$canonical=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);return ['schema_version'=>1,'source_sha256'=>hash('sha256',$source),'runtime_version'=>$payload['runtime_version'],'canonical_output_sha256'=>hash('sha256',$canonical),'entity_count'=>count($payload['entities']),'text_count'=>count($payload['texts']),'dimension_count'=>count($payload['dimensions'])];}
function visionFormat(string $intent): string {return match($intent){'scanned_pdf'=>'raster_pdf','dimensioned_raster'=>'ppm',default=>'svg'};}
function visionTrace(string $intent,string $sha): array {
 if(in_array($intent,['scanned_pdf','dimensioned_raster'],true))return ['source_sha256'=>$sha,'labels'=>[['bbox'=>[$intent==='scanned_pdf'?130:120,270,36,10],'text'=>'8.0 m'],['bbox'=>[300,125,36,10],'text'=>'5.5 m']],'room_polygon'=>[[40,40],[360,40],[360,260],[40,260]],'meters_per_unit'=>0.025];
 if($intent==='engineering')return ['source_sha256'=>$sha,'source_ids'=>['room-outline','door-opening','riser-110','riser-node','dimension-width','dimension-height'],'text'=>['dimension-width'=>'6500 mm','dimension-height'=>'3700 mm'],'evidence_ids'=>['riser-110','door-opening','dimension-width','dimension-height'],'element_points'=>['engineering-riser-110'=>[[180,80],[180,410]],'engineering-door'=>[[350,60],[440,60]]]];
 return ['source_sha256'=>$sha,'source_ids'=>['uncertain-outline','uncertain-divider','freehand-opening','review-question'],'text'=>['review-question'=>'? размер'],'attributes'=>['uncertain-outline'=>['d'=>'M70 90 L510 77 L525 330 L82 345 Z'],'uncertain-divider'=>['d'=>'M80 210 Q260 180 520 220'],'freehand-opening'=>['d'=>'M265 82 L330 80']],'evidence_ids'=>['freehand-evidence','uncertain-divider','freehand-opening'],'element_points'=>['freehand-wall'=>[[0.116667,0.225],[0.85,0.1925],[0.875,0.825],[0.136667,0.8625]]]];
}

function productionGeometry(array $payload, RecordedPort $port, ?array $confirmationPayload = null): array
{
 $vision=$port===RecordedPort::VisionExtraction
  ? VisionAnalysisData::fromProviderArray($payload,'fixture-independent-capture','corpus-capture-2026-07','corpus-capture-2026-07','vision-analysis:v1','unavailable',null,null,500)
  : null;
 $vector=$port!==RecordedPort::VisionExtraction ? VectorGeometryData::fromArray($payload) : null;
 $refs=[];
 if($vision!==null){foreach($vision->evidence as $row)$refs[]=$row->key;}
 if($vector!==null){foreach($vector->entities as $row)$refs[]='vector:'.$row['handle'];}
 $confirmation=$confirmationPayload!==null?GeometryConfirmationData::fromArray($confirmationPayload):null;
 if($confirmation!==null){foreach($confirmation->scaleEvidence as $item)$refs[]=($item['role']==='measured_segment'?'vector:':'confirmation:').($item['value_handle']??$item['entity_handle']);foreach($confirmation->elements as $element)if($element['type']==='opening')$refs[]='confirmation:'.$element['dimension_handle'];}
 $refs=array_values(array_unique($refs));sort($refs,SORT_STRING);$evidence=[];
 foreach($refs as $index=>$ref)$evidence[$ref]=$index+1;
 $assembled=(new BuildingModelAssembler)->assembleVision((new GeometryBuildingModelInputMapper)->map($vision,$vector,$evidence,'floor-1',$confirmation));
 if($assembled->clarifications!==[]||($assembled->model->metrics['complete']??false)!==true)throw new RuntimeException('generated geometry incomplete');
 $quantities=(new BuildingQuantityCalculator)->calculate((new NormalizedBuildingModelQuantityInputMapper)->map($assembled->model));
 return [$assembled->model,$quantities,$evidence];
}

function geometryConfirmation(string $slug, array $payload): array
{
 $vector=VectorGeometryData::fromArray($payload);
 $base=['schema_version'=>1,'source_fingerprint'=>$vector->sourceFingerprint,
  'geometry_payload_sha256'=>$vector->payloadSha256()];
 return match($slug){
  'vector-pdf-001'=>[...$base,'scale_evidence'=>[
    ['role'=>'dimension','value_handle'=>'page:1:object:7','entity_handle'=>'page:1:object:1','point_indexes'=>[4,1]],
    ['role'=>'dimension','value_handle'=>'page:1:object:11','entity_handle'=>'page:1:object:1','point_indexes'=>[1,2]],
   ],'elements'=>[
    ['key'=>'vector-room-a','type'=>'room','boundary_handle'=>'page:1:object:2'],
    ['key'=>'vector-wall-top','type'=>'wall','segment_handles'=>['page:1:object:0']],
   ]],
  'dwg-layout-001'=>[...$base,'scale_evidence'=>[
    ['role'=>'measured_segment','entity_handle'=>'A1','point_indexes'=>[0,1],'real_world_value'=>12000,'unit'=>'mm'],
   ],'elements'=>[
    ['key'=>'dwg-room-a2','type'=>'room','boundary_handle'=>'A2'],
    ['key'=>'dwg-wall-a1','type'=>'wall','segment_handles'=>['A1']],
   ]],
  default=>throw new RuntimeException('geometry confirmation case unsupported'),
 };
}

function productionAnalysis(array $model,array $quantities,array $catalog): array
{
 $takeoffs=array_map(static fn(array $q):array=>['scope_key'=>$q['key'],'quantity_key'=>$q['key']==='floor_area'?'finish.floor':$q['key'],
  'normalized_payload'=>['quantity_key'=>$q['key']==='floor_area'?'finish.floor':$q['key']]],$quantities['quantities']);
 $price=$catalog['prices'][0];
 return ['object'=>['object_type'=>'floor_plan_geometry','description'=>'floor plan','area'=>(float)($quantities['quantities'][0]['amount']??0)],
  'detected_structure'=>['scopes'=>[['scope_type'=>'finishing','title'=>'Finishing','source_refs'=>[]]]],
  'document_context'=>['quantity_takeoffs'=>$takeoffs],'building_model'=>$model,
  'regional_context'=>['dataset_id'=>$catalog['dataset_id'],'dataset_version'=>$catalog['dataset_version'],'region_code'=>$catalog['region_code'],
   'region_id'=>$price['region_id'],'price_zone_id'=>$price['price_zone_id'],'period_id'=>$price['period_id'],
   'price_version'=>$catalog['price_period'],'estimate_regional_price_version_id'=>$price['regional_price_version_id']],
  'generation_mode'=>'strict_documents'];
}

function authoredCaseSpec(string $slug): array
{
 $specs = [
  'vector-pdf-001'=>['dataset_id'=>1200,'version'=>'fsnb-2026.1-ceilings','work_key'=>'suspended-ceiling','work_name'=>'Монтаж подвесного потолка по бетонному перекрытию','quantity_key'=>'ceiling_area','unit'=>'m2','scope_type'=>'finishing','section_key'=>'ceilings','section_title'=>'Подвесные потолки','section'=>'15','candidates'=>[
   ['ceiling-gypsum-frame','12001','15-01-047-01','Устройство подвесного потолка из гипсокартонных листов по металлическому каркасу','concrete','ceiling_finishing','ceiling',0.96,0.98,'120001','01.6.01.01-1010','Листы гипсокартонные потолочные','m2','1.10','486.3700'],
   ['ceiling-mineral-grid','12002','15-01-050-02','Устройство подвесного потолка из минераловолокнистых плит','concrete','ceiling_finishing','ceiling',0.82,0.84,'120002','01.6.04.03-1020','Плиты минераловолокнистые потолочные','m2','1.05','593.8400'],
   ['ceiling-metal-cassette','12003','15-01-052-03','Устройство кассетного потолка из алюминиевых панелей','concrete','ceiling_finishing','ceiling',0.71,0.76,'120003','01.7.08.06-1040','Панели потолочные алюминиевые','m2','1.03','4175.2600']],'selected_candidate_id'=>'ceiling-gypsum-frame','recorded_ordering'=>['ceiling-gypsum-frame','ceiling-mineral-grid','ceiling-metal-cassette']],
  'scanned-pdf-001'=>['dataset_id'=>1201,'version'=>'fsnb-2026.1-floor-tiling','work_key'=>'ceramic-floor-tiling','work_name'=>'Облицовка бетонного пола керамической плиткой','quantity_key'=>'floor_area','unit'=>'m2','scope_type'=>'finishing','section_key'=>'floor-tiling','section_title'=>'Облицовка полов','section'=>'15','candidates'=>[
   ['tile-porcelain-rectified','12011','15-01-045-03','Облицовка полов керамогранитными плитами на клее','concrete','tiling','finishing',0.81,0.86,'120101','01.7.06.03-2010','Плиты керамогранитные ректифицированные','m2','1.04','4382.9100'],
   ['tile-ceramic-glazed','12012','15-01-045-01','Облицовка полов керамическими глазурованными плитками','concrete','tiling','finishing',0.97,0.98,'120102','01.7.06.01-2020','Плитка керамическая для пола','m2','1.03','12984.7300'],
   ['tile-clinker-floor','12013','15-01-046-02','Облицовка полов клинкерными плитками','concrete','tiling','finishing',0.78,0.82,'120103','01.7.06.04-2030','Плитка клинкерная напольная','m2','1.05','5126.4800']],'selected_candidate_id'=>'tile-ceramic-glazed','recorded_ordering'=>['tile-ceramic-glazed','tile-clinker-floor','tile-porcelain-rectified']],
  'dwg-layout-001'=>['dataset_id'=>1202,'version'=>'fsnb-2026.1-floors','work_key'=>'concrete-floor','work_name'=>'Устройство бетонного пола толщиной 100 мм','quantity_key'=>'floor_area','unit'=>'m2','scope_type'=>'floors','section_key'=>'concrete-floor','section_title'=>'Бетонные полы','section'=>'11','candidates'=>[
   ['floor-cement-screed','12021','11-01-011-01','Устройство цементно-песчаной стяжки','cement_mortar','screed','floor',0.83,0.86,'120201','01.7.03.04-3010','Раствор цементно-песчаный','m3','0.050','3894.6600'],
   ['floor-dry-screed','12022','11-01-018-02','Устройство сухой сборной стяжки пола','gypsum_fiber','dry_screed','floor',0.69,0.74,'120202','01.6.01.02-3020','Элементы пола гипсоволокнистые','m2','1.05','731.2900'],
   ['floor-concrete-b25','12023','11-01-002-04','Устройство бетонных полов из смеси B25','concrete','concrete_floor','floor',0.98,0.99,'120203','01.7.03.02-3030','Смесь бетонная B25','m3','0.102','5687.4200']],'selected_candidate_id'=>'floor-concrete-b25','recorded_ordering'=>['floor-concrete-b25','floor-dry-screed','floor-cement-screed']],
  'dimensioned-raster-001'=>['dataset_id'=>1203,'version'=>'fsnb-2026.1-screeds','work_key'=>'leveling-screed','work_name'=>'Устройство выравнивающей цементной стяжки из бетона B25','quantity_key'=>'floor_area','unit'=>'m2','scope_type'=>'floors','section_key'=>'screed','section_title'=>'Стяжки пола','section'=>'11','candidates'=>[
   ['screed-cement-40','12031','11-01-011-03','Устройство цементной стяжки толщиной 40 мм','cement_mortar','screed','floor',0.98,0.99,'120301','01.7.03.04-4010','Смесь сухая для стяжки','kg','72.0','9.8700'],
   ['screed-self-leveling','12032','11-01-019-01','Устройство наливного выравнивающего покрытия','dry_mix','self_leveling','floor',0.84,0.88,'120302','01.7.03.05-4020','Смесь для наливного пола','kg','18.0','31.4600'],
   ['screed-lightweight','12033','11-01-012-02','Устройство легкой стяжки с пористым заполнителем','lightweight_concrete','lightweight_screed','floor',0.76,0.80,'120303','01.7.03.06-4030','Смесь легкая для стяжки','m3','0.045','6241.3500']],'selected_candidate_id'=>'screed-cement-40','recorded_ordering'=>['screed-cement-40','screed-lightweight','screed-self-leveling']],
  'engineering-layout-001'=>['dataset_id'=>1205,'version'=>'fsnb-2026.1-pipelines','work_key'=>'riser-pipeline','work_name'=>'Монтаж канализационного стояка 110 мм в бетонной конструкции B25','quantity_key'=>'engineering.sewer.length','unit'=>'m','scope_type'=>'engineering','section_key'=>'sewer-riser','section_title'=>'Внутренняя канализация','section'=>'16','candidates'=>[
   ['pipe-water-ppr-32','12051','16-02-004-01','Прокладка водопроводных труб PPR диаметром 32 мм','concrete','pipe_layout','engineering',0.73,0.78,'120501','23.1.02.11-5010','Труба PPR 32 мм','m','1.01','184.6200'],
   ['pipe-sewer-pvc-50','12052','16-04-001-02','Прокладка канализационных труб ПВХ диаметром 50 мм','concrete','pipe_layout','engineering',0.86,0.89,'120502','23.1.02.12-5020','Труба ПВХ 50 мм','m','1.02','219.7400'],
   ['pipe-sewer-pvc-110','12053','16-04-001-04','Прокладка канализационных стояков ПВХ диаметром 110 мм','concrete','pipe_layout','engineering',0.99,0.99,'120503','23.1.02.12-5030','Труба ПВХ 110 мм','m','1.02','468.9300']],'selected_candidate_id'=>'pipe-sewer-pvc-110','recorded_ordering'=>['pipe-sewer-pvc-110','pipe-sewer-pvc-50','pipe-water-ppr-32']],
  'freehand-review-001'=>['dataset_id'=>1204,'version'=>'fsnb-2026.1-review-only','work_key'=>'review-only','work_name'=>'Требуется уточнение размеров эскиза','quantity_key'=>'floor_area','unit'=>'m2','scope_type'=>'review','section_key'=>'review','section_title'=>'Проверка исходных данных','section'=>'00','candidates'=>[
   ['review-measurements','12041','00-00-001-01','Проверка размеров по исходному эскизу','survey','document_review','review',0.62,0.65,'120401','91.01.01-6010','Работа специалиста по проверке','h','1.0','1247.3800'],
   ['review-site-survey','12042','00-00-002-01','Инструментальное обследование помещения','survey','site_survey','review',0.58,0.61,'120402','91.01.02-6020','Работа инженера-обследователя','h','1.0','1689.5400']],'selected_candidate_id'=>'review-measurements','recorded_ordering'=>['review-measurements','review-site-survey']],
 ];
 $spec=$specs[$slug]??throw new RuntimeException('authored case spec missing');
 $ids=array_column($spec['candidates'],0);$selected=$spec['selected_candidate_id'];
 $selectedRow=array_values(array_filter($spec['candidates'],static fn(array $candidate):bool=>$candidate[0]===$selected))[0]
  ?? throw new RuntimeException('selected candidate missing');
 $spec['scope_type']='finishing';
 $spec['section_key']='finishing';
 $spec['gate']=[$selectedRow[4],$selectedRow[5],$selectedRow[6],$spec['section'],$selectedRow[6]];
 $spec['work_intent']=['material'=>$selectedRow[4],'action'=>$selectedRow[5],'scope'=>$selectedRow[6],'object'=>$selectedRow[6],
  'dimensions'=>[$spec['unit']==='m2'?'area':'length'],'preferred_section_prefixes'=>[$spec['section']]];
 $evidence="catalog:".str_replace('fsnb-2026.1-','',$spec['version']).":$selected";
 $spec['reranker_decision']=['selected_candidate_id'=>$selected,'ordering'=>$spec['recorded_ordering'],'explanation_codes'=>['unit_match','material_match','technology_match'],'evidence_refs'=>[$evidence],'confidence'=>0.97];
 return $spec;
}

function authoredCatalog(array $spec): array
{
 $candidates=[];$resources=[];$prices=[];
 foreach($spec['candidates'] as $row){[$id,$norm,$code,$name,$material,$technology,$structure,$lexical,$semantic,$priceId,$resourceCode,$resourceName,$resourceUnit,$resourceQuantity,$price]=$row;
  $evidence="catalog:".str_replace('fsnb-2026.1-','',$spec['version']).":$id";
  $candidates[]=['candidate_id'=>$id,'normative_id'=>(int)$norm,'dataset_id'=>$spec['dataset_id'],'dataset_version'=>$spec['version'],'dataset_status'=>'parsed','code'=>$code,'name'=>$name,'unit'=>$spec['unit'],'unit_dimension'=>$spec['unit']==='m2'?'area':'length','material'=>$spec['gate'][0],'technology'=>$spec['gate'][1],'structure'=>$spec['gate'][2],'normative_section'=>$spec['gate'][3],'object_type'=>$spec['gate'][4],'region_code'=>'77','valid_from'=>'2026-01-01','lexical_score'=>$lexical,'semantic_score'=>$semantic,'source_evidence'=>[$evidence]];
  $resources[]=['candidate_id'=>$id,'normative_id'=>(int)$norm,'dataset_id'=>$spec['dataset_id'],'dataset_version'=>$spec['version'],'dataset_status'=>'parsed','code'=>$code,'name'=>$name,'unit'=>$spec['unit'],'collection'=>['code'=>'ГЭСН','name'=>'ГЭСН','norm_type'=>'gesn_building'],'section'=>['code'=>$spec['section'],'name'=>$spec['section_title']],'work_composition'=>[$spec['work_name']],'resources'=>['materials'=>[['price_id'=>(int)$priceId,'code'=>$resourceCode,'name'=>$resourceName,'unit'=>$resourceUnit,'quantity'=>$resourceQuantity,'linked_resource_id'=>(int)$priceId+900000,'price_source'=>'recorded-regional-snapshot','unit_price'=>'0']],'labor'=>[],'machinery'=>[],'other'=>[]]];
  $prices[]=['id'=>(int)$priceId,'region_id'=>77,'price_zone_id'=>1,'period_id'=>202607,'regional_price_version_id'=>$spec['dataset_id'],'base_price'=>$price,'source_type'=>'fsbc','currency'=>'RUB','snapshot_provenance'=>'approved:fgiscs-regional-capture-2026-07','snapshot_approval_ref'=>'plan3-task11-price-review'];
 }
 return ['schema_version'=>'recorded-benchmark-catalog:v1','dataset_id'=>$spec['dataset_id'],'dataset_version'=>$spec['version'],'dataset_status'=>'parsed','region_code'=>'77','price_period'=>'2026.07','currency'=>'RUB','candidates'=>$candidates,'resources'=>$resources,'prices'=>$prices,'privacy_scanner'=>'most-fixture-privacy','privacy_scanner_version'=>'1.0.0','approval_kind'=>'maintainer_code_review','approval_ref'=>'plan3-task11-corpus-v2','approved_at'=>'2026-07-12T00:00:00Z'];
}
