<?php

declare(strict_types=1);

namespace Tests\Unit\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignModelDerivativeResource;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Support\DesignViewerConverter;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Http\Request;
use Tests\TestCase;

final class DesignModelDerivativeResourcePerformanceTest extends TestCase
{
    public function test_resource_decodes_metadata_once_and_preserves_converter_status(): void
    {
        $current = DesignViewerConverter::version();
        foreach ([['ready', $current, 'ready'], ['ready', $current - 1, 'missing'], ['failed', $current - 1, 'failed']] as [$storedStatus, $converter, $expectedStatus]) {
            $derivative = new DesignModelDerivative([
                'status' => $storedStatus,
                'metadata' => [
                    'converter_version' => $converter,
                    'geometry' => ['summary' => str_repeat('x', 100000)],
                    'coordinate_transformations' => [[1, 0]],
                    'ifc_metadata' => ['indexed_element_count' => 4, 'transformations' => [[0, 1]]],
                ],
            ]);
            $encoded = $derivative->getAttributes()['metadata'];
            $reads = 0;
            Json::decodeUsing(function (mixed $value, ?bool $associative = true) use ($encoded, &$reads): mixed {
                if ($value === $encoded) {
                    $reads++;
                }

                return json_decode($value, $associative);
            });
            try {
                $payload = (new DesignModelDerivativeResource($derivative))->resolve(Request::create('/'));
            } finally {
                Json::decodeUsing(null);
            }

            self::assertSame($expectedStatus, $payload['status']);
            self::assertSame($converter, $payload['metadata']['converter_version']);
            self::assertSame(100000, strlen($payload['metadata']['geometry']['summary']));
            self::assertSame(4, $payload['metadata']['ifc_metadata']['indexed_element_count']);
            self::assertArrayNotHasKey('coordinate_transformations', $payload['metadata']);
            self::assertArrayNotHasKey('transformations', $payload['metadata']['ifc_metadata']);
            if ($expectedStatus === 'missing') {
                self::assertTrue($payload['metadata']['is_stale']);
                self::assertSame($current, $payload['metadata']['required_converter_version']);
            } else {
                self::assertArrayNotHasKey('is_stale', $payload['metadata']);
            }
            self::assertSame(1, $reads);
        }
    }
}
