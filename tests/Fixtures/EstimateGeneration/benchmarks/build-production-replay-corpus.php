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
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\RecordedNormativeContentDecision;
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
 public function __construct(private readonly RecordedNormativeContentDecision $recordedDecision) {}
 public function rerank(WorkIntentData $workItem,NormativeCandidateDecisionContextData $context,NormativeCandidateSetData $candidateSet):NormativeRerankResultData
 {
  $this->intent=$workItem;$this->context=$context;$this->set=$candidateSet;
  $ids=array_map(static fn($candidate):string=>$candidate->id,$candidateSet->candidates);
  $resolved=$this->recordedDecision->resolve($candidateSet);
  $evidence=array_values(array_unique([...$workItem->sourceEvidence,...$resolved['evidence_refs']]));
  $this->payload=[...$resolved,'evidence_refs'=>$evidence];
  return NormativeRerankResultData::fromProviderArray($this->payload,$ids,$evidence,'capture');
 }
}

$sourceRoot = __DIR__;
$knownCases = ['vector-pdf-001', 'scanned-pdf-001', 'dwg-layout-001', 'dimensioned-raster-001', 'freehand-review-001', 'engineering-layout-001'];
$only = getenv('BUILD_PRODUCTION_REPLAY_CASE');
$only = $only === false ? '' : $only;
$geometryOnly = getenv('BUILD_PRODUCTION_REPLAY_GEOMETRY_ONLY');
$geometryOnly = $geometryOnly === false ? '' : $geometryOnly;
if (($only !== '' && ! in_array($only, $knownCases, true)) || ! in_array($geometryOnly, ['', '1'], true)) {
 builderReject('partial_mode_invalid');
}
$partial = $only !== '' || $geometryOnly === '1';
$output = getenv('BUILD_PRODUCTION_REPLAY_OUTPUT_DIR');
$output = $output === false ? '' : $output;
if ($partial && $output === '') {
 builderReject('partial_output_dir_required');
}
$staging = null;
if ($partial) {
 $output = isolatedOutputPath($output, $sourceRoot);
 $parent = dirname($output);
 $staging = $parent.DIRECTORY_SEPARATOR.'.'.basename($output).'.staging-'.bin2hex(random_bytes(8));
 if (! mkdir($staging, 0700, true)) throw new RuntimeException('partial_staging_failed');
 register_shutdown_function(static function () use (&$staging): void {
  if (is_string($staging) && is_dir($staging)) removeTree($staging);
 });
 $root = $staging;
 foreach (['catalogs', 'projections', 'recordings', 'regression'] as $directory) mkdir("$root/$directory", 0700, true);
} else {
 if ($output !== '' && normalizePath($output) !== normalizePath($sourceRoot)) builderReject('full_output_dir_invalid');
 $root = $sourceRoot;
}
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
 if ($only !== '' && $only !== $slug) { continue; }
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
 if (getenv('BUILD_PRODUCTION_REPLAY_GEOMETRY_ONLY') !== '1' && $intent !== 'freehand') {
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

  $capture = new CapturingReranker(RecordedNormativeContentDecision::fromArray($caseSpec['reranker_decision']));
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
if (! $partial) {
refreshBaselineCatalog($root, $builder, [
 'case_id'=>'reg-replay-vector-wall-opening-001','slug'=>'vector','geometry'=>'vector-geometry.json','planner'=>'vector-planner.json',
 'reranker'=>'vector-reranker.json','catalog'=>'vector-wall-opening-v1.json','projection'=>'vector-wall-opening-v1.json',
 'port'=>RecordedPort::CadExtraction,'selected'=>'vector-floor-cast-b25','other'=>'vector-floor-cast-b30',
 'selected_name'=>'Устройство бетонного покрытия пола из смеси B25','other_name'=>'Устройство бетонного покрытия пола из смеси B30',
 'selected_resource_name'=>'Смесь бетонная B25','other_resource_name'=>'Смесь бетонная B30',
 'selected_evidence'=>'catalog:vector:cast-b25','other_evidence'=>'catalog:vector:cast-b30',
 'price_snapshots'=>[
  11101=>['source_dataset'=>'fgiscs-77-concrete','source_version'=>'2026.07-r11','snapshot_ref'=>'price:baseline:vector-b25','snapshot_sha256'=>'07877ca1dafc618f5046786d166ad2c756d709ab912a13a03a5fe7b94fbfe06b','reviewer_ref'=>'review:price:baseline:vector-b25','approved_at'=>'2026-07-12T07:10:00Z'],
  11102=>['source_dataset'=>'fgiscs-77-concrete','source_version'=>'2026.07-r12','snapshot_ref'=>'price:baseline:vector-b30','snapshot_sha256'=>'a2276f397f4f8713356dca4dc88c41b804cae0ce842b2b3bcc1a71b3ae7994c2','reviewer_ref'=>'review:price:baseline:vector-b30','approved_at'=>'2026-07-12T07:20:00Z']],
 'price_id_map'=>[11101=>704813,11102=>965207],
 'decision'=>decision(1101,'fsnb-2026.1-vector','11-01-001-01',['7414689a55207224811735063bf1480b06eafe0a807668c52ed0ea81f8dc73cd','1b680dfaf009974aa6335af3a05790495a5a3a788efbc6b5f0d408d6aeb9e774'],'catalog:vector:cast-b25'),
]);
refreshBaselineCatalog($root, $builder, [
 'case_id'=>'reg-replay-vision-sketch-001','slug'=>'vision','geometry'=>'vision-geometry.json','planner'=>'vision-planner.json',
 'reranker'=>'vision-reranker.json','catalog'=>'vision-sketch-v1.json','projection'=>'vision-sketch-v1.json',
 'port'=>RecordedPort::VisionExtraction,'selected'=>'vision-floor-cast-b25','other'=>'vision-floor-cast-fiber',
 'selected_name'=>'Устройство бетонного покрытия пола из смеси B25','other_name'=>'Устройство фибробетонного покрытия пола',
 'selected_resource_name'=>'Смесь бетонная B25','other_resource_name'=>'Смесь фибробетонная',
 'selected_evidence'=>'catalog:vision:cast-b25','other_evidence'=>'catalog:vision:cast-fiber',
 'price_snapshots'=>[
  11201=>['source_dataset'=>'fgiscs-77-concrete','source_version'=>'2026.07-r13','snapshot_ref'=>'price:baseline:vision-b25','snapshot_sha256'=>'ad12ef1f1ac13d9c8e4d46f49ddda57146238f98078eb4748ec21ab5ec59d711','reviewer_ref'=>'review:price:baseline:vision-b25','approved_at'=>'2026-07-12T07:30:00Z'],
  11202=>['source_dataset'=>'fgiscs-77-concrete','source_version'=>'2026.07-r14','snapshot_ref'=>'price:baseline:vision-fiber','snapshot_sha256'=>'9613ddd870fb7046e199315f93e6cf1d8640de461675c38c73dbd7e3dc6272b8','reviewer_ref'=>'review:price:baseline:vision-fiber','approved_at'=>'2026-07-12T07:40:00Z']],
 'price_id_map'=>[11201=>438571,11202=>892643],
 'decision'=>decision(1102,'fsnb-2026.1-vision','11-02-001-01',['cf8bb01ba48bf05fbbdcdf3321d84f378272de8df19b847cb069142eca370574','22626fe66eb72ca95a342f23f4ee81455fb129dd2b2ea7f037051688772e805c'],'catalog:vision:cast-b25'),
]);
}
$recordingManifest=$partial ? ['schema_version'=>1,'fixtures'=>[]]
 : json_decode((string)file_get_contents("$root/recordings/manifest.json"),true,32,JSON_THROW_ON_ERROR);
$recordingManifest['fixtures']=array_map(static function(array $row)use($root):array{
 $path="$root/{$row['locator']}";
 return is_file($path)?[...$row,'sha256'=>hash_file('sha256',$path)]:$row;
},$recordingManifest['fixtures']);
$recordingManifest['fixtures']=array_values(array_filter($recordingManifest['fixtures'],static fn(array $row):bool=>!str_starts_with($row['case_id'],'reg-replay-')||in_array($row['case_id'],['reg-replay-vector-wall-opening-001','reg-replay-vision-sketch-001'],true)));
$recordingManifest['fixtures']=[...$recordingManifest['fixtures'],...$recordingDescriptors];
writeJson("$root/recordings/manifest.json",$recordingManifest);
if ($partial) {
 $sourceManifest=json_decode((string)file_get_contents("$sourceRoot/production-replay-manifest.json"),true,64,JSON_THROW_ON_ERROR);
 $caseIds=array_column($inventory,'id');
 $sourceManifest['cases']=array_values(array_filter($sourceManifest['cases'],static fn(array $case):bool=>in_array($case['id'],$caseIds,true)));
 foreach($sourceManifest['cases'] as $case){
  $expected=$case['expected_locator'];$target="$root/$expected";is_dir(dirname($target))||mkdir(dirname($target),0700,true);
  if(!copy("$sourceRoot/$expected",$target))throw new RuntimeException('partial_expected_copy_failed');
 }
 writeJson("$root/production-replay-manifest.json",$sourceManifest);
 publishIsolatedOutput($root,$output);
 $staging=null;
}

function writeJson(string $path, array $data): void { file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)."\n"); }

