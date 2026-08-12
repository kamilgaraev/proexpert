<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateCommandContextBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateCommandInterpretation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateCommandInterpreter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\InterpretEstimateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\PreviewEstimateChange;
use App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Dialogue\EstimateChangeProposalRepository;
use App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Dialogue\EstimateInterpretationAttemptRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $sessionId, $actorId, $command, $idempotencyKey, $barrier] = $argv;
while (microtime(true) < (float) $barrier) {
    usleep(1_000);
}

$interpreter = new class($idempotencyKey) implements EstimateCommandInterpreter
{
    public function __construct(private readonly string $requestKey) {}

    public function interpret(EstimateGenerationSession $session, int $actorId, string $command, ?array $context = null): EstimateCommandInterpretation
    {
        DB::table('estimate_stage7_provider_spy')->insert([
            'request_key' => $this->requestKey,
            'created_at' => now(),
        ]);
        usleep(300_000);

        return new EstimateCommandInterpretation([
            'kind' => 'correct_fact',
            'version' => 'provider-spy:v1',
            'before' => ['value' => '10.0000'],
            'after' => ['value' => '11.0000'],
            'affected' => [],
            'dependency_keys' => [],
            'assumptions' => [],
            'questions' => [],
            'evidence' => [],
            'cost_delta_known' => false,
            'cost_delta' => null,
        ]);
    }
};

$service = new InterpretEstimateCommand(
    $interpreter,
    $app->make(PreviewEstimateChange::class),
    $app->make(EstimateChangeProposalRepository::class),
    $app->make(EstimateInterpretationAttemptRepository::class),
    new EstimateCommandContextBuilder,
);
$result = $service->handle(
    EstimateGenerationSession::query()->findOrFail((int) $sessionId),
    (int) $actorId,
    $command,
    $idempotencyKey,
);

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
