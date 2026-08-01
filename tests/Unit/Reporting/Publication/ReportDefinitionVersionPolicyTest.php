<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Catalog\ReportPermissionCatalog;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportDefinitionSemanticFingerprint;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportDefinitionVersionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFormulaConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportManifestSemanticValidator;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlReportManifestLoader;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use InvalidArgumentException;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Reporting\CatalogBindingTestFactory;

final class ReportDefinitionVersionPolicyTest extends TestCase
{
    public function test_isolated_source_schema_drift_requires_only_source_version(): void
    {
        [$current, $candidate, $evidence] = $this->scenario(
            static fn (array &$row) => $row['versions']['source_schema'] = '1.0.1',
            sourceHashDigit: '4',
        );

        $diff = (new ReportDefinitionVersionPolicy)->assertAllowed($current, $candidate, $evidence);

        self::assertTrue($diff->sourceSchemaChanged);
        self::assertFalse($diff->formulaChanged);
        self::assertFalse($diff->contractChanged);
        self::assertFalse($diff->rendererChanged);
    }

    public function test_formula_fingerprint_change_without_version_change_is_rejected(): void
    {
        [$current, $candidate, $evidence] = $this->scenario(
            static function (array &$row): void {},
            formulaHashDigit: '4',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_definition_version_change_required');

        (new ReportDefinitionVersionPolicy)->assertAllowed($current, $candidate, $evidence);
    }

    public function test_source_fingerprint_change_without_version_change_is_rejected(): void
    {
        [$current, $candidate, $evidence] = $this->scenario(
            static function (array &$row): void {},
            sourceHashDigit: '4',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_definition_version_change_required');

        (new ReportDefinitionVersionPolicy)->assertAllowed($current, $candidate, $evidence);
    }

    public function test_formula_version_bump_with_unchanged_fingerprint_is_rejected(): void
    {
        [$current, $candidate, $evidence] = $this->scenario(
            static fn (array &$row) => $row['versions']['formula'] = '1.0.1',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_definition_version_bump_without_change');

        (new ReportDefinitionVersionPolicy)->assertAllowed($current, $candidate, $evidence);
    }

    public function test_source_version_bump_with_unchanged_fingerprint_is_rejected(): void
    {
        [$current, $candidate, $evidence] = $this->scenario(
            static fn (array &$row) => $row['versions']['source_schema'] = '1.0.1',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_definition_version_bump_without_change');

        (new ReportDefinitionVersionPolicy)->assertAllowed($current, $candidate, $evidence);
    }

    public function test_candidate_fingerprint_not_derived_from_evidence_is_rejected(): void
    {
        [$current, $candidate, $evidence] = $this->scenario(
            static function (array &$row): void {
                $row['semantic_fingerprints']['formula'] = str_repeat('f', 64);
            },
            replaceCandidateFingerprints: false,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_definition_semantic_fingerprint_evidence_mismatch');

        (new ReportDefinitionVersionPolicy)->assertAllowed($current, $candidate, $evidence);
    }

    public function test_filter_change_requires_source_and_contract_versions(): void
    {
        [$current, $candidate, $evidence] = $this->scenario(
            static function (array &$row): void {
                $row['filters'] = [['id' => 'organization_id', 'weight' => 1.0]];
                $row['versions']['source_schema'] = '1.0.1';
                $row['versions']['contract'] = '1.0.1';
            },
        );

        $diff = (new ReportDefinitionVersionPolicy)->assertAllowed($current, $candidate, $evidence);

        self::assertTrue($diff->sourceSchemaChanged);
        self::assertTrue($diff->contractChanged);
    }

    public function test_permission_and_readiness_change_preserves_semantic_versions(): void
    {
        [$current, $candidate, $evidence] = $this->scenario(
            static function (array &$row): void {
                $row['permissions']['sensitive'] = ['budgeting.portfolio_dashboard.view'];
                $row['capabilities']['supports_subscriptions'] = true;
                $row['capabilities']['reproducible_scheduled_snapshot'] = true;
            },
        );

        $diff = (new ReportDefinitionVersionPolicy)->assertAllowed($current, $candidate, $evidence);

        self::assertTrue($diff->permissionsChanged);
        self::assertTrue($diff->readinessChanged);
        self::assertFalse($diff->formulaChanged);
        self::assertFalse($diff->sourceSchemaChanged);
    }

    private function scenario(
        callable $mutate,
        string $formulaHashDigit = '3',
        string $sourceHashDigit = '1',
        bool $replaceCandidateFingerprints = true,
    ): array {
        $document = Yaml::parseFile($this->candidatePath());
        $currentEvidence = $this->evidence($document['definitions'][0]);
        $this->setFingerprints($document['definitions'][0], $currentEvidence);
        $current = $this->loadDocument($document)->definitions[0];

        $mutate($document['definitions'][0]);
        $candidateEvidence = $this->evidence(
            $document['definitions'][0],
            $formulaHashDigit,
            $sourceHashDigit,
        );
        if ($replaceCandidateFingerprints) {
            $this->setFingerprints($document['definitions'][0], $candidateEvidence);
        }
        $candidate = $this->loadDocument($document)->definitions[0];

        return [
            $current,
            $candidate,
            $this->evidence($candidate, $formulaHashDigit, $sourceHashDigit),
        ];
    }

    private function setFingerprints(
        array &$row,
        ReportDefinitionConformanceEvidence $evidence,
    ): void {
        $builder = new ReportDefinitionSemanticFingerprint;
        $row['semantic_fingerprints'] = [
            'formula' => $builder->formula($evidence),
            'source' => $builder->source($row, $evidence),
        ];
    }

    private function evidence(
        array $row,
        string $formulaHashDigit = '3',
        string $sourceHashDigit = '1',
    ): ReportDefinitionConformanceEvidence {
        $definition = (new ReportDefinitionFactory)->fromManifest($row);
        $base = CatalogBindingTestFactory::evidence(
            $definition,
            CatalogBindingTestFactory::binding($definition),
            new Sha256Hash(str_repeat('f', 64)),
        );

        return new ReportDefinitionConformanceEvidence(
            $base->code,
            $base->definitionHash,
            $base->contractVersion,
            $base->sourceSchemaVersion,
            $base->fixtureHash,
            new ReportSourceConformanceEvidence(
                new Sha256Hash(str_repeat($sourceHashDigit, 64)),
                $base->source->snapshotKind,
                $base->source->snapshotId,
                $base->source->rowCount,
                $base->source->rowsHash,
                true,
                $base->source->assertionCodes,
            ),
            new ReportFormulaConformanceEvidence(
                $base->formula->formulaVersion,
                new Sha256Hash(str_repeat($formulaHashDigit, 64)),
                true,
                $base->formula->assertionCodes,
            ),
            $base->componentClassHashes,
            $base->assertionCount,
            $base->status,
            $base->commitSha,
            $base->generatedAt,
        );
    }

    private function loadDocument(array $document): \App\BusinessModules\Core\Reporting\Domain\DTO\LoadedReportManifest
    {
        return $this->loader()->loadManagement(
            'data://text/plain;base64,'.base64_encode(
                Yaml::dump($document, 20, 2, Yaml::DUMP_OBJECT_AS_MAP),
            ),
            $this->schemaPath(),
        );
    }

    private function loader(): YamlReportManifestLoader
    {
        return new YamlReportManifestLoader(
            new Draft202012SchemaValidator(new CompliantValidator),
            new ReportManifestSemanticValidator,
            new ReportPermissionCatalog,
        );
    }

    private function candidatePath(): string
    {
        return dirname(__DIR__, 4).'/tests/Fixtures/Reporting/Publication/candidate.valid.yaml';
    }

    private function schemaPath(): string
    {
        return dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/resources/management-catalog.v1.schema.json';
    }
}