function isolatedOutputPath(string $path,string $sourceRoot): string
{
 $portable=str_replace('\\','/',$path);
 if(str_contains($portable,'/../')||str_ends_with($portable,'/..'))builderReject('partial_output_dir_unsafe');
 if(!preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#',$path))builderReject('partial_output_dir_unsafe');
 $parent=realpath(dirname($path));
 if($parent===false||is_link(dirname($path))||pathHasLink(dirname($path)))builderReject('partial_output_dir_unsafe');
 $candidate=$parent.DIRECTORY_SEPARATOR.basename($path);$resolved=normalizePath($candidate);
 $repo=normalizePath(dirname(__DIR__,4));$source=normalizePath($sourceRoot);
 if($resolved===$source||str_starts_with($resolved,$source.'/')||$resolved===$repo||str_starts_with($resolved,$repo.'/'))builderReject('partial_output_dir_unsafe');
 if(file_exists($path)||is_link($path)){
  $existing=realpath($path);
  if(is_link($path)||!is_dir($path)||$existing===false||normalizePath($existing)!==$resolved||pathHasLink($path)||(new FilesystemIterator($path))->valid())builderReject('partial_output_dir_unsafe');
 }
 return $candidate;
}
function normalizePath(string $path):string{return rtrim(strtolower(str_replace('\\','/',trim($path))),'/');}
function builderReject(string $code):never{fwrite(STDERR,$code.PHP_EOL);exit(2);}
function pathHasLink(string $path):bool{$current=$path;while($current!==dirname($current)){if(is_link($current))return true;$current=dirname($current);}return is_link($current);}
function publishIsolatedOutput(string $staging,string $output):void{if(is_dir($output)&&!rmdir($output))throw new RuntimeException('partial_output_publish_failed');if(!rename($staging,$output))throw new RuntimeException('partial_output_publish_failed');}
function removeTree(string $path):void{$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($iterator as $item){$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());}rmdir($path);}

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
  $oldPriceId=$resource['resources']['materials'][0]['price_id'];
  $sourcePriceId=array_search($oldPriceId,$spec['price_id_map'],true);$sourcePriceId=$sourcePriceId===false?$oldPriceId:$sourcePriceId;
  $resource['resources']['materials'][0]['price_id']=$spec['price_id_map'][$sourcePriceId]??$oldPriceId;
  $resource['resources']['materials'][0]['linked_resource_id']=$resource['resources']['materials'][0]['price_id'];
  $resource['resources']['materials'][0]['name']=$resource['candidate_id']===$spec['selected']
   ?$spec['selected_resource_name']:$spec['other_resource_name'];
 }unset($resource);
 foreach($catalog['prices'] as &$price){$oldPriceId=$price['id'];$sourcePriceId=array_search($oldPriceId,$spec['price_id_map'],true);$sourcePriceId=$sourcePriceId===false?$oldPriceId:$sourcePriceId;$price=[...$price,...$spec['price_snapshots'][$sourcePriceId]];$price['id']=$spec['price_id_map'][$sourcePriceId]??$oldPriceId;}unset($price);
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
 $resolved=RecordedNormativeContentDecision::fromArray($spec['decision'])->resolve($set);
 $reranker['payload']=[...$resolved,'evidence_refs'=>array_values(array_unique([...$refs,...$resolved['evidence_refs']]))];
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
function captureDwg(string $path): array {$binary=getenv('LIBREDWG_DWGREAD_BINARY')?:getenv('USERPROFILE').'/.cache/most-libredwg/0.13.4/win64/dwgread.exe';$workspace=sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-replay-dwg-'.bin2hex(random_bytes(8));if(!mkdir($workspace,0700,true))throw new RuntimeException('dwg_workspace_failed');$input=$workspace.DIRECTORY_SEPARATOR.'input.dwg';if(!copy($path,$input)){removeTree($workspace);throw new RuntimeException('dwg_workspace_failed');}try{return runCapture(['python',dirname(__DIR__,4).'/app/BusinessModules/Addons/EstimateGeneration/bin/cad_geometry_extract.py','--input',$input,'--workspace',$workspace,'--dwgread',$binary]);}finally{removeTree($workspace);}}
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
   ['ceiling-gypsum-frame','12001','15-01-047-01','Устройство подвесного потолка из гипсокартонных листов по металлическому каркасу','concrete','ceiling_finishing','finishing',0.96,0.98,'781403','01.6.01.01-1010','Листы гипсокартонные потолочные','m2','1.10','486.3700'],
   ['ceiling-mineral-grid','12002','15-01-050-02','Устройство подвесного потолка из минераловолокнистых плит','concrete','ceiling_finishing','finishing',0.82,0.84,'926117','01.6.04.03-1020','Плиты минераловолокнистые потолочные','m2','1.05','593.8400'],
   ['ceiling-metal-cassette','12003','15-01-052-03','Устройство кассетного потолка из алюминиевых панелей','concrete','ceiling_finishing','finishing',0.71,0.76,'543809','01.7.08.06-1040','Панели потолочные алюминиевые','m2','1.03','4175.2600']]],
  'scanned-pdf-001'=>['dataset_id'=>1201,'version'=>'fsnb-2026.1-floor-tiling','work_key'=>'ceramic-floor-tiling','work_name'=>'Облицовка бетонного пола керамической плиткой','quantity_key'=>'floor_area','unit'=>'m2','scope_type'=>'finishing','section_key'=>'floor-tiling','section_title'=>'Облицовка полов','section'=>'15','candidates'=>[
   ['tile-porcelain-rectified','12011','15-01-045-03','Облицовка полов керамогранитными плитами на клее','concrete','tiling','finishing',0.81,0.86,'835271','01.7.06.03-2010','Плиты керамогранитные ректифицированные','m2','1.04','4382.9100'],
   ['tile-ceramic-glazed','12012','15-01-045-01','Облицовка полов керамическими глазурованными плитками','concrete','tiling','finishing',0.97,0.98,'497603','01.7.06.01-2020','Плитка керамическая для пола','m2','1.03','12984.7300'],
   ['tile-clinker-floor','12013','15-01-046-02','Облицовка полов клинкерными плитками','concrete','tiling','finishing',0.78,0.82,'918457','01.7.06.04-2030','Плитка клинкерная напольная','m2','1.05','5126.4800']]],
  'dwg-layout-001'=>['dataset_id'=>1202,'version'=>'fsnb-2026.1-floors','work_key'=>'concrete-floor','work_name'=>'Устройство бетонного пола толщиной 100 мм','quantity_key'=>'floor_area','unit'=>'m2','scope_type'=>'floors','section_key'=>'concrete-floor','section_title'=>'Бетонные полы','section'=>'11','candidates'=>[
   ['floor-cement-screed','12021','11-01-011-01','Устройство цементно-песчаной стяжки','concrete','concrete_floor','floors',0.83,0.86,'684319','01.7.03.04-3010','Раствор цементно-песчаный','m3','0.050','3894.6600'],
   ['floor-dry-screed','12022','11-01-018-02','Устройство сухой сборной стяжки пола','concrete','concrete_floor','floors',0.69,0.74,'357821','01.6.01.02-3020','Элементы пола гипсоволокнистые','m2','1.05','731.2900'],
   ['floor-concrete-b25','12023','11-01-002-04','Устройство бетонных полов из смеси B25','concrete','concrete_floor','floors',0.98,0.99,'809143','01.7.03.02-3030','Смесь бетонная B25','m3','0.102','5687.4200']]],
  'dimensioned-raster-001'=>['dataset_id'=>1203,'version'=>'fsnb-2026.1-screeds','work_key'=>'leveling-screed','work_name'=>'Устройство выравнивающей цементной стяжки из бетона B25','quantity_key'=>'floor_area','unit'=>'m2','scope_type'=>'floors','section_key'=>'screed','section_title'=>'Стяжки пола','section'=>'11','candidates'=>[
   ['screed-cement-40','12031','11-01-011-03','Устройство цементной стяжки толщиной 40 мм','concrete','concreting','floors',0.98,0.99,'472901','01.7.03.04-4010','Смесь сухая для стяжки','kg','72.0','9.8700'],
   ['screed-self-leveling','12032','11-01-019-01','Устройство наливного выравнивающего покрытия','concrete','concreting','floors',0.84,0.88,'915683','01.7.03.05-4020','Смесь для наливного пола','kg','18.0','31.4600'],
   ['screed-lightweight','12033','11-01-012-02','Устройство легкой стяжки с пористым заполнителем','concrete','concreting','floors',0.76,0.80,'638207','01.7.03.06-4030','Смесь легкая для стяжки','m3','0.045','6241.3500']]],
  'engineering-layout-001'=>['dataset_id'=>1205,'version'=>'fsnb-2026.1-pipelines','work_key'=>'riser-pipeline','work_name'=>'Монтаж канализационного стояка 110 мм в бетонной конструкции B25','quantity_key'=>'engineering.sewer.length','unit'=>'m','scope_type'=>'engineering','section_key'=>'sewer-riser','section_title'=>'Внутренняя канализация','section'=>'16','candidates'=>[
   ['pipe-water-ppr-32','12051','16-02-004-01','Прокладка водопроводных труб PPR диаметром 32 мм','concrete','pipe_layout','engineering',0.73,0.78,'827369','23.1.02.11-5010','Труба PPR 32 мм','m','1.01','184.6200'],
   ['pipe-sewer-pvc-50','12052','16-04-001-02','Прокладка канализационных труб ПВХ диаметром 50 мм','concrete','pipe_layout','engineering',0.86,0.89,'394817','23.1.02.12-5020','Труба ПВХ 50 мм','m','1.02','219.7400'],
   ['pipe-sewer-pvc-110','12053','16-04-001-04','Прокладка канализационных стояков ПВХ диаметром 110 мм','concrete','pipe_layout','engineering',0.99,0.99,'961253','23.1.02.12-5030','Труба ПВХ 110 мм','m','1.02','468.9300']]],
  'freehand-review-001'=>['dataset_id'=>1204,'version'=>'fsnb-2026.1-review-only','work_key'=>'review-only','work_name'=>'Требуется уточнение размеров эскиза','quantity_key'=>'floor_area','unit'=>'m2','scope_type'=>'review','section_key'=>'review','section_title'=>'Проверка исходных данных','section'=>'00','candidates'=>[
   ['review-measurements','12041','00-00-001-01','Проверка размеров по исходному эскизу','survey','document_review','review',0.62,0.65,'753109','91.01.01-6010','Работа специалиста по проверке','h','1.0','1247.3800'],
   ['review-site-survey','12042','00-00-002-01','Инструментальное обследование помещения','survey','site_survey','review',0.58,0.61,'486731','91.01.02-6020','Работа инженера-обследователя','h','1.0','1689.5400']]],
 ];
 $spec=$specs[$slug]??throw new RuntimeException('authored case spec missing');
 $spec['work_intent']=match($slug){
  'vector-pdf-001'=>['material'=>'concrete','action'=>'ceiling_finishing','scope'=>'finishing','object'=>'ceiling','dimensions'=>['area'],'preferred_section_prefixes'=>['15']],
  'scanned-pdf-001'=>['material'=>'concrete','action'=>'tiling','scope'=>'finishing','object'=>'finishing','dimensions'=>['area'],'preferred_section_prefixes'=>['15']],
  'dwg-layout-001'=>['material'=>'concrete','action'=>'concrete_floor','scope'=>'floors','object'=>'floor','dimensions'=>['area'],'preferred_section_prefixes'=>['11']],
  'dimensioned-raster-001'=>['material'=>'concrete','action'=>'concreting','scope'=>'floors','object'=>'floor','dimensions'=>['area'],'preferred_section_prefixes'=>['11']],
  'engineering-layout-001'=>['material'=>'concrete','action'=>'pipe_layout','scope'=>'engineering','object'=>'engineering','dimensions'=>['length'],'preferred_section_prefixes'=>['16']],
  default=>['material'=>'survey','action'=>'document_review','scope'=>'review','object'=>'review','dimensions'=>['area'],'preferred_section_prefixes'=>['00']],
 };
 $spec['reranker_decision']=recordedDecision($slug);
 return $spec;
}

