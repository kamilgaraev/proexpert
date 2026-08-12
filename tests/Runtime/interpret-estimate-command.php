<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\InterpretEstimateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $sessionId, $actorId, $command, $idempotencyKey, $barrier] = $argv;
while (microtime(true) < (float) $barrier) {
    usleep(1_000);
}

$app->instance(LLMProviderInterface::class, new class($idempotencyKey) implements LLMProviderInterface
{
    public function __construct(private readonly string $requestKey) {}

    public function chat(array $messages, array $options = []): array
    {
        $context = json_decode((string) ($messages[1]['content'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($context['context']['snapshot'] ?? null)) {
            throw new RuntimeException('production_context_missing');
        }
        DB::table('estimate_stage7_provider_spy')->insert([
            'request_key' => $this->requestKey,
            'created_at' => now(),
        ]);
        usleep(300_000);

        return ['content' => json_encode([
            'kind' => 'explain',
            'version' => 'provider-spy:v1',
            'explanation' => 'Проверка производственного контура.',
            'evidence_ids' => [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
    }

    public function countTokens(string $text): int
    {
        return max(1, (int) ceil(strlen($text) / 4));
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getModel(): string
    {
        return 'stage7-provider-spy';
    }
});

$result = $app->make(InterpretEstimateCommand::class)->handle(
    EstimateGenerationSession::query()->findOrFail((int) $sessionId),
    (int) $actorId,
    $command,
    $idempotencyKey,
);

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
