<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionProviderException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\VisionOutboundDataPolicy;
use PHPUnit\Framework\TestCase;

final class VisionOutboundDataPolicyTest extends TestCase
{
    public function test_arbitration_preserves_claim_and_evidence_but_excludes_server_and_unknown_nested_fields(): void
    {
        $source = ['arbitration' => ['contract' => 'document-arbitration:v3', 'source_version' => 'sha256:'.str_repeat('a', 64),
            'minority_evidence_required' => true, 'quarantined_items' => ['private-canary'],
            'claims' => [['id' => 'literal:1', 'role' => 'observer_literal', 'entity_key' => 'room:1', 'fact_type' => 'length',
                'value' => ['type' => 'number', 'data' => '10', 'secret' => 'private-canary'], 'unit' => 'm',
                'evidence_ref' => 'literal:dimension-1', 'explicit_evidence' => true, 'extra' => 'private-canary',
                'locator' => ['page_id' => 17, 'page_number' => 2, 'processing_unit_id' => 19, 'source_version' => 'sha256:'.str_repeat('a', 64),
                    'coordinate_space' => 'normalized_derivative_v1', 'explicit' => true,
                    'organization_id' => 7, 'project_id' => 3, 'session_id' => 11, 'storage_url' => 'private-canary']]]],
            'geometry_expert' => ['private-canary'], 'observer' => ['private-canary'], 'ledger' => ['private-canary']];
        $projected = VisionOutboundDataPolicy::metadata($source, true, false);
        self::assertSame(['arbitration'], array_keys($projected));
        $claim = $projected['arbitration']['claims'][0];
        self::assertSame(['type' => 'number', 'data' => '10'], $claim['value']);
        self::assertSame('literal:dimension-1', $claim['evidence_ref']);
        self::assertSame(17, $claim['locator']['page_id']);
        self::assertArrayNotHasKey('organization_id', $claim['locator']);
        self::assertStringNotContainsString('private-canary', json_encode($projected, JSON_THROW_ON_ERROR));
        self::assertSame(7, $source['arbitration']['claims'][0]['locator']['organization_id']);
    }

    public function test_geometry_preserves_canonical_operands_and_supporting_references_only(): void
    {
        $fingerprint = str_repeat('b', 64);
        $source = ['geometry_expert' => ['contract' => 'geometry-expert:v1', 'source_version' => 'sha256:'.str_repeat('a', 64),
            'storage_url' => 'private-canary', 'arbitration' => ['fingerprint' => $fingerprint, 'source' => ['actor' => 'private-canary'],
                'quarantined_intents' => ['private-canary'], 'decisions' => [['claim_id' => 'literal:1', 'status' => 'accepted',
                    'supporting_claim_ids' => ['literal:1', 'risk:1'], 'evidence_refs' => ['literal:dimension-1'],
                    'reason_code' => 'explicit_dimension', 'reason' => 'Dimension is visible.', 'secret' => 'private-canary',
                    'canonical_claim' => ['entity_key' => 'room:1', 'fact_type' => 'length', 'unit' => 'm', 'source_claim_id' => 'literal:1',
                        'value' => ['type' => 'number', 'data' => '10', 'secret' => 'private-canary'], 'ledger' => 'private-canary']]]]]];
        $projected = VisionOutboundDataPolicy::metadata($source, false, true);
        $arbitration = $projected['geometry_expert']['arbitration'];
        self::assertSame($fingerprint, $arbitration['fingerprint']);
        self::assertSame(['literal:1', 'risk:1'], $arbitration['decisions'][0]['supporting_claim_ids']);
        self::assertSame(['type' => 'number', 'data' => '10'], $arbitration['decisions'][0]['canonical_claim']['value']);
        self::assertSame('literal:1', $arbitration['decisions'][0]['canonical_claim']['source_claim_id']);
        self::assertStringNotContainsString('private-canary', json_encode($projected, JSON_THROW_ON_ERROR));
    }

    public function test_private_nested_object_cannot_travel_in_an_allowlisted_scalar(): void
    {
        $this->expectException(VisionProviderException::class);
        $this->expectExceptionMessage('vision_outbound_context_invalid');
        VisionOutboundDataPolicy::metadata(['arbitration' => ['claims' => [['value' => ['type' => 'number',
            'data' => ['secret' => 'private-canary']]]]]], true, false);
    }

    public function test_bounds_and_capabilities_cannot_forward_arbitrary_objects(): void
    {
        $this->expectException(VisionProviderException::class);
        VisionOutboundDataPolicy::metadata(['capabilities' => ['page_render' => ['secret' => 'private-canary']]], false, false);
    }
}