function recordedDecision(string $slug): array
{
 return match($slug){
  'vector-pdf-001'=>decision(1200,'fsnb-2026.1-ceilings','15-01-047-01',['6321fbb940fe68a4c78c1033a746cc806599fe7b02ff2f86264f501bbeb8b0b6','52ceb69cf31fbc39c12e0e5aa635f33334018e962d56191a8a0bffea3ddcf5f5','7013411c99bd544aa2e7077b4b6c984bf2bd272c4ec23d67c15f2f3ce66cdedd'],'normative-content:15-01-047-01'),
  'scanned-pdf-001'=>decision(1201,'fsnb-2026.1-floor-tiling','15-01-045-01',['7186e01e58a653874d74c84396823f3926db0fb30aab42bcb50d39bff680b2d9','e35e2fc6c95476adc5faa4b2720a43e1ea5b6986dc255275c0d8f8bdf69d4d9a','1aa16773f8ca96123bc3de4bb30e21e0fcff2e9422296321e6b2c32c23216135'],'normative-content:15-01-045-01'),
  'dwg-layout-001'=>decision(1202,'fsnb-2026.1-floors','11-01-002-04',['3e14c1d9ba41466f42088f675571d37102c7673d32539c6fd49f853022b7da56','16964a8e9592b790b3b441ef153e701b0ca5ba890b1bbfe4214e4af281801cca','f30de51f73eb1f290a38a22c44b4c3e7dceeea470e6083aeaba783791046614f'],'normative-content:11-01-002-04'),
  'dimensioned-raster-001'=>decision(1203,'fsnb-2026.1-screeds','11-01-011-03',['15e2a00a000696a41fcff43726658f40b7e7baf89e2cfeab66d5cd8349038490','254b09c2536fa5e30569f7bd167ca783f96732ee4837b0ae6a4a52e6a276d069','00c725f2660bce37a2a89cce87df4c70799a2d53694b1107d9aafdcacadb8f3b'],'normative-content:11-01-011-03'),
  'engineering-layout-001'=>decision(1205,'fsnb-2026.1-pipelines','16-04-001-04',['16e38a5e58e5e6d34c2fb28edd56836464858a2777ba6fae8271f3cec4674eb8','428248125b1ccbe59886d6049d9671b7cb508705ecb64bc5ca072c03b2da2c01','b92e4057ef96d90aaa1f6c94f7417d68724713e71e421ca940ac9d47d4ca8d47'],'normative-content:16-04-001-04'),
  default=>decision(1204,'fsnb-2026.1-review-only','00-00-001-01',['639c068c282e17db925063d6c58f1304e3e9913cfe235b80d9f1e6c84ac03d71','ff4f020140405075bae5267f0b134626552e6fe7f02bf4952d7a0476aab7c0a0'],'normative-content:00-00-001-01'),
 };
}

