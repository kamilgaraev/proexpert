<?php

declare(strict_types=1);

use App\Services\Privacy\PublicCore\PublicCoreRuntimeReadiness;
use App\Services\Privacy\PublicCore\PublicCoreProcessor;
use App\Services\Privacy\PublicCore\RegisteredPublicFixtureRegistry;

require_once __DIR__ . '/../../app/Services/Privacy/PublicCore/RegisteredPublicFixtureRegistry.php';
require_once __DIR__ . '/../../app/Services/Privacy/PublicCore/PublicCoreRuntimeReadiness.php';

$readiness = new PublicCoreRuntimeReadiness(RegisteredPublicFixtureRegistry::compiled());

if (PHP_SAPI === 'cli' && isset($publicCoreProcessor, $publicCoreServe)
    && $publicCoreProcessor instanceof PublicCoreProcessor && $publicCoreServe instanceof Closure) {
    $publicCoreServe(static fn (string $command, array $payload, array $verifiedPeer): array =>
        $publicCoreProcessor->handle($command, $payload, $verifiedPeer));
    return;
}

if (PHP_SAPI !== 'cli') {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
}

echo json_encode($readiness->resolve(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
