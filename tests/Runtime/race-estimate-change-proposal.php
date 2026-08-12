<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\ApplyEstimateChangeProposal;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\CancelEstimateChangeProposal;
use App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Dialogue\EstimateChangeProposalRepository;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (count($argv) !== 9) {
    throw new InvalidArgumentException('Invalid race process arguments.');
}
[, $operation, $actorId, $organizationId, $projectId, $sessionId, $proposalId, $expectedVersion, $barrier] = $argv;
if (! in_array($operation, ['apply', 'cancel'], true)
    || array_filter(
        [$actorId, $organizationId, $projectId, $sessionId],
        static fn (string $value): bool => preg_match('/^[1-9][0-9]{0,18}$/D', $value) !== 1,
    ) !== []
    || preg_match('/^(?:0|[1-9][0-9]{0,18})$/D', $expectedVersion) !== 1
    || preg_match('/^[0-9a-f-]{36}$/D', $proposalId) !== 1
    || ! is_numeric($barrier)) {
    throw new InvalidArgumentException('Invalid race process arguments.');
}
while (microtime(true) < (float) $barrier) {
    usleep(1000);
}

$actor = User::query()->whereKey((int) $actorId)->firstOrFail();
try {
    $proposal = $operation === 'apply'
        ? $app->make(ApplyEstimateChangeProposal::class)->handle(
            $actor,
            (int) $organizationId,
            (int) $projectId,
            (int) $sessionId,
            $proposalId,
            (int) $expectedVersion,
        )
        : $app->make(CancelEstimateChangeProposal::class)->handle(
            (int) $actorId,
            (int) $organizationId,
            (int) $projectId,
            (int) $sessionId,
            $proposalId,
        );
} catch (RuntimeException) {
    $proposal = $app->make(EstimateChangeProposalRepository::class)->find(
        $proposalId,
        (int) $organizationId,
        (int) $projectId,
        (int) $sessionId,
    );
}

fwrite(STDOUT, json_encode(['status' => $proposal->payload['status']], JSON_THROW_ON_ERROR));
