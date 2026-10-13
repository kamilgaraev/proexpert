<?php

declare(strict_types=1);

namespace Tests\Unit\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignModelDerivativeResource;
use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignPackageResource;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Support\DesignViewerConverter;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Tests\TestCase;

final class DesignModelDerivativeResourcePerformanceTest extends TestCase
{
    public function test_decoded_metadata_keeps_mutation_requests_instances_and_converter_checks_fresh(): void
    {
        $converter = DesignViewerConverter::version();
        $request = Request::create('/api/v1/admin/design-management/packages');
        $this->app->instance('request', $request);
        $derivative = new DesignModelDerivative(['id' => 77, 'status' => 'ready', 'metadata' => ['converter_version' => $converter, 'geometry' => ['count' => 1]]]);
        $reads = 0;
        Json::decodeUsing(function (mixed $value, ?bool $associative = true) use (&$reads): mixed {
            $reads++;

            return json_decode($value, $associative);
        });
        try {
            $metadata = DesignViewerConverter::metadata($derivative);
            $metadata['geometry']['count'] = 99;
            self::assertSame(1, DesignViewerConverter::metadata($derivative)['geometry']['count']);
            self::assertFalse(DesignViewerConverter::isStale($derivative));
            config(['design_management.viewer_converter_version' => $converter + 1]);
            self::assertTrue(DesignViewerConverter::isStale($derivative));
            self::assertTrue(DesignViewerConverter::isCurrent($derivative, ['converter_version' => $converter + 1]));
            $derivative->status = 'failed';
            self::assertFalse(DesignViewerConverter::isStale($derivative));
            self::assertSame(1, $reads);
            $derivative->metadata = ['converter_version' => $converter + 1, 'geometry' => ['count' => 2]];
            self::assertSame(2, DesignViewerConverter::metadata($derivative)['geometry']['count']);
            $distinct = new DesignModelDerivative(['id' => 77, 'metadata' => ['geometry' => ['count' => 3]]]);
            self::assertSame(3, DesignViewerConverter::metadata($distinct)['geometry']['count']);
            $this->app->instance('request', Request::create('/api/v1/admin/design-management/packages'));
            self::assertSame(2, DesignViewerConverter::metadata($derivative)['geometry']['count']);
            self::assertSame(4, $reads);
        } finally {
            Json::decodeUsing(null);
            config(['design_management.viewer_converter_version' => $converter]);
        }
    }

    public function test_decoded_metadata_is_not_reused_outside_the_pir_list_get(): void
    {
        $derivative = new DesignModelDerivative(['metadata' => ['geometry' => ['count' => 1]]]);
        $reads = 0;
        Json::decodeUsing(function (mixed $value, ?bool $associative = true) use (&$reads): mixed {
            $reads++;

            return json_decode($value, $associative);
        });
        try {
            foreach ([Request::create('/'), Request::create('/api/v1/admin/design-management/packages/1'), Request::create('/api/v1/admin/design-management/packages', 'POST')] as $request) {
                $this->app->instance('request', $request);
                DesignViewerConverter::metadata($derivative);
                DesignViewerConverter::metadata($derivative);
            }
            self::assertSame(6, $reads);
        } finally {
            Json::decodeUsing(null);
        }
    }

    public function test_package_workflow_and_resources_share_one_metadata_decode(): void
    {
        $request = Request::create('/api/v1/admin/design-management/packages');
        $this->app->instance('request', $request);
        $package = new DesignPackage(['id' => 1, 'status' => 'draft', 'metadata' => []]);
        $artifact = new DesignArtifact(['id' => 11, 'artifact_type' => 'model', 'metadata' => []]);
        $version = new DesignArtifactVersion(['id' => 21, 'status' => 'current', 'is_current' => true]);
        $derivative = new DesignModelDerivative([
            'id' => 31, 'status' => 'ready', 'viewer_provider' => 'thatopen', 'derivative_format' => 'thatopen_frag',
            'metadata' => ['converter_version' => DesignViewerConverter::version(), 'geometry' => ['points' => array_fill(0, 2000, [1.5, 2.5, 3.5])]],
        ]);
        foreach (['artifacts' => new Collection([$artifact]), 'sections' => new Collection, 'latestCompletenessCheck' => null, 'project' => null, 'reviewComments' => new Collection, 'workflowEvents' => new Collection] as $name => $value) {
            $package->setRelation($name, $value);
        }
        foreach (['section' => null, 'currentVersion' => $version, 'versions' => new Collection([$version]), 'sheets' => new Collection, 'package' => $package] as $name => $value) {
            $artifact->setRelation($name, $value);
        }
        foreach (['artifact' => $artifact, 'derivatives' => new Collection([$derivative]), 'readyDerivative' => $derivative, 'sheets' => new Collection] as $name => $value) {
            $version->setRelation($name, $value);
        }
        $encoded = $derivative->getAttributes()['metadata'];
        $reads = 0;
        Json::decodeUsing(function (mixed $value, ?bool $associative = true) use ($encoded, &$reads): mixed {
            $reads += $value === $encoded ? 1 : 0;

            return json_decode($value, $associative);
        });
        try {
            $payload = (new DesignPackageResource($package))->response($request)->getData(true)['data'];
            self::assertSame('ready', $payload['viewer_status']);
            self::assertSame('ready', $payload['derivative']['status']);
            self::assertSame('ready', $payload['current_version']['derivative']['status']);
            self::assertTrue($payload['workflow_summary']['has_ready_viewer']);
            self::assertSame(2000, count($payload['derivative']['metadata']['geometry']['points']));
            self::assertSame(1, $reads);
        } finally {
            Json::decodeUsing(null);
        }
    }

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
        app('translator')->addLines(['design_management.statuses.derivatives.missing' => 'fixture-RU'], 'ru');
        app('translator')->addLines(['design_management.statuses.derivatives.missing' => 'fixture-EN'], 'en');
        app()->setLocale('ru');
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
            self::assertSame('fixture-RU', $stale['status_label']);
            app()->setLocale('en');
            $localized = (new DesignModelDerivativeResource($derivative))->resolve($request);
            self::assertSame('fixture-EN', $localized['status_label']);
            self::assertSame(1, $reads);
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
