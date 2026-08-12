<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateChangeProposalResource;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class EstimateChangeProposalResourceTest extends TestCase
{
    public function test_it_exposes_only_bounded_business_data(): void
    {
        $resource = new EstimateChangeProposalResource([
            'id' => 'proposal-1',
            'intent' => 'correct_fact',
            'command_excerpt' => 'Исправить площадь',
            'before_payload' => ['area' => '100.0000', 'prompt' => 'secret'],
            'after_payload' => ['area' => '110.0000', 'exception' => 'internal'],
            'affected_payload' => ['count' => 1],
            'assumptions' => [],
            'questions' => [],
            'evidence' => [
                ['artifact_id' => 7, 'page' => 2, 'native_reference' => 'АР-2', 'prompt' => 'secret'],
                ['artifact_id' => 'foreign'],
            ],
            'cost_delta_known' => true,
            'cost_delta' => '1250.5000',
            'status' => 'proposed',
            'status_version' => 1,
            'version_fence' => ['draft_version' => 'secret'],
            'result' => ['internal' => true],
            'failure_code' => 'provider_exception',
        ]);

        $payload = $resource->toArray(Request::create('/'));

        self::assertSame(['area' => '100.0000'], $payload['before_payload']);
        self::assertSame(['area' => '110.0000'], $payload['after_payload']);
        self::assertSame([['artifact_id' => 7, 'page' => 2, 'native_reference' => 'АР-2']], $payload['evidence']);
        self::assertArrayNotHasKey('version_fence', $payload);
        self::assertArrayNotHasKey('result', $payload);
        self::assertArrayNotHasKey('failure_code', $payload);
    }
}
