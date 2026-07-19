<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ImmutableAuditDeploymentAdmissionTest extends TestCase
{
    public function test_backend_workflow_is_valid_yaml_and_enforces_staged_admission_order(): void
    {
        $root = dirname(__DIR__, 3);
        $workflow = file_get_contents($root.'/.github/workflows/deploy-backend.yml');

        self::assertIsString($workflow);
        self::assertIsArray(Yaml::parse($workflow));
        $orderedMarkers = [
            'docker pull "${IMAGE_REF}"',
            'ensure_immutable_audit_writer_secret',
            'systemctl stop nginx',
            'docker compose stop --timeout 7200 ${BACKEND_SERVICES}',
            'assert_legacy_runtime_stopped',
            'php artisan migrate:safe --force',
            'php artisan immutable-audit:confirm-drain',
            'php artisan immutable-audit:phase-b-cutover --confirm-writer-version=2',
            'php artisan immutable-audit:writer-readiness',
            'docker compose up -d --force-recreate --remove-orphans ${BACKEND_SERVICES}',
            'curl -fsS http://localhost:8000/ready',
            'systemctl start nginx',
        ];
        $previous = -1;
        foreach ($orderedMarkers as $marker) {
            $position = strrpos($workflow, $marker);
            self::assertIsInt($position, $marker);
            self::assertGreaterThan($previous, $position, $marker);
            $previous = $position;
        }
    }

    public function test_cutover_flag_and_writer_secret_never_persist_or_cross_the_ssh_boundary(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 3).'/.github/workflows/deploy-backend.yml');

        self::assertIsString($workflow);
        self::assertStringContainsString('openssl rand -hex 32', $workflow);
        self::assertStringContainsString('upsert_env LEGAL_ARCHIVE_AUDIT_WRITER_SECRET', $workflow);
        self::assertStringContainsString('-e LEGAL_ARCHIVE_AUDIT_PHASE_B_CUTOVER_ENABLED=true', $workflow);
        self::assertSame(2, substr_count($workflow, '-e LEGAL_ARCHIVE_AUDIT_PHASE_B_CUTOVER_ENABLED=true'));
        self::assertStringContainsString('remove_env_key LEGAL_ARCHIVE_AUDIT_PHASE_B_CUTOVER_ENABLED', $workflow);
        self::assertStringNotContainsString('upsert_env LEGAL_ARCHIVE_AUDIT_PHASE_B_CUTOVER_ENABLED', $workflow);
        self::assertStringNotContainsString('LEGAL_ARCHIVE_AUDIT_WRITER_SECRET: ${{ secrets.', $workflow);
        self::assertStringNotContainsString('envs: LEGAL_ARCHIVE_AUDIT_WRITER_SECRET', $workflow);
        self::assertStringNotContainsString('echo "${LEGAL_ARCHIVE_AUDIT_WRITER_SECRET', $workflow);
        self::assertStringNotContainsString('set -x', $workflow);
        self::assertStringNotContainsString('sed -i', $workflow);
    }

    public function test_any_post_stop_failure_keeps_ingress_and_writers_stopped(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 3).'/.github/workflows/deploy-backend.yml');

        self::assertIsString($workflow);
        self::assertStringContainsString('trap deployment_failed ERR', $workflow);
        self::assertStringContainsString('deployment_failed()', $workflow);
        self::assertStringContainsString('systemctl stop nginx', $workflow);
        self::assertStringContainsString('docker compose stop --timeout 30 ${BACKEND_SERVICES}', $workflow);
        self::assertStringNotContainsString('continue-on-error:', $workflow);
        self::assertStringNotContainsString('rollback', strtolower($workflow));
    }

    public function test_api_compose_health_is_traffic_readiness_not_liveness(): void
    {
        $compose = file_get_contents(dirname(__DIR__, 3).'/docker-compose.yml');

        self::assertIsString($compose);
        self::assertStringContainsString('curl -f http://localhost:8000/ready', $compose);
        self::assertStringNotContainsString('curl -f http://localhost:8000/up', $compose);
    }
}
