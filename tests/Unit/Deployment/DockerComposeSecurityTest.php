<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;

final class DockerComposeSecurityTest extends TestCase
{
    public function test_api_octane_port_is_bound_to_host_loopback(): void
    {
        $compose = file_get_contents(dirname(__DIR__, 3).'/docker-compose.yml');

        self::assertIsString($compose);
        self::assertStringContainsString('"127.0.0.1:8000:8000"', $compose);
        self::assertStringNotContainsString('"8000:8000"', $compose);
    }

    public function test_production_docker_context_keeps_filament_vite_build_assets(): void
    {
        $rootPath = dirname(__DIR__, 3);
        $dockerfile = file_get_contents($rootPath.'/Dockerfile.prod');
        $dockerignore = file($rootPath.'/.dockerignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        self::assertIsString($dockerfile);
        self::assertIsArray($dockerignore);
        self::assertStringContainsString('COPY . ${APP_DIR}', $dockerfile);

        self::assertNotContains('/public/build', $dockerignore);
        self::assertContains('/public/build/*', $dockerignore);
        self::assertContains('!/public/build/manifest.json', $dockerignore);
        self::assertContains('!/public/build/assets/', $dockerignore);
        self::assertContains('!/public/build/assets/*', $dockerignore);
    }

    public function test_backend_deploy_runs_when_docker_context_rules_change(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 3).'/.github/workflows/deploy-backend.yml');

        self::assertIsString($workflow);
        self::assertStringContainsString("      - 'Dockerfile.prod'", $workflow);
        self::assertStringContainsString("      - '.dockerignore'", $workflow);
        self::assertStringContainsString("      - 'public/**'", $workflow);
    }

    public function test_monitoring_stack_deploys_container_alerting_services(): void
    {
        $rootPath = dirname(__DIR__, 3);
        $compose = file_get_contents($rootPath.'/docker-compose.yml');
        $prometheus = file_get_contents($rootPath.'/monitoring/prometheus/prometheus.yml');
        $workflow = file_get_contents($rootPath.'/.github/workflows/deploy-monitoring.yml');

        self::assertIsString($compose);
        self::assertIsString($prometheus);
        self::assertIsString($workflow);

        self::assertStringContainsString('alertmanager:', $compose);
        self::assertStringContainsString('cadvisor:', $compose);
        self::assertStringContainsString("targets: ['alertmanager:9093']", $prometheus);
        self::assertStringContainsString("targets: ['cadvisor:8080']", $prometheus);
        self::assertStringContainsString('container_alert_rules.yml', $prometheus);
        self::assertStringContainsString('prometheus alertmanager cadvisor grafana', $workflow);
    }

    public function test_container_alert_rules_cover_horizon_failures(): void
    {
        $rules = file_get_contents(dirname(__DIR__, 3).'/monitoring/prometheus/container_alert_rules.yml');

        self::assertIsString($rules);
        self::assertStringContainsString('HorizonContainerMissing', $rules);
        self::assertStringContainsString('HorizonContainerRestartLoop', $rules);
        self::assertStringContainsString('HorizonContainerHighMemory', $rules);
        self::assertStringContainsString('container_label_com_docker_compose_service="horizon"', $rules);
        self::assertStringContainsString('ProhelperApiContainerRestartLoop', $rules);
    }
}
