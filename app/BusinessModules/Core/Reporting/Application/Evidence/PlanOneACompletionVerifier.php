<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Evidence;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Opis\JsonSchema\CompliantValidator;
use Symfony\Component\Process\Process;
use Throwable;

final class PlanOneACompletionVerifier
{
    private const LOCK_PATH = 'docs/reports/contracts/plan-1a-contract-lock.json';
    private const COMPLETION_SCHEMA_PATH = 'docs/reports/contracts/plan-1a-completion.schema.json';
    private const COMPLETION_ARTIFACT_PATH = 'build/reports/plan-1a-completion.json';
    private const AUTHORIZATION_SCHEMA_PATH = 'docs/reports/contracts/plan-1a-gate-evidence.schema.json';
    private const AUTHORIZATION_ARTIFACT_PATH = 'build/reports/plan-1a-ci-authorization.json';

    private const AUTHORIZATION_CASES = [
        ['unauthenticated_catalog_denied', 401],
        ['non_admin_catalog_denied', 403],
        ['module_disabled_catalog_denied', 403],
        ['missing_global_permission_catalog_denied', 403],
        ['view_actor_catalog_allowed', 200],
        ['view_actor_run_status_allowed', 200],
        ['view_actor_rows_allowed', 200],
        ['view_actor_run_create_denied', 403],
        ['view_actor_export_create_denied', 403],
        ['view_actor_download_denied', 403],
        ['runner_run_create_allowed', 201],
        ['runner_run_retry_allowed', 202],
        ['runner_run_cancel_allowed', 200],
        ['runner_export_denied', 403],
        ['exporter_export_allowed', 201],
        ['exporter_download_denied', 403],
        ['downloader_revoked_definition_denied', 403],
        ['manage_does_not_expand_operational_permissions', 403],
        ['foreign_and_nonexistent_filter_indistinguishable', 422],
        ['foreign_and_nonexistent_source_indistinguishable', 422],
        ['blocked_actor_denied_after_context_reload', 403],
        ['deleted_actor_denied_after_context_reload', 403],
    ];

    private const CASE_KEYS = [
        'case_id',
        'status',
        'request_count',
        'response_statuses',
        'response_codes',
        'action_calls',
        'actor_loads',
        'assertions',
    ];