function decision(int $datasetId,string $version,string $code,array $hashes,string $evidence): array
{
 return ['dataset_id'=>$datasetId,'dataset_version'=>$version,'code'=>$code,'selected_content_sha256'=>$hashes[0],
  'ordering_content_sha256'=>$hashes,'explanation_codes'=>['unit_match','material_match','technology_match'],
  'evidence_refs'=>[$evidence],'confidence'=>0.97];
}

function authoredCatalog(array $spec): array
{
 $candidates=[];$resources=[];$prices=[];
 foreach($spec['candidates'] as $row){[$id,$norm,$code,$name,$material,$technology,$structure,$lexical,$semantic,$priceId,$resourceCode,$resourceName,$resourceUnit,$resourceQuantity,$price]=$row;
  $evidence="normative-content:$code";
  $candidateSection=strtok($code,'-');
  $candidates[]=['candidate_id'=>$id,'normative_id'=>(int)$norm,'dataset_id'=>$spec['dataset_id'],'dataset_version'=>$spec['version'],'dataset_status'=>'parsed','code'=>$code,'name'=>$name,'unit'=>$spec['unit'],'unit_dimension'=>$spec['unit']==='m2'?'area':'length','material'=>$material,'technology'=>$technology,'structure'=>$structure,'normative_section'=>$candidateSection,'object_type'=>candidateObject($code),'region_code'=>'77','valid_from'=>'2026-01-01','lexical_score'=>$lexical,'semantic_score'=>$semantic,'source_evidence'=>[$evidence]];
  $resources[]=['candidate_id'=>$id,'normative_id'=>(int)$norm,'dataset_id'=>$spec['dataset_id'],'dataset_version'=>$spec['version'],'dataset_status'=>'parsed','code'=>$code,'name'=>$name,'unit'=>$spec['unit'],'collection'=>['code'=>'ГЭСН','name'=>'ГЭСН','norm_type'=>'gesn_building'],'section'=>['code'=>$candidateSection,'name'=>$name],'work_composition'=>[$name],'resources'=>['materials'=>[['price_id'=>(int)$priceId,'code'=>$resourceCode,'name'=>$resourceName,'unit'=>$resourceUnit,'quantity'=>$resourceQuantity,'linked_resource_id'=>(int)$priceId,'price_source'=>'recorded-regional-snapshot','unit_price'=>'0']],'labor'=>[],'machinery'=>[],'other'=>[]]];
  $prices[]=['id'=>(int)$priceId,'region_id'=>77,'price_zone_id'=>1,'period_id'=>202607,'regional_price_version_id'=>$spec['dataset_id'],'base_price'=>$price,'source_type'=>'fsbc','currency'=>'RUB',...priceSnapshot((int)$priceId)];
 }
 return ['schema_version'=>'recorded-benchmark-catalog:v1','dataset_id'=>$spec['dataset_id'],'dataset_version'=>$spec['version'],'dataset_status'=>'parsed','region_code'=>'77','price_period'=>'2026.07','currency'=>'RUB','candidates'=>$candidates,'resources'=>$resources,'prices'=>$prices,'privacy_scanner'=>'most-fixture-privacy','privacy_scanner_version'=>'1.0.0','approval_kind'=>'maintainer_code_review','approval_ref'=>'plan3-task11-corpus-v2','approved_at'=>'2026-07-12T00:00:00Z'];
}

