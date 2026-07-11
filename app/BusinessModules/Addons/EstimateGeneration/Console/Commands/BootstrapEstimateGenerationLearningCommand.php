<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Console\Commands;

use App\BusinessModules\Features\BudgetEstimates\Integrations\EstimateGeneration\EstimateGenerationLearningBootstrapService;
use Illuminate\Console\Command;

final class BootstrapEstimateGenerationLearningCommand extends Command
{
    protected $signature = 'estimates:generation-learning:bootstrap
        {--organization_id= : Limit bootstrap to one organization}
        {--project_id= : Limit bootstrap to one project}
        {--estimate_id= : Limit bootstrap to one estimate}
        {--limit= : Limit processed estimates}
        {--chunk=100 : Estimate chunk size}
        {--min-quality=0.85 : Minimal source quality score}
        {--allow-unverified-units : Allow examples without confirmed unit compatibility}
        {--include-demo : Include onboarding demo estimates}
        {--write : Persist examples; without this option the command only reports candidates}';

    protected $description = 'Bootstrap estimate generation learning examples from already imported estimates.';

    public function handle(EstimateGenerationLearningBootstrapService $bootstrapService): int
    {
        $minQuality = $this->floatOption('min-quality', 0.85);

        if ($minQuality < 0.0 || $minQuality > 1.0) {
            $this->error('--min-quality must be between 0 and 1.');

            return self::FAILURE;
        }

        $result = $bootstrapService->bootstrap([
            'organization_id' => $this->nullableIntOption('organization_id'),
            'project_id' => $this->nullableIntOption('project_id'),
            'estimate_id' => $this->nullableIntOption('estimate_id'),
            'limit' => $this->nullableIntOption('limit'),
            'chunk' => $this->nullableIntOption('chunk'),
            'min_quality' => $minQuality,
            'require_unit_compatible' => ! (bool) $this->option('allow-unverified-units'),
            'include_demo' => (bool) $this->option('include-demo'),
            'write' => (bool) $this->option('write'),
        ]);

        $this->table(['Metric', 'Value'], [
            ['mode', $result['dry_run'] ? 'dry-run' : 'write'],
            ['processed_estimates', $result['processed_estimates']],
            ['candidate_examples', $result['candidate_examples']],
            ['passed_quality_gate', $result['passed_quality_gate']],
            ['skipped_low_quality', $result['skipped_low_quality']],
            ['existing_examples', $result['existing_examples']],
            ['would_create_examples', $result['would_create_examples']],
            ['created_examples', $result['created_examples']],
        ]);

        if ($result['dry_run']) {
            $this->warn('Dry run only. Add --write to persist learning examples.');
        } elseif ((int) $result['created_examples'] > 0) {
            $this->info('Next step: run ai-assistant:rag-backfill with --source_type=estimate_generation_learning.');
        }

        return self::SUCCESS;
    }

    private function nullableIntOption(string $key): ?int
    {
        $value = $this->option($key);

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function floatOption(string $key, float $default): float
    {
        $value = $this->option($key);

        return is_numeric($value) ? (float) $value : $default;
    }
}
