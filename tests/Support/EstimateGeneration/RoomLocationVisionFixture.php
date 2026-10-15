<?php

declare(strict_types=1);

namespace Tests\Support\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationInputBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers\ObserverInputBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers\ObserverProfile;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\ProjectiveTransformFactory;
use App\BusinessModules\Addons\EstimateGeneration\Vision\RoleVisionResponseCanonicalizer;

/** Recorded provider observations through the real observer contract, without HTTP or paid calls. */
final class RoomLocationVisionFixture
{
    public static function claims(array $scope, int $documentId, string $version, string $sheetType, ?string $floor = '-1', string $variant = 'valid', ?\App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\ProjectiveTransformData $transform = null): array
    {
        [$organization, $project, $session] = $scope;
        $nativeReference = $variant === 'native_unicode' ? 'pdf:'.str_repeat('я', 150) : 'pdf:page:1/text';
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $content = (string) ob_get_clean();
        $source = new VisionDocumentInput($organization, $project, $session, $documentId, 17, 1, 19, $version,
            'sha256:'.hash('sha256', $content), 'image/png', $content, 'high',
            new AiOperationContext(AiOperationContext::deterministicId('room-location:correlation'),
                AiOperationContext::deterministicId('room-location:'.$documentId),
                $organization, $project, $session, 'understand_documents', 'vision', 1, $documentId, 17, 19),
            $transform ?? (new ProjectiveTransformFactory)->identity(), nativeReferences: [$nativeReference]);
        $input = (new ObserverInputBuilder)->build($source, ObserverProfile::Literal, static function (): void {});
        $facts = $evidence = [];
        foreach (['room_number' => '001', 'room_floor' => $floor, 'room_zone' => 'A'] as $type => $value) {
            if ($value === null) {
                continue;
            }
            $evidence[] = ['key' => $type, 'locator' => ['document_id' => 999, 'page_number' => 99,
                'floor_id' => 'forged', 'explicit' => false]];
            $facts[] = ['entityKey' => $type === 'room_floor' && $variant === 'other_room' ? 'room:other' : 'room:office',
                'factType' => $type, 'value' => ['type' => 'string', 'data' => $value], 'unit' => null,
                'evidenceRef' => $type,
                'sourcePolygonOrNativeRef' => match ($variant) {
                    'horizontal' => [[0.1, 0.5], [0.9, 0.5]],
                    'vertical' => [[0.5, 0.1], [0.5, 0.9]],
                    'diagonal' => [[0.1, 0.1], [0.9, 0.9]],
                    'numeric_string' => [['0.1', '0.5'], ['0.9', '0.5']],
                    'native', 'native_unicode' => $nativeReference,
                    default => $type === 'room_floor' && $variant === 'implicit' ? null : [[0.1, 0.1], [0.4, 0.1], [0.4, 0.2], [0.1, 0.2]],
                },
                'confidence' => 0.9, 'contractVersion' => 'sheet-analysis:v3'];
        }
        $payload = (new RoleVisionResponseCanonicalizer)->canonicalize([
            'schema_version' => 3, 'sheet_type' => $sheetType, 'evidence' => $evidence,
            'elements' => [], 'scale_candidates' => [], 'warnings' => ['scale_missing'], 'visual_attributes' => [],
            'project_sheet_analysis' => ['contractVersion' => 'sheet-analysis:v3',
                'role' => $sheetType === 'floor_plan' ? 'plan' : 'explication', 'facts' => $facts],
        ], $input)->payload;
        $analysis = VisionAnalysisData::fromProviderArray($payload, 'timeweb', 'openai/gpt-6-luna',
            'openai/gpt-6-luna', 'recorded-luna-v1', 'measured', 10, 10, 100, nativeReferences: $input->nativeReferences);
        $runs = ['observer_literal' => new AiRoleRunResult([
            'schema_version' => 3, 'role' => 'observer_literal',
            'source' => ['document_id' => $documentId, 'page_id' => 17, 'page_number' => 1, 'source_version' => $version],
            'observation' => ['sheet_type' => $analysis->sheetType],
            'claims' => $analysis->projectSheetAnalysis->facts,
            'evidence' => array_map(static fn ($proof): array => $variant === 'already_source'
                ? [...$proof->toArray(), 'locator' => [...$proof->locator, 'coordinate_space' => 'normalized_source_v1']]
                : $proof->toArray(), $analysis->evidence),
        ], AiOperationContext::deterministicId('room-location:physical:'.$documentId))];

        return (new ArbitrationInputBuilder)->claimBatch($source, $runs)->claims;
    }
}
