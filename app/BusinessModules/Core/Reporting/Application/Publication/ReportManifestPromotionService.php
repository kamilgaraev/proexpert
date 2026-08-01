<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\LoadedReportManifest;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedDefinitionRelease;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCandidateValidationItem;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCandidateValidationResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationLock;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\PublishedReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlReportManifestLoader;
use App\BusinessModules\Core\Reporting\Infrastructure\Publication\FilesystemReportPublicationLedger;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

final class ReportManifestPromotionService
{
    private object $lockSchema;

    public function __construct(
        private ReportDefinitionVersionPolicy $versions,
        private ReportDefinitionCanonicalProjector $projector,
        private ReportDefinitionFactory $definitions,
        private YamlReportManifestLoader $manifests,
        private Draft202012SchemaValidator $schemas,
        private string $manifestSchemaPath,
        string $lockSchemaPath,
        private ?FilesystemReportPublicationLedger $ledger = null,
        private ?string $ledgerPath = null,
    ) {
        if (($ledger === null) !== ($ledgerPath === null)) {
            throw new InvalidArgumentException('report_publication_ledger_configuration_invalid');
        }

        $schemaBytes = @file_get_contents($lockSchemaPath);
        if (! is_string($schemaBytes)) {
            throw new RuntimeException('report_publication_lock_schema_unreadable');
        }
        try {
            $schema = json_decode($schemaBytes, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('report_publication_lock_schema_invalid', 0, $exception);
        }
        if (! is_object($schema)) {
            throw new RuntimeException('report_publication_lock_schema_invalid');
        }
        $this->lockSchema = $schema;
    }

    public function promote(
        LoadedReportManifest $current,
        LoadedReportManifest $candidateManifest,
        CandidateReportDefinition $candidate,
        ReportCandidateValidationResult $validation,
        ReportDefinitionConformanceEvidence $conformance,
        Sha256Hash $expectedCandidateBytes,
        string $releaseSha,
        DateTimeImmutable $publishedAt,
    ): PublishedDefinitionRelease {
        $this->assertManifestHeaders($current, $candidateManifest);
        if (! hash_equals($candidateManifest->bytesHash->value, $expectedCandidateBytes->value)) {
            throw new InvalidArgumentException('report_promotion_candidate_bytes_mismatch');
        }

        $currentRows = $this->index($current);
        $candidateRows = $this->index($candidateManifest);
        $candidateRow = $candidateRows[$candidate->code] ?? null;
        $currentRow = $currentRows[$candidate->code] ?? null;
        $expectedCandidate = is_array($candidateRow)
            ? $this->definitions->fromManifest($candidateRow)
            : null;
        if (! is_array($candidateRow)
            || ! is_array($currentRow)
            || $expectedCandidate === null
            || ! $this->projector->equals($expectedCandidate, $candidate->payload())) {
            throw new InvalidArgumentException('report_promotion_candidate_wrapper_mismatch');
        }

        $this->assertValidation($candidateManifest, $validation, $candidate);
        $this->assertConformance($candidate, $conformance);
        $this->assertCandidateReadiness($candidateRow);
        $this->versions->assertAllowed($currentRow, $candidateRow, $conformance);
        $this->assertUnrelatedDefinitionsUnchanged($currentRows, $candidateRows, $candidate->code);

        $publishedRows = $candidateManifest->definitions;
        $targetOrdinal = $this->targetOrdinal($publishedRows, $candidate->code);
        $publishedRows[$targetOrdinal]['readiness']['publication'] = 'published';
        $expectedPublishedRows = $publishedRows;

        $publishedBytes = Yaml::dump(
            [
                'catalog' => $candidateManifest->catalog,
                'contract_version' => $candidateManifest->contractVersion,
                'definitions' => $publishedRows,
            ],
            20,
            2,
            Yaml::DUMP_OBJECT_AS_MAP,
        );
        if (! str_ends_with($publishedBytes, "\n")
            || str_ends_with($publishedBytes, "\n\n")
            || str_contains($publishedBytes, "\r")
            || str_starts_with($publishedBytes, "\xEF\xBB\xBF")) {
            throw new RuntimeException('report_promotion_published_bytes_invalid');
        }

        $reloaded = $this->manifests->loadManagement(
            'data://text/plain;base64,'.base64_encode($publishedBytes),
            $this->manifestSchemaPath,
        );
        if (CanonicalJson::encode($reloaded->definitions) !== CanonicalJson::encode($expectedPublishedRows)) {
            throw new InvalidArgumentException('report_promotion_rendered_output_changed');
        }

        $published = (new PublishedReportDefinitionRegistry(
            $reloaded,
            $this->definitions,
        ))->published($candidate->code);
        $this->assertPublishedPayload(
            $this->definitions->fromManifest($expectedPublishedRows[$targetOrdinal]),
            $published->payload(),
        );

        $publishedHash = new Sha256Hash(hash('sha256', $publishedBytes));
        $lock = new ReportPublicationLock(
            $candidate->code,
            $current->bytesHash,
            $candidateManifest->bytesHash,
            $publishedHash,
            $published->definitionHash,
            $conformance->digest(),
            $releaseSha,
            $publishedAt,
        );
        $this->schemas->assertValid(
            $this->toObject($lock->canonicalPayload()),
            $this->lockSchema,
            'most.report-publication-lock.v1',
        );

        if ($this->ledger !== null && $this->ledgerPath !== null) {
            $this->ledger->append($this->ledgerPath, $lock);
        }

        return new PublishedDefinitionRelease(
            $published,
            $lock,
            $publishedBytes,
            $publishedHash,
        );
    }

    private function assertManifestHeaders(
        LoadedReportManifest $current,
        LoadedReportManifest $candidate,
    ): void {
        if ($current->catalog !== 'management-catalog.v1'
            || ! hash_equals($current->catalog, $candidate->catalog)
            || ! hash_equals($current->contractVersion, $candidate->contractVersion)) {
            throw new InvalidArgumentException('report_promotion_manifest_identity_mismatch');
        }
    }

    private function assertValidation(
        LoadedReportManifest $manifest,
        ReportCandidateValidationResult $validation,
        CandidateReportDefinition $target,
    ): void {
        $expected = [];
        foreach ($manifest->definitions as $row) {
            $readiness = $row['readiness'] ?? null;
            if (is_array($readiness) && ($readiness['publication'] ?? null) === 'candidate') {
                $definition = $this->definitions->fromManifest($row);
                $expected[] = [$definition->code, $definition->definitionHash->value];
            }
        }

        if (count($expected) !== count($validation->items) || $expected === []) {
            throw new InvalidArgumentException('report_promotion_validation_set_mismatch');
        }
        foreach ($expected as $ordinal => [$code, $hash]) {
            $item = $validation->items[$ordinal] ?? null;
            if (! $item instanceof ReportCandidateValidationItem
                || ! $item->passed
                || $item->failureCodes !== []
                || ! hash_equals($code, $item->code)
                || ! hash_equals($hash, $item->definitionHash->value)) {
                throw new InvalidArgumentException('report_promotion_validation_set_mismatch');
            }
        }
        $targetItem = $validation->item($target->code);
        if (! $targetItem->passed
            || ! hash_equals($targetItem->definitionHash->value, $target->definitionHash->value)) {
            throw new InvalidArgumentException('report_promotion_target_validation_failed');
        }
    }

    private function assertConformance(
        CandidateReportDefinition $candidate,
        ReportDefinitionConformanceEvidence $conformance,
    ): void {
        $definition = $candidate->payload();
        $digest = $conformance->digest();
        if (! $conformance->passed()
            || ! hash_equals($candidate->code, $conformance->code)
            || ! hash_equals($candidate->definitionHash->value, $conformance->definitionHash->value)
            || ! hash_equals($definition->contractVersion, $conformance->contractVersion)
            || ! hash_equals($definition->sourceSchemaVersion, $conformance->sourceSchemaVersion)
            || ! hash_equals($definition->formulaVersion, $conformance->formula->formulaVersion)
            || ! hash_equals($digest->value, $conformance->digest()->value)) {
            throw new InvalidArgumentException('report_promotion_conformance_mismatch');
        }
    }

    private function assertCandidateReadiness(array $row): void
    {
        $readiness = $row['readiness'] ?? null;
        if (! is_array($readiness)
            || ($readiness['source'] ?? null) !== 'ready'
            || ($readiness['formula'] ?? null) !== 'ready'
            || ($readiness['delivery'] ?? null) !== 'verified'
            || ($readiness['publication'] ?? null) !== 'candidate') {
            throw new InvalidArgumentException('report_promotion_candidate_readiness_invalid');
        }
    }

    private function assertUnrelatedDefinitionsUnchanged(
        array $current,
        array $candidate,
        string $targetCode,
    ): void {
        if (array_keys($current) !== array_keys($candidate)) {
            throw new InvalidArgumentException('report_promotion_definition_set_changed');
        }
        foreach ($current as $code => $row) {
            if ($code !== $targetCode
                && CanonicalJson::encode($row) !== CanonicalJson::encode($candidate[$code])) {
                throw new InvalidArgumentException('report_promotion_unrelated_definition_changed');
            }
        }
    }

    private function assertPublishedPayload(
        \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition $expected,
        \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition $published,
    ): void {
        if (! $this->projector->equals($expected, $published)) {
            throw new InvalidArgumentException('report_promotion_published_payload_mismatch');
        }
    }

    private function index(LoadedReportManifest $manifest): array
    {
        $indexed = [];
        foreach ($manifest->definitions as $row) {
            $code = $row['code'] ?? null;
            if (! is_string($code) || isset($indexed[$code])) {
                throw new InvalidArgumentException('report_promotion_manifest_definition_invalid');
            }
            $indexed[$code] = $row;
        }

        return $indexed;
    }

    private function targetOrdinal(array $definitions, string $code): int
    {
        foreach ($definitions as $ordinal => $row) {
            if (($row['code'] ?? null) === $code) {
                return $ordinal;
            }
        }

        throw new InvalidArgumentException('report_promotion_target_not_found');
    }

    private function toObject(array $value): object
    {
        return json_decode(
            json_encode($value, JSON_THROW_ON_ERROR),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
