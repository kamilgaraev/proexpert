<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantDesignAdditionalMetadata as Metadata;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

final class AssistantDesignAdditionalMetadataTest extends TestCase
{
    public function test_all_twenty_remaining_models_have_safe_fields_canonical_rights_and_explicit_ancestry(): void
    {
        $definitions = Metadata::entityDefinitions();
        self::assertCount(20, $definitions);
        $actualModels = glob(dirname(__DIR__, 3).'/app/BusinessModules/Features/DesignManagement/Models/*.php');
        self::assertIsArray($actualModels);
        $alreadyCovered = ['DesignPackage','DesignArtifact','DesignArtifactVersion','DesignReviewComment','DesignModelSet'];
        $expected = array_values(array_filter(array_map(static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME), $actualModels),
            static fn (string $name): bool => ! in_array($name, $alreadyCovered, true)));
        $classes = array_map(static fn (array $record): string => $record[0], Metadata::records());
        sort($expected); sort($classes);
        self::assertSame($expected, $classes);
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/config/ModuleList/features/design-management.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($definitions as $type => [$source, $class, $domain]) {
            self::assertSame('design_additional', $source);
            self::assertTrue(is_subclass_of($class, Model::class));
            foreach (Metadata::entityPermissions()[$type] as $permission) { self::assertContains($permission, $manifest['permissions']); }
            self::assertSame([], array_intersect(Metadata::fields()[$type], Metadata::technicalExclusions()), $type);
            self::assertSame([], array_intersect(Metadata::safeSelectColumns()[$type], Metadata::technicalExclusions()), $type);
            self::assertTrue(isset(Metadata::globalCatalogEntities()[$type]) || isset(Metadata::parentColumns()[$type]), $type);
            self::assertArrayHasKey($domain, Metadata::domainGates());
        }
        self::assertSame('express_id', Metadata::parentColumns()['bim_progress_group_element']['element_id']['key']);
        self::assertSame(['version_id' => 'version_id'], Metadata::parentColumns()['bim_progress_group_element']['element_id']['matches']);
        self::assertSame(['status' => 'active'], Metadata::rowPredicates()['design_normative_source']);
        self::assertSame(['type' => 'design_normative_source', 'nullable' => true, 'match_project' => false], Metadata::parentColumns()['design_document_template']['normative_source_id']);
        self::assertSame(['design-management.normative_catalog.view'], Metadata::entityPermissions()['design_document_template']);
        self::assertArrayNotHasKey('design_package', $definitions);
    }
}
