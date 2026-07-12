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
 if ($intent === 'engineering') {
  $payload['evidence'][] = ['key'=>'riser-110','locator'=>$payload['evidence'][0]['locator']];
  $payload['elements'][] = ['key'=>'engineering-riser-110','type'=>'engineering_element','label'=>'Стояк 110','polygon'=>[[0.225,0.16],[0.225,0.82]],'confidence'=>1.0,'evidence_ref'=>'riser-110'];
 }
 if ($intent === 'freehand') {
  $payload['evidence'][0]['key'] = 'freehand-evidence';
  $payload['elements'][0]['evidence_ref'] = 'freehand-evidence';
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
 if ($type === BenchmarkSourceType::Dwg) writeJson("$root/recordings/$slug-parser-proof.json", parserProof($source, $payload));
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
 if (in_array($intent, ['scanned_pdf', 'dimensioned_raster'], true)) {
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
function vectorPdf(): string { $s="2 w\n60 650 m 500 650 l 500 360 l 60 360 l h S\n60 500 m 260 500 l 260 650 l S\n320 650 m 400 650 l S\n55 680 m 505 680 l S\n55 675 m 55 685 l S\n505 675 m 505 685 l S\nBT /F1 14 Tf 235 700 Td (4400 mm) Tj ET\n530 355 m 530 655 l S\n525 355 m 535 355 l S\n525 655 m 535 655 l S\nBT /F1 14 Tf 540 490 Td (2900 mm) Tj ET\nBT /F1 16 Tf 180 560 Td (ROOM A) Tj ET\n"; return makePdf(["<< /Type /Catalog /Pages 2 0 R >>","<< /Type /Pages /Kids [3 0 R] /Count 1 >>","<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>","<< /Length ".strlen($s)." >>\nstream\n$s"."endstream","<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>"]); }
function scannedPdf(): string { $i=planPixels(400,300,true); $s="q 500 0 0 375 45 300 cm /Im0 Do Q\n"; return makePdf(["<< /Type /Catalog /Pages 2 0 R >>","<< /Type /Pages /Kids [3 0 R] /Count 1 >>","<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Im0 5 0 R >> >> /Contents 4 0 R >>","<< /Length ".strlen($s)." >>\nstream\n$s"."endstream","<< /Type /XObject /Subtype /Image /Width 400 /Height 300 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Length ".strlen($i)." >>\nstream\n$i\nendstream"]); }
function makePdf(array $objects): string { $out="%PDF-1.4\n";$offsets=[];foreach($objects as $n=>$o){$offsets[]=strlen($out);$out.=($n+1)." 0 obj\n$o\nendobj\n";}$xref=strlen($out);$out.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";foreach($offsets as $offset){$out.=sprintf('%010d 00000 n ',$offset)."\n";}return $out."trailer << /Size ".(count($objects)+1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n"; }
function raster(): string { return "P6\n400 300\n255\n".planPixels(400,300,false); }
function planPixels(int $w,int $h,bool $scan): string {$p=str_repeat("\xff\xff\xff",$w*$h);$black=function(int $x,int $y)use(&$p,$w,$h):void{if($x<0||$y<0||$x>=$w||$y>=$h)return;$o=($y*$w+$x)*3;$p[$o]=$p[$o+1]=$p[$o+2]="\0";};for($t=0;$t<6;$t++){for($x=50;$x<=350;$x++){if($x<170||$x>220){$black($x,45+$t);$black($x,245+$t);}}for($y=45;$y<=250;$y++){$black(50+$t,$y);$black(345+$t,$y);}}for($x=50;$x<=350;$x++)$black($x,275);for($y=45;$y<=250;$y++)$black(370,$y);foreach($scan?[[120,260],[145,260],[220,260],[245,260]]:[[105,260],[130,260],[230,260],[255,260]] as [$gx,$gy])for($y=0;$y<10;$y++)for($x=0;$x<7;$x++)if($x===0||$x===6||$y===0||$y===9||(($x+$y)%5===0))$black($gx+$x,$gy+$y);return $p;}
function freehand(): string { return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 400"><title>Неуверенный эскиз</title><path id="uncertain-outline" d="M70 90 L510 77 L525 330 L82 345 Z" fill="none" stroke="black" stroke-width="7" stroke-dasharray="18 7"/><path id="uncertain-divider" d="M80 210 Q260 180 520 220" fill="none" stroke="black" stroke-width="5"/><text id="review-question" x="245" y="375" font-size="26">? размер</text></svg>'; }
function engineering(): string { return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 500"><title>Инженерный план</title><rect id="room-outline" x="70" y="60" width="650" height="370" fill="none" stroke="black" stroke-width="4"/><path id="door-opening" d="M350 60 h90" stroke="white" stroke-width="10"/><line id="riser-110" x1="180" y1="80" x2="180" y2="410" stroke="blue" stroke-width="8"/><circle id="riser-node" cx="180" cy="245" r="24" fill="none" stroke="blue" stroke-width="6"/><text id="riser-label" x="215" y="250" font-size="26">Стояк 110</text><text id="dimension-width" x="300" y="470" font-size="22">6500 mm</text><text id="dimension-height" x="730" y="250" font-size="22">3700 mm</text></svg>'; }
function dwg(): string { $b=@file_get_contents(dirname(__DIR__).'/Vision/simple-house.dwg');if(is_string($b))return $b;throw new RuntimeException('dwg fixture missing'); }
function runCapture(array $command): array {$json=shell_exec(implode(' ',array_map('escapeshellarg',$command)));if(!is_string($json)||$json==='')throw new RuntimeException('geometry capture failed');return json_decode($json,true,128,JSON_THROW_ON_ERROR);}
function captureVectorPdf(string $path): array {return runCapture(['python',dirname(__DIR__,4).'/app/BusinessModules/Addons/EstimateGeneration/bin/pdf_geometry_extract.py','--input',$path,'--workspace',dirname($path),'--contract-vector']);}
function captureDwg(string $path): array {$binary=getenv('LIBREDWG_DWGREAD_BINARY')?:getenv('USERPROFILE').'/.cache/most-libredwg/0.13.4/win64/dwgread.exe';return runCapture(['python',dirname(__DIR__,4).'/app/BusinessModules/Addons/EstimateGeneration/bin/cad_geometry_extract.py','--input',$path,'--workspace',dirname($path),'--dwgread',$binary]);}
function parserProof(string $source,array $payload): array {$canonical=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);return ['schema_version'=>1,'source_sha256'=>hash('sha256',$source),'runtime_version'=>$payload['runtime_version'],'canonical_output_sha256'=>hash('sha256',$canonical),'entity_count'=>count($payload['entities']),'text_count'=>count($payload['texts']),'dimension_count'=>count($payload['dimensions'])];}

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
