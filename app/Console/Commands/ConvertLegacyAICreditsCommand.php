<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use Illuminate\Console\Command;

final class ConvertLegacyAICreditsCommand extends Command
{
    protected $signature = 'ai-credits:convert-legacy {--execute : Apply conversion; default is dry run} {--organization_id=}';

    protected $description = 'Конвертировать неиспользованные старые запросы ИИ и платные дополнения в единицы МОСТ';

    public function handle(\App\Services\Credits\LegacyAICreditConversionService $conversion): int
    {
        $execute = (bool) $this->option('execute');
        $converted = 0;
        $organizations = Organization::query()->when($this->option('organization_id'), fn ($query, $id) => $query->whereKey((int) $id))->cursor();
        foreach ($organizations as $organization) {
            $report = $conversion->report($organization, $execute);
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $converted += $report['total_units'];
        }
        $this->info(($execute ? 'Конвертировано: ' : 'Будет конвертировано: ').$converted.' единиц.');
        return self::SUCCESS;
    }
}
