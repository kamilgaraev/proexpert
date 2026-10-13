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
    public function test_pir_reuses_resolved_projection_for_unchanged_model_in_same_request(): void
    {
        $derivative = new DesignModelDerivative([
            'status' => 'ready',
            'metadata' => [
                'converter_version' => DesignViewerConverter::version(),
                'geometry' => ['points' => array_fill(0, 2000, [1.5, 2.5, 3.5])],
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
            $request = Request::create('/api/v1/admin/design-management/packages');
            $payload = (new DesignModelDerivativeResource($derivative))->resolve($request);
            for ($i = 0; $i < 5; $i++) {
                self::assertSame($payload, (new DesignModelDerivativeResource($derivative))->resolve($request));
            }
            $payload['metadata']['geometry']['points'][0][0] = 99;
            $next = (new DesignModelDerivativeResource($derivative))->resolve($request);
            self::assertSame([1.5, 2.5, 3.5], $next['metadata']['geometry']['points'][0]);
            self::assertArrayNotHasKey('coordinate_transformations', $next['metadata']);
            self::assertArrayNotHasKey('transformations', $next['metadata']['ifc_metadata']);
            self::assertSame(1, $reads);
        } finally {
            Json::decodeUsing(null);
        }
    }

    public function test_projection_is_fresh_for_new_request_and_model_changes(): void
    {
        $derivative = new DesignModelDerivative([
            'status' => 'ready',
            'metadata' => ['converter_version' => DesignViewerConverter::version(), 'geometry' => ['count' => 1]],
        ]);
        $reads = 0;
        Json::decodeUsing(function (mixed $value, ?bool $associative = true) use (&$reads): mixed {
            $reads++;

            return json_decode($value, $associative);
        });
        try {
            $request = Request::create('/api/v1/admin/design-management/packages');
            $first = (new DesignModelDerivativeResource($derivative))->resolve($request);
            $second = (new DesignModelDerivativeResource($derivative))->resolve(Request::create('/api/v1/admin/design-management/packages'));
            self::assertSame($first, $second);
            self::assertSame(2, $reads);
            $derivative->status = 'failed';
            $derivative->failed_reason = 'changed';
            $derivative->metadata = ['converter_version' => DesignViewerConverter::version(), 'geometry' => ['count' => 2]];
            $changed = (new DesignModelDerivativeResource($derivative))->resolve($request);
            self::assertSame('failed', $changed['status']);
            self::assertSame('changed', $changed['failed_reason']);
            self::assertSame(2, $changed['metadata']['geometry']['count']);
            self::assertSame(3, $reads);
        } finally {
            Json::decodeUsing(null);
        }
    }

    public function test_projection_keeps_distinct_instances_separate_even_when_ids_match(): void
    {
        $request = Request::create('/api/v1/admin/design-management/packages');
        $ready = new DesignModelDerivative(['id' => 77, 'status' => 'ready', 'metadata' => ['converter_version' => DesignViewerConverter::version()]]);
        $failed = new DesignModelDerivative(['id' => 77, 'status' => 'failed', 'failed_reason' => 'distinct']);
        self::assertSame('ready', (new DesignModelDerivativeResource($ready))->resolve($request)['status']);
        $payload = (new DesignModelDerivativeResource($failed))->resolve($request);
        self::assertSame('failed', $payload['status']);
        self::assertSame('distinct', $payload['failed_reason']);
    }

    public function test_non_list_paths_do_not_reuse_projection(): void
    {
        $derivative = new DesignModelDerivative(['status' => 'failed', 'metadata' => ['geometry' => ['count' => 1]]]);
        $reads = 0;
        Json::decodeUsing(function (mixed $value, ?bool $associative = true) use (&$reads): mixed {
            $reads++;

            return json_decode($value, $associative);
        });
        try {
            foreach ([Request::create('/'), Request::create('/api/v1/admin/design-management/packages/1'), Request::create('/api/v1/admin/design-management/packages', 'POST')] as $request) {
                (new DesignModelDerivativeResource($derivative))->resolve($request);
                (new DesignModelDerivativeResource($derivative))->resolve($request);
            }
            self::assertSame(6, $reads);
        } finally {
            Json::decodeUsing(null);
        }
    }

    public function test_projection_tracks_converter_version_and_locale(): void
    {
        $version = DesignViewerConverter::version();
        $locale = app()->getLocale();
        $derivative = new DesignModelDerivative(['status' => 'ready', 'metadata' => ['converter_version' => $version]]);
        $reads = 0;
        Json::decodeUsing(function (mixed $value, ?bool $associative = true) use (&$reads): mixed {
            $reads++;

            return json_decode($value, $associative);
        });
        try {
            $request = Request::create('/api/v1/admin/design-management/packages');
            self::assertSame('ready', (new DesignModelDerivativeResource($derivative))->resolve($request)['status']);
            config(['design_management.viewer_converter_version' => $version + 1]);
            $stale = (new DesignModelDerivativeResource($derivative))->resolve($request);
            self::assertSame('missing', $stale['status']);
            self::assertSame($version + 1, $stale['metadata']['required_converter_version']);
            app()->setLocale($locale === 'en' ? 'ru' : 'en');
            (new DesignModelDerivativeResource($derivative))->resolve($request);
            self::assertSame(3, $reads);
        } finally {
            Json::decodeUsing(null);
            config(['design_management.viewer_converter_version' => $version]);
            app()->setLocale($locale);
        }
    }

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
