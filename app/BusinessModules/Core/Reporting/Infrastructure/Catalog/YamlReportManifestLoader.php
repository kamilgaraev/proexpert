<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Application\Catalog\ReportPermissionCatalog;
use App\BusinessModules\Core\Reporting\Domain\DTO\LoadedReportManifest;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use JsonException;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class YamlReportManifestLoader
{
    public function __construct(
        private Draft202012SchemaValidator $schemas,
        private ReportManifestSemanticValidator $semantics,
        private ReportPermissionCatalog $permissions,
    ) {}

    public function loadManagement(string $path, string $schemaPath): LoadedReportManifest
    {
        $loaded = $this->load($path, $schemaPath, 'most.management-catalog.v1');
        $this->semantics->assertManagement($loaded['document']);
        $this->permissions->assertKnownAndTranslated($this->permissionSlugs($loaded['document']));

        return new LoadedReportManifest(
            $this->string($loaded['document'], 'catalog'),
            $this->string($loaded['document'], 'contract_version'),
            $loaded['hash'],
            $this->definitions($loaded['document']),
        );
    }

    public function loadOfficial(string $path, string $schemaPath): LoadedReportManifest
    {
        $loaded = $this->load($path, $schemaPath, 'most.official-document-catalog.v1');
        $this->semantics->assertOfficial($loaded['document']);

        return new LoadedReportManifest(
            $this->string($loaded['document'], 'catalog'),
            $this->string($loaded['document'], 'contract_version'),
            $loaded['hash'],
            $this->definitions($loaded['document']),
        );
    }

    private function load(string $path, string $schemaPath, string $schemaId): array
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException('report_manifest_unreadable');
        }
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            throw new RuntimeException('report_manifest_utf8_invalid');
        }

        $hash = new Sha256Hash(hash('sha256', $bytes));
        try {
            $document = Yaml::parse($bytes);
        } catch (ParseException $exception) {
            throw new RuntimeException('report_manifest_yaml_invalid', 0, $exception);
        }
        if (! is_array($document) || array_is_list($document)) {
            throw new RuntimeException('report_manifest_document_invalid');
        }

        $schemaBytes = @file_get_contents($schemaPath);
        if ($schemaBytes === false) {
            throw new RuntimeException('report_manifest_schema_unreadable');
        }

        try {
            $documentObject = json_decode(
                json_encode($document, JSON_THROW_ON_ERROR),
                false,
                512,
                JSON_THROW_ON_ERROR,
            );
            $schema = json_decode($schemaBytes, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('report_manifest_json_conversion_invalid', 0, $exception);
        }
        if (! is_object($documentObject) || ! is_object($schema)) {
            throw new RuntimeException('report_manifest_schema_invalid');
        }

        $this->schemas->assertValid($documentObject, $schema, $schemaId);

        return ['document' => $document, 'hash' => $hash];
    }

    private function permissionSlugs(array $document): array
    {
        $slugs = [];
        foreach ($this->definitions($document) as $definition) {
            $permissions = $definition['permissions'] ?? null;
            if (! is_array($permissions)) {
                throw new RuntimeException('report_manifest_permission_policy_invalid');
            }
            foreach ($permissions as $items) {
                if (! is_array($items) || ! array_is_list($items)) {
                    throw new RuntimeException('report_manifest_permission_policy_invalid');
                }
                foreach ($items as $slug) {
                    if (! is_string($slug)) {
                        throw new RuntimeException('report_manifest_permission_policy_invalid');
                    }
                    $slugs[$slug] = $slug;
                }
            }
        }

        return array_values($slugs);
    }

    private function definitions(array $document): array
    {
        $definitions = $document['definitions'] ?? null;
        if (! is_array($definitions) || ! array_is_list($definitions)) {
            throw new RuntimeException('report_manifest_definitions_invalid');
        }

        foreach ($definitions as $definition) {
            if (! is_array($definition) || array_is_list($definition)) {
                throw new RuntimeException('report_manifest_definition_invalid');
            }
        }

        return $definitions;
    }

    private function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (! is_string($value)) {
            throw new RuntimeException('report_manifest_header_invalid');
        }

        return $value;
    }
}
