<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Credits\AICreditEconomicsMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AICreditEconomicsReportCommand extends Command
{
    protected $signature = 'ai-credits:economics-report {--date= : Last complete UTC day, YYYY-MM-DD} {--days=30 : Number of complete days} {--output= : Private JSON output path}';

    protected $description = 'Сохранить фактическую экономику помощника без изменения списаний и готовности платного режима';

    public function handle(AICreditEconomicsMonitor $monitor): int
    {
        $date = $this->option('date') ?: CarbonImmutable::now('UTC')->subDay()->toDateString();
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 366]]);
        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1 || $days === false) {
            $this->error('economics_period_invalid');
            return self::INVALID;
        }
        $temporary = null;
        try {
            $last = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
            if ($last === false || $last->toDateString() !== $date) { throw new \InvalidArgumentException('economics_period_invalid'); }
            $report = $monitor->report($last->subDays($days - 1), $last->addDay());
            $path = $this->option('output') ?: storage_path('app/private/assistant-economics/'.$date.'.json');
            $directory = dirname($path);
            if (! is_dir($directory) && ! mkdir($directory, 0700, true)) { throw new \RuntimeException('economics_directory_write_failed'); }
            $temporary = tempnam($directory, '.economics-');
            if ($temporary === false || file_put_contents($temporary, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), LOCK_EX) === false
                || ! chmod($temporary, 0600) || ! rename($temporary, $path)) { throw new \RuntimeException('economics_report_write_failed'); }
            $temporary = null;
            $metrics = array_intersect_key($report, array_flip(['state', 'external_cost_micro_rub', 'assistant_revenue_minor', 'unknown_cost_count', 'unknown_allocation_count', 'missing_source_count', 'provider_call_count', 'settled_assistant_payment_count']));
            if (in_array($report['state'], ['coverage_incomplete', 'exceeds_30_percent'], true)) {
                Log::warning('ai_credits.economics_monitor', $metrics);
            } else {
                Log::info('ai_credits.economics_monitor', $metrics);
            }
            $this->line(json_encode($metrics, JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (Throwable $exception) {
            if (is_string($temporary) && is_file($temporary)) { unlink($temporary); }
            Log::error('ai_credits.economics_monitor_failed', ['exception_class' => $exception::class]);
            $this->error('economics_report_failed');
            return self::FAILURE;
        }
    }
}
