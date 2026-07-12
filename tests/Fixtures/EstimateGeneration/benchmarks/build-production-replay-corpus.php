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

final class CapturingReranker implements NormativeCandidateRerankerInterface
{
 public WorkIntentData $intent;
 public NormativeCandidateDecisionContextData $context;
 public NormativeCandidateSetData $set;
 public array $payload=[];
 public function __construct(private readonly string $primary) {}
 public function rerank(WorkIntentData $workItem,NormativeCandidateDecisionContextData $context,NormativeCandidateSetData $candidateSet):NormativeRerankResultData
 {
  $this->intent=$workItem;$this->context=$context;$this->set=$candidateSet;
  $ids=array_map(static fn($candidate):string=>$candidate->id,$candidateSet->candidates);
  $ordering=[$this->primary,...array_values(array_filter($ids,fn(string $id):bool=>$id!==$this->primary))];
  $evidence=array_values(array_unique([...$workItem->sourceEvidence,...($candidateSet->candidates[array_search($this->primary,$ids,true)]->sourceEvidence??[])]));
  $this->payload=['selected_candidate_id'=>$this->primary,'ordering'=>$ordering,'explanation_codes'=>['unit_match','material_match','technology_match'],
   'evidence_refs'=>$evidence,'confidence'=>0.97,'schema_version'=>'normative-rerank-v1'];
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
 $id = 'reg-replay-'.$slug;
 $directory = "$root/regression/replay-$slug";
 is_dir($directory) || mkdir($directory, 0777, true);
 file_put_contents("$directory/$filename", $source);
 $sha = hash('sha256', $source);
 $case = new BenchmarkPredictionCaseData($id, BenchmarkDatasetType::Regression, $type,
  "regression/replay-$slug/$filename", $sha, ['production-replay', $intent],
  ['document_understanding', $port === RecordedPort::VisionExtraction ? 'vision' : 'geometry'], [], []);
 $payload = $port === RecordedPort::VisionExtraction ? visionPayload($intent, $sha) : vectorPayload($intent, $sha, $type);
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
 $recordingDescriptors[] = ['case_id'=>$id,'port'=>$port->value,'locator'=>$recording,'sha256'=>hash_file('sha256',"$root/$recording")];
 $catalog = json_decode((string) file_get_contents("$root/catalogs/vision-sketch-v1.json"), true, 64, JSON_THROW_ON_ERROR);
 $catalog['dataset_id'] = 1200 + count($inventory);
 $catalog['dataset_version'] = "fsnb-2026.1-$intent";
 foreach (['candidates', 'resources'] as $collection) { foreach ($catalog[$collection] as &$row) { $row['dataset_id']=$catalog['dataset_id']; $row['dataset_version']=$catalog['dataset_version']; } unset($row); }
 $catalog['approval_ref'] = 'plan3-task11-corpus-v1';
 $candidateIds = ["$slug-floor-alt", "$slug-floor-primary"];
 foreach ($catalog['candidates'] as $index => &$row) {
  $row['candidate_id'] = $candidateIds[$index];
  $row['normative_id'] = $catalog['dataset_id'] * 10 + $index + 1;
  $row['source_evidence'] = ["catalog:$slug:".($index === 0 ? 'alt' : 'primary')];
 }
 unset($row);
 foreach ($catalog['resources'] as $index => &$row) {
  $row['candidate_id'] = $candidateIds[$index === 0 ? 1 : 0];
  $row['normative_id'] = $catalog['dataset_id'] * 10 + ($index === 0 ? 2 : 1);
  foreach ($row['resources']['materials'] as &$material) {
   $material['price_id'] = $catalog['dataset_id'] * 100 + $index + 1;
  }
  unset($material);
 }
 unset($row);
 foreach ($catalog['prices'] as $index => &$price) {
  $price['id'] = $catalog['dataset_id'] * 100 + $index + 1;
  $price['regional_price_version_id'] = $catalog['dataset_id'];
  $price['base_price'] = (string) (1250 + count($inventory) * 75 + $index * 150).'.0000';
 }
 unset($price);
 $catalogRef = "catalogs/$slug.json";
 writeJson("$root/$catalogRef", $catalog);
 if ($intent !== 'freehand') {
  [$model, $quantities, $evidence] = productionGeometry($payload, $port);
  $quantity = $quantities->get('floor_area') ?? throw new RuntimeException('floor_area missing');
  $quantityRefs = array_map('strval', $quantity->evidenceIds);
  $workKey = "$slug-floor-work";
  $plannerPayload = ['schema_version'=>'work-planner-v1','sections'=>[['section_key'=>'finishing','title'=>'Отделочные работы',
   'scope_type'=>'finishing','source_refs'=>$quantityRefs,'work_intents'=>[['intent_key'=>$workKey,
   'quantity_key'=>'floor_area','name'=>'Устройство бетонного покрытия пола B25','category'=>'finishing','unit'=>'m2',
   'quantity'=>$quantity->amount,'quantity_source_refs'=>$quantityRefs,'confidence'=>0.95]]]]];
  $plannerMeta = [...$metadata, 'port'=>RecordedPort::WorkPlanningModel->value, 'provider'=>'planner-independent-capture',
   'model_version'=>'planner-model-2026-07','prompt_version'=>'planner-prompt:v1','payload_schema_version'=>'work-planner-v1'];
  $plannerEnvelope = $builder->envelope($plannerMeta, $plannerPayload,
   $builder->plannerDependency($model->toArray(), $quantities->toArray(), $evidence), $sha);
  $plannerRecording = "recordings/$slug-planner.json";
  writeJson("$root/$plannerRecording", $plannerEnvelope);
  $recordingDescriptors[]=['case_id'=>$id,'port'=>RecordedPort::WorkPlanningModel->value,'locator'=>$plannerRecording,
   'sha256'=>hash_file('sha256',"$root/$plannerRecording")];

  $capture = new CapturingReranker($candidateIds[1]);
  $workflow = new NormativeMatchingWorkflow(new NormativeRetrievalService(
   new RecordedCatalogNormativeCandidateSource(RecordedBenchmarkCatalogData::fromArray($catalog)), new NormativeHardGate, 16, null), $capture);
  $plan = app(WorkPlanCompiler::class)->compile(productionAnalysis($model->toArray(), $quantities->toArray(), $catalog),
   new WorkPlannerResponseData($plannerPayload['sections']));
  $item = $plan['local_estimates'][0]['sections'][0]['work_items'][0];
  $context=['organization_id'=>1,'project_id'=>1,'session_id'=>1,'checkpoint_claim_token'=>'018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c',
   'input_version'=>'sha256:'.$sha,'logical_attempt'=>1,'scope_type'=>'finishing','local_estimate_title'=>$plan['local_estimates'][0]['title'],
   'section_title'=>$plan['local_estimates'][0]['sections'][0]['title'],'source_refs'=>$quantityRefs,
   'regional_context'=>productionAnalysis($model->toArray(), $quantities->toArray(), $catalog)['regional_context'],'applicability_date'=>'2026-07-12'];
  $factory=app(NormativeWorkIntentFactory::class);
  $workflow->match($factory->intent($item,$context,$catalog['dataset_version']),$factory->decision($item,$context),true);
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
 unset($plannerRecording, $rerankerRecording);
}
writeJson("$root/production-replay-corpus-inventory.json", ['schema_version'=>1,'cases'=>$inventory]);
$recordingManifest=json_decode((string)file_get_contents("$root/recordings/manifest.json"),true,32,JSON_THROW_ON_ERROR);
$recordingManifest['fixtures']=array_values(array_filter($recordingManifest['fixtures'],static fn(array $row):bool=>!str_starts_with($row['case_id'],'reg-replay-')||in_array($row['case_id'],['reg-replay-vector-wall-opening-001','reg-replay-vision-sketch-001'],true)));
$recordingManifest['fixtures']=[...$recordingManifest['fixtures'],...$recordingDescriptors];
writeJson("$root/recordings/manifest.json",$recordingManifest);

function writeJson(string $path, array $data): void { file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)."\n"); }
function visionPayload(string $intent,string $sha): array { $wall="$intent-wall-evidence";$room="$intent-room-evidence";$locator=['page_id'=>1,'page_number'=>1,'processing_unit_id'=>1,'source_version'=>"sha256:$sha",'coordinate_space'=>'normalized_source_v1'];$evidence=[['key'=>$wall,'locator'=>$locator]];$elements=[['key'=>"$intent-wall",'type'=>'wall','label'=>null,'polygon'=>[[0.1,0.1],[0.7,0.1]],'confidence'=>$intent==='freehand'?0.62:0.95,'evidence_ref'=>$wall]];if($intent!=='freehand'){$evidence[]=['key'=>$room,'locator'=>$locator];$elements[]=['key'=>"$intent-room",'type'=>'room','label'=>'Комната','polygon'=>[[0.1,0.1],[0.7,0.1],[0.7,0.5],[0.1,0.5]],'confidence'=>0.96,'evidence_ref'=>$room];}return ['schema_version'=>1,'sheet_type'=>'floor_plan','evidence'=>$evidence,'elements'=>$elements,'scale_candidates'=>$intent==='freehand'?[]:[['source'=>'dimension_text','meters_per_unit'=>10.0,'confidence'=>0.99,'evidence_ref'=>$wall,'detail'=>'visible_dimension'],['source'=>'manual_reference','meters_per_unit'=>10.0,'confidence'=>1.0,'evidence_ref'=>$room,'detail'=>'confirmed_control_dimension']],'warnings'=>$intent==='freehand'?['scale_missing']:[]]; }
function vectorPayload(string $intent,string $sha,BenchmarkSourceType $type): array { return ['schema_version'=>1,'runtime_version'=>$type===BenchmarkSourceType::Dwg?'cad-geometry:v1;libredwg:0.13.4':'pdf-geometry:v1;pypdfium2:5.8.0','source_fingerprint'=>"sha256:$sha",'source_unit'=>'mm','unit_status'=>'confirmed','bounds'=>[0,0,4800,3600],'layers'=>[['name'=>strtoupper($intent),'visible'=>true]],'blocks'=>[],'entities'=>[['handle'=>'R1','type'=>'lwpolyline','layer'=>strtoupper($intent),'points'=>[[0,0],[4800,0],[4800,3600],[0,3600]],'closed'=>true],['handle'=>'W1','type'=>'line','layer'=>strtoupper($intent),'points'=>[[0,0],[4800,0]]]],'texts'=>[],'dimensions'=>[],'pages'=>[],'scale_candidates'=>[],'warnings'=>[]]; }
function vectorPdf(): string { $s="BT /F1 14 Tf 60 760 Td (VECTOR FLOOR PLAN PDF ROOM 4800 mm x 3600 mm) Tj ET\n50 700 m 500 700 l 500 350 l 50 350 l h S\n"; return makePdf(["<< /Type /Catalog /Pages 2 0 R >>","<< /Type /Pages /Kids [3 0 R] /Count 1 >>","<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>","<< /Length ".strlen($s)." >>\nstream\n$s"."endstream","<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>"]); }
function scannedPdf(): string { $i=str_repeat("\xff\xff\xff",120*90); $s="q 480 0 0 360 55 300 cm /Im0 Do Q\n"; return makePdf(["<< /Type /Catalog /Pages 2 0 R >>","<< /Type /Pages /Kids [3 0 R] /Count 1 >>","<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Im0 5 0 R >> >> /Contents 4 0 R >>","<< /Length ".strlen($s)." >>\nstream\n$s"."endstream","<< /Type /XObject /Subtype /Image /Width 120 /Height 90 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Length ".strlen($i)." >>\nstream\n$i\nendstream"]); }
function makePdf(array $objects): string { $out="%PDF-1.4\n";$offsets=[];foreach($objects as $n=>$o){$offsets[]=strlen($out);$out.=($n+1)." 0 obj\n$o\nendobj\n";}$xref=strlen($out);$out.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";foreach($offsets as $offset){$out.=sprintf('%010d 00000 n ',$offset)."\n";}return $out."trailer << /Size ".(count($objects)+1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n"; }
function raster(): string { $w=400;$h=300;$p=str_repeat("\xff\xff\xff",$w*$h);for($y=50;$y<250;$y++){for($x=60;$x<340;$x++){if($x<64||$x>335||$y<54||$y>245){$o=($y*$w+$x)*3;$p[$o]=$p[$o+1]=$p[$o+2]="\0";}}}return "P6\n# DIMENSION 5.2m x 3.8m\n$w $h\n255\n".$p; }
function freehand(): string { return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 400"><title>uncertain freehand house</title><path d="M70 90 L510 77 L525 330 L82 345 Z" fill="none" stroke="black" stroke-width="7"/><path d="M80 210 Q260 180 520 220" fill="none" stroke="black" stroke-width="5"/></svg>'; }
function engineering(): string { return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 500"><title>Engineering layout RISER 110</title><rect x="70" y="60" width="650" height="370" fill="none" stroke="black" stroke-width="4"/><line x1="180" y1="80" x2="180" y2="410" stroke="blue" stroke-width="8"/><circle cx="180" cy="245" r="24" fill="none" stroke="blue" stroke-width="6"/><text x="215" y="250" font-size="26">RISER 110</text><text x="300" y="470" font-size="22">6500 mm</text></svg>'; }
function dwg(): string { $b=@file_get_contents(dirname(__DIR__).'/Vision/simple-house.dwg');if(is_string($b))return $b;throw new RuntimeException('dwg fixture missing'); }

function productionGeometry(array $payload, RecordedPort $port): array
{
 $vision=$port===RecordedPort::VisionExtraction
  ? VisionAnalysisData::fromProviderArray($payload,'fixture-independent-capture','corpus-capture-2026-07','corpus-capture-2026-07','vision-analysis:v1','unavailable',null,null,500)
  : null;
 $vector=$port!==RecordedPort::VisionExtraction ? VectorGeometryData::fromArray($payload) : null;
 $refs=[];
 if($vision!==null){foreach($vision->evidence as $row)$refs[]=$row->key;}
 if($vector!==null){foreach($vector->entities as $row)$refs[]='vector:'.$row['handle'];}
 $refs=array_values(array_unique($refs));sort($refs,SORT_STRING);$evidence=[];
 foreach($refs as $index=>$ref)$evidence[$ref]=$index+1;
 $assembled=(new BuildingModelAssembler)->assembleVision((new GeometryBuildingModelInputMapper)->map($vision,$vector,$evidence));
 if($assembled->clarifications!==[]||($assembled->model->metrics['complete']??false)!==true)throw new RuntimeException('generated geometry incomplete');
 $quantities=(new BuildingQuantityCalculator)->calculate((new NormalizedBuildingModelQuantityInputMapper)->map($assembled->model));
 return [$assembled->model,$quantities,$evidence];
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