function candidateObject(string $code): string
{
 return match($code){
  '15-01-047-01','15-01-050-02','15-01-052-03'=>'ceiling',
  '11-01-011-01','11-01-018-02','11-01-002-04','11-01-011-03','11-01-019-01','11-01-012-02'=>'floor',
  '16-02-004-01','16-04-001-02','16-04-001-04'=>'engineering',
  '00-00-001-01','00-00-002-01'=>'review',
  default=>'finishing',
 };
}

function priceSnapshot(int $priceId): array
{
 $snapshots=[
  781403=>['source_dataset'=>'fgiscs-77-ceilings','source_version'=>'2026.07-r4','snapshot_ref'=>'price:ceilings:gkl-batch-7','snapshot_sha256'=>'2f5dd6dbfcf0b3c06f675dcf0f44a98f6e5d8d113ecf2d6af2c2146c1c0c11bd','reviewer_ref'=>'review:price:ceilings:gkl','approved_at'=>'2026-07-12T08:10:00Z'],
  926117=>['source_dataset'=>'fgiscs-77-ceilings','source_version'=>'2026.07-r5','snapshot_ref'=>'price:ceilings:mineral-batch-3','snapshot_sha256'=>'9b694f624c69e00f3e08678e40f2377e240900c167c55c2b526de3421fc85021','reviewer_ref'=>'review:price:ceilings:mineral','approved_at'=>'2026-07-12T08:20:00Z'],
  543809=>['source_dataset'=>'fgiscs-77-metals','source_version'=>'2026.07-r2','snapshot_ref'=>'price:ceilings:cassette-batch-9','snapshot_sha256'=>'7577385f5a74f78fbf1c805fcaa45ef25c4ddd5689adf911573312f6280e47f6','reviewer_ref'=>'review:price:ceilings:cassette','approved_at'=>'2026-07-12T08:30:00Z'],
  835271=>['source_dataset'=>'fgiscs-77-tiles','source_version'=>'2026.07-r7','snapshot_ref'=>'price:tiles:porcelain-18','snapshot_sha256'=>'5a2ba12f9d9744789b79ebd98ce6eab9b89151db780c42491a36da805d750aac','reviewer_ref'=>'review:price:tiles:porcelain','approved_at'=>'2026-07-12T09:10:00Z'],
  497603=>['source_dataset'=>'fgiscs-77-tiles','source_version'=>'2026.07-r8','snapshot_ref'=>'price:tiles:ceramic-22','snapshot_sha256'=>'338c1de15d503422398e195c1680e1879b9da96c5b41cd12fa721f42411083f5','reviewer_ref'=>'review:price:tiles:ceramic','approved_at'=>'2026-07-12T09:20:00Z'],
  918457=>['source_dataset'=>'fgiscs-77-tiles','source_version'=>'2026.07-r3','snapshot_ref'=>'price:tiles:clinker-11','snapshot_sha256'=>'a6e54880de6d57dc46ea61037d9fc5fc773b51485f858dfdf60af81c034d93c1','reviewer_ref'=>'review:price:tiles:clinker','approved_at'=>'2026-07-12T09:30:00Z'],
  684319=>['source_dataset'=>'fgiscs-77-mortars','source_version'=>'2026.07-r6','snapshot_ref'=>'price:floors:mortar-4','snapshot_sha256'=>'e1e65a045d8db5d6a6a77d1dfa6f66617545360e6e1fb7efcc6011cf0c601413','reviewer_ref'=>'review:price:floors:mortar','approved_at'=>'2026-07-12T10:10:00Z'],
  357821=>['source_dataset'=>'fgiscs-77-dry-floor','source_version'=>'2026.07-r2','snapshot_ref'=>'price:floors:dry-6','snapshot_sha256'=>'aa55e4d53472e504ab04cfeca63ca3774392dbe45420b169e3f245c6fd371b9e','reviewer_ref'=>'review:price:floors:dry','approved_at'=>'2026-07-12T10:20:00Z'],
  809143=>['source_dataset'=>'fgiscs-77-concrete','source_version'=>'2026.07-r9','snapshot_ref'=>'price:floors:b25-31','snapshot_sha256'=>'34c092d94765d9c2291de92ad69b6c97924cc06d40a0cce4bde7a131cc68ae12','reviewer_ref'=>'review:price:floors:b25','approved_at'=>'2026-07-12T10:30:00Z'],
  472901=>['source_dataset'=>'fgiscs-77-screeds','source_version'=>'2026.07-r1','snapshot_ref'=>'price:screed:cement-14','snapshot_sha256'=>'73b0b541cf717ad42d672d09cf88d5c5d1c3f528d49d810d4352742ecc3bda29','reviewer_ref'=>'review:price:screed:cement','approved_at'=>'2026-07-12T11:10:00Z'],
  915683=>['source_dataset'=>'fgiscs-77-screeds','source_version'=>'2026.07-r4','snapshot_ref'=>'price:screed:self-level-8','snapshot_sha256'=>'518bff4bd05026f65605d01415009228db0d2f99478ec9e4e99a38e4e97eec61','reviewer_ref'=>'review:price:screed:self-level','approved_at'=>'2026-07-12T11:20:00Z'],
  638207=>['source_dataset'=>'fgiscs-77-screeds','source_version'=>'2026.07-r5','snapshot_ref'=>'price:screed:light-12','snapshot_sha256'=>'8dc7cd2344eef6f08f001d5503bda250d5e0967f9fddf84751d50dc819eb6b37','reviewer_ref'=>'review:price:screed:light','approved_at'=>'2026-07-12T11:30:00Z'],
  753109=>['source_dataset'=>'most-survey-rates','source_version'=>'2026.07-r2','snapshot_ref'=>'price:survey:desk-5','snapshot_sha256'=>'26a85a11a6e1c82ff3e704fc3daf5e4dde594384c2cb2ecc68fa074f4ccf9fc0','reviewer_ref'=>'review:price:survey:desk','approved_at'=>'2026-07-12T12:10:00Z'],
  486731=>['source_dataset'=>'most-survey-rates','source_version'=>'2026.07-r3','snapshot_ref'=>'price:survey:site-7','snapshot_sha256'=>'4d849e8577615cdcc482e281c43024e87e537866599abceae77f3443a13af80d','reviewer_ref'=>'review:price:survey:site','approved_at'=>'2026-07-12T12:20:00Z'],
  827369=>['source_dataset'=>'fgiscs-77-pipes','source_version'=>'2026.07-r4','snapshot_ref'=>'price:pipes:ppr32-16','snapshot_sha256'=>'69473ced7c371b792d328d3e3617c440503c5978273e1bc3c663db69768f55a8','reviewer_ref'=>'review:price:pipes:ppr32','approved_at'=>'2026-07-12T13:10:00Z'],
  394817=>['source_dataset'=>'fgiscs-77-pipes','source_version'=>'2026.07-r6','snapshot_ref'=>'price:pipes:pvc50-19','snapshot_sha256'=>'a162203d84cdd9609187067e66fd68d8fe2c354819422b307c52ff7ef3baa05b','reviewer_ref'=>'review:price:pipes:pvc50','approved_at'=>'2026-07-12T13:20:00Z'],
  961253=>['source_dataset'=>'fgiscs-77-pipes','source_version'=>'2026.07-r8','snapshot_ref'=>'price:pipes:pvc110-21','snapshot_sha256'=>'d1300989b40f257f0f8625069980b7655d70fe82ed18ef09af78e760ca96d7b1','reviewer_ref'=>'review:price:pipes:pvc110','approved_at'=>'2026-07-12T13:30:00Z'],
 ];
 return $snapshots[$priceId]??throw new RuntimeException('price snapshot missing');
}
