<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
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
            'assert_legacy_runtime_stopped',
            'php artisan immutable-audit:confirm-drain',
            'assert_legacy_runtime_stopped',
            'php artisan immutable-audit:phase-b-cutover --confirm-writer-version=2',
            'assert_legacy_runtime_stopped',
            'php artisan immutable-audit:confirm-drain',
            'assert_legacy_runtime_stopped',
            'php artisan immutable-audit:repair-invariants',
            'php artisan immutable-audit:writer-readiness',
            'docker compose up -d --force-recreate --remove-orphans ${BACKEND_SERVICES}',
            'curl -fsS http://localhost:8000/ready',
            'systemctl start nginx',
        ];
        $previous = -1;
        foreach ($orderedMarkers as $marker) {
            $position = strpos($workflow, $marker, $previous + 1);
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
        self::assertSame(3, substr_count($workflow, '-e LEGAL_ARCHIVE_AUDIT_PHASE_B_CUTOVER_ENABLED=true'));
        self::assertStringContainsString('remove_env_key LEGAL_ARCHIVE_AUDIT_PHASE_B_CUTOVER_ENABLED', $workflow);
        self::assertStringNotContainsString('upsert_env LEGAL_ARCHIVE_AUDIT_PHASE_B_CUTOVER_ENABLED', $workflow);
        self::assertStringContainsString('-e LEGAL_ARCHIVE_AUDIT_REPAIR_ENABLED=true api php artisan immutable-audit:repair-invariants --confirm-repair', $workflow);
        self::assertStringNotContainsString('upsert_env LEGAL_ARCHIVE_AUDIT_REPAIR_ENABLED', $workflow);
        self::assertStringNotContainsString('LEGAL_ARCHIVE_AUDIT_WRITER_SECRET: ${{ secrets.', $workflow);
        self::assertStringNotContainsString('envs: LEGAL_ARCHIVE_AUDIT_WRITER_SECRET', $workflow);
        self::assertStringNotContainsString('echo "${LEGAL_ARCHIVE_AUDIT_WRITER_SECRET', $workflow);
        self::assertStringNotContainsString('set -x', $workflow);
        self::assertStringNotContainsString('sed -i', $workflow);
        self::assertStringContainsString('source .github/scripts/atomic-env.sh', $workflow);
        self::assertStringContainsString('trap cleanup_deployment_temporary_files EXIT', $workflow);
    }

    public function test_atomic_env_replacement_hardens_mode_preserves_owner_content_and_emits_nothing(): void
    {
        $root = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir().'/most-env-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0777, true));
        $env = $directory.'/.env';
        file_put_contents($env, "APP_NAME=MOST\nKEEP=value\nSECRET=original\n");
        chmod($env, 0644);
        $originalUid = fileowner($env);
        $script = $directory.'/atomic-env.sh';
        $helper = file_get_contents($root.'/.github/scripts/atomic-env.sh');
        self::assertIsString($helper);
        file_put_contents($script, str_replace("\r\n", "\n", $helper));
        if (PHP_OS_FAMILY === 'Windows') {
            $env = $this->wslPath($env);
            $script = $this->wslPath($script);
        }
        $process = new Process([
            'bash', '-c',
            'ENV_FILE='.escapeshellarg($env).'; source '.escapeshellarg($script).'; initialize_secure_env; upsert_env SECRET replacement; remove_env_key MISSING; assert_env_security',
        ]);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertSame('', $process->getOutput());
        $nativeEnv = $directory.'/.env';
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0600, fileperms($nativeEnv) & 0777);
        }
        self::assertSame($originalUid, fileowner($nativeEnv));
        self::assertSame("APP_NAME=MOST\nKEEP=value\nSECRET=replacement\n", file_get_contents($nativeEnv));
        unlink($nativeEnv);
        unlink($directory.'/atomic-env.sh');
        rmdir($directory);
    }

    public function test_atomic_env_helper_never_overwrites_the_existing_inode(): void
    {
        $helper = file_get_contents(dirname(__DIR__, 3).'/.github/scripts/atomic-env.sh');

        self::assertIsString($helper);
        self::assertStringContainsString('mv -f -- "${ENV_TEMP_FILE}" "${ENV_FILE}"', $helper);
        self::assertStringContainsString('chmod 600', $helper);
        self::assertStringContainsString("stat -c '%a'", $helper);
        self::assertStringContainsString('command -v sync >/dev/null 2>&1 || return 1', $helper);
        self::assertStringContainsString('sync -f -- "${ENV_TEMP_FILE}" || return 1', $helper);
        self::assertStringContainsString('sync -f -- "${env_directory}" || return 1', $helper);
        self::assertTrue(strpos($helper, 'sync -f -- "${ENV_TEMP_FILE}"') < strpos($helper, 'mv -f -- "${ENV_TEMP_FILE}"'));
        self::assertTrue(strpos($helper, 'mv -f -- "${ENV_TEMP_FILE}"') < strpos($helper, 'sync -f -- "${env_directory}"'));
        self::assertStringNotContainsString('cat "${ENV_TEMP_FILE}" >', $helper);
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

    public function test_host_runtime_drain_uses_committed_allowlist_and_fail_closed_proc_inspection(): void
    {
        $root = dirname(__DIR__, 3);
        $workflow = file_get_contents($root.'/.github/workflows/deploy-backend.yml');
        $allowlist = file_get_contents($root.'/deploy/backend-runtime-allowlist.sh');

        self::assertIsString($workflow);
        self::assertIsString($allowlist);
        self::assertStringContainsString('source deploy/backend-runtime-allowlist.sh', $workflow);
        self::assertStringContainsString('MOST_COMPOSE_WRITER_SERVICES', $allowlist);
        self::assertStringContainsString('MOST_SYSTEMD_WRITER_UNITS', $allowlist);
        self::assertStringContainsString('MOST_SUPERVISOR_WRITER_PROGRAM_PATTERN', $allowlist);
        self::assertStringContainsString('/proc/[0-9]*', $workflow);
        self::assertStringContainsString('/cmdline', $workflow);
        self::assertStringContainsString('/cgroup', $workflow);
        self::assertStringContainsString('readlink -f', $workflow);
        self::assertStringContainsString('php([0-9.]+)?', $workflow);
        self::assertStringContainsString('rr[[:space:]]+serve', $workflow);
        self::assertStringContainsString('MOST backend writer process remains active', $workflow);
        self::assertStringContainsString('MOST backend writer unit remains active', $workflow);
        self::assertStringContainsString('MOST backend supervisor writer remains active', $workflow);
        self::assertStringContainsString('systemctl is-active --quiet "${unit}"', $workflow);
        self::assertStringContainsString('$2 == "RUNNING"', $workflow);
        self::assertStringContainsString('stop_legacy_systemd_processes', $workflow);
        self::assertStringContainsString('prohelper-octane.service', $allowlist);
        self::assertStringContainsString('prohelper-queue.service', $allowlist);
        self::assertStringContainsString('reverb.service', $allowlist);
        self::assertStringNotContainsString('most-backend.service', $allowlist);
        self::assertSame(6, substr_count($workflow, 'assert_legacy_runtime_stopped'));
        $compose = Yaml::parseFile($root.'/docker-compose.yml');
        self::assertIsArray($compose);
        foreach (['api', 'websockets', 'horizon', 'geometry-worker', 'geometry-recovery-worker', 'worker-heavy', 'worker-ifc', 'scheduler'] as $service) {
            self::assertArrayHasKey($service, $compose['services']);
            self::assertStringContainsString($service, $allowlist);
        }
        foreach ([
            'php8.2 artisan queue:work',
            'php -d memory_limit=1G ./artisan horizon',
            '/usr/bin/php artisan schedule:work',
            './rr serve -c .rr.yaml',
            'roadrunner serve',
        ] as $command) {
            self::assertMatchesRegularExpression('/(?:php(?:[0-9.]+)?|rr|roadrunner).*?(?:artisan|queue:work|horizon|schedule:work|serve|roadrunner)/', $command);
        }
    }

    public function test_api_compose_health_is_traffic_readiness_not_liveness(): void
    {
        $compose = file_get_contents(dirname(__DIR__, 3).'/docker-compose.yml');

        self::assertIsString($compose);
        self::assertStringContainsString('curl -f http://localhost:8000/ready', $compose);
        self::assertStringNotContainsString('curl -f http://localhost:8000/up', $compose);
    }

    private function wslPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (preg_match('/^([A-Za-z]):\/(.*)$/', $path, $matches) !== 1) {
            return $path;
        }

        return '/mnt/'.strtolower($matches[1]).'/'.$matches[2];
    }
}