    public function assertReady(
        string $lock,
        string $completionSchema,
        string $completionArtifact,
        string $authorizationSchema,
        string $authorizationArtifact,
    ): PlanOneACompletionRef {
        try {
            $relativePaths = [
                $lock,
                $completionSchema,
                $completionArtifact,
                $authorizationSchema,
                $authorizationArtifact,
            ];
            if ($relativePaths !== [
                self::LOCK_PATH,
                self::COMPLETION_SCHEMA_PATH,
                self::COMPLETION_ARTIFACT_PATH,
                self::AUTHORIZATION_SCHEMA_PATH,
                self::AUTHORIZATION_ARTIFACT_PATH,
            ]) {
                $this->fail();
            }
            $root = $this->repositoryRoot($lock);
            $canonicalRoot = realpath($root);
            if (!is_string($canonicalRoot) || !is_dir($canonicalRoot)) {
                $this->fail();
            }
            $canonicalExpected = [
                $canonicalRoot.'/'.self::LOCK_PATH,
                $canonicalRoot.'/'.self::COMPLETION_SCHEMA_PATH,
                $canonicalRoot.'/'.self::COMPLETION_ARTIFACT_PATH,
                $canonicalRoot.'/'.self::AUTHORIZATION_SCHEMA_PATH,
                $canonicalRoot.'/'.self::AUTHORIZATION_ARTIFACT_PATH,
            ];
            foreach ($relativePaths as $index => $path) {
                $this->assertExactRegularFile($path, $canonicalExpected[$index]);
            }

            $lockBytes = $this->read($lock);
            $completionSchemaBytes = $this->read($completionSchema);
            $completionBytes = $this->read($completionArtifact);
            $completion = $this->validateDocument($completionBytes, $completionSchemaBytes);
            $lockSha256 = hash('sha256', $lockBytes);
            if (!isset($completion['contract_lock_sha256'])
                || !is_string($completion['contract_lock_sha256'])
                || !hash_equals($completion['contract_lock_sha256'], $lockSha256)) {
                $this->fail();
            }

            $commit = $completion['commit_sha'] ?? null;
            if (!is_string($commit) || preg_match('/^[a-f0-9]{40}$/', $commit) !== 1) {
                $this->fail();
            }

            $authorizationSchemaBytes = $this->read($authorizationSchema);
            $this->assertCommitBlobEquals($root, $commit, self::AUTHORIZATION_SCHEMA_PATH, $authorizationSchemaBytes);
            $authorizationBytes = $this->read($authorizationArtifact);
            $authorization = $this->validateDocument($authorizationBytes, $authorizationSchemaBytes);
            $this->assertAuthorization($authorization);

            $expectedAuthorizationDigest = $completion['ci_http_matrices']['authorization']['artifact_sha256'] ?? null;
            $authorizationSha256 = hash('sha256', $authorizationBytes);
            if (!is_string($expectedAuthorizationDigest)
                || !hash_equals($expectedAuthorizationDigest, $authorizationSha256)) {
                $this->fail();
            }

            $generatedAt = $completion['commands'][0]['executed_at'] ?? null;
            if (!is_string($generatedAt)) {
                $this->fail();
            }

            return new PlanOneACompletionRef(
                $lockSha256,
                hash('sha256', $completionBytes),
                new DateTimeImmutable($generatedAt, new DateTimeZone('UTC')),
                $completion['status'],
            );
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR, previous: $exception);
        }
    }

    private function repositoryRoot(string $lock): string
    {
        if ($lock !== self::LOCK_PATH) {
            $this->fail();
        }
        $canonicalLock = realpath($lock);
        if (!is_string($canonicalLock)) {
            $this->fail();
        }
        $normalized = $this->normalize($canonicalLock);
        $suffix = '/'.self::LOCK_PATH;
        if (!str_ends_with(strtolower($normalized), strtolower($suffix))) {
            $this->fail();
        }
        $root = substr($normalized, 0, -strlen($suffix));

        return $root;
    }

    private function assertExactRegularFile(string $path, string $canonicalExpected): void
    {
        if (!is_file($path)
            || is_link($path)) {
            $this->fail();
        }
        $real = realpath($path);
        if ($real === false
            || !$this->pathEquals($real, $canonicalExpected)) {
            $this->fail();
        }
    }

    private function read(string $path): string
    {
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            $this->fail();
        }

        return $bytes;
    }

    private function validateDocument(string $documentBytes, string $schemaBytes): array
    {
        try {
            $document = json_decode($documentBytes, false, 512, JSON_THROW_ON_ERROR);
            $schema = json_decode($schemaBytes, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR, previous: $exception);
        }
        if (!is_object($document) || !is_object($schema)
            || !(new CompliantValidator())->validate($document, $schema)->isValid()) {
            $this->fail();
        }

        return json_decode($documentBytes, true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertCommitBlobEquals(
        string $root,
        string $commit,
        string $path,
        string $workingBytes,
    ): void {
        $process = new Process(['git', 'show', $commit.':'.$path], $root);
        $process->setTimeout(10);
        $process->run();
        if (!$process->isSuccessful() || !hash_equals($process->getOutput(), $workingBytes)) {
            $this->fail();
        }
    }

    private function assertAuthorization(array $authorization): void
    {
        if (($authorization['artifact_id'] ?? null) !== 'plan_1a_ci_authorization'
            || ($authorization['verification_mode'] ?? null) !== 'hermetic_http'
            || ($authorization['status'] ?? null) !== 'passed'
            || ($authorization['counts'] ?? null) !== [
                'cases' => 22,
                'passed' => 22,
                'allowed_cases' => 7,
                'denied_cases' => 15,
                'http_requests' => 28,
                'assertions' => 132,
            ]
            || !isset($authorization['cases'])
            || !is_array($authorization['cases'])
            || !array_is_list($authorization['cases'])
            || count($authorization['cases']) !== count(self::AUTHORIZATION_CASES)) {
            $this->fail();
        }

        $allowed = 0;
        $denied = 0;
        $requests = 0;
        $assertions = 0;
        foreach (self::AUTHORIZATION_CASES as $index => [$caseId, $status]) {
            $case = $authorization['cases'][$index];
            if (!is_array($case)
                || array_keys($case) !== self::CASE_KEYS
                || ($case['case_id'] ?? null) !== $caseId
                || ($case['status'] ?? null) !== $status
                || !is_int($case['request_count'] ?? null)
                || !is_int($case['assertions'] ?? null)) {
                $this->fail();
            }
            $status >= 200 && $status < 300 ? $allowed++ : $denied++;
            $requests += $case['request_count'];
            $assertions += $case['assertions'];
        }
        if ([$allowed, $denied, $requests, $assertions] !== [7, 15, 28, 132]) {
            $this->fail();
        }
    }

    private function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function pathEquals(string $left, string $right): bool
    {
        return strcasecmp($this->normalize($left), $this->normalize($right)) === 0;
    }

    private function fail(): never
    {
        throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
    }
}
