<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCandidateValidationItem;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCandidateValidationResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogActivationInputs;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlReportManifestLoader;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Closure;
use RuntimeException;

/** Validates and materializes the candidate-only activation bundle. */
final class ReportCatalogActivationInputBundleLoader
{
    /**
     * @param Closure(array):array<ReportDefinitionBinding> $bindingLoader
     * @param Closure(array):array<ReportDefinitionConformanceEvidence> $conformanceLoader
     */
    public function __construct(
        private readonly string $repositoryRoot,
        private readonly Draft202012SchemaValidator $schemas,
        private readonly YamlReportManifestLoader $manifests,
        private readonly string $manifestSchemaPath,
        private readonly Closure $bindingLoader,
        private readonly Closure $conformanceLoader,
    ) {
    }

    public function load(string $path): ReportCatalogActivationInputs
    {
        $bytes = @file_get_contents($path);
        if (! is_string($bytes)) {
            throw new RuntimeException('report_catalog_activation_inputs_unreadable');
        }
        $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($document) || array_is_list($document) || CanonicalJson::encode($document)."\n" !== $bytes) {
            throw new RuntimeException('report_catalog_activation_inputs_noncanonical');
        }
        $schemaBytes = @file_get_contents($this->repositoryRoot.'/docs/reports/contracts/report-catalog-activation-input-bundle.schema.json');
        if (! is_string($schemaBytes)) {
            throw new RuntimeException('report_catalog_activation_inputs_schema_unreadable');
        }
        $schema = json_decode($schemaBytes, false, 512, JSON_THROW_ON_ERROR);
        $json = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR);
        if (! is_object($schema) || ! is_object($json)) {
            throw new RuntimeException('report_catalog_activation_inputs_schema_invalid');
        }
        $this->schemas->assertValid($json, $schema, 'most.report-catalog-activation-input-bundle.v1');
        $this->assertSemantics($document);

        $manifest = $document['candidate_manifest'];
        $manifestBytes = $manifest['bytes'] ?? null;
        if (! is_string($manifestBytes) || ! hash_equals($manifest['sha256'] ?? '', hash('sha256', $manifestBytes))) {
            throw new RuntimeException('report_catalog_activation_inputs_manifest_invalid');
        }
        $candidate = $this->manifests->loadManagement(
            'data://text/plain;base64,'.base64_encode($manifestBytes),
            $this->manifestSchemaPath,
        );
        $validation = [];
        foreach ($document['validation_items'] as $item) {
            $validation[] = new ReportCandidateValidationItem($item['code'], new Sha256Hash($item['definition_hash']), true, []);
        }
        $bindings = ($this->bindingLoader)($document['bindings']);
        $conformance = ($this->conformanceLoader)($document['conformance_records']);
        foreach ($bindings as $binding) {
            if (! $binding instanceof ReportDefinitionBinding) {
                throw new RuntimeException('report_catalog_activation_inputs_binding_invalid');
            }
        }
        foreach ($conformance as $record) {
            if (! $record instanceof ReportDefinitionConformanceEvidence) {
                throw new RuntimeException('report_catalog_activation_inputs_conformance_invalid');
            }
        }

        return new ReportCatalogActivationInputs($candidate, new ReportCandidateValidationResult($validation), $bindings, $conformance, $document['plan_evidence_documents']);
    }

    private function assertSemantics(array $document): void
    {
        if (($document['artifact_id'] ?? null) !== 'report_catalog_activation_inputs'
            || ($document['schema_version'] ?? null) !== '1.0.0'
            || ($document['status'] ?? null) !== 'activation_inputs_passed'
            || ! is_array($document['counts'] ?? null)
            || ($document['counts']['candidate_payloads'] ?? null) !== 28) {
            throw new RuntimeException('report_catalog_activation_inputs_invalid');
        }
        $sections = ['candidate_payloads', 'validation_items', 'bindings', 'conformance_records'];
        $codes = null;
        foreach ($sections as $section) {
            $rows = $document[$section] ?? null;
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== 28) {
                throw new RuntimeException('report_catalog_activation_inputs_count_invalid');
            }
            $current = array_column($rows, 'code');
            if (count($current) !== 28 || count(array_unique($current)) !== 28 || $current !== array_filter($current, 'is_string')) {
                throw new RuntimeException('report_catalog_activation_inputs_code_set_invalid');
            }
            if ($codes === null) {
                $codes = $current;
            } elseif ($codes !== $current) {
                throw new RuntimeException('report_catalog_activation_inputs_code_order_invalid');
            }
        }
        foreach ($document['validation_items'] as $item) {
            if (($item['passed'] ?? null) !== true || ($item['failure_codes'] ?? null) !== []) {
                throw new RuntimeException('report_catalog_activation_inputs_validation_invalid');
            }
        }
        foreach (['candidate_manifest', 'candidate_payloads', 'validation_items', 'bindings', 'conformance_records', 'plan_evidence_documents'] as $section) {
            if (($document['section_hashes'][$section] ?? null) !== hash('sha256', CanonicalJson::encode($document[$section]))) {
                throw new RuntimeException('report_catalog_activation_inputs_section_hash_invalid');
            }
        }
    }
}
