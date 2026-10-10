<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\SessionStateStore;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CreateEstimateGenerationSession
{
    public function __construct(
        private SessionStateStore $stateStore,
        private InitialProjectFactsWriter $facts,
        private EstimateGenerationActionAuthorization $authorization,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(array $attributes, User $actor): EstimateGenerationSession
    {
        return DB::transaction(function () use ($attributes, $actor): EstimateGenerationSession {
            $session = $this->stateStore->create([...$attributes, 'user_id' => (int) $actor->id]);
            $this->authorization->authorize($actor, $session, 'estimate_generation.create');
            $this->facts->write($session, $actor);

            return $session;
        }, 3);
    }
}
