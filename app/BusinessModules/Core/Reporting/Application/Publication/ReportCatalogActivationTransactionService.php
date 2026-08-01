<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\LoadedReportManifest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCandidateValidationResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogActivation;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlReportManifestLoader;
use App\BusinessModules\Core\Reporting\Infrastructure\Publication\FilesystemReportPublicationLedger;
use Closure;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final class ReportCatalogActivationTransactionService
{
    private Closure $atomicRename;

    public function __construct(
        private ReportCatalogActivationService $activation,
        private ReportManifestPromotionService $promotions,
        private ReportDefinitionFactory $definitions,
        private YamlReportManifestLoader $manifests,
        private FilesystemReportPublicationLedger $ledger,
        private string $manifestSchemaPath,
        ?Closure $atomicRename = null,
    ) {
        $this->atomicRename = $atomicRename
            ?? static fn (string $from, string $to): bool => rename($from, $to);
    }

    public function activate(
        string $currentPath,
        string $ledgerPath,
        LoadedReportManifest $current,
        LoadedReportManifest $candidate,
        ReportCandidateValidationResult $validation,
        iterable $candidateBindings,
        iterable $conformanceEvidence,
        array $planEvidenceDocuments,
        string $releaseSha,
        DateTimeImmutable $activatedAt,
    ): ReportCatalogActivation {
        $bindings = $this->bindings($candidateBindings);
        $conformance = $this->conformance($conformanceEvidence);
        $this->activation->activate(
            $current,
            $candidate,
            $validation,
            $bindings,
            $conformance,
            $planEvidenceDocuments,
            $releaseSha,
            $activatedAt,
        );
        $this->assertActivePaths($currentPath, $ledgerPath);

        $lockPath = $ledgerPath.'.activation.lock';
        $handle = fopen($lockPath, 'c+b');
        if (! is_resource($handle) || ! flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('report_catalog_activation_lock_failed');
        }

        try {
            return $this->activateUnderLock(
                $currentPath,
                $ledgerPath,
                $current,
                $candidate,
                $validation,
                $bindings,
                $conformance,
                $releaseSha,
                $activatedAt,
            );
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function activateUnderLock(
        string $currentPath,
        string $ledgerPath,
        LoadedReportManifest $current,
        LoadedReportManifest $candidate,
        ReportCandidateValidationResult $validation,
        array $bindings,
        array $conformance,
        string $releaseSha,
        DateTimeImmutable $activatedAt,
    ): ReportCatalogActivation {
        $currentBytes = $this->read($currentPath);
        $ledgerBytes = $this->read($ledgerPath);
        if (! hash_equals($current->bytesHash->value, hash('sha256', $currentBytes))) {
            throw new RuntimeException('report_catalog_activation_current_bytes_changed');
        }
        $this->assertEmptyLedger($ledgerBytes);

        $stage = dirname($currentPath).DIRECTORY_SEPARATOR.'.report-catalog-activation-'.bin2hex(random_bytes(12));
        if (! mkdir($stage, 0700)) {
            throw new RuntimeException('report_catalog_activation_stage_failed');
        }
        $stagedManifest = $stage.DIRECTORY_SEPARATOR.'management-catalog.v1.yaml';
        $stagedLedger = $stage.DIRECTORY_SEPARATOR.'report-publication-ledger.v1.json';

        try {
            if (file_put_contents($stagedLedger, $ledgerBytes, LOCK_EX) !== strlen($ledgerBytes)) {
                throw new RuntimeException('report_catalog_activation_stage_failed');
            }

            $working = $current;
            $locks = [];
            $conformanceHashes = [];
            $publishedCodes = [];
            foreach ($candidate->definitions as $candidateRow) {
                $code = $candidateRow['code'] ?? null;
                if (! is_string($code)) {
                    throw new RuntimeException('report_catalog_activation_candidate_invalid');
                }
                $singleCandidate = $this->singleCandidateManifest($working, $candidate, $code);
                $candidateDefinition = new CandidateReportDefinition(
                    $this->definitions->fromManifest($this->definition($candidate, $code)),
                );
                $release = $this->promotions->promote(
                    $working,
                    $singleCandidate,
                    $candidateDefinition,
                    new ReportCandidateValidationResult([$validation->item($code)]),
                    $conformance[$code],
                    $singleCandidate->bytesHash,
                    $releaseSha,
                    $activatedAt,
                );
                $working = $this->loadBytes($release->publishedBytes);
                $this->ledger->append($stagedLedger, $release->lock);
                $publishedCodes[] = $code;
                $locks[] = $release->lock->digest()->value;
                $conformanceHashes[] = $conformance[$code]->digest()->value;
            }

            $finalManifestBytes = $this->manifestBytes($working);
            if (file_put_contents($stagedManifest, $finalManifestBytes, LOCK_EX) !== strlen($finalManifestBytes)) {
                throw new RuntimeException('report_catalog_activation_stage_failed');
            }
            $finalManifest = $this->manifests->loadManagement($stagedManifest, $this->manifestSchemaPath);
            $finalLedgerBytes = $this->read($stagedLedger);
            $this->assertFinalArtifacts($finalManifest, $finalLedgerBytes, $publishedCodes, $locks, $conformanceHashes, $releaseSha);

            $this->replacePair($currentPath, $ledgerPath, $stagedManifest, $stagedLedger, $stage);
            $published = $this->manifests->loadManagement($currentPath, $this->manifestSchemaPath);
            if (! hash_equals($finalManifest->bytesHash->value, $published->bytesHash->value)
                || ! hash_equals($finalLedgerBytes, $this->read($ledgerPath))) {
                throw new RuntimeException('report_catalog_activation_reread_failed');
            }

            return new ReportCatalogActivation(
                'catalog_activated',
                $releaseSha,
                $current->bytesHash,
                $published->bytesHash,
                $publishedCodes,
                array_map(static fn (ReportDefinitionBinding $binding): string => $binding->code, $bindings),
                $locks,
                $conformanceHashes,
                $activatedAt,
            );
        } finally {
            $this->removeDirectory($stage);
        }
    }

    private function singleCandidateManifest(LoadedReportManifest $working, LoadedReportManifest $candidate, string $code): LoadedReportManifest
    {
        $definitions = $working->definitions;
        foreach ($definitions as $index => $row) {
            if (($row['code'] ?? null) === $code) {
                $definitions[$index] = $this->definition($candidate, $code);

                return $this->loadBytes(Yaml::dump([
                    'catalog' => $working->catalog,
                    'contract_version' => $working->contractVersion,
                    'definitions' => $definitions,
                ], 20, 2, Yaml::DUMP_OBJECT_AS_MAP));
            }
        }

        throw new RuntimeException('report_catalog_activation_candidate_invalid');
    }

    private function definition(LoadedReportManifest $manifest, string $code): array
    {
        foreach ($manifest->definitions as $definition) {
            if (($definition['code'] ?? null) === $code) {
                return $definition;
            }
        }

        throw new RuntimeException('report_catalog_activation_candidate_invalid');
    }

    private function manifestBytes(LoadedReportManifest $manifest): string
    {
        return Yaml::dump([
            'catalog' => $manifest->catalog,
            'contract_version' => $manifest->contractVersion,
            'definitions' => $manifest->definitions,
        ], 20, 2, Yaml::DUMP_OBJECT_AS_MAP);
    }

    private function loadBytes(string $bytes): LoadedReportManifest
    {
        return $this->manifests->loadManagement(
            'data://text/plain;base64,'.base64_encode($bytes),
            $this->manifestSchemaPath,
        );
    }

    private function bindings(iterable $bindings): array
    {
        $result = [];
        foreach ($bindings as $binding) {
            if (! $binding instanceof ReportDefinitionBinding || isset($result[$binding->code])) {
                throw new RuntimeException('report_catalog_activation_binding_invalid');
            }
            $result[$binding->code] = $binding;
        }

        return array_values($result);
    }

    private function conformance(iterable $evidence): array
    {
        $result = [];
        foreach ($evidence as $item) {
            if (! $item instanceof ReportDefinitionConformanceEvidence || ! $item->passed() || isset($result[$item->code])) {
                throw new RuntimeException('report_catalog_activation_conformance_invalid');
            }
            $result[$item->code] = $item;
        }

        return $result;
    }

    private function assertActivePaths(string $currentPath, string $ledgerPath): void
    {
        if (dirname($currentPath) !== dirname($ledgerPath)
            || is_link($currentPath)
            || is_link($ledgerPath)
            || ! is_file($currentPath)
            || ! is_file($ledgerPath)) {
            throw new RuntimeException('report_catalog_activation_paths_invalid');
        }
    }

    private function assertEmptyLedger(string $bytes): void
    {
        $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($document)
            || $document !== ['artifact_id' => 'report_publication_ledger', 'events' => [], 'schema_version' => '1.0.0']
            || $bytes !== json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n") {
            throw new RuntimeException('report_catalog_activation_ledger_invalid');
        }
    }

    private function assertFinalArtifacts(LoadedReportManifest $manifest, string $ledgerBytes, array $codes, array $locks, array $conformance, string $releaseSha): void
    {
        if (count($codes) !== 28 || count($locks) !== 28 || count($conformance) !== 28) {
            throw new RuntimeException('report_catalog_activation_count_invalid');
        }
        foreach ($manifest->definitions as $definition) {
            if (($definition['readiness']['publication'] ?? null) !== 'published') {
                throw new RuntimeException('report_catalog_activation_manifest_incomplete');
            }
        }
        $ledger = json_decode($ledgerBytes, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($ledger) || count($ledger['events'] ?? []) !== 28) {
            throw new RuntimeException('report_catalog_activation_ledger_incomplete');
        }
        foreach ($ledger['events'] as $index => $event) {
            $lock = $event['lock'] ?? null;
            if (! is_array($lock)
                || ($lock['code'] ?? null) !== $codes[$index]
                || ($lock['release_sha'] ?? null) !== $releaseSha
                || ($event['lock_digest'] ?? null) !== $locks[$index]
                || ($lock['conformance_hash'] ?? null) !== $conformance[$index]) {
                throw new RuntimeException('report_catalog_activation_ledger_incomplete');
            }
        }
    }

    private function replacePair(string $currentPath, string $ledgerPath, string $stagedManifest, string $stagedLedger, string $stage): void
    {
        $manifestBackup = $stage.DIRECTORY_SEPARATOR.'previous-management-catalog.v1.yaml';
        $ledgerBackup = $stage.DIRECTORY_SEPARATOR.'previous-report-publication-ledger.v1.json';
        $manifestBacked = false;
        $ledgerBacked = false;
        try {
            if (! ($this->atomicRename)($currentPath, $manifestBackup)) {
                throw new RuntimeException('report_catalog_activation_replace_failed');
            }
            $manifestBacked = true;
            if (! ($this->atomicRename)($ledgerPath, $ledgerBackup)) {
                throw new RuntimeException('report_catalog_activation_replace_failed');
            }
            $ledgerBacked = true;
            if (! ($this->atomicRename)($stagedManifest, $currentPath)
                || ! ($this->atomicRename)($stagedLedger, $ledgerPath)) {
                throw new RuntimeException('report_catalog_activation_replace_failed');
            }
            if (! unlink($manifestBackup) || ! unlink($ledgerBackup)) {
                throw new RuntimeException('report_catalog_activation_cleanup_failed');
            }
        } catch (Throwable $exception) {
            $this->restore($currentPath, $manifestBackup, $manifestBacked);
            $this->restore($ledgerPath, $ledgerBackup, $ledgerBacked);
            throw $exception instanceof RuntimeException
                ? $exception
                : new RuntimeException('report_catalog_activation_replace_failed', 0, $exception);
        }
    }

    private function restore(string $path, string $backup, bool $backed): void
    {
        if (! $backed || ! is_file($backup)) {
            return;
        }
        if (is_file($path) && ! unlink($path)) {
            throw new RuntimeException('report_catalog_activation_rollback_failed');
        }
        if (! ($this->atomicRename)($backup, $path)) {
            throw new RuntimeException('report_catalog_activation_rollback_failed');
        }
    }

    private function read(string $path): string
    {
        $bytes = @file_get_contents($path);
        if (! is_string($bytes)) {
            throw new RuntimeException('report_catalog_activation_read_failed');
        }

        return $bytes;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_file($directory.DIRECTORY_SEPARATOR.$entry)) {
                unlink($directory.DIRECTORY_SEPARATOR.$entry);
            }
        }
        rmdir($directory);
    }
}
