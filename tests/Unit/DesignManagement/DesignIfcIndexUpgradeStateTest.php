<?php

declare(strict_types=1);

namespace Tests\Unit\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Support\DesignIfcIndexUpgradeState;
use App\BusinessModules\Features\DesignManagement\Support\DesignViewerConverter;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignModelDerivativeResource;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class DesignIfcIndexUpgradeStateTest extends TestCase
{
    public function test_legacy_published_geometry_is_current_before_during_and_after_failed_index_upgrade(): void
    {
        $previous = Container::getInstance();
        $previousFacade = Facade::getFacadeApplication();
        $container = new Container;
        $container->instance('app', new class
        {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        $container->instance('config', new Repository(['design_management' => ['viewer_converter_version' => 4]]));
        $container->instance('translator', new Translator(new FileLoader(new Filesystem, dirname(__DIR__, 3).'/lang'), 'ru'));
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        try {
            foreach ([null, 'queued', 'processing', 'failed'] as $upgradeStatus) {
                $metadata = ['converter_version' => 4, 'ifc_index_schema_version' => 1];
                if ($upgradeStatus !== null) {
                    $metadata['index_upgrade'] = ['generation' => 'replacement', 'status' => $upgradeStatus];
                }
                $derivative = new DesignModelDerivative(['status' => 'ready', 'derivative_file_path' => 'org-2/published/model.frag', 'metadata' => $metadata]);
                self::assertTrue(DesignViewerConverter::isCurrent($derivative), (string) $upgradeStatus);
                self::assertFalse(DesignViewerConverter::isStale($derivative), (string) $upgradeStatus);
                self::assertFalse(DesignViewerConverter::isIndexCurrent($derivative), (string) $upgradeStatus);
                self::assertSame('ready', $derivative->status->value);
                $resource = (new DesignModelDerivativeResource($derivative))->toArray(Request::create('/'));
                self::assertSame('ready', $resource['status']);
                self::assertSame(1, $resource['metadata']['ifc_index_schema_version']);
                self::assertArrayNotHasKey('is_stale', $resource['metadata']);
            }
            $oldGeometry = new DesignModelDerivative(['status' => 'ready', 'metadata' => ['converter_version' => 3, 'ifc_index_schema_version' => 2]]);
            self::assertFalse(DesignViewerConverter::isCurrent($oldGeometry));
            self::assertTrue(DesignViewerConverter::isIndexCurrent($oldGeometry));
        } finally {
            Container::setInstance($previous);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousFacade);
        }
    }

    public function test_upgrade_generation_fences_old_jobs_without_replacing_published_generation(): void
    {
        $metadata = ['generation' => 'published', 'index_upgrade' => ['generation' => 'replacement', 'status' => 'queued']];
        self::assertSame('replacement', DesignIfcIndexUpgradeState::generation($metadata));
        self::assertTrue(DesignIfcIndexUpgradeState::active($metadata));
        self::assertSame('published', $metadata['generation']);
        self::assertSame('published', DesignIfcIndexUpgradeState::generation(['generation' => 'published']));
    }

    public function test_upgrade_source_snapshot_rejects_foreign_versions_and_changed_source(): void
    {
        $metadata = ['index_upgrade' => ['source_version_id' => 7, 'source_path' => 'org-2/a.ifc', 'source_sha256' => 'original']];
        self::assertTrue(DesignIfcIndexUpgradeState::sourceMatches($metadata, 7, 'org-2/a.ifc', 'original'));
        self::assertFalse(DesignIfcIndexUpgradeState::sourceMatches($metadata, 8, 'org-2/a.ifc', 'original'));
        self::assertFalse(DesignIfcIndexUpgradeState::sourceMatches($metadata, 7, 'org-3/a.ifc', 'original'));
        self::assertFalse(DesignIfcIndexUpgradeState::sourceMatches($metadata, 7, 'org-2/a.ifc', 'changed'));
    }

    public function test_normal_generation_does_not_require_upgrade_snapshot(): void
    {
        self::assertTrue(DesignIfcIndexUpgradeState::sourceMatches(['generation' => 'normal'], 7, 'org-2/a.ifc', null));
        self::assertFalse(DesignIfcIndexUpgradeState::active(['generation' => 'normal']));
    }
}
