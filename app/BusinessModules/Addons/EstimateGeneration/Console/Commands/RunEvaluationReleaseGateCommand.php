<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Console\Commands;

use App\BusinessModules\Addons\EstimateGeneration\Evaluation\EvaluationReleaseGate;
use DomainException;
use Illuminate\Console\Command;

use function trans_message;

final class RunEvaluationReleaseGateCommand extends Command
{
    protected $signature = 'estimate-generation:evaluation-release-gate {organization_id}';

    protected $description = '';

    public function __construct(private readonly EvaluationReleaseGate $gate)
    {
        parent::__construct();
        $this->setDescription(trans_message('estimate_generation.evaluation_release_gate_description'));
    }

    public function handle(): int
    {
        $organizationId = filter_var($this->argument('organization_id'), FILTER_VALIDATE_INT);
        if (! is_int($organizationId) || $organizationId < 1) {
            $this->error(trans_message('estimate_generation.evaluation_release_gate_invalid_organization'));

            return self::INVALID;
        }

        try {
            $examples = $this->gate->reviewedCorpus($organizationId);
        } catch (DomainException) {
            $this->error(trans_message('estimate_generation.evaluation_release_gate_empty'));

            return self::FAILURE;
        }

        $this->info(trans_message('estimate_generation.evaluation_release_gate_ready', [
            'count' => count($examples),
        ]));

        return self::SUCCESS;
    }
}
