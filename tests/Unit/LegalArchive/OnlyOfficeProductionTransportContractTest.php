<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use PHPUnit\Framework\TestCase;

final class OnlyOfficeProductionTransportContractTest extends TestCase
{
    public function test_production_image_and_composer_require_curl_transport(): void
    {
        $root = dirname(__DIR__, 3);
        $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $dockerfile = (string) file_get_contents($root.'/Dockerfile.prod');
        $fetcher = (string) file_get_contents($root.'/app/Services/LegalArchive/Editor/OnlyOfficeBoundedDocumentFetcher.php');

        self::assertSame('*', $composer['require']['ext-curl'] ?? null);
        self::assertStringContainsString('curl-dev', $dockerfile);
        self::assertStringContainsString('apk del .curl-build-deps', $dockerfile);
        self::assertMatchesRegularExpression('/docker-php-ext-install[^\n]+curl/', $dockerfile);
        self::assertStringContainsString("extension_loaded('curl')", $dockerfile);
        self::assertStringContainsString('new CurlHttpClient', $fetcher);
        self::assertStringNotContainsString("'proxy' => ''", $fetcher);
    }

    public function test_document_server_reaches_the_api_callback_through_the_docker_host_gateway(): void
    {
        $root = dirname(__DIR__, 3);
        $compose = (string) file_get_contents($root.'/deploy/onlyoffice/docker-compose.yml');
        $environment = (string) file_get_contents($root.'/deploy/onlyoffice/.env.example');
        $deployment = (string) file_get_contents($root.'/.github/workflows/deploy-backend.yml');

        self::assertStringContainsString('extra_hosts:', $compose);
        self::assertStringContainsString('${ONLYOFFICE_CALLBACK_HOST:?Set ONLYOFFICE_CALLBACK_HOST in .env}:host-gateway', $compose);
        self::assertStringContainsString('ONLYOFFICE_CALLBACK_HOST=api.example.ru', $environment);
        self::assertStringContainsString("- 'deploy/**'", $deployment);
        self::assertStringContainsString('apply_onlyoffice_callback_transport', $deployment);
        self::assertStringContainsString('ENV_FILE="deploy/onlyoffice/.env"', $deployment);
        self::assertStringContainsString('docker compose up -d --force-recreate onlyoffice', $deployment);
        self::assertStringContainsString('sed -E "s/^', $deployment);
        self::assertStringNotContainsString('sed -E \\"', $deployment);
    }

    public function test_deployment_refuses_to_continue_with_mismatched_onlyoffice_jwt_secrets(): void
    {
        $deployment = (string) file_get_contents(dirname(__DIR__, 3).'/.github/workflows/deploy-backend.yml');
        $guardStart = strpos($deployment, 'ensure_onlyoffice_jwt_secret_alignment()');
        $guardEnd = strpos($deployment, 'apply_onlyoffice_callback_transport()');
        $runtimeGuard = strpos($deployment, "ensure_onlyoffice_jwt_secret_alignment\n            if ! git diff");
        $runtimeShutdown = strpos($deployment, 'trap deployment_failed ERR');

        self::assertIsInt($guardStart);
        self::assertIsInt($guardEnd);
        self::assertIsInt($runtimeGuard);
        self::assertIsInt($runtimeShutdown);
        $guard = substr($deployment, $guardStart, $guardEnd - $guardStart);

        self::assertStringContainsString('editor_enabled_lines', $deployment);
        self::assertStringContainsString("'1'|'true'|'on'|'yes'", $deployment);
        self::assertStringContainsString("s/^[[:space:]]+//; s/[[:space:]]+$//", $deployment);
        self::assertStringContainsString('OnlyOffice editor configuration is missing, duplicated or invalid; refusing deployment', $deployment);
        self::assertStringContainsString('LEGAL_DOCUMENT_EDITOR_JWT_SECRET', $guard);
        self::assertStringContainsString('ONLYOFFICE_JWT_SECRET', $guard);
        self::assertStringContainsString('onlyoffice_runtime_secret', $guard);
        self::assertStringContainsString("docker inspect -f '{{.State.Running}}' most-onlyoffice", $guard);
        self::assertStringContainsString("docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' most-onlyoffice", $guard);
        self::assertStringContainsString('OnlyOffice JWT secrets or running container differ; refusing deployment', $guard);
        self::assertMatchesRegularExpression('/OnlyOffice JWT secrets or running container differ; refusing deployment\'\R\s+return 1/', $guard);
        self::assertStringNotContainsString('echo "${backend_secret}"', $guard);
        self::assertStringNotContainsString('echo "${onlyoffice_secret}"', $guard);
        self::assertLessThan(
            $runtimeShutdown,
            $runtimeGuard,
        );
    }
}
